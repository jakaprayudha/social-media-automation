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
        if ($method !== 'GET') {
            header('Allow: GET');
            http_response_code(405);
            echo 'Menu admin hanya menerima navigasi GET.';
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
        if ($section === 'dashboard') {
            $companyInput = $_GET['company'] ?? 'all';
            if (!is_string($companyInput)
                || ($companyInput !== 'all' && !preg_match('/\A[1-9][0-9]{0,8}\z/', $companyInput))) {
                http_response_code(400);
                echo 'Filter perusahaan tidak valid.';
                exit;
            }
            try {
                $dashboard = (new Dashboard($app))->snapshot($companyInput === 'all' ? null : (int) $companyInput);
            } catch (InvalidArgumentException) {
                http_response_code(400);
                echo 'Perusahaan yang dipilih tidak terdaftar.';
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
