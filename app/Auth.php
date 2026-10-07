<?php
declare(strict_types=1);

final class Auth
{
    private const DUMMY_HASH = '$2y$12$YqOhDREvPA5osHwPIlSXM.TVGFAwgU1wZptBks5KJSF.1mN9znG6q';

    public function __construct(private readonly App $app)
    {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->app->config['session_lifetime_seconds']);
        session_save_path($this->app->storage . '/sessions');
        session_name('ruang_social_session');
        $path = parse_url($this->app->baseUrl, PHP_URL_PATH) ?: '';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim($path, '/') . '/',
            'secure' => str_starts_with($this->app->baseUrl, 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Cannot start session.');
        }
    }

    public function csrf(): string
    {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public function validCsrf(mixed $value): bool
    {
        return is_string($value) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $value);
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if (!isset($_SESSION['user_id'], $_SESSION['auth_version'], $_SESSION['signed_in_at'], $_SESSION['last_seen'])) {
            return null;
        }
        $now = time();
        if ($now - (int) $_SESSION['last_seen'] >= $this->app->config['session_idle_seconds']
            || $now - (int) $_SESSION['signed_in_at'] >= $this->app->config['session_lifetime_seconds']) {
            $this->clearSession();
            return null;
        }
        $query = $this->app->db()->prepare('SELECT id, name, email, role, is_active, auth_version FROM users WHERE id = ?');
        $query->execute([$_SESSION['user_id']]);
        $user = $query->fetch();
        if (!$user || !(int) $user['is_active'] || (int) $user['auth_version'] !== (int) $_SESSION['auth_version']) {
            $this->clearSession();
            return null;
        }
        $_SESSION['last_seen'] = $now;
        return $user;
    }

    public function clearSession(): void
    {
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Cannot rotate session.');
        }
    }

    public function login(string $email, string $password, string $ip): string
    {
        $ipAllowed = $this->app->rateLimit('login-ip:' . $ip, 30, 900);
        $accountAllowed = $this->app->rateLimit('login-account:' . $email, 10, 900);
        if (!$ipAllowed || !$accountAllowed) {
            $this->app->audit('login.rate_limited');
            return 'rate_limited';
        }
        $query = $this->app->db()->prepare('SELECT * FROM users WHERE email = ?');
        $query->execute([$email]);
        $user = $query->fetch();
        $valid = strlen($password) <= 72 && !str_contains($password, "\0")
            && password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if (!$user || !$valid || !(int) $user['is_active']) {
            $this->app->audit('login.failed');
            return 'invalid';
        }
        // Recheck credentials inside the write transaction to avoid a concurrent reset reauthorizing an old password.
        $version = $this->app->transaction(function (PDO $db) use ($user, $password): ?int {
            $query = $db->prepare('SELECT password_hash, auth_version, is_active FROM users WHERE id = ?');
            $query->execute([$user['id']]);
            $current = $query->fetch();
            if (!$current || !(int) $current['is_active'] || $current['password_hash'] !== $user['password_hash']) {
                return null;
            }
            if (password_needs_rehash($current['password_hash'], PASSWORD_DEFAULT)) {
                $db->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), time(), $user['id']]);
            }
            $this->app->audit('login.succeeded', (int) $user['id']);
            return (int) $current['auth_version'];
        });
        if ($version === null) {
            return 'invalid';
        }
        $this->clearSession();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['auth_version'] = $version;
        $_SESSION['signed_in_at'] = time();
        $_SESSION['last_seen'] = time();
        return 'ok';
    }

    public function requestReset(string $email, string $ip): string
    {
        if (!Mailer::configured($this->app)) {
            $this->app->log('reset.mail_not_configured', bin2hex(random_bytes(8)));
            return 'unavailable';
        }
        if (!$this->app->rateLimit('reset-ip:' . $ip, 10, 900)) {
            $this->app->audit('reset.rate_limited');
            return 'rate_limited';
        }
        if (!$this->app->rateLimit('reset-account:' . $email, 3, 900)) {
            $this->app->audit('reset.rate_limited');
            return 'accepted';
        }
        $token = bin2hex(random_bytes(32));
        $now = time();
        $expires = $now + $this->app->config['reset_lifetime_seconds'];
        $this->app->transaction(function (PDO $db) use ($email, $token, $now, $expires): void {
            $query = $db->prepare('SELECT id, email FROM users WHERE email = ? AND is_active = 1');
            $query->execute([$email]);
            $user = $query->fetch();
            if (!$user) {
                $this->app->audit('reset.requested');
                return;
            }
            // Keep existing links usable until a reset succeeds, avoiding denial of service by repeated requests.
            $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$user['id'], hash('sha256', $token), $expires, $now]);
            $resetId = (int) $db->lastInsertId();
            $url = $this->app->url('reset') . '&token=' . $token;
            $minutes = (int) ceil($this->app->config['reset_lifetime_seconds'] / 60);
            $body = "Permintaan reset kata sandi Ruang Social\n\n"
                . "Buka tautan berikut untuk membuat kata sandi baru:\n" . $url
                . "\n\nTautan berlaku selama " . $minutes . " menit dan hanya dapat digunakan sekali."
                . "\nJika Anda tidak meminta reset, abaikan email ini. Kata sandi Anda belum berubah.\n";
            $payload = $this->app->encrypt(json_encode([
                'to' => $user['email'], 'subject' => 'Reset kata sandi Ruang Social', 'body' => $body,
            ], JSON_THROW_ON_ERROR));
            $db->prepare('INSERT INTO mail_jobs (reset_id, encrypted_payload, available_at, expires_at, created_at)
                VALUES (?, ?, ?, ?, ?)')->execute([$resetId, $payload, $now, $expires, $now]);
            $this->app->audit('reset.requested', (int) $user['id']);
        });
        return 'accepted';
    }

    public function resetUsable(string $token): bool
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return false;
        }
        $query = $this->app->db()->prepare('SELECT 1 FROM password_resets r JOIN users u ON u.id = r.user_id
            WHERE r.token_hash = ? AND r.consumed_at IS NULL AND r.expires_at > ? AND u.is_active = 1');
        $query->execute([hash('sha256', $token), time()]);
        return (bool) $query->fetchColumn();
    }

    public function resetPassword(string $token, string $password): bool
    {
        if (passwordProblem($password) !== null || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new InvalidArgumentException('Reset input must be validated before saving.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        return $this->app->transaction(function (PDO $db) use ($token, $hash): bool {
            $now = time();
            $query = $db->prepare('SELECT r.user_id FROM password_resets r JOIN users u ON u.id = r.user_id
                WHERE r.token_hash = ? AND r.consumed_at IS NULL AND r.expires_at > ? AND u.is_active = 1');
            $query->execute([hash('sha256', $token), $now]);
            $userId = $query->fetchColumn();
            if ($userId === false) {
                $this->app->audit('reset.invalid');
                return false;
            }
            $db->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1, updated_at = ? WHERE id = ?')
                ->execute([$hash, $now, $userId]);
            $db->prepare('UPDATE password_resets SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL')
                ->execute([$now, $userId]);
            $db->prepare("UPDATE mail_jobs SET status = 'cancelled', encrypted_payload = NULL, lease_token = NULL,
                lock_until = NULL WHERE reset_id IN (SELECT id FROM password_resets WHERE user_id = ?)
                AND status IN ('pending', 'processing')")->execute([$userId]);
            $this->app->audit('reset.succeeded', (int) $userId);
            return true;
        });
    }
}
