<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
umask(0077);
$app = bootstrap();
if (($argv[1] ?? '') === 'reset') {
    $token = (string) getenv('TEST_RESET_TOKEN');
    echo (new Auth($app))->resetPassword($token, 'concurrent-passphrase-2026') ? '1' : '0';
} elseif (($argv[1] ?? '') === 'mail') {
    echo json_encode((new MailWorker($app))->run(), JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown test operation.');
}
