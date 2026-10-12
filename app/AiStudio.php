<?php
declare(strict_types=1);

final class AiStudio
{
    public const PLATFORMS = [
        'blog' => 'Blog', 'content' => 'Konten umum', 'instagram' => 'Instagram',
        'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'facebook' => 'Facebook',
    ];
    public const MEDIA = [
        'text' => 'Teks / caption', 'image' => 'Gambar + caption (brief gambar)',
        'video' => 'Video + caption (skrip / storyboard)', 'audio' => 'Audio + caption (naskah narasi)',
    ];
    public const OUTPUT_FIELDS = [
        'title' => 'Judul', 'body' => 'Artikel / naskah utama', 'caption' => 'Caption / deskripsi',
        'hook' => 'Hook pembuka', 'script' => 'Skrip / storyboard', 'cta' => 'Call to action',
        'hashtags' => 'Hashtag', 'alt_text' => 'Alt text', 'seo_description' => 'Deskripsi SEO',
        'image_brief' => 'Brief gambar (bukan file)', 'video_brief' => 'Brief video (bukan file)',
        'audio_script' => 'Naskah audio (bukan file)',
    ];
    public const STATUSES = [
        'brief' => 'Brief tersimpan', 'queued' => 'Menunggu worker', 'processing' => 'Sedang diproses',
        'generated' => 'Draft dihasilkan — perlu review', 'failed' => 'Generasi gagal',
    ];
    public const ERRORS = [
        'configuration' => 'Konfigurasi Gemini belum lengkap.',
        'credentials' => 'API key ditolak. Periksa izin dan konfigurasi privat.',
        'quota' => 'Kuota / batas Gemini tercapai. Periksa billing sebelum mencoba ulang.',
        'model' => 'Model tidak tersedia. Periksa model pada konfigurasi privat.',
        'provider' => 'Gemini menolak permintaan atau sedang bermasalah.',
        'transport' => 'Koneksi Gemini gagal / timeout. Permintaan mungkin sudah dikenai biaya; periksa sebelum mencoba ulang.',
        'incomplete' => 'Output diblokir atau tidak selesai. Periksa brief sebelum mencoba ulang.',
        'invalid_output' => 'Output tidak memenuhi struktur / kelengkapan yang diperlukan.',
        'expired' => 'Worker terhenti / melewati batas waktu. Periksa kemungkinan biaya sebelum mencoba ulang.',
        'scope' => 'Admin, perusahaan, atau kampanye tidak lagi tersedia untuk generasi.',
    ];

    public function __construct(private readonly App $app)
    {
    }

    private function activeScope(PDO $db, int $actorId, int $companyId, ?int $campaignId): void
    {
        $query = $db->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'system_admin' AND is_active = 1");
        $query->execute([$actorId]);
        if (!$query->fetchColumn()) {
            throw new InvalidArgumentException('Hanya administrator aktif dapat menggunakan Studio AI.');
        }
        $query = $db->prepare("SELECT 1 FROM companies WHERE id = ? AND status = 'active'");
        $query->execute([$companyId]);
        if (!$query->fetchColumn()) {
            throw new InvalidArgumentException('Pilih perusahaan aktif.');
        }
        if ($campaignId !== null) {
            $query = $db->prepare("SELECT 1 FROM campaigns WHERE id = ? AND company_id = ? AND status != 'archived'");
            $query->execute([$campaignId, $companyId]);
            if (!$query->fetchColumn()) {
                throw new InvalidArgumentException('Pilih kampanye nonarsip dari perusahaan yang sama.');
            }
        }
    }

