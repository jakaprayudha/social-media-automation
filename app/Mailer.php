<?php
declare(strict_types=1);

final class DeliveryFailure extends RuntimeException
{
}

final class Mailer
{
    public static function configured(App $app): bool
    {
        $mail = $app->config['mail'] ?? [];
        if (!is_array($mail)) {
            return false;
        }
        if (($mail['transport'] ?? '') === 'file') {
            return $app->environment === 'development';
        }
        return ($mail['transport'] ?? '') === 'smtp'
            && extension_loaded('curl')
            && in_array('smtp', curl_version()['protocols'], true)
            && emailAddress((string) ($mail['from_address'] ?? '')) !== null
            && preg_match('/\A[a-zA-Z0-9.-]+\z/', (string) ($mail['host'] ?? '')) === 1
            && is_int($mail['port'] ?? null) && $mail['port'] > 0 && $mail['port'] <= 65535
            && in_array($mail['encryption'] ?? '', ['starttls', 'tls'], true)
            && is_string($mail['username'] ?? null) && $mail['username'] !== ''
            && is_string($mail['password'] ?? null) && $mail['password'] !== ''
            && is_string($mail['from_name'] ?? null) && !preg_match('/[\r\n]/', $mail['from_name'])
            && is_int($mail['timeout_seconds'] ?? null)
            && $mail['timeout_seconds'] >= 1 && $mail['timeout_seconds'] <= 60;
    }

