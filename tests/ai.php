<?php
declare(strict_types=1);
require_once __DIR__ . '/AiFixture.php';

/** @param list<int> $companyIds */
function testAiStudio(App $app, int $userId, array $companyIds, string $adminUrl, string $jar, string $directory): void
{
    $url = $adminUrl . '&section=ai';
    $studio = new AiStudio($app);
    $page = request($url, $jar);
    check($page['status'] === 200 && str_contains($page['body'], 'Gemini belum dikonfigurasi')
        && str_contains($page['body'], 'Belum ada brief AI')
        && !Gemini::configured($app), 'Studio has honest empty state and no configured provider');
    check(request($url, $directory . '/ai-guest.txt')['status'] === 303, 'Studio requires authentication');
    foreach (['company[]=1', 'company=999999', 'id[]=1', 'id=999999', 'download=1'] as $bad) {
        check(request($url . '&' . $bad, $jar)['status'] === 400, 'Reject AI filter or export ' . $bad);
    }
    $input = ['title' => '<script>Studio topic</script>', 'brief' => 'Public marketing brief',
        'audience' => 'UMKM', 'tone' => 'Ramah', 'facts' => 'Public verified fact',
        'company_id' => (string) $companyIds[0], 'campaign_id' => '', 'media_type' => 'video',
        'creation_key' => bin2hex(random_bytes(32))];
    $platforms = array_keys(AiStudio::PLATFORMS);
    foreach ([
        ['title' => ''], ['title' => str_repeat('x', 201)], ['brief' => ''],
        ['brief' => str_repeat('x', 5001)], ['audience' => ''], ['tone' => ''],
        ['facts' => str_repeat('x', 3001)], ['facts' => "\0"], ['media_type' => 'fake'],
        ['company_id' => '999999'], ['campaign_id' => '999999'], ['creation_key' => 'bad'],
    ] as $invalid) {
        try {
            $studio->create(array_replace($input, $invalid), $platforms, $userId);
            check(false, 'Reject AI brief ' . array_key_first($invalid));
        } catch (InvalidArgumentException) {
            check(true, 'Reject AI brief ' . array_key_first($invalid));
        }
    }
    foreach ([[], ['instagram', 'instagram'], ['unknown'], [['instagram']]] as $invalidPlatforms) {
        try {
            $studio->create($input, $invalidPlatforms, $userId);
            check(false, 'Reject invalid AI platform set');
        } catch (InvalidArgumentException) {
            check(true, 'Reject invalid AI platform set');
        }
    }
    $id = $studio->create($input, $platforms, $userId);
    check($studio->create($input, $platforms, $userId) === $id && count($studio->snapshot()['items']) === 1,
        'Studio brief creation is idempotent');
    check($studio->snapshot($companyIds[1])['items'] === []
        && request($url . '&company=' . $companyIds[1] . '&id=' . $id, $jar)['status'] === 400,
        'Studio scopes company list and rejects cross-company detail');
    $detailPage = request($url . '&id=' . $id, $jar);
    check(str_contains($detailPage['body'], '&lt;script&gt;Studio topic')
        && !str_contains($detailPage['body'], '<script>Studio topic')
        && str_contains($detailPage['body'], 'type="submit" disabled'),
        'Studio escapes saved brief and disables generate without configuration');
    $generatePost = ['action' => 'generate_ai', 'draft_id' => (string) $id, 'version' => '1',
        'consent' => '1', 'csrf' => csrfFrom($detailPage['body'])];
    check(request($url . '&id=' . $id, $jar, array_replace($generatePost, ['csrf' => 'bad']))['status'] === 419,
        'AI mutations require CSRF');
    check(request($url . '&id=' . $id, $jar, $generatePost)['status'] === 422
        && $studio->snapshot(null, $id)['detail']['status'] === 'brief',
        'Server blocks unconfigured generation without changing brief');
    foreach (['model' => 'invalid/model', 'timeout_seconds' => 1, 'api_key' => "bad\r\nkey", 'provider' => 'fake'] as $field => $value) {
        $badConfig = $app->config;
        $badConfig['ai'] = ['provider' => 'gemini', 'api_key' => str_repeat('x', 30),
            'model' => 'gemini-test', 'timeout_seconds' => 30];
        $badConfig['ai'][$field] = $value;
        check(!Gemini::configured(new App($badConfig)), 'Reject unsafe Gemini configuration ' . $field);
    }
    $mockConfig = $app->config;
    $mockConfig['ai'] = ['provider' => 'gemini', 'api_key' => str_repeat('x', 30), 'model' => 'gemini-test', 'timeout_seconds' => 30];
    $dottedKeyConfig = $mockConfig;
    $dottedKeyConfig['ai']['api_key'] = 'AQ.' . str_repeat('x', 40);
    check(Gemini::configured(new App($dottedKeyConfig)),
        'Gemini accepts dotted API key format without exposing the key');
    $mockApp = new App($mockConfig);
    $mockStudio = new AiStudio($mockApp);
    try {
        $mockStudio->queue($id, 1, $userId, false);
        check(false, 'AI requires explicit data-sharing consent');
    } catch (InvalidArgumentException) {
        check(true, 'AI requires explicit data-sharing consent');
    }
    $mockStudio->queue($id, 1, $userId, true);
    try {
        $mockStudio->queue($id, 1, $userId, true);
        check(false, 'Repeated generation cannot duplicate queued work');
    } catch (InvalidArgumentException) {
        check(true, 'Repeated generation cannot duplicate queued work');
    }
    $fixture = new AiFixture();
    $worker = new AiWorker($mockApp, $fixture);
    check($worker->run() === ['generated' => 1, 'failed' => 0] && $fixture->calls === 1
        && $worker->run() === ['generated' => 0, 'failed' => 0],
        'Worker processes queued brief once without making external API requests in tests');
    $generated = $mockStudio->snapshot(null, $id)['detail'];
    check($generated['status'] === 'generated' && count($generated['result']['variants']) === 6
        && $generated['model'] === 'gemini-test' && $generated['consent_at'] !== null,
        'Studio stores exact six platform outputs and model/consent metadata');
    $payload = Gemini::payload(array_replace($generated, ['platforms' => $platforms]));
    $payloadText = $payload['contents'][0]['parts'][0]['text'];
    check($payload['generationConfig']['responseMimeType'] === 'application/json'
        && $payload['generationConfig']['responseJsonSchema']['properties']['variants']['items']['properties']['platform']['enum'] === $platforms
        && !str_contains($payloadText, 'gemini-test') && !str_contains($payloadText, str_repeat('x', 30))
        && !str_contains($payloadText, 'owner_name'),
        'Gemini payload uses structured schema and includes only intended public brief context');
    $response = ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
        ['text' => 'Do not expose reasoning', 'thought' => true],
        ['text' => json_encode($generated['result'], JSON_THROW_ON_ERROR)],
    ]]]]];
    check(Gemini::decode(json_encode($response, JSON_THROW_ON_ERROR), $platforms) === $generated['result'],
        'Gemini adapter parses complete structured output and excludes thought text');
    foreach (['not json', 'null', '{}', '{"candidates":[{"finishReason":"MAX_TOKENS"}]}',
        '{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"bad json"}]}}]}'] as $badResponse) {
        try {
            Gemini::decode($badResponse, $platforms);
            check(false, 'Reject incomplete or malformed Gemini response');
        } catch (AiFailure) {
            check(true, 'Reject incomplete or malformed Gemini response');
        }
    }
    foreach (['variants', 'caption', 'script', 'checks', 'title', 'platform'] as $badField) {
        $badResult = $generated['result'];
        if ($badField === 'variants') {
            array_pop($badResult['variants']);
        } elseif ($badField === 'checks') {
            $badResult['variants'][0]['checks'] = [];
        } elseif ($badField === 'platform') {
            $badResult['variants'][0]['platform'] = 'instagram';
        } elseif ($badField === 'caption') {
            $badResult['variants'][2]['caption'] = '';
        } elseif ($badField === 'script') {
            $badResult['variants'][3]['script'] = '';
        } else {
            $badResult['variants'][0]['title'] = str_repeat('x', 201);
        }
        try {
            AiStudio::validateResult($badResult, $platforms);
            check(false, 'Reject incomplete AI result ' . $badField);
        } catch (AiFailure) {
            check(true, 'Reject incomplete AI result ' . $badField);
        }
    }
    $resultPage = request($url . '&id=' . $id, $jar);
    check(str_contains($resultPage['body'], '&lt;script&gt;Fixture article')
        && !str_contains($resultPage['body'], '<script>Fixture article')
        && substr_count($resultPage['body'], 'class="ai-result"') === 6,
        'Studio renders escaped per-platform results without executing model output');
    $download = request($url . '&id=' . $id . '&download=1', $jar);
    check($download['status'] === 200 && str_contains($download['headers'], 'text/plain')
        && str_contains($download['headers'], 'attachment; filename="studio-ai-')
        && str_contains($download['body'], 'DRAFT AI') && str_contains($download['body'], 'TikTok'),
        'Studio exports a real text package with review disclaimer');
    try {
        $mockStudio->saveContent($id, $userId, false);
        check(false, 'Saving generated content requires confirmation');
    } catch (InvalidArgumentException) {
        check(true, 'Saving generated content requires confirmation');
    }
    $savePost = ['action' => 'save_ai_content', 'draft_id' => (string) $id, 'confirm_draft' => '1',
        'csrf' => csrfFrom($resultPage['body'])];
    check(request($url . '&id=' . $id, $jar, $savePost)['status'] === 303,
        'HTTP result saves as content without calling provider again');
    $saved = $studio->snapshot(null, $id)['detail'];
    $contentId = (int) $saved['content_id'];
    $content = (new ContentLibrary($app))->snapshot(null, 'all', null, '', 1, $contentId)['detail'];
    check($content['status'] === 'ai_draft' && $content['scheduled_at'] === null && $content['planned_at'] === null
        && (int) $content['ai_draft_id'] === $id && $fixture->calls === 1
        && $mockStudio->saveContent($id, $userId, true) === $contentId
        && (int) $app->db()->query('SELECT COUNT(*) FROM content_ai_variants WHERE content_item_id = ' . $contentId)->fetchColumn() === 6,
        'Saving is idempotent keeps six variants and never approves schedules or publishes');
    check(str_contains(request($adminUrl . '&section=content&id=' . $contentId, $jar)['body'], 'Lihat hasil AI per platform'),
        'Content detail links back to full Studio output');
    $failedId = $mockStudio->create(array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), ['instagram'], $userId);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $current = $mockStudio->snapshot(null, $failedId)['detail'];
        $mockStudio->queue($failedId, (int) $current['version'], $userId, true);
        check((new AiWorker($mockApp, new AiFixture('quota')))->run() === ['generated' => 0, 'failed' => 1],
            'Failed generation records explicit sanitized error attempt ' . $attempt);
    }
    $failed = $mockStudio->snapshot(null, $failedId)['detail'];
    check($failed['last_error'] === 'quota' && $failed['attempt_count'] === 3 && $failed['result'] === null,
        'Failure cannot masquerade as generated output');
    try {
        $mockStudio->queue($failedId, (int) $failed['version'], $userId, true);
        check(false, 'AI manual retries capped at three');
    } catch (InvalidArgumentException) {
        check(true, 'AI manual retries capped at three');
    }
    $expiredId = $mockStudio->create(array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), ['instagram'], $userId);
    $app->db()->prepare("UPDATE ai_drafts SET status = 'processing', lock_until = ?, lease_token = 'old' WHERE id = ?")
        ->execute([time() - 1, $expiredId]);
    check($worker->run()['failed'] === 1 && $mockStudio->snapshot(null, $expiredId)['detail']['last_error'] === 'expired',
        'Expired jobs become visible failures rather than silent automatic retries');
    $scopeId = $mockStudio->create(array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), ['instagram'], $userId);
    $mockStudio->queue($scopeId, 1, $userId, true);
    $app->db()->prepare("UPDATE companies SET status = 'inactive' WHERE id = ?")->execute([$companyIds[0]]);
    check($worker->run()['failed'] === 1 && $mockStudio->snapshot(null, $scopeId)['detail']['last_error'] === 'scope',
        'Worker rechecks company before sending any data to provider');
    $app->db()->prepare("UPDATE companies SET status = 'active' WHERE id = ?")->execute([$companyIds[0]]);
    $httpPage = request($url, $jar);
    preg_match('/name="creation_key" value="([a-f0-9]{64})"/', $httpPage['body'], $key);
    $httpInput = array_replace($input, ['action' => 'create_ai_brief', 'platforms' => ['blog', 'youtube'],
        'creation_key' => $key[1], 'csrf' => csrfFrom($httpPage['body'])]);
    check(request($url, $jar, array_replace($httpInput, ['platforms' => 'instagram']))['status'] === 422
        && request($url, $jar, array_replace($httpInput, ['platforms' => [['instagram']]]))['status'] === 422,
        'Studio rejects malformed platform POST values without crashing');
    check(request($url, $jar, $httpInput)['status'] === 303 && request($url, $jar, $httpInput)['status'] === 422,
        'HTTP Studio creates brief once and rejects used creation token');
    $concurrentId = $mockStudio->create(array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), ['facebook'], $userId);
    $mockStudio->queue($concurrentId, 1, $userId, true);
    $concurrentConfigPath = $directory . '/ai-config.php';
    file_put_contents($concurrentConfigPath, '<?php return ' . var_export($mockConfig, true) . ';');
    $runs = array_map(fn (string $output): array => json_decode($output, true, 8, JSON_THROW_ON_ERROR),
        concurrent('ai', $concurrentConfigPath));
    check(array_sum(array_column($runs, 'generated')) === 1 && array_sum(array_column($runs, 'failed')) === 0
        && $mockStudio->snapshot(null, $concurrentId)['detail']['attempt_count'] === 1,
        'Parallel AI workers claim same job once without duplicate generation');
    foreach (['image', 'video', 'audio'] as $media) {
        $mediaId = $mockStudio->create(array_replace($input, ['media_type' => $media,
            'creation_key' => bin2hex(random_bytes(32))]), ['instagram'], $userId);
        $mockStudio->queue($mediaId, 1, $userId, true);
        check((new AiWorker($mockApp, new AiFixture(null, true)))->run()['failed'] === 1
            && $mockStudio->snapshot(null, $mediaId)['detail']['last_error'] === 'invalid_output',
            'Missing ' . $media . ' brief cannot count as generated success');
    }
    $audioId = $mockStudio->create(array_replace($input, ['media_type' => 'audio',
        'creation_key' => bin2hex(random_bytes(32))]), ['youtube'], $userId);
    $mockStudio->queue($audioId, 1, $userId, true);
    $worker->run();
    $audioContentId = $mockStudio->saveContent($audioId, $userId, true);
    check((new ContentLibrary($app))->snapshot(null, 'all', null, '', 1, $audioContentId)['detail']['format'] === 'text',
        'Audio narration is saved as text rather than claiming an audio file exists');
    $campaignInput = ['company_id' => (string) $companyIds[0], 'name' => 'AI campaign',
        'objective' => '', 'theme' => '', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        'status' => 'active', 'creation_key' => bin2hex(random_bytes(32))];
    $campaignService = new Campaigns($app);
    $aiCampaignId = $campaignService->save($campaignInput, $userId);
    try {
        $mockStudio->create(array_replace($input, ['company_id' => (string) $companyIds[1],
            'campaign_id' => (string) $aiCampaignId, 'creation_key' => bin2hex(random_bytes(32))]), ['blog'], $userId);
        check(false, 'Studio rejects cross-company campaign');
    } catch (InvalidArgumentException) {
        check(true, 'Studio rejects cross-company campaign');
    }
    $campaignDraftId = $mockStudio->create(array_replace($input, ['campaign_id' => (string) $aiCampaignId,
        'creation_key' => bin2hex(random_bytes(32))]), ['facebook'], $userId);
    $mockStudio->queue($campaignDraftId, 1, $userId, true);
    $worker->run();
    $campaignService->archive($aiCampaignId, 1, $userId);
    try {
        $mockStudio->saveContent($campaignDraftId, $userId, true);
        check(false, 'Archived campaign cannot receive newly generated content');
    } catch (InvalidArgumentException) {
        check(true, 'Archived campaign cannot receive newly generated content');
    }
    $inactiveId = $mockStudio->create(array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), ['blog'], $userId);
    $mockStudio->queue($inactiveId, 1, $userId, true);
    $app->db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
    $beforeInactive = $fixture->calls;
    check($worker->run()['failed'] === 1 && $fixture->calls === $beforeInactive,
        'Inactive owner cannot trigger external generation');
    try {
        $mockStudio->create(array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), ['blog'], $userId);
        check(false, 'Inactive actor cannot create AI brief');
    } catch (InvalidArgumentException) {
        check(true, 'Inactive actor cannot create AI brief');
    }
    $app->db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$userId]);
    $httpGenerateId = $mockStudio->create(array_replace($input, ['creation_key' => bin2hex(random_bytes(32))]), ['instagram'], $userId);
    $webConfigPath = $directory . '/test-config.php';
    file_put_contents($webConfigPath, '<?php return ' . var_export($mockConfig, true) . ';');
    try {
        $configuredPage = request($url . '&id=' . $httpGenerateId, $jar);
        $httpGenerate = ['action' => 'generate_ai', 'draft_id' => (string) $httpGenerateId,
            'version' => '1', 'csrf' => csrfFrom($configuredPage['body'])];
        check($configuredPage['status'] === 200 && str_contains($configuredPage['body'], 'Gemini terkonfigurasi'),
            'HTTP Studio recognizes configured Gemini (status ' . $configuredPage['status']
                . ', unconfigured=' . (int) str_contains($configuredPage['body'], 'Gemini belum dikonfigurasi') . ')');
        $noConsent = request($url . '&id=' . $httpGenerateId, $jar, $httpGenerate);
        check($noConsent['status'] === 422,
            'Configured Studio still requires explicit consent via HTTP (status ' . $noConsent['status'] . ')');
        $httpGenerate['consent'] = '1';
        check(request($url . '&id=' . $httpGenerateId, $jar, $httpGenerate)['status'] === 303
            && request($url . '&id=' . $httpGenerateId, $jar, $httpGenerate)['status'] === 422,
            'HTTP generation queues once and rejects stale double submit');
        check($worker->run()['generated'] === 1, 'HTTP queued draft reaches fixture worker successfully');
    } finally {
        file_put_contents($webConfigPath, '<?php return ' . var_export($app->config, true) . ';');
    }
    $beforeMigration = (int) $app->db()->query('SELECT COUNT(*) FROM ai_drafts')->fetchColumn();
    $app->migrate();
    check((int) $app->db()->query('SELECT COUNT(*) FROM ai_drafts')->fetchColumn() === $beforeMigration,
        'AI migration rerun preserves briefs and generated content');
}
