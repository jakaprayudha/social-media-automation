<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
umask(0077);
$root = dirname(__DIR__);
$directory = $root . '/var/test-' . bin2hex(random_bytes(8));
$server = null;
$checks = 0;
$messages = [];
$exitCode = 0;

function check(bool $condition, string $message): void
{
    global $checks, $messages;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $messages[] = 'PASS ' . $message;
}

/** @return array{status:int, body:string, headers:string} */
function request(string $url, string $jar, ?array $data = null): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Cannot create test client.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 10,
    ]);
    if ($data !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    }
    $response = curl_exec($curl);
    if (!is_string($response)) {
        throw new RuntimeException('HTTP test request failed.');
    }
    $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    unset($curl);
    return ['status' => $status, 'headers' => substr($response, 0, $size), 'body' => substr($response, $size)];
}

function csrfFrom(string $html): string
{
    if (!preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $matches)) {
        throw new RuntimeException('CSRF field missing.');
    }
    return $matches[1];
}

function latestToken(App $app, int $userId): string
{
    $query = $app->db()->prepare('SELECT encrypted_payload FROM mail_jobs j JOIN password_resets r ON r.id = j.reset_id
        WHERE r.user_id = ? AND j.encrypted_payload IS NOT NULL ORDER BY j.id DESC LIMIT 1');
    $query->execute([$userId]);
    $payload = json_decode($app->decrypt((string) $query->fetchColumn()), true, 8, JSON_THROW_ON_ERROR);
    if (!preg_match('/token=([a-f0-9]{64})/', $payload['body'], $match)) {
        throw new RuntimeException('Reset token not in mail payload.');
    }
    return $match[1];
}

function concurrent(string $operation, string $configPath, ?string $token = null): array
{
    $processes = [];
    $environment = getenv();
    $environment['APP_CONFIG'] = $configPath;
    if ($token !== null) {
        $environment['TEST_RESET_TOKEN'] = $token;
    }
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/concurrent.php', $operation],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot launch concurrent test.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') {
            throw new RuntimeException('Concurrent test failed: ' . $error);
        }
        $results[] = $output;
    }
    return $results;
}