    /** @param array<string, mixed> $payload */
    public static function send(App $app, int $jobId, array $payload): void
    {
        if (!self::configured($app)) {
            throw new DeliveryFailure('mail_configuration');
        }
        if (!isset($payload['to'], $payload['subject'], $payload['body'])
            || !is_string($payload['to']) || emailAddress($payload['to']) === null
            || !is_string($payload['subject']) || preg_match('/[\r\n]/', $payload['subject'])
            || !is_string($payload['body'])) {
            throw new DeliveryFailure('mail_payload');
        }
        $mail = $app->config['mail'];
        $fromAddress = $mail['transport'] === 'file' ? 'development@localhost.test' : $mail['from_address'];
        $fromName = mb_encode_mimeheader($mail['from_name'] ?? 'Ruang Social', 'UTF-8', 'B', "\r\n");
        $message = 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n"
            . 'Message-ID: <reset-' . $jobId . '-' . hash_hmac('sha256', (string) $jobId, $app->key)
            . '@' . parse_url($app->baseUrl, PHP_URL_HOST) . ">\r\n"
            . 'From: ' . $fromName . ' <' . $fromAddress . ">\r\n"
            . 'To: <' . $payload['to'] . ">\r\n"
            . 'Subject: ' . mb_encode_mimeheader($payload['subject'], 'UTF-8', 'B', "\r\n") . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($payload['body']), 76, "\r\n");
        if ($mail['transport'] === 'file') {
            $path = $app->storage . '/mail/reset-' . $jobId . '.eml';
            if (file_put_contents($path, $message, LOCK_EX) === false || !chmod($path, 0600)) {
                throw new DeliveryFailure('mail_file_write');
            }
            return;
        }
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new DeliveryFailure('mail_stream');
        }
        $handle = curl_init();
        if ($handle === false) {
            fclose($stream);
            throw new DeliveryFailure('mail_client');
        }
        try {
            if (fwrite($stream, $message) !== strlen($message) || !rewind($stream)) {
                throw new DeliveryFailure('mail_stream');
            }
            $scheme = $mail['encryption'] === 'tls' ? 'smtps' : 'smtp';
            curl_setopt_array($handle, [
                CURLOPT_URL => $scheme . '://' . $mail['host'] . ':' . $mail['port'],
                CURLOPT_PROTOCOLS => CURLPROTO_SMTP | CURLPROTO_SMTPS,
                CURLOPT_USE_SSL => CURLUSESSL_ALL,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERNAME => $mail['username'],
                CURLOPT_PASSWORD => $mail['password'],
                CURLOPT_MAIL_FROM => $fromAddress,
                CURLOPT_MAIL_RCPT => [$payload['to']],
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $stream,
                CURLOPT_INFILESIZE => strlen($message),
                CURLOPT_CONNECTTIMEOUT => min(10, $mail['timeout_seconds']),
                CURLOPT_TIMEOUT => $mail['timeout_seconds'],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $result = curl_exec($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($result === false || $code < 200 || $code >= 300) {
                throw new DeliveryFailure('smtp_' . curl_errno($handle) . '_' . $code);
            }
        } finally {
            fclose($stream);
            // CurlHandle is automatically released when it leaves scope.
        }
    }
}

final class MailWorker
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array{sent: int, retried: int, failed: int} */
    public function run(int $maxJobs = 10, int $maxSeconds = 50): array
    {
        if (!Mailer::configured($this->app)) {
            throw new RuntimeException('Email transport is not configured; pending requests cannot be delivered.');
        }
        $summary = ['sent' => 0, 'retried' => 0, 'failed' => 0];
        $deadline = microtime(true) + $maxSeconds;
        $this->app->db()->prepare('DELETE FROM rate_limits WHERE expires_at <= ?')->execute([time()]);
        $this->app->db()->prepare('DELETE FROM password_resets WHERE expires_at < ?')
            ->execute([time() - 30 * 86400]);
        for ($processed = 0; $processed < $maxJobs && microtime(true) < $deadline; $processed++) {
            [$job, $exhausted] = $this->claim();
            if ($exhausted > 0) {
                $summary['failed'] += $exhausted;
                $this->app->log('mail.attempt_limit', 'count-' . $exhausted);
            }
            if ($job === null) {
                break;
            }
            try {
                $payload = json_decode($this->app->decrypt($job['encrypted_payload']), true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new RuntimeException('Invalid mail payload.');
                }
                Mailer::send($this->app, (int) $job['id'], $payload);
                $query = $this->app->db()->prepare("UPDATE mail_jobs SET status = 'sent', encrypted_payload = NULL,
                    sent_at = ?, lock_until = NULL, lease_token = NULL, last_error = NULL
                    WHERE id = ? AND status = 'processing' AND lease_token = ?");
                $query->execute([time(), $job['id'], $job['lease_token']]);
                $summary['sent'] += $query->rowCount();
            } catch (DeliveryFailure $error) {
                $terminal = (int) $job['attempt_count'] >= 5
                    || time() + 60 >= (int) $job['expires_at'];
                $this->app->log('mail.delivery_failed', 'job-' . $job['id'] . '-' . $error->getMessage());
                $query = $this->app->db()->prepare('UPDATE mail_jobs SET status = ?, available_at = ?,
                    last_error = ?, lock_until = NULL, lease_token = NULL,
                    encrypted_payload = CASE WHEN ? THEN NULL ELSE encrypted_payload END
                    WHERE id = ? AND status = \'processing\' AND lease_token = ?');
                $query->execute([
                    $terminal ? 'failed' : 'pending',
                    time() + min(900, 60 * (2 ** ((int) $job['attempt_count'] - 1))) + random_int(0, 15),
                    $error->getMessage(), (int) $terminal, $job['id'], $job['lease_token'],
                ]);
                if ($query->rowCount() > 0) {
                    $summary[$terminal ? 'failed' : 'retried']++;
                }
            }
        }
        $this->app->log('mail.worker_completed', bin2hex(random_bytes(8)));
        return $summary;
    }

    /** @return array{0: array<string, mixed>|null, 1: int} */
    private function claim(): array
    {
        return $this->app->transaction(function (PDO $db): array {
            $now = time();
            $db->prepare("UPDATE mail_jobs SET status = 'cancelled', encrypted_payload = NULL, lease_token = NULL,
                lock_until = NULL WHERE status IN ('pending', 'processing') AND (expires_at <= ?
                OR reset_id IN (SELECT id FROM password_resets WHERE consumed_at IS NOT NULL))")->execute([$now]);
            $exhaustedQuery = $db->prepare("UPDATE mail_jobs SET status = 'failed', encrypted_payload = NULL, lease_token = NULL,
                lock_until = NULL, last_error = 'attempt_limit' WHERE attempt_count >= 5
                AND (status = 'pending' OR (status = 'processing' AND lock_until <= ?))");
            $exhaustedQuery->execute([$now]);
            $exhausted = $exhaustedQuery->rowCount();
            $query = $db->prepare("SELECT * FROM mail_jobs WHERE encrypted_payload IS NOT NULL AND expires_at > ?
                AND ((status = 'pending' AND available_at <= ?) OR (status = 'processing' AND lock_until <= ?))
                ORDER BY available_at, id LIMIT 1");
            $query->execute([$now, $now, $now]);
            $job = $query->fetch();
            if (!$job) {
                return [null, $exhausted];
            }
            $lease = bin2hex(random_bytes(16));
            $db->prepare("UPDATE mail_jobs SET status = 'processing', attempt_count = attempt_count + 1,
                lock_until = ?, lease_token = ? WHERE id = ?")->execute([$now + 120, $lease, $job['id']]);
            $job['attempt_count'] = (int) $job['attempt_count'] + 1;
            $job['lease_token'] = $lease;
            return [$job, $exhausted];
        });
    }
}
