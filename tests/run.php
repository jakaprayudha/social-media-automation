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
    check(passwordProblem('short') !== null && passwordProblem(str_repeat('a', 73)) !== null
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
        [PHP_BINARY, '-S', $address, '-t', $root . '/public'],
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
    check(request($resetUrl, $jar, ['csrf' => $resetCsrf, 'password' => 'short', 'confirmation' => 'short'])['status'] === 422,
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