    /** @param array<string, string> $input @param list<string> $platforms */
    public function create(array $input, array $platforms, int $actorId): int
    {
        foreach (['title' => 200, 'brief' => 5000, 'audience' => 500, 'tone' => 200, 'facts' => 3000] as $field => $limit) {
            if (str_contains($input[$field], "\0")) {
                throw new InvalidArgumentException('Brief tidak boleh memuat karakter NUL.');
            }
            $input[$field] = trim($input[$field]);
            if (($field !== 'facts' && $input[$field] === '') || mb_strlen($input[$field]) > $limit
                || str_contains($input[$field], "\0")) {
                throw new InvalidArgumentException('Judul, brief, audiens, atau gaya bahasa wajib diisi dalam batas panjang form; fakta opsional.');
            }
        }
        if ($platforms === [] || count($platforms) > count(self::PLATFORMS) || !array_is_list($platforms)) {
            throw new InvalidArgumentException('Pilih satu atau lebih platform tanpa duplikasi.');
        }
        foreach ($platforms as $platform) {
            if (!is_string($platform) || !isset(self::PLATFORMS[$platform])) {
                throw new InvalidArgumentException('Platform tidak tersedia.');
            }
            if (count(array_unique($platforms)) !== count($platforms)) {
                throw new InvalidArgumentException('Platform tidak boleh duplikat.');
            }
        }
        if (!isset(self::MEDIA[$input['media_type']])
            || !preg_match('/\A[1-9][0-9]{0,8}\z/', $input['company_id'])
            || ($input['campaign_id'] !== '' && !preg_match('/\A[1-9][0-9]{0,8}\z/', $input['campaign_id']))
            || !preg_match('/\A[a-f0-9]{64}\z/', $input['creation_key'])) {
            throw new InvalidArgumentException('Perusahaan, kampanye, jenis media, atau token form tidak valid.');
        }
        return $this->app->transaction(function (PDO $db) use ($input, $platforms, $actorId): int {
            $this->activeScope($db, $actorId, (int) $input['company_id'],
                $input['campaign_id'] === '' ? null : (int) $input['campaign_id']);
            $query = $db->prepare('SELECT id, owner_id FROM ai_drafts WHERE creation_key = ?');
            $query->execute([$input['creation_key']]);
            $existing = $query->fetch();
            if ($existing) {
                if ((int) $existing['owner_id'] !== $actorId) {
                    throw new InvalidArgumentException('Token brief milik pengguna lain.');
                }
                return (int) $existing['id'];
            }
            $db->prepare('INSERT INTO ai_drafts (company_id, campaign_id, owner_id, title, brief, audience, tone,
                facts, platforms, media_type, creation_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) $input['company_id'], $input['campaign_id'] === '' ? null : (int) $input['campaign_id'],
                    $actorId, $input['title'], $input['brief'], $input['audience'], $input['tone'], $input['facts'],
                    json_encode($platforms, JSON_THROW_ON_ERROR), $input['media_type'], $input['creation_key'], time(), time()]);
            $id = (int) $db->lastInsertId();
            $this->app->audit('ai.brief_created', $actorId);
            return $id;
        });
    }

    public function queue(int $id, int $version, int $actorId, bool $consent): void
    {
        if (!$consent) {
            throw new InvalidArgumentException('Setujui pengiriman brief dan fakta publik ke Google Gemini terlebih dahulu.');
        }
        if (!Gemini::configured($this->app)) {
            throw new InvalidArgumentException('Gemini belum dikonfigurasi. Brief tetap tersimpan; generate belum tersedia.');
        }
        $this->app->transaction(function (PDO $db) use ($id, $version, $actorId): void {
            $query = $db->prepare('SELECT * FROM ai_drafts WHERE id = ?');
            $query->execute([$id]);
            $draft = $query->fetch();
            if (!$draft || (int) $draft['version'] !== $version
                || !in_array($draft['status'], ['brief', 'failed'], true) || (int) $draft['attempt_count'] >= 3) {
                throw new InvalidArgumentException('Brief berubah, sudah diproses, atau mencapai tiga percobaan. Muat ulang detail.');
            }
            $this->activeScope($db, $actorId, (int) $draft['company_id'],
                $draft['campaign_id'] === null ? null : (int) $draft['campaign_id']);
            $db->prepare("UPDATE ai_drafts SET status = 'queued', model = ?, consent_at = ?,
                last_error = NULL, version = version + 1, updated_at = ? WHERE id = ?")
                ->execute([$this->app->config['ai']['model'], time(), time(), $id]);
            $this->app->audit('ai.queued', $actorId);
        });
    }

    /** @param array<string, mixed> $result @param list<string> $platforms @return array<string, mixed> */
    public static function validateResult(array $result, array $platforms): array
    {
        if (!is_array($result['variants'] ?? null) || !array_is_list($result['variants'])
            || count($result['variants']) !== count($platforms)) {
            throw new AiFailure('invalid_output');
        }
        $seen = [];
        foreach ($result['variants'] as $variant) {
            if (!is_array($variant) || !is_string($variant['platform'] ?? null)
                || !in_array($variant['platform'], $platforms, true) || in_array($variant['platform'], $seen, true)) {
                throw new AiFailure('invalid_output');
            }
            $seen[] = $variant['platform'];
            foreach (array_keys(self::OUTPUT_FIELDS) as $field) {
                if (!is_string($variant[$field] ?? null) || mb_strlen($variant[$field]) > 30000
                    || str_contains($variant[$field], "\0")) {
                    throw new AiFailure('invalid_output');
                }
            }
            if (trim($variant['title']) === '' || mb_strlen($variant['title']) > 200
                || trim($variant['cta']) === ''
                || (in_array($variant['platform'], ['blog', 'content'], true) && trim($variant['body']) === '')
                || (in_array($variant['platform'], ['instagram', 'youtube', 'tiktok', 'facebook'], true) && trim($variant['caption']) === '')
                || (in_array($variant['platform'], ['youtube', 'tiktok'], true) && (trim($variant['script']) === '' || trim($variant['hook']) === ''))
                || !is_array($variant['checks'] ?? null) || !array_is_list($variant['checks'])
                || $variant['checks'] === [] || count($variant['checks']) > 20) {
                throw new AiFailure('invalid_output');
            }
            foreach ($variant['checks'] as $check) {
                if (!is_string($check) || trim($check) === '' || mb_strlen($check) > 1000 || str_contains($check, "\0")) {
                    throw new AiFailure('invalid_output');
                }
                if (count($variant) !== count(self::OUTPUT_FIELDS) + 2) {
                    throw new AiFailure('invalid_output');
                }
            }
            if (count($result) !== 1) {
                throw new AiFailure('invalid_output');
            }
        }
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 350000) {
            throw new AiFailure('invalid_output');
        }
        return $result;
    }

    public function saveContent(int $id, int $actorId, bool $confirmed): int
    {
        if (!$confirmed) {
            throw new InvalidArgumentException('Konfirmasi bahwa hasil akan disimpan sebagai draft yang masih perlu review.');
        }
        return $this->app->transaction(function (PDO $db) use ($id, $actorId): int {
            $query = $db->prepare('SELECT * FROM ai_drafts WHERE id = ?');
            $query->execute([$id]);
            $draft = $query->fetch();
            if (!$draft || $draft['status'] !== 'generated') {
                throw new InvalidArgumentException('Hasil AI belum tersedia.');
            }
            $this->activeScope($db, $actorId, (int) $draft['company_id'], null);
            if ($draft['content_id'] !== null) {
                return (int) $draft['content_id'];
            }
            $this->activeScope($db, $actorId, (int) $draft['company_id'],
                $draft['campaign_id'] === null ? null : (int) $draft['campaign_id']);
            $result = self::validateResult(json_decode($draft['result_json'], true, 32, JSON_THROW_ON_ERROR),
                json_decode($draft['platforms'], true, 8, JSON_THROW_ON_ERROR));
            $db->prepare("INSERT INTO content_items (company_id, owner_id, title, brief, format, status,
                campaign_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'ai_draft', ?, ?, ?)")
                ->execute([$draft['company_id'], $actorId, $draft['title'], $draft['brief'],
                    $draft['media_type'] === 'audio' ? 'text' : $draft['media_type'],
                    $draft['campaign_id'], time(), time()]);
            $contentId = (int) $db->lastInsertId();
            foreach ($result['variants'] as $variant) {
                $db->prepare('INSERT INTO content_ai_variants (content_item_id, platform, payload_json, model,
                    prompt_version, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$contentId, $variant['platform'], json_encode($variant, JSON_THROW_ON_ERROR),
                        $draft['model'], $draft['prompt_version'], time()]);
            }
            $db->prepare('UPDATE ai_drafts SET content_id = ?, version = version + 1, updated_at = ? WHERE id = ?')
                ->execute([$contentId, time(), $id]);
            $this->app->audit('ai.saved_as_draft', $actorId);
            return $contentId;
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(?int $companyId = null, ?int $id = null): array
    {
        $db = $this->app->db();
        $db->beginTransaction();
        try {
            $companies = (new Dashboard($this->app))->companies();
            $selected = null;
            foreach ($companies as $company) {
                if ((int) $company['id'] === $companyId) {
                    $selected = $company;
                }
            }
            if ($companyId !== null && $selected === null) {
                throw new InvalidArgumentException('Perusahaan tidak tersedia.');
            }
            $select = 'SELECT a.*, co.name AS company_name, co.status AS company_status,
                u.name AS owner_name, ca.name AS campaign_name
                FROM ai_drafts a JOIN companies co ON co.id = a.company_id
                JOIN users u ON u.id = a.owner_id LEFT JOIN campaigns ca ON ca.id = a.campaign_id';
            $query = $db->prepare($select . ($companyId === null ? '' : ' WHERE a.company_id = ?')
                . ' ORDER BY a.updated_at DESC, a.id DESC LIMIT 50');
            $query->execute($companyId === null ? [] : [$companyId]);
            $items = $query->fetchAll();
            $detail = null;
            if ($id !== null) {
                $query = $db->prepare($select . ' WHERE a.id = ?');
                $query->execute([$id]);
                $detail = $query->fetch();
                if (!$detail || ($companyId !== null && (int) $detail['company_id'] !== $companyId)) {
                    throw new InvalidArgumentException('Brief tidak tersedia dalam perusahaan yang dipilih.');
                }
                $detail['platform_list'] = json_decode($detail['platforms'], true, 8, JSON_THROW_ON_ERROR);
                $detail['result'] = $detail['result_json'] === null ? null
                    : self::validateResult(json_decode($detail['result_json'], true, 32, JSON_THROW_ON_ERROR), $detail['platform_list']);
            }
            $campaigns = $db->query('SELECT ca.id, ca.name, ca.company_id, ca.status, co.name AS company_name
                FROM campaigns ca JOIN companies co ON co.id = ca.company_id ORDER BY ca.name, ca.id')->fetchAll();
            $db->commit();
            return ['companies' => $companies, 'campaigns' => $campaigns, 'selected_company' => $selected,
                'items' => $items, 'detail' => $detail, 'configured' => Gemini::configured($this->app)];
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed> $draft */
    public static function export(array $draft): string
    {
        if ($draft['result'] === null) {
            throw new InvalidArgumentException('Belum ada hasil untuk diekspor.');
        }
        $text = $draft['title'] . "\nDRAFT AI — PERLU REVIEW. Tidak mencakup file media atau publikasi.\n";
        foreach ($draft['result']['variants'] as $variant) {
            $text .= "\n=== " . self::PLATFORMS[$variant['platform']] . " ===\n";
            foreach (self::OUTPUT_FIELDS as $field => $label) {
                if ($variant[$field] !== '') {
                    $text .= "\n" . $label . "\n" . $variant[$field] . "\n";
                }
            }
            $text .= "\nPemeriksaan wajib:\n- " . implode("\n- ", $variant['checks']) . "\n";
        }
        return $text;
    }
}

final class AiWorker
{
    public function __construct(private readonly App $app, private readonly AiTextProvider $provider)
    {
    }

    /** @return array{generated:int, failed:int} */
    public function run(int $maxJobs = 1): array
    {
        if ($maxJobs < 1 || $maxJobs > 3) {
            throw new InvalidArgumentException('Worker memproses 1–3 brief per eksekusi.');
        }
        $summary = ['generated' => 0, 'failed' => 0];
        $expired = $this->app->db()->prepare("UPDATE ai_drafts SET status = 'failed', last_error = 'expired',
            lease_token = NULL, lock_until = NULL, version = version + 1, updated_at = ?
            WHERE status = 'processing' AND lock_until < ?");
        $expired->execute([time(), time()]);
        $summary['failed'] += $expired->rowCount();
        if ($expired->rowCount() > 0) {
            $this->app->log('ai.worker_expired', 'count-' . $expired->rowCount());
        }
        for ($i = 0; $i < $maxJobs; $i++) {
            $draft = $this->app->transaction(function (PDO $db): ?array {
                $draft = $db->query("SELECT a.*, co.name AS company_name, co.status AS company_status,
                    u.is_active, u.role, ca.status AS campaign_status FROM ai_drafts a
                    JOIN companies co ON co.id = a.company_id JOIN users u ON u.id = a.owner_id
                    LEFT JOIN campaigns ca ON ca.id = a.campaign_id WHERE a.status = 'queued' ORDER BY a.id LIMIT 1")->fetch();
                if (!$draft) {
                    return null;
                }
                $draft['lease_token'] = bin2hex(random_bytes(32));
                $db->prepare("UPDATE ai_drafts SET status = 'processing', attempt_count = attempt_count + 1,
                    lease_token = ?, lock_until = ?, version = version + 1, updated_at = ? WHERE id = ?")
                    ->execute([$draft['lease_token'], time() + 180, time(), $draft['id']]);
                $draft['platforms'] = json_decode($draft['platforms'], true, 8, JSON_THROW_ON_ERROR);
                return $draft;
            });
            if ($draft === null) {
                break;
            }
            try {
                if ((int) $draft['is_active'] !== 1 || $draft['role'] !== 'system_admin'
                    || $draft['company_status'] !== 'active'
                    || ($draft['campaign_id'] !== null && $draft['campaign_status'] === 'archived')) {
                    throw new AiFailure('scope');
                }
                $result = AiStudio::validateResult($this->provider->generate($draft), $draft['platforms']);
                $mediaField = match ($draft['media_type']) {
                    'image' => 'image_brief', 'video' => 'video_brief', 'audio' => 'audio_script', default => null,
                };
                if ($mediaField !== null) {
                    foreach ($result['variants'] as $variant) {
                        if (trim($variant[$mediaField]) === '') {
                            throw new AiFailure('invalid_output');
                        }
                    }
                }
                $query = $this->app->db()->prepare("UPDATE ai_drafts SET status = 'generated', result_json = ?,
                    last_error = NULL, lease_token = NULL, lock_until = NULL, version = version + 1, updated_at = ?
                    WHERE id = ? AND status = 'processing' AND lease_token = ?");
                $query->execute([json_encode($result, JSON_THROW_ON_ERROR), time(), $draft['id'], $draft['lease_token']]);
                if ($query->rowCount() !== 1) {
                    throw new RuntimeException('AI worker lost its lease; result was not saved.');
                }
                $summary['generated']++;
                $this->app->audit('ai.generated', (int) $draft['owner_id']);
            } catch (AiFailure $error) {
                if (!isset(AiStudio::ERRORS[$error->getMessage()])) {
                    throw $error;
                }
                $query = $this->app->db()->prepare("UPDATE ai_drafts SET status = 'failed', last_error = ?,
                    lease_token = NULL, lock_until = NULL, version = version + 1, updated_at = ?
                    WHERE id = ? AND status = 'processing' AND lease_token = ?");
                $query->execute([$error->getMessage(), time(), $draft['id'], $draft['lease_token']]);
                $summary['failed'] += $query->rowCount();
                $this->app->log('ai.generation_failed', 'draft-' . $draft['id'] . '-' . $error->getMessage());
            }
        }
        return $summary;
    }
}
