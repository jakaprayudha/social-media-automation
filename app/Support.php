<?php
declare(strict_types=1);

final class App
{
    private ?PDO $connection = null;
    public readonly string $storage;
    public readonly string $baseUrl;
    public readonly string $key;
    public readonly string $environment;

    /** @param array<string, mixed> $config */
    public function __construct(public readonly array $config)
    {
        $this->environment = (string) ($config['environment'] ?? '');
        if (!in_array($this->environment, ['development', 'testing', 'production'], true)) {
            throw new RuntimeException('Invalid application environment.');
        }
        $key = $config['app_key'] ?? '';
        if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/', $key)) {
            throw new RuntimeException('A 32-byte application key is required.');
        }
        $this->key = sodium_hex2bin($key);
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        $parts = parse_url($base);
        if (!filter_var($base, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\r\n]/', $base)
            || ($this->environment !== 'development' && ($parts['scheme'] ?? '') !== 'https')) {
            throw new RuntimeException('A canonical HTTPS base URL is required outside development.');
        }
        $this->baseUrl = $base;
        foreach (['session_idle_seconds', 'session_lifetime_seconds', 'reset_lifetime_seconds'] as $setting) {
            if (!isset($config[$setting]) || !is_int($config[$setting]) || $config[$setting] < 60) {
                throw new RuntimeException('Invalid duration setting: ' . $setting);
            }
        }
        $storage = (string) ($config['storage_path'] ?? '');
        if ($storage === '' || !str_starts_with($storage, '/')) {
            throw new RuntimeException('An absolute private storage path is required.');
        }
        $this->makeDirectory($storage);
        $resolved = realpath($storage);
        $public = realpath(dirname(__DIR__) . '/public');
        if ($resolved === false || $public === false || $resolved === $public
            || str_starts_with($resolved . '/', $public . '/')) {
            throw new RuntimeException('Storage must be outside the public document root.');
        }
        $this->storage = $resolved;
        foreach (['sessions', 'logs', 'mail'] as $directory) {
            $this->makeDirectory($this->storage . '/' . $directory);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create private storage.');
        }
        if (!is_writable($path)) {
            throw new RuntimeException('Private storage is not writable.');
        }
    }

    public function db(bool $allowCreate = false): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $path = $this->storage . '/app.sqlite';
        if (!$allowCreate && !is_file($path)) {
            throw new RuntimeException('Database is not installed. Run the migrations first.');
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('PRAGMA synchronous = FULL');
        $this->connection = $db;
        return $db;
    }

    public function migrate(): void
    {
        $db = $this->db(true);
        $mode = $db->query('PRAGMA journal_mode = WAL')->fetchColumn();
        if (strtolower((string) $mode) !== 'wal') {
            throw new RuntimeException('SQLite WAL could not be enabled.');
        }
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at INTEGER NOT NULL)');
        foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $file) {
            $version = basename($file);
            $this->transaction(function (PDO $db) use ($version, $file): void {
                $query = $db->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
                $query->execute([$version]);
                if ($query->fetchColumn()) {
                    return;
                }
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException('Cannot read migration.');
                }
                $db->exec($sql);
                $db->prepare('INSERT INTO schema_migrations VALUES (?, ?)')->execute([$version, time()]);
            });
        }
        if (!chmod($this->storage . '/app.sqlite', 0600)) {
            throw new RuntimeException('Cannot protect database permissions.');
        }
    }

    public function transaction(callable $callback): mixed
    {
        $db = $this->db();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $result = $callback($db);
            $db->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $db->exec('ROLLBACK');
            throw $error;
        }
    }

    public function url(string $page = 'login'): string
    {
        return $this->basePath() . '/index.php?page=' . rawurlencode($page);
    }

    public function absoluteUrl(string $page = 'login'): string
    {
        return $this->baseUrl . '/index.php?page=' . rawurlencode($page);
    }

    public function basePath(): string
    {
        return rtrim((string) parse_url($this->baseUrl, PHP_URL_PATH), '/');
    }

    public function audit(string $action, ?int $userId = null): void
    {
        $this->db()->prepare('INSERT INTO audit_events (actor_id, action, created_at) VALUES (?, ?, ?)')
            ->execute([$userId, $action, time()]);
    }

    public function log(string $event, string $reference): void
    {
        // Never accept exception messages, email addresses, passwords or reset URLs here.
        $line = json_encode(['time' => gmdate('c'), 'event' => $event, 'reference' => $reference], JSON_THROW_ON_ERROR);
        if (file_put_contents($this->storage . '/logs/app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('Application logging failed; reference=' . $reference);
            throw new RuntimeException('Cannot write application log.');
        }
    }

    public function rateLimit(string $bucket, int $limit, int $window): bool
    {
        $key = hash_hmac('sha256', $bucket, $this->key);
        return $this->transaction(function (PDO $db) use ($key, $limit, $window): bool {
            $now = time();
            $query = $db->prepare('SELECT attempts, expires_at FROM rate_limits WHERE bucket = ?');
            $query->execute([$key]);
            $row = $query->fetch();
            if ($row && (int) $row['expires_at'] > $now) {
                if ((int) $row['attempts'] >= $limit) {
                    return false;
                }
                $db->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE bucket = ?')->execute([$key]);
            } else {
                $db->prepare('INSERT INTO rate_limits (bucket, attempts, expires_at) VALUES (?, 1, ?)
                    ON CONFLICT(bucket) DO UPDATE SET attempts = 1, expires_at = excluded.expires_at')
                    ->execute([$key, $now + $window]);
            }
            return true;
        });
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid encrypted mail payload.');
        }
        $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Mail payload authentication failed.');
        }
        return $plain;
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function emailAddress(string $input): ?string
{
    $email = strtolower(trim($input));
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
}

function passwordProblem(string $password): ?string
{
    if (mb_strlen($password) < 12 || strlen($password) > 72) {
        return 'Gunakan minimal 12 karakter dan maksimal 72 byte untuk kata sandi.';
    }
    if (str_contains($password, "\0") || preg_match('/\A\s+\z/u', $password)) {
        return 'Kata sandi tidak boleh hanya berisi spasi atau karakter yang tidak valid.';
    }
    return null;
}