try {
    if (!mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create isolated test storage.');
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Cannot allocate test port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address;
    $config = require $root . '/config/example.php';
    $config['environment'] = 'development';
    $config['base_url'] = $baseUrl;
    $config['app_key'] = bin2hex(random_bytes(32));
    $config['storage_path'] = $directory;
    $config['mail']['transport'] = 'file';
    $configPath = $directory . '/test-config.php';
    file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');
    $app = new App($config);
    check($app->url('forgot') === '/index.php?page=forgot'
        && $app->absoluteUrl('reset') === $baseUrl . '/index.php?page=reset',
        'Separate same-origin web URLs from canonical email URLs');
    $subdirectoryConfig = $config;
    $subdirectoryConfig['base_url'] = 'https://social.example.test/workspace';
    $subdirectoryApp = new App($subdirectoryConfig);
    check($subdirectoryApp->basePath() === '/workspace'
        && $subdirectoryApp->url('forgot') === '/workspace/index.php?page=forgot'
        && $subdirectoryApp->absoluteUrl('reset') === 'https://social.example.test/workspace/index.php?page=reset',
        'Preserve subdirectory in web and canonical URLs');
    $app->migrate();
    check($app->db()->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'SQLite migration and integrity');
    $app->db()->prepare('INSERT INTO users (name, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(['Admin Test', 'admin@example.test', password_hash('initial-passphrase-2026', PASSWORD_DEFAULT), time(), time()]);
    $userId = (int) $app->db()->lastInsertId();
    $app->migrate();
    check((int) $app->db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Migrations preserve existing users');
    $dashboardService = new Dashboard($app);
    $emptyDashboard = $dashboardService->snapshot(null);
    check(count($emptyDashboard['companies']) === 3
        && array_column($emptyDashboard['companies'], 'name') === ['Signal Prima Solusi', 'Netindo Persada Nusantara', 'Mega Data Link'],
        'Onboard exactly the three PRD companies without duplicate seeds');
    check($emptyDashboard['total_content'] === 0 && $emptyDashboard['total_accounts'] === 0
        && array_sum($emptyDashboard['metrics']) === 0, 'Empty dashboard reports real zeros without demo content/accounts');
    $companyIds = array_map('intval', array_column($emptyDashboard['companies'], 'id'));
    foreach ($companyIds as $index => $companyId) {
        foreach (array_keys(Dashboard::STATUSES) as $status) {
            for ($copy = 0; $copy <= $index; $copy++) {
                $app->db()->prepare('INSERT INTO content_items (company_id, owner_id, title, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?)')->execute([
                        $companyId, $userId, 'Fixture ' . $companyId . ' ' . $status . ' <script>test</script>',
                        $status, time(), time() + $index,
                    ]);
            }
        }
        $app->db()->prepare('INSERT INTO social_accounts (company_id, platform, account_id, display_name,
            connection_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$companyId, 'instagram', 'fixture-' . $companyId, 'Fixture account', $index === 0 ? 'connected' : 'needs_authorization', time(), time()]);
    }
    $allDashboard = $dashboardService->snapshot(null);
    check($allDashboard['total_content'] === 66 && $allDashboard['metrics']['ideas'] === 6
        && $allDashboard['metrics']['drafts'] === 18 && $allDashboard['metrics']['review'] === 6
        && $allDashboard['metrics']['scheduled'] === 6 && $allDashboard['metrics']['published'] === 6
        && $allDashboard['metrics']['failed'] === 6, 'Aggregate all statuses across three companies without multiplying rows');
    check($allDashboard['total_accounts'] === 3 && $allDashboard['connected_accounts'] === 1,
        'Connection summary reflects stored account states');
    foreach ($companyIds as $index => $companyId) {
        $scoped = $dashboardService->snapshot($companyId);
        check(count($scoped['visible_companies']) === 1 && $scoped['total_content'] === 11 * ($index + 1)
            && $scoped['metrics']['drafts'] === 3 * ($index + 1) && $scoped['total_accounts'] === 1
            && count(array_unique(array_column($scoped['recent_content'], 'company_name'))) === 1,
            'Isolate counts, channels and recent content for company ' . $companyId);
    }
    check(count($allDashboard['recent_content']) === 8
        && $allDashboard['recent_content'][0]['company_name'] === 'Mega Data Link', 'Bound recent content and sort newest first');
    check(dashboardTime(0, 'Asia/Jakarta') === '01/01/1970 07:00', 'Display UTC timestamps in company timezone');
    try {
        $dashboardService->snapshot(999999);
        check(false, 'Reject unknown company filter');
    } catch (InvalidArgumentException) {
        check(true, 'Reject unknown company filter');
    }
    try {
        $app->db()->prepare('INSERT INTO content_items (company_id, owner_id, title, created_at, updated_at)
            VALUES (999999, ?, ?, ?, ?)')->execute([$userId, 'Invalid', time(), time()]);
        check(false, 'Enforce company foreign key on content');
    } catch (PDOException) {
        check(true, 'Enforce company foreign key on content');
    }
    check(passwordProblem('123') !== null && passwordProblem('1234') === null && passwordProblem(str_repeat('a', 73)) !== null
        && passwordProblem(str_repeat('a', 72)) === null
        && passwordProblem(str_repeat('é', 37)) !== null, 'Password minimum and bcrypt byte limit');
    $invalidConfig = $config;
    $invalidConfig['base_url'] = 'https://user@example.test';
    try {
        new App($invalidConfig);
        check(false, 'Reject credentials in base URL');
    } catch (RuntimeException) {
        check(true, 'Reject credentials in base URL');
    }
    $auth = new Auth($app);
    $calendarService = new ContentCalendar($app);
    $emptyCalendar = $calendarService->snapshot(null, '2026-10', 'all');
    check($emptyCalendar['total'] === 0 && $emptyCalendar['unscheduled'] === 66,
        'Empty calendar does not invent dates or demo content');
    $fixtureQuery = $app->db()->prepare('SELECT id FROM content_items WHERE company_id = ? AND status = ? ORDER BY id LIMIT 1');
    $calendarFixtures = [
        [$companyIds[0], 'scheduled', '2026-09-30T17:00:00Z'],
        [$companyIds[1], 'published', '2026-10-31T16:59:59Z'],
        [$companyIds[2], 'failed', '2026-10-31T17:00:00Z'],
    ];
    foreach ($calendarFixtures as [$fixtureCompany, $fixtureStatus, $fixtureTime]) {
        $fixtureQuery->execute([$fixtureCompany, $fixtureStatus]);
        $fixtureId = $fixtureQuery->fetchColumn();
        $fixtureQuery->closeCursor();
        $app->db()->prepare('UPDATE content_items SET scheduled_at = ?, brief = ? WHERE id = ?')
            ->execute([(new DateTimeImmutable($fixtureTime))->getTimestamp(), '<script>calendar brief</script>', $fixtureId]);
    }
    $octoberCalendar = $calendarService->snapshot(null, '2026-10', 'all');
    check($octoberCalendar['total'] === 2 && count($octoberCalendar['days']['2026-10-01']) === 1
        && count($octoberCalendar['days']['2026-10-31']) === 1 && $octoberCalendar['unscheduled'] === 63,
        'Calendar respects local month boundaries and excludes the next month');
    check($calendarService->snapshot($companyIds[0], '2026-10', 'scheduled')['total'] === 1
        && $calendarService->snapshot($companyIds[0], '2026-10', 'published')['total'] === 0
        && $calendarService->snapshot(null, '2026-10', 'published')['total'] === 1,
        'Calendar company and status filters isolate actual content');
    check($calendarService->snapshot(null, '2028-02', 'all')['start']->format('t') === '29'
        && $calendarService->snapshot(null, '2000-01', 'all')['previous_month'] === null
        && $calendarService->snapshot(null, '2099-12', 'all')['next_month'] === null,
        'Calendar supports leap months and bounds month navigation');
    $app->db()->prepare('UPDATE companies SET timezone = ? WHERE id = ?')->execute(['America/New_York', $companyIds[0]]);
    check($calendarService->snapshot($companyIds[0], '2026-09', 'all')['total'] === 1
        && $calendarService->snapshot($companyIds[0], '2026-10', 'all')['total'] === 0,
        'Selected company calendar uses its timezone');
    $app->db()->prepare('UPDATE companies SET timezone = ? WHERE id = ?')->execute(['Asia/Jakarta', $companyIds[0]]);
    $auth->startSession();
    check(strlen($auth->csrf()) === 64 && !$auth->validCsrf('wrong') && !$auth->validCsrf([]), 'CSRF generation and validation');
    $sessionBefore = session_id();
    check($auth->login('admin@example.test', 'wrong', 'unit-login') === 'invalid', 'Reject incorrect credentials');
    check($auth->login('admin@example.test', 'initial-passphrase-2026', 'unit-login') === 'ok', 'Authenticate with stored password hash');
    check(session_id() !== $sessionBefore && $auth->user()['id'] === $userId, 'Rotate session after login');
    $_SESSION['last_seen'] = time() - 1801;
    check($auth->user() === null, 'Enforce idle session expiry');
    $auth->login('admin@example.test', 'initial-passphrase-2026', 'unit-login');
    $_SESSION['signed_in_at'] = time() - 28801;
    check($auth->user() === null, 'Enforce absolute session expiry');
    $auth->login('admin@example.test', 'initial-passphrase-2026', 'unit-login');
    for ($i = 0; $i < 10; $i++) {
        $result = $auth->login('missing@example.test', 'incorrect', 'rate-test');
    }
    check($result === 'invalid' && $auth->login('missing@example.test', 'incorrect', 'rate-test') === 'rate_limited',
        'Rate-limit login for unknown accounts too');
    check($auth->requestReset('nobody@example.test', 'reset-unit') === 'accepted'
        && (int) $app->db()->query('SELECT COUNT(*) FROM mail_jobs')->fetchColumn() === 0,
        'Reset request hides unknown addresses without sending mail');
    check($auth->requestReset('admin@example.test', 'reset-unit') === 'accepted', 'Queue reset request for active account');
    $token = latestToken($app, $userId);
    $stored = $app->db()->query('SELECT token_hash FROM password_resets')->fetchColumn();
    $encrypted = $app->db()->query('SELECT encrypted_payload FROM mail_jobs')->fetchColumn();
    check($stored === hash('sha256', $token) && !str_contains($encrypted, $token), 'Hash reset token and encrypt queued email');
    $mailPayload = json_decode($app->decrypt($encrypted), true, 8, JSON_THROW_ON_ERROR);
    check(str_contains($mailPayload['body'], $baseUrl . '/index.php?page=reset&token='),
        'Reset email contains configured absolute URL');
    check($auth->resetUsable($token) && !$auth->resetUsable('bogus'), 'Validate token shape and validity');
    $summary = (new MailWorker($app))->run();
    check($summary['sent'] === 1 && count(glob($directory . '/mail/*.eml')) === 1, 'Deliver development reset mail to private storage');
    check($app->db()->query('SELECT encrypted_payload FROM mail_jobs')->fetchColumn() === null, 'Remove mail payload after delivery');
    check($auth->resetPassword($token, 'new-passphrase-2026'), 'Reset password using valid token');
    check(!$auth->resetPassword($token, 'should-not-be-saved-2026'), 'Reject token replay');
    check($auth->user() === null, 'Password reset revokes previously signed-in session');
    check($auth->login('admin@example.test', 'initial-passphrase-2026', 'new-ip') === 'invalid'
        && $auth->login('admin@example.test', 'new-passphrase-2026', 'new-ip') === 'ok',
        'Old password rejected and new password accepted');
    $auth->requestReset('admin@example.test', 'reset-unit');
    $expired = latestToken($app, $userId);
    $app->db()->prepare('UPDATE password_resets SET expires_at = ? WHERE token_hash = ?')
        ->execute([time() - 1, hash('sha256', $expired)]);
    check(!$auth->resetUsable($expired) && !$auth->resetPassword($expired, 'should-not-be-saved-2026'), 'Reject expired reset token');
    $auth->requestReset('admin@example.test', 'reset-unit');
    $raceToken = latestToken($app, $userId);
    $outcomes = concurrent('reset', $configPath, $raceToken);
    sort($outcomes);
    check($outcomes === ['0', '1'], 'Concurrent reset consumes token exactly once');
    $app->db()->exec('DELETE FROM rate_limits');
    $auth->requestReset('admin@example.test', 'reset-race');
    $auth->requestReset('admin@example.test', 'reset-race');
    $raceMail = concurrent('mail', $configPath);
    $sent = array_sum(array_map(static fn(string $value): int => json_decode($value, true, 8, JSON_THROW_ON_ERROR)['sent'], $raceMail));
    check($sent === 2 && (int) $app->db()->query("SELECT MAX(attempt_count) FROM mail_jobs WHERE status = 'sent'")->fetchColumn() === 1,
        'Concurrent mail workers claim jobs once');
    check((int) $app->db()->query("SELECT COUNT(*) FROM mail_jobs WHERE status = 'cancelled'")->fetchColumn() >= 2,
        'Successful reset invalidates all pending reset emails');
    $app->db()->exec('DELETE FROM rate_limits');
    $auth->requestReset('admin@example.test', 'smtp-failure');
    $retryJobId = (int) $app->db()->query('SELECT MAX(id) FROM mail_jobs')->fetchColumn();
    $smtpConfig = $config;
    $smtpConfig['mail'] = [
        'transport' => 'smtp', 'from_address' => 'sender@example.test', 'from_name' => 'Test',
        'host' => '127.0.0.1', 'port' => (int) substr($address, strrpos($address, ':') + 1),
        'encryption' => 'starttls', 'username' => 'test', 'password' => 'test-only',
        'timeout_seconds' => 1,
    ];
    $smtpApp = new App($smtpConfig);
    check(Mailer::configured($smtpApp), 'Validate supported SMTP transport configuration');
    $retry = (new MailWorker($smtpApp))->run();
    check($retry['retried'] === 1 && $retry['sent'] === 0, 'SMTP connection failure is retried, never reported sent');
    $app->db()->prepare('UPDATE mail_jobs SET attempt_count = 4, available_at = ? WHERE id = ?')
        ->execute([time() - 1, $retryJobId]);
    $failure = (new MailWorker($smtpApp))->run();
    $failedJob = $app->db()->query('SELECT status, encrypted_payload, last_error FROM mail_jobs WHERE id = ' . $retryJobId)->fetch();
    check($failure['failed'] === 1 && $failedJob['status'] === 'failed' && $failedJob['encrypted_payload'] === null,
        'Bound failed delivery attempts and discard terminal reset payload');
    check(!str_contains(file_get_contents($directory . '/logs/app.log'), 'test-only'), 'Do not log SMTP credentials');
    $app->db()->exec('DELETE FROM rate_limits');
    $auth->requestReset('admin@example.test', 'crashed-worker');
    $crashedJobId = (int) $app->db()->query('SELECT MAX(id) FROM mail_jobs')->fetchColumn();
    $app->db()->prepare("UPDATE mail_jobs SET status = 'processing', attempt_count = 5, lock_until = ? WHERE id = ?")
        ->execute([time() - 1, $crashedJobId]);
    $recovered = (new MailWorker($app))->run();
    check($app->db()->query('SELECT status FROM mail_jobs WHERE id = ' . $crashedJobId)->fetchColumn() === 'failed',
        'Crash recovery cannot retry beyond attempt limit');
    check($recovered['failed'] === 1, 'Crash recovery surfaces exhausted delivery jobs to operator');
    $noMailConfig = $config;
    $noMailConfig['mail']['transport'] = 'smtp';
    check((new Auth(new App($noMailConfig)))->requestReset('admin@example.test', 'mail-off') === 'unavailable',
        'Unconfigured SMTP reports unavailable instead of success');
    $productionFileConfig = $config;
    $productionFileConfig['environment'] = 'production';
    $productionFileConfig['base_url'] = 'https://example.test';
    check(!Mailer::configured(new App($productionFileConfig)), 'Forbid development mail sink in production');
    $auth->clearSession();
    session_write_close();

    $environment = getenv();
    $environment['APP_CONFIG'] = $configPath;
    $server = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable=0', '-S', $address, '-t', $root . '/public'],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
        $pipes, $root, $environment,
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Cannot start HTTP test server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'Local HTTP test server responds');
    $jar = $directory . '/cookies.txt';
    $loginUrl = $baseUrl . '/index.php?page=login';
    $forgotUrl = $baseUrl . '/index.php?page=forgot';
    $resetUrl = $baseUrl . '/index.php?page=reset';
    $adminUrl = $baseUrl . '/index.php?page=admin';
    check(request($adminUrl, $directory . '/guest-cookies.txt')['status'] === 303, 'Admin menu requires authenticated session');
    $response = request($loginUrl, $jar);
    check($response['status'] === 200 && str_contains($response['body'], 'Selamat datang kembali'), 'Render login page');
    check(str_contains($response['body'], 'href="/assets/auth.css"')
        && str_contains($response['body'], 'src="/assets/auth.js"')
        && str_contains($response['body'], 'action="/index.php?page=login"')
        && !str_contains($response['body'], $baseUrl),
        'Render same-origin assets, navigation and form actions');
    $alternateUrlConfig = $config;
    $alternateUrlConfig['base_url'] = 'http://sosmed-automation.test';
    file_put_contents($configPath, '<?php return ' . var_export($alternateUrlConfig, true) . ';');
    $alternateResponse = request($loginUrl, $jar);
    check($alternateResponse['status'] === 200
        && str_contains($alternateResponse['body'], 'href="/assets/auth.css"')
        && !str_contains($alternateResponse['body'], 'sosmed-automation.test'),
        'Alternate development origin does not leak canonical host into page URLs');
    $alternateCsrf = csrfFrom(request($forgotUrl, $jar)['body']);
    $alternatePost = request($forgotUrl, $jar, ['csrf' => $alternateCsrf, 'email' => 'alternate@example.test']);
    check($alternatePost['status'] === 303
        && str_contains($alternatePost['headers'], 'Location: /index.php?page=forgot'),
        'Redirect stays on current origin when canonical host differs');
    file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');
    check(str_contains($response['headers'], 'Content-Security-Policy:')
        && str_contains($response['headers'], 'Cache-Control: no-store')
        && str_contains($response['headers'], 'HttpOnly') && str_contains($response['headers'], 'SameSite=Lax'),
        'Serve CSP, no-store and protected session cookie');
    check(request($loginUrl, $jar, ['email' => 'admin@example.test', 'password' => 'concurrent-passphrase-2026'])['status'] === 419,
        'Reject login without CSRF');
    $csrf = csrfFrom(request($loginUrl, $jar)['body']);
    check(request($loginUrl, $jar, ['csrf' => $csrf, 'email' => 'admin@example.test', 'password' => 'wrong'])['status'] === 422,
        'Show server-side credential error');
    check(request($loginUrl, $jar, ['csrf' => $csrf, 'email' => 'admin@example.test', 'password' => 'concurrent-passphrase-2026'])['status'] === 303,
        'Successful login redirects');
    check(request($loginUrl, $jar)['status'] === 303, 'Authenticated login redirects to admin shell');
    $signedIn = request($adminUrl, $jar);
    check($signedIn['status'] === 200 && str_contains($signedIn['body'], 'Menu admin')
        && str_contains($signedIn['body'], 'ADMIN LINTAS PERUSAHAAN') && !str_contains($signedIn['body'], 'name="password"'),
        'Show dashboard with protected multi-company admin navigation');
    check(str_contains($signedIn['body'], 'data-metric="drafts">18</strong>')
        && str_contains($signedIn['body'], '&lt;script&gt;test&lt;/script&gt;')
        && !str_contains($signedIn['body'], '<script>test</script>'), 'Render actual aggregate data and escape content titles');
    check(preg_match('/assets\/admin\.js\?v=[0-9]+/', $signedIn['body']) === 1
        && preg_match('/assets\/admin\.css\?v=[0-9]+/', $signedIn['body']) === 1,
        'Admin assets have versioned URLs so updated calendar interactions bypass stale caches');
    foreach ($companyIds as $index => $companyId) {
        $scopedPage = request($adminUrl . '&company=' . $companyId, $jar);
        check($scopedPage['status'] === 200
            && str_contains($scopedPage['body'], 'data-metric="drafts">' . (3 * ($index + 1)) . '</strong>'),
            'HTTP dashboard company filter ' . $companyId);
    }
    check(request($adminUrl . '&company[]=1', $jar)['status'] === 400
        && request($adminUrl . '&company=invalid', $jar)['status'] === 400
        && request($adminUrl . '&company=999999', $jar)['status'] === 400,
        'Reject malformed and unknown company filters');
    $sections = array_merge(...array_values(adminNavigation()));
    $calendarUrl = $adminUrl . '&section=calendar';
    $calendarPage = request($calendarUrl . '&month=2026-10', $jar);
    check($calendarPage['status'] === 200 && str_contains($calendarPage['body'], 'Oktober 2026')
        && str_contains($calendarPage['body'], '2 konten bertanggal')
        && str_contains($calendarPage['body'], '<details class="calendar-event')
        && str_contains($calendarPage['body'], '&lt;script&gt;calendar brief&lt;/script&gt;')
        && !str_contains($calendarPage['body'], '<script>calendar brief</script>'),
        'Render SQLite calendar, expandable details and escaped brief');
    $filteredCalendar = request($calendarUrl . '&month=2026-10&status=scheduled&company=' . $companyIds[0], $jar);
    check($filteredCalendar['status'] === 200 && str_contains($filteredCalendar['body'], '1 konten bertanggal')
        && str_contains($filteredCalendar['body'], 'status=scheduled&amp;campaign=all&amp;month=2026-09')
        && str_contains($filteredCalendar['body'], 'value="2026-10"'),
        'Calendar navigation preserves filters and month input');
    check(str_contains(request($calendarUrl . '&month=2028-02', $jar)['body'], 'Belum ada konten bertanggal'),
        'Calendar shows honest empty state');
    foreach (['month=2026-13', 'month[]=2026-10', 'month=invalid', 'status=unknown', 'status[]=scheduled',
        'company[]=1', 'company=999999', 'company='] as $invalidCalendarFilter) {
        check(request($calendarUrl . '&' . $invalidCalendarFilter, $jar)['status'] === 400,
            'Reject calendar filter: ' . $invalidCalendarFilter);
    }
    check(request($calendarUrl, $directory . '/guest-calendar-cookies.txt')['status'] === 303
        && request($calendarUrl, $jar, [])['status'] === 405, 'Calendar requires authentication and rejects mutations');
    check(str_contains($calendarPage['body'], '+ Tambah konten')
        && str_contains($calendarPage['body'], 'Hari Batik Nasional')
        && str_contains($calendarPage['body'], 'Hari Sumpah Pemuda')
        && str_contains($calendarPage['body'], 'Lihat sumber tanggal'),
        'Calendar exposes content creation and sourced national observances');
    $contentInput = [
        'company_id' => (string) $companyIds[0], 'title' => '<script>Manual title</script>',
        'brief' => 'Manual brief', 'status' => 'idea', 'format' => 'image',
        'planned_at' => '2026-10-12T10:30', 'creation_key' => bin2hex(random_bytes(32)),
    ];
    $editor = new ContentEditor($app);
    $manualId = $editor->create($contentInput, $userId);
    $manualQuery = $app->db()->prepare('SELECT * FROM content_items WHERE id = ?');
    $manualQuery->execute([$manualId]);
    $manual = $manualQuery->fetch();
    $manualQuery->closeCursor();
    check($manual['status'] === 'idea' && $manual['scheduled_at'] === null
        && (int) $manual['planned_at'] === (new DateTimeImmutable('2026-10-12T03:30:00Z'))->getTimestamp()
        && (int) $manual['owner_id'] === $userId
        && $editor->create($contentInput, $userId) === $manualId,
        'Manual creation stores local planning time in UTC and replay does not duplicate content');
    $noDateInput = $contentInput;
    $noDateInput['creation_key'] = bin2hex(random_bytes(32));
    $noDateInput['planned_at'] = '';
    $noDateInput['status'] = 'brief';
    $noDateId = $editor->create($noDateInput, $userId);
    $manualQuery->execute([$noDateId]);
    check($manualQuery->fetch()['planned_at'] === null, 'Content can be saved without inventing a planned date');
    $manualQuery->closeCursor();
    foreach ([
        ['title' => ''], ['title' => str_repeat('x', 201)], ['brief' => str_repeat('x', 5001)],
        ['company_id' => '999999'], ['status' => 'published'], ['format' => 'unknown'],
        ['planned_at' => '2026-02-30T09:00'], ['planned_at' => '2026-10-12T25:00'],
        ['planned_at' => '2100-01-01T09:00'], ['creation_key' => 'bad'],
    ] as $invalidInput) {
        try {
            $editor->create(array_replace($contentInput, $invalidInput), $userId);
            check(false, 'Reject invalid content field ' . array_key_first($invalidInput));
        } catch (InvalidArgumentException) {
            check(true, 'Reject invalid content field ' . array_key_first($invalidInput));
        }
    }
    $app->db()->prepare("UPDATE companies SET timezone = 'America/New_York' WHERE id = ?")->execute([$companyIds[0]]);
    try {
        $editor->create(array_replace($contentInput, ['planned_at' => '2026-03-08T02:30']), $userId);
        check(false, 'Reject non-existent DST local time');
    } catch (InvalidArgumentException) {
        check(true, 'Reject non-existent DST local time');
    }
    $app->db()->prepare("UPDATE companies SET timezone = 'Asia/Jakarta' WHERE id = ?")->execute([$companyIds[0]]);
    $creationPage = request($calendarUrl . '&month=2026-10', $jar);
    if (!preg_match('/name="creation_key" value="([a-f0-9]{64})"/', $creationPage['body'], $keyMatch)) {
        throw new RuntimeException('Content creation key missing.');
    }
    $httpContent = array_replace($contentInput, [
        'action' => 'create_content', 'creation_key' => $keyMatch[1], 'title' => 'HTTP content',
    ]);
    $beforeContent = (int) $app->db()->query('SELECT COUNT(*) FROM content_items')->fetchColumn();
    check(request($calendarUrl, $jar, $httpContent)['status'] === 419
        && (int) $app->db()->query('SELECT COUNT(*) FROM content_items')->fetchColumn() === $beforeContent,
        'CSRF protects content creation from writes');
    $httpContent['csrf'] = csrfFrom($creationPage['body']);
    $invalidContent = request($calendarUrl, $jar, array_replace($httpContent, ['title' => '', 'brief' => '<script>invalid brief</script>']));
    check($invalidContent['status'] === 422 && str_contains($invalidContent['body'], 'Judul wajib diisi')
        && str_contains($invalidContent['body'], '&lt;script&gt;invalid brief&lt;/script&gt;'),
        'Creation errors are shown explicitly with escaped retained fields');
    check(request($calendarUrl, $jar, $httpContent)['status'] === 303
        && (int) $app->db()->query('SELECT COUNT(*) FROM content_items')->fetchColumn() === $beforeContent + 1,
        'Valid content submission persists and redirects');
    check(request($calendarUrl, $jar, $httpContent)['status'] === 422
        && (int) $app->db()->query('SELECT COUNT(*) FROM content_items')->fetchColumn() === $beforeContent + 1,
        'Replayed creation form cannot duplicate content');
    check(str_contains(request($calendarUrl . '&month=2026-10', $jar)['body'], 'HTTP content'),
        'Newly saved content appears in the SQLite calendar');
    $automation = new ObservanceAutomation($app);
    check(!$automation->generate(2026)['enabled'], 'Automatic ideas are explicit opt-in and disabled before activation');
    $enableData = ['action' => 'automation_enable', 'csrf' => csrfFrom(request($calendarUrl, $jar)['body'])];
    check(request($calendarUrl . '&month=2026-10', $jar, ['action' => 'automation_enable'])['status'] === 419,
        'CSRF protects observance automation settings');
    check(request($calendarUrl . '&month=2026-10', $jar, $enableData)['status'] === 303,
        'Admin can activate yearly ideas from the calendar');
    $catalogSize = (int) $app->db()->query('SELECT COUNT(*) FROM observances')->fetchColumn();
    check((int) $app->db()->query('SELECT COUNT(*) FROM content_items WHERE observance_year = 2026')->fetchColumn() === $catalogSize * 3
        && (int) $app->db()->query("SELECT COUNT(*) FROM content_items WHERE observance_id IS NOT NULL AND (status != 'idea' OR scheduled_at IS NOT NULL)")->fetchColumn() === 0,
        'Generate one real idea per observance and company without approval or publication');
    $app->db()->exec("UPDATE content_items SET title = 'Human edited idea', status = 'brief'
        WHERE id = (SELECT MIN(id) FROM content_items WHERE observance_id IS NOT NULL)");
    check($automation->generate(2026)['created'] === 0
        && (int) $app->db()->query("SELECT COUNT(*) FROM content_items WHERE title = 'Human edited idea' AND status = 'brief'")->fetchColumn() === 1,
        'Repeated generation never duplicates or overwrites human changes');
    $parallelIdeas = array_map(fn(string $result): array => json_decode($result, true, 8, JSON_THROW_ON_ERROR),
        concurrent('calendar', $configPath));
    check(array_sum(array_column($parallelIdeas, 'created')) === $catalogSize * 3
        && (int) $app->db()->query('SELECT COUNT(*) FROM content_items WHERE observance_year = 2027')->fetchColumn() === $catalogSize * 3,
        'Concurrent yearly generators create ideas exactly once');
    $app->db()->prepare("UPDATE companies SET status = 'inactive' WHERE id = ?")->execute([$companyIds[2]]);
    check($automation->generate(2028)['created'] === $catalogSize * 2, 'Inactive companies do not receive automatic ideas');
    try {
        $editor->create(array_replace($contentInput, ['company_id' => (string) $companyIds[2]]), $userId);
        check(false, 'Reject content creation for inactive companies');
    } catch (InvalidArgumentException) {
        check(true, 'Reject content creation for inactive companies');
    }
    $app->db()->prepare("UPDATE companies SET status = 'active' WHERE id = ?")->execute([$companyIds[2]]);
    check($automation->generate(2028)['created'] === $catalogSize, 'Newly active companies receive only their missing ideas');
    $app->db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
    try {
        $automation->generate(2029);
        check(false, 'Inactive automation owner is an explicit error');
    } catch (InvalidArgumentException) {
        check(true, 'Inactive automation owner is an explicit error');
    }
    try {
        $editor->create($contentInput, $userId);
        check(false, 'Reject content creation by inactive owners');
    } catch (InvalidArgumentException) {
        check(true, 'Reject content creation by inactive owners');
    }
    $app->db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$userId]);
    $internationalCalendar = request($calendarUrl . '&month=2026-06&status=published', $jar);
    check(str_contains($internationalCalendar['body'], 'Hari Lingkungan Hidup Sedunia')
        && !str_contains($internationalCalendar['body'], 'Ide: Hari Lingkungan Hidup Sedunia'),
        'International observances remain visible independently of the content status filter');
    $beforeGet = (int) $app->db()->query('SELECT COUNT(*) FROM content_items')->fetchColumn();
    request($calendarUrl . '&month=2030-10', $jar);
    check((int) $app->db()->query('SELECT COUNT(*) FROM content_items')->fetchColumn() === $beforeGet,
        'Viewing a future calendar never writes or triggers generation through GET');
    $datedPage = request($calendarUrl . '&month=2026-10&company=' . $companyIds[0] . '&date=2026-10-20', $jar);
    check($datedPage['status'] === 200 && str_contains($datedPage['body'], 'id="content-create" open')
        && str_contains($datedPage['body'], 'value="2026-10-20T09:00"')
        && str_contains($datedPage['body'], 'data-add-date="2026-10-20"')
        && str_contains($datedPage['body'], '&amp;date=2026-10-20#content-create'),
        'Date links open a prefilled form without JavaScript and preserve company filters');
    foreach (['date=2026-02-30', 'date=2026-11-01', 'date[]=2026-10-20', 'date=invalid'] as $invalidDate) {
        check(request($calendarUrl . '&month=2026-10&' . $invalidDate, $jar)['status'] === 400,
            'Reject invalid calendar add date: ' . $invalidDate);
    }
    $httpId = (int) $app->db()->query("SELECT id FROM content_items WHERE title = 'HTTP content'")->fetchColumn();
    $deleteData = ['action' => 'delete_content', 'content_id' => (string) $httpId, 'confirm_delete' => '1'];
    check(request($calendarUrl, $jar, $deleteData)['status'] === 419,
        'Deleting calendar content requires CSRF');
    $deleteData['csrf'] = csrfFrom($datedPage['body']);
    check(request($calendarUrl, $jar, array_replace($deleteData, ['confirm_delete' => '']))['status'] === 422
        && request($calendarUrl, $jar, array_replace($deleteData, ['content_id' => 'bad']))['status'] === 422,
        'Deletion requires explicit confirmation and a valid content ID');
    $beforeDeleteCalendar = $calendarService->snapshot($companyIds[0], '2026-10', 'all')['total'];
    $beforeDeleteDashboard = $dashboardService->snapshot($companyIds[0])['total_content'];
    $deleteResponse = request($calendarUrl . '&month=2026-10&status=idea&company=' . $companyIds[0], $jar, $deleteData);
    check($deleteResponse['status'] === 303
        && str_contains($deleteResponse['headers'], 'month=2026-10&status=idea')
        && $calendarService->snapshot($companyIds[0], '2026-10', 'all')['total'] === $beforeDeleteCalendar - 1
        && $dashboardService->snapshot($companyIds[0])['total_content'] === $beforeDeleteDashboard - 1
        && !str_contains(request($calendarUrl . '&month=2026-10', $jar)['body'], 'HTTP content'),
        'Deletion removes content from calendar and dashboard and preserves redirect filters');
    check(request($calendarUrl, $jar, $deleteData)['status'] === 422,
        'Already deleted content is reported explicitly');
    $deletedRecord = $app->db()->prepare('SELECT deleted_at, deleted_by FROM content_items WHERE id = ?');
    $deletedRecord->execute([$httpId]);
    $deletedRow = $deletedRecord->fetch();
    $deletedRecord->closeCursor();
    check($deletedRow['deleted_at'] !== null && (int) $deletedRow['deleted_by'] === $userId
        && !in_array('HTTP content', array_column($dashboardService->snapshot(null)['recent_content'], 'title'), true),
        'Deletion persists actor/time and removes content from aggregate recent items');
    $beforeUndatedDelete = $calendarService->snapshot(null, '2026-10', 'all')['unscheduled'];
    $editor->delete($noDateId, $userId);
    check($calendarService->snapshot(null, '2026-10', 'all')['unscheduled'] === $beforeUndatedDelete - 1,
        'Deleting a brief also removes it from undated counts');
    try {
        $editor->create($noDateInput, $userId);
        check(false, 'Replayed creation key cannot restore deleted content');
    } catch (InvalidArgumentException) {
        check(true, 'Replayed creation key cannot restore deleted content');
    }
    foreach (array_diff(array_keys(Dashboard::STATUSES), ['idea', 'brief']) as $protectedStatus) {
        $protectedQuery = $app->db()->prepare('SELECT id FROM content_items WHERE status = ? AND deleted_at IS NULL LIMIT 1');
        $protectedQuery->execute([$protectedStatus]);
        $protectedId = (int) $protectedQuery->fetchColumn();
        $protectedQuery->closeCursor();
        check(request($calendarUrl, $jar, array_replace($deleteData, ['content_id' => (string) $protectedId]))['status'] === 422,
            'Server prevents deletion of status ' . $protectedStatus);
    }
    $publishedCalendar = request($calendarUrl . '&month=2026-10&status=published', $jar);
    check(!str_contains($publishedCalendar['body'], 'name="action" value="delete_content"'),
        'Delete controls are not rendered for published content');
    $app->db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
    try {
        $editor->delete($manualId, $userId);
        check(false, 'Inactive administrators cannot delete content');
    } catch (InvalidArgumentException) {
        check(true, 'Inactive administrators cannot delete content');
    }
    $app->db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$userId]);
    $autoId = (int) $app->db()->query("SELECT MIN(id) FROM content_items WHERE observance_year = 2027")->fetchColumn();
    $editor->delete($autoId, $userId);
    $afterAutoDelete = (int) $app->db()->query('SELECT COUNT(*) FROM content_items WHERE observance_year = 2027 AND deleted_at IS NULL')->fetchColumn();
    $parallelDeletedIdeas = array_map(fn(string $result): array => json_decode($result, true, 8, JSON_THROW_ON_ERROR),
        concurrent('calendar', $configPath));
    check(array_sum(array_column($parallelDeletedIdeas, 'created')) === 0
        && (int) $app->db()->query('SELECT COUNT(*) FROM content_items WHERE observance_year = 2027 AND deleted_at IS NULL')->fetchColumn() === $afterAutoDelete,
        'Parallel generators cannot recreate a deleted automatic idea');
    check((int) $app->db()->query("SELECT COUNT(*) FROM audit_events WHERE action = 'content.deleted'")->fetchColumn() === 3,
        'Successful deletions are audited and rejected deletions are not success events');
    check(request($calendarUrl, $jar, ['action' => 'automation_disable', 'csrf' => $enableData['csrf']])['status'] === 303
        && !$automation->generate(2029)['enabled']
        && (int) $app->db()->query('SELECT COUNT(*) FROM content_items WHERE observance_year = 2026')->fetchColumn() === $catalogSize * 3,
        'Pausing automation stops generation but preserves existing ideas');
    $app->migrate();
    check((int) $app->db()->query('SELECT COUNT(*) FROM observances')->fetchColumn() === $catalogSize,
        'Calendar migration is repeatable and does not duplicate the catalog');
    $campaignService = new Campaigns($app);
    $campaignUrl = $adminUrl . '&section=campaigns';
    check($campaignService->snapshot(null, 'all')['items'] === []
        && str_contains(request($campaignUrl, $jar)['body'], 'Belum ada kampanye'),
        'Campaign page starts with an honest SQLite empty state');
    $campaignInput = [
        'company_id' => (string) $companyIds[0], 'name' => '<script>Campaign</script>',
        'objective' => '<script>Objective</script>', 'theme' => 'Digital',
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft',
        'creation_key' => bin2hex(random_bytes(32)),
    ];
    $campaignId = $campaignService->save($campaignInput, $userId);
    check($campaignService->save($campaignInput, $userId) === $campaignId
        && count($campaignService->snapshot(null, 'all')['items']) === 1,
        'Campaign creation is persistent and replay-safe');
    $app->db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
    try {
        $campaignService->save($campaignInput, $userId);
        check(false, 'Inactive administrators cannot manage campaigns');
    } catch (InvalidArgumentException) {
        check(true, 'Inactive administrators cannot manage campaigns');
    }
    $app->db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$userId]);
    $app->db()->prepare("UPDATE companies SET status = 'inactive' WHERE id = ?")->execute([$companyIds[0]]);
    try {
        $campaignService->save($campaignInput, $userId);
        check(false, 'Inactive companies cannot receive new campaigns');
    } catch (InvalidArgumentException) {
        check(true, 'Inactive companies cannot receive new campaigns');
    }
    $app->db()->prepare("UPDATE companies SET status = 'active' WHERE id = ?")->execute([$companyIds[0]]);
    foreach ([
        ['name' => ''], ['name' => str_repeat('x', 201)], ['objective' => str_repeat('x', 5001)],
        ['theme' => str_repeat('x', 501)], ['start_date' => '2026-02-30'],
        ['end_date' => '2026-09-30'], ['company_id' => '999999'], ['status' => 'archived'],
        ['status' => 'invalid'], ['creation_key' => 'invalid'],
    ] as $invalidCampaign) {
        try {
            $campaignService->save(array_replace($campaignInput, $invalidCampaign), $userId);
            check(false, 'Reject invalid campaign field ' . array_key_first($invalidCampaign));
        } catch (InvalidArgumentException) {
            check(true, 'Reject invalid campaign field ' . array_key_first($invalidCampaign));
        }
    }
    $campaignService->save(array_replace($campaignInput, ['status' => 'active']), $userId, $campaignId, 1);
    check($campaignService->snapshot($companyIds[0], 'active')['items'][0]['version'] === 2
        && $campaignService->snapshot($companyIds[1], 'all')['items'] === [],
        'Campaign edit increments version and filters isolate company/status');
    foreach ([
        [$campaignInput, 1],
        [array_replace($campaignInput, ['company_id' => (string) $companyIds[1]]), 2],
    ] as [$editInput, $editVersion]) {
        try {
            $campaignService->save($editInput, $userId, $campaignId, $editVersion);
            check(false, 'Reject stale campaign edit or company reassignment');
        } catch (InvalidArgumentException) {
            check(true, 'Reject stale campaign edit or company reassignment');
        }
    }
    $campaignService->assign($campaignId, $manualId, $userId);
    $linkedInput = array_replace($contentInput, [
        'title' => 'Campaign linked content', 'creation_key' => bin2hex(random_bytes(32)),
        'campaign_id' => (string) $campaignId,
    ]);
    $linkedId = $editor->create($linkedInput, $userId);
    check($campaignService->snapshot(null, 'all', $campaignId)['items'][0]['content_count'] === 2
        && $calendarService->snapshot($companyIds[0], '2026-10', 'all', $campaignId)['total'] === 2,
        'Existing and new calendar content link to campaigns with correct aggregates');
    foreach ([
        array_replace($linkedInput, ['company_id' => (string) $companyIds[1]]),
        array_replace($linkedInput, ['campaign_id' => '999999']),
    ] as $invalidLink) {
        try {
            $editor->create($invalidLink, $userId);
            check(false, 'Reject new content campaign mismatch');
        } catch (InvalidArgumentException) {
            check(true, 'Reject new content campaign mismatch');
        }
    }
    $foreignContent = $app->db()->prepare("SELECT id FROM content_items WHERE company_id = ? AND status = 'idea' AND deleted_at IS NULL LIMIT 1");
    $foreignContent->execute([$companyIds[1]]);
    $foreignId = (int) $foreignContent->fetchColumn();
    $foreignContent->closeCursor();
    try {
        $campaignService->assign($campaignId, $foreignId, $userId);
        check(false, 'Reject existing cross-company content assignment');
    } catch (InvalidArgumentException) {
        check(true, 'Reject existing cross-company content assignment');
    }
    try {
        $app->db()->prepare('UPDATE content_items SET campaign_id = ? WHERE id = ?')->execute([$campaignId, $foreignId]);
        check(false, 'Database enforces campaign and content company consistency');
    } catch (PDOException) {
        check(true, 'Database enforces campaign and content company consistency');
    }
    $campaignDetailPage = request($campaignUrl . '&id=' . $campaignId, $jar);
    check($campaignDetailPage['status'] === 200
        && str_contains($campaignDetailPage['body'], '&lt;script&gt;Objective&lt;/script&gt;')
        && str_contains($campaignDetailPage['body'], 'Campaign linked content')
        && str_contains($campaignDetailPage['body'], '+ Tambah konten kampanye')
        && !str_contains($campaignDetailPage['body'], '<script>Campaign</script>'),
        'Campaign detail renders escaped fields, linked content and calendar actions');
    $campaignCalendarPage = request($calendarUrl . '&month=2026-10&campaign=' . $campaignId, $jar);
    check($campaignCalendarPage['status'] === 200
        && str_contains($campaignCalendarPage['body'], '2 konten bertanggal')
        && str_contains($campaignCalendarPage['body'], '&amp;campaign=' . $campaignId . '&amp;month=2026-09')
        && str_contains($campaignCalendarPage['body'], 'id="content-campaign"'),
        'Calendar campaign filter applies to all content and preserves month navigation');
    foreach (['status=invalid', 'status[]=draft', 'company=invalid', 'company=999999', 'id=invalid', 'id=999999', 'edit=1'] as $badCampaignFilter) {
        check(request($campaignUrl . '&' . $badCampaignFilter, $jar)['status'] === 400,
            'Reject campaign filter ' . $badCampaignFilter);
    }
    check(request($calendarUrl . '&campaign[]=1', $jar)['status'] === 400
        && request($calendarUrl . '&campaign=999999', $jar)['status'] === 400
        && request($calendarUrl . '&company=' . $companyIds[1] . '&campaign=' . $campaignId, $jar)['status'] === 400,
        'Reject malformed or cross-company calendar campaign filters');
    $campaignCreationPage = request($campaignUrl, $jar);
    if (!preg_match('/name="creation_key" value="([a-f0-9]{64})"/', $campaignCreationPage['body'], $campaignKey)) {
        throw new RuntimeException('Campaign form key missing.');
    }
    $campaignPost = array_replace($campaignInput, [
        'action' => 'create_campaign', 'name' => 'HTTP campaign', 'creation_key' => $campaignKey[1],
    ]);
    check(request($campaignUrl, $jar, $campaignPost)['status'] === 419,
        'Campaign creation requires CSRF');
    $campaignPost['csrf'] = csrfFrom($campaignCreationPage['body']);
    $invalidCampaignPage = request($campaignUrl, $jar, array_replace($campaignPost, ['name' => '', 'objective' => '<script>retained</script>']));
    check($invalidCampaignPage['status'] === 422 && str_contains($invalidCampaignPage['body'], '&lt;script&gt;retained&lt;/script&gt;'),
        'Campaign validation displays errors with escaped retained input');
    check(request($campaignUrl, $jar, $campaignPost)['status'] === 303
        && request($campaignUrl, $jar, $campaignPost)['status'] === 422,
        'HTTP campaign creation redirects and rejects duplicate form replay');
    $httpCampaignId = (int) $app->db()->query("SELECT id FROM campaigns WHERE name = 'HTTP campaign'")->fetchColumn();
    $assignInput = array_replace($contentInput, ['creation_key' => bin2hex(random_bytes(32)), 'title' => 'Existing content to link']);
    $assignId = $editor->create($assignInput, $userId);
    $assignPost = ['action' => 'assign_content', 'campaign_id' => (string) $httpCampaignId, 'content_id' => (string) $assignId];
    check(request($campaignUrl . '&id=' . $httpCampaignId, $jar, $assignPost)['status'] === 419,
        'Linking existing campaign content requires CSRF');
    $assignPost['csrf'] = $campaignPost['csrf'];
    check(request($campaignUrl . '&id=' . $httpCampaignId, $jar, $assignPost)['status'] === 303
        && request($campaignUrl . '&id=' . $httpCampaignId, $jar, $assignPost)['status'] === 422,
        'Existing content linking persists once and cannot silently replace another association');
    $publishedId = (int) $app->db()->query("SELECT MIN(id) FROM content_items WHERE status = 'published'")->fetchColumn();
    check(request($campaignUrl . '&id=' . $httpCampaignId, $jar, array_replace($assignPost, ['content_id' => (string) $publishedId]))['status'] === 422,
        'Campaign assignment cannot mutate already published content');
    $editPage = request($campaignUrl . '&id=' . $campaignId . '&edit=1', $jar);
    check($editPage['status'] === 200 && str_contains($editPage['body'], 'value="update_campaign"')
        && str_contains($editPage['body'], 'value="2"'),
        'Campaign edit opens a versioned, prefilled form');
    $campaignUpdate = array_replace($campaignInput, [
        'action' => 'update_campaign', 'campaign_id' => (string) $campaignId, 'version' => '2',
        'status' => 'completed', 'csrf' => csrfFrom($editPage['body']),
    ]);
    check(request($campaignUrl . '&id=' . $campaignId, $jar, $campaignUpdate)['status'] === 303
        && request($campaignUrl . '&id=' . $campaignId, $jar, $campaignUpdate)['status'] === 422,
        'HTTP campaign edit persists and stale version is rejected');
    $archivePost = ['action' => 'archive_campaign', 'campaign_id' => (string) $campaignId, 'version' => '3',
        'csrf' => $campaignUpdate['csrf']];
    check(request($campaignUrl . '&id=' . $campaignId, $jar, $archivePost)['status'] === 422,
        'Campaign archiving requires explicit confirmation');
    $archivePost['confirm_archive'] = '1';
    check(request($campaignUrl . '&id=' . $campaignId, $jar, $archivePost)['status'] === 303
        && count($campaignService->snapshot(null, 'all', $campaignId)['content']) === 2,
        'Archiving keeps linked content and history');
    check(request($campaignUrl . '&id=' . $campaignId, $jar, $campaignUpdate)['status'] === 422,
        'Server rejects forged edits of an archived campaign');
    check($calendarService->snapshot($companyIds[0], '2026-10', 'all', $campaignId)['total'] === 2,
        'Archived campaign content remains accessible in the calendar');
    try {
        $editor->create(array_replace($linkedInput, ['creation_key' => bin2hex(random_bytes(32))]), $userId);
        check(false, 'Archived campaigns cannot receive new content');
    } catch (InvalidArgumentException) {
        check(true, 'Archived campaigns cannot receive new content');
    }
    try {
        $campaignService->assign($campaignId, $foreignId, $userId);
        check(false, 'Archived campaigns cannot link existing content');
    } catch (InvalidArgumentException) {
        check(true, 'Archived campaigns cannot link existing content');
    }
    check(request($campaignUrl . '&id=' . $campaignId . '&edit=1', $jar)['status'] === 400
        && request($campaignUrl, $directory . '/campaign-guest.txt')['status'] === 303,
        'Archived campaigns cannot be edited and guests cannot access campaign pages');
    $editor->delete($linkedId, $userId);
    check(count($campaignService->snapshot(null, 'all', $campaignId)['content']) === 1,
        'Campaign content counts exclude deleted content');
    $app->migrate();
    check(count($campaignService->snapshot(null, 'all')['items']) === 2,
        'Campaign migration preserves data and does not insert demo campaigns');
    $library = new ContentLibrary($app);
    $libraryUrl = $adminUrl . '&section=content';
    $libraryPage = request($libraryUrl, $jar);
    check($libraryPage['status'] === 200 && str_contains($libraryPage['body'], 'Cari judul / brief')
        && str_contains($libraryPage['body'], 'id="content-create"'), 'Content library renders filters and shared creation form');
    check(request($libraryUrl, $directory . '/library-guest.txt')['status'] === 303, 'Guests cannot access content library');
    foreach (['company[]=1', 'company=999999', 'campaign[]=1', 'campaign=999999',
        'status[]=idea', 'status=unknown', 'q[]=test', 'q=' . str_repeat('x', 201),
        'p=0', 'p=999999', 'id[]=1', 'id=999999', 'edit=1'] as $badFilter) {
        check(request($libraryUrl . '&' . $badFilter, $jar)['status'] === 400, 'Reject library filter ' . substr($badFilter, 0, 30));
    }
    check($library->snapshot(null, 'all', null, 'impossible-library-query')['total'] === 0
        && str_contains(request($libraryUrl . '&q=impossible-library-query', $jar)['body'], 'Belum ada konten sesuai filter'),
        'Content search has honest empty state');
    $libraryInput = array_replace($contentInput, [
        'title' => '<script>Library 100%_literal</script>', 'brief' => 'Searchable unique-library-brief',
        'planned_at' => '', 'creation_key' => bin2hex(random_bytes(32)),
    ]);
    $libraryId = $editor->create($libraryInput, $userId);
    check($library->snapshot(null, 'all', null, '100%_literal')['total'] === 1
        && $library->snapshot(null, 'all', null, 'unique-library-brief')['total'] === 1,
        'Library searches brief and treats LIKE wildcards literally');
    check($library->snapshot($companyIds[1], 'all', null, 'unique-library-brief')['total'] === 0
        && $library->snapshot(null, 'published', null, 'unique-library-brief')['total'] === 0
        && $library->snapshot($companyIds[0], 'idea', null, 'unique-library-brief')['total'] === 1,
        'Library filters combine company status and search');
    check($library->snapshot(null, 'all', $campaignId, '')['total'] === 1,
        'Library campaign filter excludes deleted linked content');
    $libraryDetail = request($libraryUrl . '&id=' . $libraryId . '&edit=1', $jar);
    check($libraryDetail['status'] === 200 && str_contains($libraryDetail['body'], 'value="update_content"')
        && str_contains($libraryDetail['body'], '&lt;script&gt;Library')
        && !str_contains($libraryDetail['body'], '<script>Library'),
        'Content detail and prefilled edit form escape stored data');
    $libraryUpdate = array_replace($libraryInput, [
        'action' => 'update_content', 'content_id' => (string) $libraryId, 'version' => '1',
        'status' => 'brief', 'planned_at' => '2026-10-28T09:30', 'csrf' => csrfFrom($libraryDetail['body']),
    ]);
    check(request($libraryUrl . '&id=' . $libraryId, $jar, array_replace($libraryUpdate, ['csrf' => 'bad']))['status'] === 419,
        'Library edit requires CSRF');
    check(request($libraryUrl . '&id=' . $libraryId, $jar, array_replace($libraryUpdate, ['title' => '']))['status'] === 422,
        'Library edit validates title without mutating content');
    check(request($libraryUrl . '&id=' . $libraryId, $jar, $libraryUpdate)['status'] === 303,
        'Library edit persists validated brief and local planning time');
    $savedLibrary = $library->snapshot(null, 'all', null, '', 1, $libraryId)['detail'];
    check((int) $savedLibrary['version'] === 2 && $savedLibrary['status'] === 'brief'
        && (int) $savedLibrary['owner_id'] === $userId && $savedLibrary['scheduled_at'] === null
        && (int) $savedLibrary['planned_at'] === (new DateTimeImmutable('2026-10-28T02:30:00Z'))->getTimestamp(),
        'Edit increments version preserves ownership and does not schedule publishing');
    $editedDay = $calendarService->snapshot($companyIds[0], '2026-10', 'all')['days']['2026-10-28'];
    $editedCalendarItems = array_values(array_filter($editedDay, fn (array $item): bool => (int) $item['id'] === $libraryId));
    check(count($editedCalendarItems) === 1 && $editedCalendarItems[0]['status'] === 'brief'
        && $editedCalendarItems[0]['brief'] === $savedLibrary['brief'],
        'Library edits immediately appear with exact date status and brief in calendar');
    $staleResponse = request($libraryUrl . '&id=' . $libraryId, $jar, $libraryUpdate);
    check($staleResponse['status'] === 422 && str_contains($staleResponse['body'], 'name="version" value="1"'),
        'Stale edit is rejected and submitted version is not silently refreshed');
    foreach ([
        ['version' => 'bad'], ['status' => 'published'], ['company_id' => (string) $companyIds[1]],
        ['campaign_id' => (string) $httpCampaignId], ['planned_at' => '2026-02-30T10:00'],
        ['brief' => str_repeat('x', 5001)], ['format' => 'unknown'],
    ] as $invalidEdit) {
        check(request($libraryUrl . '&id=' . $libraryId, $jar,
            array_replace($libraryUpdate, ['version' => '2'], $invalidEdit))['status'] === 422,
            'Reject invalid library edit ' . array_key_first($invalidEdit));
    }
    $app->db()->prepare("UPDATE companies SET status = 'inactive' WHERE id = ?")->execute([$companyIds[0]]);
    check(request($libraryUrl . '&id=' . $libraryId . '&edit=1', $jar)['status'] === 400
        && request($libraryUrl . '&id=' . $libraryId, $jar, array_replace($libraryUpdate, ['version' => '2']))['status'] === 422,
        'Inactive companies reject library edits');
    $app->db()->prepare("UPDATE companies SET status = 'active' WHERE id = ?")->execute([$companyIds[0]]);
    check(request($libraryUrl . '&id=' . $publishedId . '&edit=1', $jar)['status'] === 400
        && !str_contains(request($libraryUrl . '&id=' . $publishedId, $jar)['body'], 'value="delete_content"'),
        'Advanced content is read-only without edit or delete actions');
    $campaignService->assign($httpCampaignId, $libraryId, $userId);
    check((int) $library->snapshot(null, 'all', null, '', 1, $libraryId)['detail']['version'] === 3,
        'Campaign assignment invalidates stale content forms');
    $app->db()->prepare("UPDATE content_items SET status = 'pending_review' WHERE id = ?")->execute([$libraryId]);
    check(request($libraryUrl . '&id=' . $libraryId, $jar, array_replace($libraryUpdate, ['version' => '4',
        'campaign_id' => (string) $httpCampaignId]))['status'] === 422,
        'Server rejects forged edits of advanced content');
    $app->db()->prepare("UPDATE content_items SET status = 'brief' WHERE id = ?")->execute([$libraryId]);
    $deleteLibrary = ['action' => 'delete_content', 'content_id' => (string) $libraryId, 'csrf' => $libraryUpdate['csrf']];
    check(request($libraryUrl . '&id=' . $libraryId, $jar, $deleteLibrary)['status'] === 422,
        'Library deletion requires explicit confirmation');
    check(request($libraryUrl . '&id=' . $libraryId, $jar, array_replace($deleteLibrary, ['confirm_delete' => '1']))['status'] === 303
        && $library->snapshot(null, 'all', null, 'unique-library-brief')['total'] === 0
        && request($libraryUrl . '&id=' . $libraryId, $jar)['status'] === 400,
        'Library deletion hides content and rejects deleted detail');
    $creationPage = request($libraryUrl, $jar);
    preg_match('/name="creation_key" value="([a-f0-9]{64})"/', $creationPage['body'], $libraryKey);
    $createLibrary = array_replace($libraryInput, ['action' => 'create_content', 'creation_key' => $libraryKey[1],
        'csrf' => csrfFrom($creationPage['body']), 'title' => 'Library HTTP creation']);
    check(request($libraryUrl, $jar, $createLibrary)['status'] === 303
        && request($libraryUrl, $jar, $createLibrary)['status'] === 422,
        'Library HTTP creation persists once and rejects used form token');
    for ($i = 0; $i < 21; $i++) {
        $editor->create(array_replace($libraryInput, ['title' => 'Library pagination ' . $i,
            'creation_key' => bin2hex(random_bytes(32))]), $userId);
    }
    $firstLibrary = $library->snapshot(null, 'all', null, 'Library pagination');
    $secondLibrary = $library->snapshot(null, 'all', null, 'Library pagination', 2);
    check($firstLibrary['total'] === 21 && count($firstLibrary['items']) === 20
        && count($secondLibrary['items']) === 1 && $firstLibrary['pages'] === 2
        && array_intersect(array_column($firstLibrary['items'], 'id'), array_column($secondLibrary['items'], 'id')) === [],
        'Library paginates exactly twenty items with no overlap');
    check(str_contains(request($libraryUrl . '&q=Library+pagination', $jar)['body'], '&amp;p=2')
        && request($libraryUrl . '&q=Library+pagination&p=3', $jar)['status'] === 400,
        'Pagination preserves search and rejects unavailable pages');
    $archivedContent = $library->snapshot(null, 'all', null, '', 1, $manualId)['detail'];
    $archivedEdit = array_replace($contentInput, ['campaign_id' => (string) $campaignId,
        'title' => 'Edited inside archived campaign', 'planned_at' => '']);
    $editor->update($archivedEdit, $userId, $manualId, (int) $archivedContent['version']);
    $afterArchivedEdit = $library->snapshot(null, 'all', null, '', 1, $manualId)['detail'];
    check($afterArchivedEdit['planned_at'] === null && (int) $afterArchivedEdit['campaign_id'] === $campaignId
        && $afterArchivedEdit['title'] === 'Edited inside archived campaign',
        'Editing an existing archived campaign association preserves it and can clear planning time');
    check($campaignService->snapshot(null, 'all', $campaignId)['content'][0]['title'] === $afterArchivedEdit['title'],
        'Campaign detail immediately reflects edited content');
    $app->db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
    try {
        $editor->update($archivedEdit, $userId, $manualId, (int) $afterArchivedEdit['version']);
        check(false, 'Inactive actor cannot edit library content');
    } catch (InvalidArgumentException) {
        check(true, 'Inactive actor cannot edit library content');
    }
    $app->db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$userId]);
    $automaticContent = $app->db()->query("SELECT * FROM content_items WHERE observance_id IS NOT NULL
        AND deleted_at IS NULL AND status = 'idea' ORDER BY id LIMIT 1")->fetch();
    $automaticEdit = array_replace($contentInput, ['company_id' => (string) $automaticContent['company_id'],
        'title' => 'Curated observance brief', 'status' => 'brief', 'planned_at' => '2026-10-28T11:00']);
    $editor->update($automaticEdit, $userId, (int) $automaticContent['id'], (int) $automaticContent['version']);
    $afterAutomaticEdit = $library->snapshot(null, 'all', null, '', 1, (int) $automaticContent['id'])['detail'];
    check($afterAutomaticEdit['observance_id'] === $automaticContent['observance_id']
        && $afterAutomaticEdit['observance_year'] === $automaticContent['observance_year']
        && $afterAutomaticEdit['owner_id'] === $automaticContent['owner_id'],
        'Editing automatic ideas preserves observance identity and owner');
    $beforeGenerator = $afterAutomaticEdit['version'];
    $automation->generate((int) $automaticContent['observance_year']);
    check($library->snapshot(null, 'all', null, '', 1, (int) $automaticContent['id'])['detail']['version'] === $beforeGenerator,
        'Observance generator does not overwrite human edits');
    check(request($libraryUrl . '&id=' . $publishedId, $jar,
        array_replace($deleteLibrary, ['content_id' => (string) $publishedId, 'confirm_delete' => '1']))['status'] === 422,
        'Library rejects forged deletion of published content');
    check(request($libraryUrl . '&company=' . $companyIds[1] . '&id=' . $manualId, $jar)['status'] === 400
        && request($libraryUrl . '&company=' . $companyIds[1] . '&campaign=' . $campaignId, $jar)['status'] === 400,
        'Library rejects cross-company detail and campaign scope');
    require __DIR__ . '/ai.php';
    testAiStudio($app, $userId, $companyIds, $adminUrl, $jar, $directory);
    require __DIR__ . '/images.php';
    testAiImages($app, $userId, $adminUrl, $jar);
    foreach ($sections as $key => $item) {
        $menuPage = request($adminUrl . '&section=' . $key, $jar);
        if ($item['later'] ?? false) {
            check($menuPage['status'] === 404 && str_contains($signedIn['body'], 'aria-disabled="true"'),
                'Future metrics is visibly disabled and not routable');
        } else {
            check($menuPage['status'] === 200 && str_contains($menuPage['body'], '<h1>' . escape($item['label']) . '</h1>')
                && substr_count($menuPage['body'], 'aria-current="page"') === 1,
                'Render active admin menu: ' . $key);
        }
    }
    check(request($adminUrl . '&section=unknown', $jar)['status'] === 404, 'Reject unknown admin section');
    check(request($adminUrl, $jar, ['csrf' => csrfFrom($signedIn['body'])])['status'] === 405,
        'Admin placeholders do not accept feature mutations');
    check(request($baseUrl . '/assets/admin.css', $jar)['status'] === 200
        && request($baseUrl . '/assets/admin.js', $jar)['status'] === 200, 'Serve same-origin admin assets');
    check(request($baseUrl . '/index.php?page=logout', $jar)['status'] === 405, 'Logout cannot be triggered by GET');
    $csrf = csrfFrom($signedIn['body']);
    check(request($baseUrl . '/index.php?page=logout', $jar, ['csrf' => $csrf])['status'] === 303, 'Logout requires valid POST and CSRF');
    check(request($adminUrl, $jar)['status'] === 303, 'Logged-out session cannot access admin shell');
    $app->db()->exec('DELETE FROM rate_limits');
    $forgot = request($forgotUrl, $jar);
    $csrf = csrfFrom($forgot['body']);
    check(request($forgotUrl, $jar, ['csrf' => $csrf, 'email' => 'missing@example.test'])['status'] === 303, 'Unknown email gets generic reset response');
    $unknownNotice = request($forgotUrl, $jar)['body'];
    check(request($forgotUrl, $jar, ['csrf' => $csrf, 'email' => 'admin@example.test'])['status'] === 303, 'Known email gets same reset response');
    check($unknownNotice === request($forgotUrl, $jar)['body'], 'Known and unknown reset responses are identical');
    $httpToken = latestToken($app, $userId);
    $oldSessionJar = $directory . '/old-session-cookies.txt';
    $oldSessionCsrf = csrfFrom(request($loginUrl, $oldSessionJar)['body']);
    request($loginUrl, $oldSessionJar, ['csrf' => $oldSessionCsrf, 'email' => 'admin@example.test', 'password' => 'concurrent-passphrase-2026']);
    $tokenResponse = request($resetUrl . '&token=' . $httpToken, $jar);
    check($tokenResponse['status'] === 303 && !str_contains($tokenResponse['headers'], $httpToken), 'Strip reset secret from rendered URL');
    $resetPage = request($resetUrl, $jar);
    $resetCsrf = csrfFrom($resetPage['body']);
    check(!str_contains($resetPage['body'], $httpToken), 'Do not render reset secret into DOM');
    check(request($resetUrl, $jar, ['csrf' => $resetCsrf, 'password' => '123', 'confirmation' => '123'])['status'] === 422,
        'Validate password on server');
    check(request($resetUrl, $jar, ['csrf' => $resetCsrf, 'password' => 'http-reset-passphrase-2026', 'confirmation' => 'different'])['status'] === 422,
        'Reject mismatching password confirmation');
    check(request($resetUrl, $jar, ['csrf' => $resetCsrf, 'password' => 'http-reset-passphrase-2026', 'confirmation' => 'http-reset-passphrase-2026'])['status'] === 303,
        'Complete HTTP password reset');
    check(str_contains(request($loginUrl, $oldSessionJar)['body'], 'Selamat datang kembali'), 'Revoke other browser session after password reset');
    request($resetUrl . '&token=' . $httpToken, $jar);
    check(str_contains(request($resetUrl, $jar)['body'], 'Tautan tidak dapat digunakan'), 'Show expired/replayed link state');
    $csrf = csrfFrom(request($loginUrl, $jar)['body']);
    check(request($loginUrl, $jar, ['csrf' => $csrf, 'email' => '<script>alert(1)</script>', 'password' => 'wrong'])['status'] === 422,
        'Reject invalid email');
    $escaped = request($loginUrl, $jar, ['csrf' => $csrf, 'email' => '<script>alert(1)</script>', 'password' => 'wrong']);
    check(str_contains($escaped['body'], '&lt;script&gt;') && !str_contains($escaped['body'], '<script>alert'),
        'Escape reflected form input');
    foreach (['/app/Support.php', '/config/local.php', '/var/app.sqlite', '/database/migrations/001_auth.sql', '/templates/auth.php'] as $path) {
        check(request($baseUrl . $path, $jar)['status'] === 404, 'Private resource not served: ' . $path);
    }
    check(request($baseUrl . '/assets/auth.css', $jar)['status'] === 200
        && request($baseUrl . '/assets/auth.js', $jar)['status'] === 200, 'Serve local CSS and JavaScript without external dependencies');
    check(str_contains(request($baseUrl . '/index.php?page=missing', $jar)['body'], 'Halaman tidak ditemukan'), 'Reject unknown routes');
    echo implode(PHP_EOL, $messages), PHP_EOL, PHP_EOL, $checks, " checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, implode(PHP_EOL, $messages) . PHP_EOL . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    // Remove only this run's explicitly created, isolated storage.
    if (is_dir($directory) && str_starts_with($directory, $root . '/var/test-')) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
exit($exitCode);
