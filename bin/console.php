#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
require dirname(__DIR__) . '/app/bootstrap.php';

function prompt(string $label, bool $secret = false): string
{
    if (!stream_isatty(STDIN)) {
        throw new RuntimeException('Use an interactive terminal to create the administrator.');
    }
    fwrite(STDOUT, $label . ': ');
    if ($secret) {
        passthru('stty -echo', $code);
        if ($code !== 0) {
            throw new RuntimeException('Cannot hide password input.');
        }
    }
    try {
        $value = fgets(STDIN);
        if ($value === false) {
            throw new RuntimeException('Input was interrupted.');
        }
        return rtrim($value, "\r\n");
    } finally {
        if ($secret) {
            passthru('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }
}

try {
    $command = $argv[1] ?? 'help';
    if ($command === 'key:generate') {
        echo bin2hex(random_bytes(32)), PHP_EOL;
        exit;
    }
    if ($command === 'help') {
        echo "Ruang Social\n\n"
            . "  key:generate   Generate a private application key\n"
            . "  init:development  Install local-only configuration and database (no default account)\n"
            . "  migrate        Apply SQLite migrations without dropping data\n"
            . "  admin:create   Create an administrator interactively\n"
            . "  mail:work      Process up to 10 reset emails (cron-safe)\n"
            . "  mail:status    Show queue health without sensitive payloads\n"
            . "  preflight      Validate configuration and runtime\n";
        exit;
    }
    if ($command === 'init:development') {
        $path = dirname(__DIR__) . '/config/local.php';
        if (is_file($path) || getenv('APP_CONFIG')) {
            throw new RuntimeException('Configuration already exists or APP_CONFIG is set; nothing was overwritten.');
        }
        $config = require dirname(__DIR__) . '/config/example.php';
        $config['environment'] = 'development';
        $config['base_url'] = 'http://127.0.0.1:8080';
        $config['app_key'] = bin2hex(random_bytes(32));
        $config['mail']['transport'] = 'file';
        if (file_put_contents($path, "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n", LOCK_EX) === false
            || !chmod($path, 0600)) {
            throw new RuntimeException('Cannot write private development configuration.');
        }
        $app = bootstrap();
        $app->migrate();
        echo "Local development initialized at http://127.0.0.1:8080.\n"
            . "No accounts created. Run admin:create to create your own administrator.\n"
            . "Development emails are written to private var/mail, never sent externally.\n";
        exit;
    }
    $app = bootstrap();
    switch ($command) {
        case 'migrate':
            $app->migrate();
            echo "Migrations applied.\n";
            break;
        case 'admin:create':
            $name = trim(prompt('Nama administrator'));
            $email = emailAddress(prompt('Email'));
            $password = prompt('Kata sandi (minimal 12 karakter)', true);
            $confirmation = prompt('Ulangi kata sandi', true);
            if ($name === '' || mb_strlen($name) > 100 || $email === null) {
                throw new RuntimeException('Nama atau email tidak valid.');
            }
            if (($problem = passwordProblem($password)) !== null) {
                throw new RuntimeException($problem);
            }
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('Konfirmasi kata sandi tidak cocok.');
            }
            $app->transaction(function (PDO $db) use ($app, $name, $email, $password): void {
                $query = $db->prepare('SELECT 1 FROM users WHERE email = ?');
                $query->execute([$email]);
                if ($query->fetchColumn()) {
                    throw new RuntimeException('Email sudah terdaftar.');
                }
                $db->prepare('INSERT INTO users (name, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), time(), time()]);
                $app->audit('admin.created', (int) $db->lastInsertId());
            });
            echo "Administrator created. No default credentials are installed.\n";
            break;
        case 'mail:work':
            $summary = (new MailWorker($app))->run();
            echo json_encode($summary, JSON_THROW_ON_ERROR), PHP_EOL;
            exit($summary['failed'] > 0 || $summary['retried'] > 0 ? 1 : 0);
        case 'mail:status':
            $rows = $app->db()->query("SELECT status, COUNT(*) AS count, MIN(created_at) AS oldest_created_at
                FROM mail_jobs GROUP BY status")->fetchAll();
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
            break;
        case 'preflight':
            $db = $app->db();
            if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
                || strtolower((string) $db->query('PRAGMA journal_mode')->fetchColumn()) !== 'wal') {
                throw new RuntimeException('SQLite integrity/WAL check failed.');
            }
            $db->query('SELECT id FROM users LIMIT 1');
            if (!Mailer::configured($app)) {
                throw new RuntimeException('Email transport is not configured.');
            }
            echo "PHP, private storage, SQLite and mail configuration checks passed.\n"
                . "Also verify HTTPS, document root, SMTP delivery and cron on the target hosting.\n";
            break;
        default:
            throw new RuntimeException('Unknown command. Run php bin/console.php help.');
    }
} catch (Throwable $error) {
    $reference = bin2hex(random_bytes(8));
    error_log('CLI failure: ' . get_class($error) . '; reference=' . $reference);
    if (isset($app)) {
        $app->log('cli.failed', $reference);
    }
    // Controlled operational messages are safe; database errors may contain private values.
    $message = $error instanceof PDOException ? 'Database operation failed. Check installation and permissions.' : $error->getMessage();
    fwrite(STDERR, 'Error: ' . $message . ' [' . $reference . ']' . PHP_EOL);
    exit(1);
}
