<?php
declare(strict_types=1);

final class ImageFixture implements AiImageProvider
{
    public int $calls = 0;

    public function __construct(private readonly bool $fail = false)
    {
    }

    public static function png(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\x80\x80\x80", $width), $height)))
            . $chunk('IEND', '');
    }

    public function generate(array $image): array
    {
        $this->calls++;
        if ($this->fail) {
            throw new AiFailure('quota');
        }
        [$w, $h] = array_map('intval', explode(':', $image['aspect_ratio']));
        return GeminiImage::validateBytes(self::png($w * 10, $h * 10), 'image/png', $image['aspect_ratio']);
    }
}

function testAiImages(App $app, int $userId, string $adminUrl, string $jar): void
{
    $config = $app->config;
    $config['ai'] = ['provider' => 'gemini', 'api_key' => str_repeat('x', 30), 'model' => 'gemini-test',
        'image_model' => 'gemini-2.5-flash-image', 'timeout_seconds' => 30];
    $mockApp = new App($config);
    $draft = $app->db()->query("SELECT id, content_id, company_id FROM ai_drafts WHERE status = 'generated'
        AND content_id IS NOT NULL ORDER BY id LIMIT 1")->fetch();
    $draftId = (int) $draft['id'];
    $service = new AiImages($mockApp);
    $fixture = new ImageFixture();
    $input = ['platform' => 'instagram', 'prompt' => 'A public visual brief with space for headline',
        'style' => 'photo', 'aspect_ratio' => '1:1', 'creation_key' => bin2hex(random_bytes(32))];
    $url = $adminUrl . '&section=ai&id=' . $draftId;
    check(str_contains(request($url, $jar)['body'], 'Generate gambar dari brief'),
        'Studio offers image action underneath each platform visual brief');
    foreach ([['prompt' => ''], ['prompt' => str_repeat('x', 4001)], ['prompt' => "\0bad"],
        ['aspect_ratio' => '2:7'], ['style' => 'bad'], ['platform' => 'bad'], ['creation_key' => 'bad']] as $bad) {
        try {
            $service->generate($draftId, $userId, array_replace($input, $bad), true, $fixture);
            check(false, 'Reject invalid image ' . array_key_first($bad));
        } catch (InvalidArgumentException) {
            check(true, 'Reject invalid image ' . array_key_first($bad));
        }
    }
    try {
        $service->generate($draftId, $userId, $input, false, $fixture);
        check(false, 'Image generation requires consent');
    } catch (InvalidArgumentException) {
        check(true, 'Image generation requires consent');
    }
    check($fixture->calls === 0, 'Invalid image requests never call provider');
    $imageIds = [];
    foreach (array_keys(AiImages::RATIOS) as $ratio) {
        $key = bin2hex(random_bytes(32));
        $requestInput = array_replace($input, ['aspect_ratio' => $ratio, 'creation_key' => $key]);
        $id = $service->generate($draftId, $userId, $requestInput, true, $fixture);
        $imageIds[] = $id;
        $before = $fixture->calls;
        check($service->generate($draftId, $userId, $requestInput, true, $fixture) === $id
            && $fixture->calls === $before, 'Same image token does not incur duplicate generation for ' . $ratio);
        $file = $service->file($draftId, $id);
        $info = getimagesize($file['path']);
        [$w, $h] = array_map('intval', explode(':', $ratio));
        check($info[0] === $w * 10 && $info[1] === $h * 10
            && substr(sprintf('%o', fileperms($file['path'])), -4) === '0600',
            'Generated image persists privately with exact requested ratio ' . $ratio);
    }
    $id = $imageIds[0];
    $preview = request($url . '&image=' . $id, $jar);
    check($preview['status'] === 200 && str_contains($preview['headers'], 'Content-Type: image/png')
        && str_contains($preview['headers'], 'inline; filename=')
        && $preview['body'] === file_get_contents($service->file($draftId, $id)['path']),
        'Authenticated preview serves exact image bytes');
    check(request($url . '&image=' . $id, $app->storage . '/image-guest.txt')['status'] === 303,
        'Guest cannot access generated image bytes');
    $download = request($url . '&image=' . $id . '&image_download=1', $jar);
    check($download['status'] === 200 && str_contains($download['headers'], 'attachment; filename='),
        'Image download uses authenticated attachment route');
    check(request($url . '&image=../../config/local.php', $jar)['status'] === 400
        && request($url . '&image[]=1', $jar)['status'] === 400
        && request($adminUrl . '&section=ai&id=999999&image=' . $id, $jar)['status'] === 400,
        'Image route rejects malformed and incorrect draft scopes');
    try {
        $service->file($draftId + 1, $id);
        check(false, 'Cannot retrieve image via another draft');
    } catch (InvalidArgumentException) {
        check(true, 'Cannot retrieve image via another draft');
    }
    $imagePost = ['action' => 'generate_image', 'draft_id' => (string) $draftId, 'csrf' => 'bad'];
    check(request($url, $jar, $imagePost)['status'] === 419, 'Image action requires CSRF');
    $page = request($url, $jar);
    preg_match('/name="image_creation_key" value="([a-f0-9]{64})"/', $page['body'], $key);
    $post = ['action' => 'generate_image', 'draft_id' => (string) $draftId,
        'csrf' => csrfFrom($page['body']), 'image_platform' => 'instagram', 'image_prompt' => 'A public image',
        'image_style' => 'photo', 'image_aspect_ratio' => '1:1', 'image_creation_key' => $key[1], 'image_consent' => '1'];
    check(request($url, $jar, $post)['status'] === 422, 'Server rejects image generation without configured key');
    $attach = ['action' => 'attach_image', 'draft_id' => (string) $draftId, 'image_id' => (string) $id,
        'csrf' => csrfFrom($page['body'])];
    check(request($url, $jar, $attach)['status'] === 422, 'Attaching image requires confirmation');
    $attach['confirm_image'] = '1';
    check(request($url, $jar, $attach)['status'] === 303
        && request($url, $jar, $attach)['status'] === 303
        && count((new ContentLibrary($app))->snapshot(null, 'all', null, '', 1, (int) $draft['content_id'])['images']) === 1,
        'Image association is idempotent and visible as content asset');
    check(str_contains(request($adminUrl . '&section=content&id=' . (int) $draft['content_id'], $jar)['body'], 'Aset gambar AI:'),
        'Saved content shows linked image preview');
    $failedId = $service->generate($draftId, $userId,
        array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), true, new ImageFixture(true));
    $rows = $service->listing($draftId);
    check($rows[0]['id'] === $failedId && $rows[0]['status'] === 'failed'
        && $rows[0]['last_error'] === 'quota' && $rows[0]['filename'] === null,
        'Failed image generation stores error and no fake preview');
    $response = ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
        ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(ImageFixture::png(40, 50))]],
    ]]]]];
    $decoded = GeminiImage::decode(json_encode($response, JSON_THROW_ON_ERROR), '4:5');
    check($decoded['width'] === 40 && $decoded['height'] === 50, 'Gemini image adapter decodes and validates binary response');
    $payload = GeminiImage::payload(['prompt' => 'Public brief', 'style' => 'Minimal',
        'title' => 'Topic', 'brief' => 'Context', 'platform' => 'instagram', 'aspect_ratio' => '4:5']);
    check($payload['generationConfig']['imageConfig']['aspectRatio'] === '4:5'
        && $payload['generationConfig']['responseModalities'] === ['TEXT', 'IMAGE'],
        'Gemini image request uses requested aspect ratio and image output modality');
    $app->db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
    try {
        $service->generate($draftId, $userId, array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), true, $fixture);
        check(false, 'Inactive admin cannot generate paid image');
    } catch (InvalidArgumentException) {
        check(true, 'Inactive admin cannot generate paid image');
    }
    $app->db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$userId]);
    $app->db()->prepare("UPDATE companies SET status = 'inactive' WHERE id = ?")->execute([$draft['company_id']]);
    try {
        $service->generate($draftId, $userId, array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), true, $fixture);
        check(false, 'Inactive company cannot generate paid image');
    } catch (InvalidArgumentException) {
        check(true, 'Inactive company cannot generate paid image');
    }
    $app->db()->prepare("UPDATE companies SET status = 'active' WHERE id = ?")->execute([$draft['company_id']]);
    foreach (['not json', '{}', '{"candidates":[{"finishReason":"MAX_TOKENS"}]}',
        '{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"No image"}]}}]}'] as $invalid) {
        try {
            GeminiImage::decode($invalid, '1:1');
            check(false, 'Reject malformed or incomplete image response');
        } catch (AiFailure) {
            check(true, 'Reject malformed or incomplete image response');
        }
    }
    foreach ([['bad', 'image/png', '1:1'], [ImageFixture::png(10, 10), 'image/jpeg', '1:1'],
        [ImageFixture::png(10, 10), 'image/png', '9:16']] as [$bytes, $mime, $ratio]) {
        try {
            GeminiImage::validateBytes($bytes, $mime, $ratio);
            check(false, 'Reject image bytes MIME or ratio mismatch');
        } catch (AiFailure) {
            check(true, 'Reject image bytes MIME or ratio mismatch');
        }
    }
    for ($i = count($rows); $i < 10; $i++) {
        $service->generate($draftId, $userId, array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), true, new ImageFixture(true));
    }
    try {
        $service->generate($draftId, $userId, array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), true, $fixture);
        check(false, 'Image generation caps paid requests per brief');
    } catch (InvalidArgumentException) {
        check(true, 'Image generation caps paid requests per brief');
    }
}
