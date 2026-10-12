<?php
declare(strict_types=1);

final class ContentEditor
{
    public const FORMATS = [
        'unspecified' => 'Belum ditentukan', 'image' => 'Gambar',
        'carousel' => 'Carousel', 'video' => 'Video', 'text' => 'Teks',
    ];
    public const INITIAL_STATUSES = ['idea' => 'Ide', 'brief' => 'Brief'];

    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string, string> $input */
    public function create(array $input, int $ownerId): int
    {
        return $this->save($input, $ownerId);
    }

    /** @param array<string, string> $input */
    public function update(array $input, int $actorId, int $id, int $version): int
    {
        return $this->save($input, $actorId, $id, $version);
    }

    /** @param array<string, string> $input */
    private function save(array $input, int $ownerId, ?int $id = null, ?int $version = null): int
    {
        $title = trim($input['title']);
        $brief = trim($input['brief']);
        if ($title === '' || mb_strlen($title) > 200 || mb_strlen($brief) > 5000
            || str_contains($title . $brief, "\0")) {
            throw new InvalidArgumentException('Judul wajib diisi (maksimal 200 karakter), brief maksimal 5.000 karakter.');
        }
        if (!preg_match('/\A[1-9][0-9]{0,8}\z/', $input['company_id'])
            || !isset(self::FORMATS[$input['format']]) || !isset(self::INITIAL_STATUSES[$input['status']])
            || ($id === null && !preg_match('/\A[a-f0-9]{64}\z/', $input['creation_key']))) {
            throw new InvalidArgumentException('Perusahaan, format, status awal, atau token form tidak valid.');
        }
        return $this->app->transaction(function (PDO $db) use ($input, $ownerId, $title, $brief, $id, $version): int {
            $owner = $db->prepare("SELECT 1 FROM users WHERE id = ? AND is_active = 1 AND role = 'system_admin'");
            $owner->execute([$ownerId]);
            if (!$owner->fetchColumn()) {
                throw new InvalidArgumentException('Pemilik harus administrator aktif.');
            }
            $current = null;
            if ($id !== null) {
                $query = $db->prepare('SELECT * FROM content_items WHERE id = ? AND deleted_at IS NULL');
                $query->execute([$id]);
                $current = $query->fetch();
                if (!$current || !isset(self::INITIAL_STATUSES[$current['status']])) {
                    throw new InvalidArgumentException('Hanya konten Ide atau Brief yang tersedia dapat diedit.');
                }
                if ((int) $current['version'] !== $version) {
                    throw new InvalidArgumentException('Konten telah berubah. Muat ulang detail sebelum mengedit kembali.');
                }
                if ((int) $current['company_id'] !== (int) $input['company_id']
                    || (string) ($current['campaign_id'] ?? '') !== ($input['campaign_id'] ?? '')) {
                    throw new InvalidArgumentException('Perusahaan dan kaitan kampanye tidak dapat diganti melalui edit konten.');
                }
            }
            $company = $db->prepare("SELECT timezone FROM companies WHERE id = ? AND status = 'active'");
            $company->execute([(int) $input['company_id']]);
            $timezone = $company->fetchColumn();
            if ($timezone === false) {
                throw new InvalidArgumentException('Pilih perusahaan yang terdaftar dan aktif.');
            }
            $campaignId = $input['campaign_id'] ?? '';
            if ($campaignId !== '' && $current === null) {
                if (!preg_match('/\A[1-9][0-9]{0,8}\z/', $campaignId)) {
                    throw new InvalidArgumentException('Kampanye tidak valid.');
                }
                $campaign = $db->prepare("SELECT 1 FROM campaigns WHERE id = ? AND company_id = ? AND status != 'archived'");
                $campaign->execute([(int) $campaignId, (int) $input['company_id']]);
                if (!$campaign->fetchColumn()) {
                    throw new InvalidArgumentException('Pilih kampanye yang tidak diarsipkan dari perusahaan yang sama.');
                }
            }
            $plannedAt = null;
            if ($input['planned_at'] !== '') {
                if (!preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $input['planned_at'])) {
                    throw new InvalidArgumentException('Tanggal rencana tidak valid (tahun 2000–2099).');
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input['planned_at'], new DateTimeZone($timezone));
                if ($date === false || $date->format('Y-m-d\TH:i') !== $input['planned_at']) {
                    throw new InvalidArgumentException('Tanggal atau waktu rencana tidak tersedia di zona waktu perusahaan.');
                }
                $plannedAt = $date->getTimestamp();
            }
            if ($id !== null) {
                $db->prepare('UPDATE content_items SET title = ?, brief = ?, format = ?, status = ?,
                    planned_at = ?, updated_at = ?, version = version + 1 WHERE id = ?')
                    ->execute([$title, $brief, $input['format'], $input['status'], $plannedAt, time(), $id]);
                $this->app->audit('content.updated', $ownerId);
                return $id;
            }
            $existing = $db->prepare('SELECT id, owner_id, deleted_at FROM content_items WHERE creation_key = ?');
            $existing->execute([$input['creation_key']]);
            $row = $existing->fetch();
            if ($row) {
                if ($row['deleted_at'] !== null) {
                    throw new InvalidArgumentException('Konten dari form ini sudah dihapus. Gunakan form baru.');
                }
                if ((int) $row['owner_id'] !== $ownerId) {
                    throw new InvalidArgumentException('Token form tidak sesuai dengan pemilik konten.');
                }
                return (int) $row['id'];
            }
            $now = time();
            $db->prepare('INSERT INTO content_items (company_id, owner_id, title, format, brief, status,
                planned_at, creation_key, created_at, updated_at, campaign_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) $input['company_id'], $ownerId, $title, $input['format'], $brief,
                    $input['status'], $plannedAt, $input['creation_key'], $now, $now, $campaignId === '' ? null : (int) $campaignId]);
            $id = (int) $db->lastInsertId();
            $this->app->audit('content.created', $ownerId);
            return $id;
        });
    }

    public function delete(int $contentId, int $actorId): void
    {
        $this->app->transaction(function (PDO $db) use ($contentId, $actorId): void {
            $actor = $db->prepare("SELECT 1 FROM users WHERE id = ? AND is_active = 1 AND role = 'system_admin'");
            $actor->execute([$actorId]);
            if (!$actor->fetchColumn()) {
                throw new InvalidArgumentException('Hanya administrator aktif yang dapat menghapus konten.');
            }
            $query = $db->prepare('SELECT status, deleted_at FROM content_items WHERE id = ?');
            $query->execute([$contentId]);
            $content = $query->fetch();
            if (!$content || $content['deleted_at'] !== null) {
                throw new InvalidArgumentException('Konten tidak ditemukan atau sudah dihapus.');
            }
            if (!isset(self::INITIAL_STATUSES[$content['status']])) {
                throw new InvalidArgumentException('Hanya konten berstatus Ide atau Brief yang dapat dihapus.');
            }
            // Preserve the observance's unique key so automation cannot recreate deleted ideas.
            $db->prepare('UPDATE content_items SET deleted_at = ?, deleted_by = ?, updated_at = ? WHERE id = ?')
                ->execute([time(), $actorId, time(), $contentId]);
            $this->app->audit('content.deleted', $actorId);
        });
    }
}
