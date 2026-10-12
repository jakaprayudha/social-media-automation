<?php
declare(strict_types=1);

umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

require dirname(__DIR__) . '/app/bootstrap.php';

/** Read scalar form fields only; arrays are rejected rather than coerced. */
function formField(string $key): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('Malformed form field.');
    }
    return $value;
}

function redirectTo(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

try {
    $app = bootstrap();
    $basePath = $app->basePath();
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!in_array($requestPath, [$basePath . '/', $basePath . '/index.php', $basePath === '' ? '/' : $basePath], true)) {
        http_response_code(404);
        echo 'Halaman tidak ditemukan.';
        exit;
    }
    if ($app->environment !== 'development') {
        if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTPS'] ?? '') !== '1') {
            http_response_code(400);
            echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>HTTPS diperlukan</title>'
                . '<h1>Koneksi HTTPS diperlukan</h1><p>Administrator perlu memeriksa konfigurasi HTTPS server.</p></html>';
            exit;
        }
        header('Strict-Transport-Security: max-age=31536000');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        http_response_code(405);
        echo 'Metode request tidak didukung.';
        exit;
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code(413);
        echo 'Form terlalu besar.';
        exit;
    }
    $page = $_GET['page'] ?? 'login';
    if (!is_string($page) || !in_array($page, ['login', 'forgot', 'reset', 'logout', 'admin'], true)) {
        http_response_code(404);
        echo 'Halaman tidak ditemukan.';
        exit;
    }
    $auth = new Auth($app);
    $auth->startSession();
    $user = $auth->user();
    if ($page === 'login' && $user !== null && $method === 'GET') {
        redirectTo($app->url('admin'));
    }
    if ($page === 'admin') {
        if ($user === null) {
            redirectTo($app->url());
        }
        if ($user['role'] !== 'system_admin') {
            http_response_code(403);
            echo 'Anda tidak memiliki akses admin sistem.';
            exit;
        }
        $navigation = adminNavigation();
        $sections = array_merge(...array_values($navigation));
        $section = $_GET['section'] ?? 'dashboard';
        if (!is_string($section) || !isset($sections[$section]) || ($sections[$section]['later'] ?? false)) {
            http_response_code(404);
            echo 'Menu tidak tersedia.';
            exit;
        }
        $adminActions = [
            'calendar' => ['create_content', 'delete_content', 'automation_enable', 'automation_disable', 'generate_ideas'],
            'campaigns' => ['create_campaign', 'update_campaign', 'archive_campaign', 'assign_content'],
        ];
        if ($method === 'POST' && (!is_string($_POST['action'] ?? null)
            || !in_array($_POST['action'], $adminActions[$section] ?? [], true))) {
            header('Allow: GET');
            http_response_code(405);
            echo 'Tindakan pada menu admin tidak tersedia.';
            exit;
        }
        if ($section === 'calendar') {
            $_SESSION['content_creation_key'] ??= bin2hex(random_bytes(32));
            $contentForm = [
                'title' => '', 'brief' => '', 'company_id' => '', 'format' => 'unspecified',
                'status' => 'idea', 'planned_at' => '', 'creation_key' => $_SESSION['content_creation_key'], 'campaign_id' => '',
            ];
            $contentFormOpen = false;
        }
        if ($section === 'campaigns') {
            $_SESSION['campaign_creation_key'] ??= bin2hex(random_bytes(32));
            $campaignForm = ['name' => '', 'objective' => '', 'theme' => '', 'company_id' => '',
                'start_date' => '', 'end_date' => '', 'status' => 'draft', 'creation_key' => $_SESSION['campaign_creation_key']];
            $campaignFormOpen = false;
            $campaignEdit = false;
        }
        if (in_array($section, ['dashboard', 'calendar', 'campaigns'], true)) {
            $companyInput = $_GET['company'] ?? 'all';
            if (!is_string($companyInput)
                || ($companyInput !== 'all' && !preg_match('/\A[1-9][0-9]{0,8}\z/', $companyInput))) {
                http_response_code(400);
                echo 'Filter perusahaan tidak valid.';
                exit;
            }
            $monthInput = $_GET['month'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m');
            $statusInput = $_GET['status'] ?? 'all';
            $campaignInput = $_GET['campaign'] ?? 'all';
            $detailInput = $_GET['id'] ?? null;
            if (($section === 'calendar' && (!is_string($campaignInput)
                || ($campaignInput !== 'all' && !preg_match('/\A[1-9][0-9]{0,8}\z/', $campaignInput))))
                || ($section === 'campaigns' && (!is_string($statusInput)
                || ($statusInput !== 'all' && !isset(Campaigns::STATUSES[$statusInput]))
                || ($detailInput !== null && (!is_string($detailInput) || !preg_match('/\A[1-9][0-9]{0,8}\z/', $detailInput)))))) {
                http_response_code(400);
                echo 'Filter kampanye tidak valid.';
                exit;
            }
            if ($section === 'calendar' && (!is_string($monthInput)
                || !preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/', $monthInput)
                || !is_string($statusInput)
                || ($statusInput !== 'all' && !isset(Dashboard::STATUSES[$statusInput])))) {
                http_response_code(400);
                echo 'Filter bulan atau status tidak valid.';
                exit;
            }
            if ($section === 'calendar' && $method === 'GET' && array_key_exists('date', $_GET)) {
                $dateInput = $_GET['date'];
                if (!is_string($dateInput) || !preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}\z/', $dateInput)
                    || substr($dateInput, 0, 7) !== $monthInput
                    || !checkdate((int) substr($dateInput, 5, 2), (int) substr($dateInput, 8, 2), (int) substr($dateInput, 0, 4))) {
                    http_response_code(400);
                    echo 'Tanggal kalender tidak valid.';
                    exit;
                }
                $contentForm['planned_at'] = $dateInput . 'T09:00';
                $contentFormOpen = true;
            }
            try {
                $companyId = $companyInput === 'all' ? null : (int) $companyInput;
                if ($section === 'calendar') {
                    $calendar = (new ContentCalendar($app))->snapshot($companyId, $monthInput, $statusInput,
                        $campaignInput === 'all' ? null : (int) $campaignInput);
                    if ($method === 'GET' && $calendar['selected_campaign'] !== null) {
                        $contentForm['company_id'] = (string) $calendar['selected_campaign']['company_id'];
                        if ($calendar['selected_campaign']['status'] !== 'archived') {
                            $contentForm['campaign_id'] = (string) $calendar['selected_campaign']['id'];
                        }
                    }
                } elseif ($section === 'campaigns') {
                    $campaignData = (new Campaigns($app))->snapshot($companyId, $statusInput,
                        $detailInput === null ? null : (int) $detailInput);
                    $campaignVersion = $campaignData['detail']['version'] ?? null;
                    if ($method === 'GET' && isset($_GET['edit'])) {
                        if ($_GET['edit'] !== '1' || $campaignData['detail'] === null
                            || $campaignData['detail']['status'] === 'archived') {
                            throw new InvalidArgumentException('Invalid campaign edit.');
                        }
                        foreach (array_keys($campaignForm) as $field) {
                            $campaignForm[$field] = (string) $campaignData['detail'][$field];
                        }
                        $campaignEdit = true;
                        $campaignFormOpen = true;
                    }
                } else {
                    $dashboard = (new Dashboard($app))->snapshot($companyId);
                }
            } catch (InvalidArgumentException) {
                http_response_code(400);
                echo 'Perusahaan atau kampanye yang dipilih tidak tersedia.';
                exit;
            }
        }
    }
    $notice = $_SESSION['notice'] ?? null;
    unset($_SESSION['notice']);
    $error = null;
    $emailInput = '';
    $resetUsable = false;
    $mailConfigured = Mailer::configured($app);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if ($page === 'reset' && $method === 'GET' && array_key_exists('token', $_GET)) {
        $token = $_GET['token'];
        unset($_SESSION['reset_token']);
        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $_SESSION['reset_token'] = $token;
        }
        // Remove the secret from the address bar before rendering any page or assets.
        redirectTo($app->url('reset'));
    }

    if ($page === 'logout' && $method !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo 'Keluar hanya dapat dilakukan melalui form.';
        exit;
    }
    if ($method === 'POST') {
        if (!$auth->validCsrf($_POST['csrf'] ?? null)) {
            http_response_code(419);
            $error = 'Form telah kedaluwarsa atau tidak valid. Muat ulang halaman dan coba kembali.';
            if ($page === 'logout') {
                $page = 'login';
            }
        } elseif ($page === 'logout') {
            if ($user !== null) {
                $app->audit('logout.succeeded', (int) $user['id']);
            }
            $auth->clearSession();
            $_SESSION['notice'] = 'Anda telah keluar dari akun.';
            redirectTo($app->url());
        } elseif ($page === 'admin' && $section === 'campaigns') {
            $action = formField('action');
            $campaignService = new Campaigns($app);
            try {
                $targetId = null;
                if ($action !== 'create_campaign') {
                    $idInput = formField('campaign_id');
                    if (!preg_match('/\A[1-9][0-9]{0,8}\z/', $idInput)) {
                        throw new InvalidArgumentException('ID kampanye tidak valid.');
                    }
                    $targetId = (int) $idInput;
                    if ($campaignData['detail'] === null || (int) $campaignData['detail']['id'] !== $targetId) {
                        throw new InvalidArgumentException('Kampanye pada form tidak sesuai dengan halaman detail.');
                    }
                }
                if (in_array($action, ['create_campaign', 'update_campaign'], true)) {
                    $campaignEdit = $action === 'update_campaign';
                    $campaignFormOpen = true;
                    foreach (array_keys($campaignForm) as $field) {
                        $campaignForm[$field] = formField($field);
                    }
                    if (!$campaignEdit && !hash_equals($_SESSION['campaign_creation_key'], $campaignForm['creation_key'])) {
                        throw new InvalidArgumentException('Form kampanye sudah digunakan atau kedaluwarsa. Muat ulang halaman.');
                    }
                    $versionInput = formField('version');
                    $campaignVersion = $versionInput;
                    if ($campaignEdit && !preg_match('/\A[1-9][0-9]*\z/', $versionInput)) {
                        throw new InvalidArgumentException('Versi kampanye tidak valid.');
                    }
                    $targetId = $campaignService->save($campaignForm, (int) $user['id'], $targetId,
                        $campaignEdit ? (int) $versionInput : null);
                    if (!$campaignEdit) {
                        $_SESSION['campaign_creation_key'] = bin2hex(random_bytes(32));
                    }
                    $_SESSION['notice'] = 'Kampanye berhasil disimpan. Tidak ada publikasi otomatis.';
                } elseif ($action === 'archive_campaign') {
                    $versionInput = formField('version');
                    if (formField('confirm_archive') !== '1' || !preg_match('/\A[1-9][0-9]*\z/', $versionInput)) {
                        throw new InvalidArgumentException('Centang konfirmasi arsip dan gunakan versi form yang valid.');
                    }
                    $campaignService->archive($targetId, (int) $versionInput, (int) $user['id']);
                    $_SESSION['notice'] = 'Kampanye diarsipkan. Konten dan riwayatnya tetap disimpan.';
                } else {
                    $contentInput = formField('content_id');
                    if (!preg_match('/\A[1-9][0-9]{0,8}\z/', $contentInput)) {
                        throw new InvalidArgumentException('Pilih konten yang valid.');
                    }
                    $campaignService->assign($targetId, (int) $contentInput, (int) $user['id']);
                    $_SESSION['notice'] = 'Konten berhasil dikaitkan ke kampanye.';
                }
                redirectTo($app->url('admin') . '&section=campaigns&id=' . $targetId);
            } catch (InvalidArgumentException $validationError) {
                http_response_code(422);
                $error = $validationError->getMessage();
            }
        } elseif ($page === 'admin' && $section === 'calendar') {
            $action = formField('action');
            try {
                if ($action === 'delete_content') {
                    $contentId = formField('content_id');
                    if (!preg_match('/\A[1-9][0-9]{0,8}\z/', $contentId) || formField('confirm_delete') !== '1') {
                        throw new InvalidArgumentException('Konfirmasi penghapusan dan pilih konten yang valid.');
                    }
                    (new ContentEditor($app))->delete((int) $contentId, (int) $user['id']);
                    $_SESSION['notice'] = 'Konten dihapus dari kalender dan dashboard. Ide otomatis yang dihapus tidak akan dibuat ulang.';
                    redirectTo($app->url('admin') . '&' . http_build_query([
                        'section' => 'calendar', 'company' => $companyInput, 'month' => $monthInput, 'status' => $statusInput, 'campaign' => $campaignInput,
                    ]));
                }
                if ($action === 'create_content') {
                    foreach (array_keys($contentForm) as $field) {
                        $contentForm[$field] = formField($field);
                    }
                    $contentFormOpen = true;
                    if (!hash_equals($_SESSION['content_creation_key'], $contentForm['creation_key'])) {
                        throw new InvalidArgumentException('Form konten sudah digunakan atau kedaluwarsa. Muat ulang halaman.');
                    }
                    (new ContentEditor($app))->create($contentForm, (int) $user['id']);
                    $_SESSION['content_creation_key'] = bin2hex(random_bytes(32));
                    $_SESSION['notice'] = 'Konten disimpan sebagai ' . ContentEditor::INITIAL_STATUSES[$contentForm['status']]
                        . '. Tanggal rencana bukan jadwal publikasi otomatis.';
                    redirectTo($app->url('admin') . '&' . http_build_query([
                        'section' => 'calendar', 'company' => $contentForm['company_id'],
                        'month' => $contentForm['planned_at'] === '' ? $monthInput : substr($contentForm['planned_at'], 0, 7),
                        'campaign' => $contentForm['campaign_id'] === '' ? 'all' : $contentForm['campaign_id'],
                    ]));
                }
                $automation = new ObservanceAutomation($app);
                if ($action === 'automation_disable') {
                    $automation->disable((int) $user['id']);
                    $_SESSION['notice'] = 'Pembuatan ide otomatis dijeda. Konten yang sudah ada tidak dihapus.';
                } else {
                    if ($action === 'automation_enable') {
                        $automation->enable((int) $user['id']);
                    }
                    $summary = $automation->generate((int) substr($monthInput, 0, 4));
                    if (!$summary['enabled']) {
                        throw new InvalidArgumentException('Aktifkan ide otomatis terlebih dahulu.');
                    }
                    $_SESSION['notice'] = $summary['created'] . ' ide baru dibuat untuk semua perusahaan aktif pada tahun '
                        . $summary['year'] . '. Ide yang sudah ada tidak ditimpa; tidak ada publikasi.';
                }
                redirectTo($app->url('admin') . '&' . http_build_query([
                    'section' => 'calendar', 'company' => $companyInput, 'month' => $monthInput, 'status' => $statusInput, 'campaign' => $campaignInput,
                ]));
            } catch (InvalidArgumentException $validationError) {
                http_response_code(422);
                $error = $validationError->getMessage();
            }
        } elseif ($page === 'login' && $user === null) {
            $emailInput = trim(formField('email'));
            $email = emailAddress($emailInput);
            $password = formField('password');
            if ($email === null || $password === '') {
                http_response_code(422);
                $error = 'Masukkan email yang valid dan kata sandi Anda.';
            } else {
                $result = $auth->login($email, $password, $ip);
                if ($result === 'ok') {
                    redirectTo($app->url('admin'));
                }
                http_response_code($result === 'rate_limited' ? 429 : 422);
                if ($result === 'rate_limited') {
                    header('Retry-After: 900');
                }
                $error = $result === 'rate_limited'
                    ? 'Terlalu banyak percobaan masuk. Tunggu 15 menit sebelum mencoba kembali.'
                    : 'Email atau kata sandi tidak cocok. Periksa kembali dan coba lagi.';
            }
        } elseif ($page === 'forgot') {
            $emailInput = trim(formField('email'));
            $email = emailAddress($emailInput);
            if ($email === null) {
                http_response_code(422);
                $error = 'Masukkan alamat email yang valid.';
            } else {
                $result = $auth->requestReset($email, $ip);
                if ($result === 'accepted') {
                    $_SESSION['notice'] = 'Jika email terdaftar dan akun aktif, permintaan tautan reset akan diproses. Periksa kotak masuk dan folder spam.';
                    redirectTo($app->url('forgot'));
                }
                http_response_code($result === 'rate_limited' ? 429 : 503);
                if ($result === 'rate_limited') {
                    header('Retry-After: 900');
                }
                $error = $result === 'rate_limited'
                    ? 'Terlalu banyak permintaan. Tunggu 15 menit sebelum mencoba kembali.'
                    : 'Layanan email reset belum tersedia. Hubungi administrator.';
            }
        } elseif ($page === 'reset') {
            if (!$app->rateLimit('reset-submit-ip:' . $ip, 20, 900)) {
                http_response_code(429);
                header('Retry-After: 900');
                $error = 'Terlalu banyak percobaan. Tunggu 15 menit sebelum mencoba kembali.';
            } else {
                $token = $_SESSION['reset_token'] ?? '';
                $password = formField('password');
                $confirmation = formField('confirmation');
                if (!$auth->resetUsable($token)) {
                    http_response_code(422);
                    $error = 'Tautan reset tidak valid, sudah digunakan, atau telah kedaluwarsa.';
                } elseif (($problem = passwordProblem($password)) !== null) {
                    http_response_code(422);
                    $error = $problem;
                } elseif (!hash_equals($password, $confirmation)) {
                    http_response_code(422);
                    $error = 'Konfirmasi kata sandi belum cocok.';
                } elseif ($auth->resetPassword($token, $password)) {
                    $auth->clearSession();
                    $_SESSION['notice'] = 'Kata sandi berhasil diperbarui. Masuk kembali dengan kata sandi baru; semua session lama telah dicabut.';
                    redirectTo($app->url());
                } else {
                    http_response_code(422);
                    $error = 'Tautan reset tidak valid, sudah digunakan, atau telah kedaluwarsa.';
                }
            }
        }
    }
    if ($page === 'reset') {
        $resetUsable = $auth->resetUsable($_SESSION['reset_token'] ?? '');
        if (!$resetUsable) {
            unset($_SESSION['reset_token']);
        }
    }
    $csrf = $auth->csrf();
    if ($page === 'admin' || ($page === 'login' && $user !== null)) {
        $navigation = adminNavigation();
        $sections = array_merge(...array_values($navigation));
        $section = $section ?? 'dashboard';
        if ($section === 'dashboard' && !isset($dashboard)) {
            $dashboard = (new Dashboard($app))->snapshot(null);
        }
        require dirname(__DIR__) . '/templates/admin.php';
    } else {
        require dirname(__DIR__) . '/templates/auth.php';
    }
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo 'Form tidak valid. Muat ulang halaman dan coba kembali.';
} catch (Throwable $error) {
    $reference = bin2hex(random_bytes(8));
    error_log('Web failure: ' . get_class($error) . '; reference=' . $reference);
    if (isset($app)) {
        $app->log('web.failed', $reference);
    }
    http_response_code(503);
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Layanan belum tersedia</title><h1>Layanan belum tersedia</h1>'
        . '<p>Silakan hubungi administrator dan sertakan kode referensi: ' . escape($reference) . '.</p></html>';
}
