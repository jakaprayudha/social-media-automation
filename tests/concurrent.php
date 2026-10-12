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
} elseif (($argv[1] ?? '') === 'calendar') {
    echo json_encode((new ObservanceAutomation($app))->generate(2027), JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'ai') {
    require __DIR__ . '/AiFixture.php';
    echo json_encode((new AiWorker($app, new AiFixture()))->run(), JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown test operation.');
}
