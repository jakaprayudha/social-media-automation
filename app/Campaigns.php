<?php
declare(strict_types=1);

final class Campaigns
{
    public const STATUSES = ['draft' => 'Draft', 'active' => 'Aktif', 'completed' => 'Selesai', 'archived' => 'Diarsipkan'];

    public function __construct(private readonly App $app)
    {
    }

    private function validateActor(PDO $db, int $actorId): void
    {
        $query = $db->prepare("SELECT 1 FROM users WHERE id = ? AND is_active = 1 AND role = 'system_admin'");
        $query->execute([$actorId]);
        if (!$query->fetchColumn()) {
            throw new InvalidArgumentException('Hanya administrator aktif yang dapat mengelola kampanye.');
        }
    }

    /** @param array<string, string> $input */
    public function save(array $input, int $actorId, ?int $id = null, ?int $version = null): int
    {
        $name = trim($input['name']);
        $objective = trim($input['objective']);
        $theme = trim($input['theme']);
        if ($name === '' || mb_strlen($name) > 200 || mb_strlen($objective) > 5000 || mb_strlen($theme) > 500
            || str_contains($name . $objective . $theme, "\0")) {
            throw new InvalidArgumentException('Nama wajib diisi (maksimal 200 karakter), tujuan maksimal 5.000, dan tema maksimal 500 karakter.');
        }
        if (!isset(self::STATUSES[$input['status']]) || $input['status'] === 'archived') {
            throw new InvalidArgumentException('Pilih status Draft, Aktif, atau Selesai. Gunakan tindakan arsip untuk mengarsipkan.');
        }
        foreach (['start_date', 'end_date'] as $field) {
            $value = $input[$field];
            if (!preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}\z/', $value)
                || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                throw new InvalidArgumentException('Periode kampanye wajib tanggal yang valid, tahun 2000–2099.');
            }
        }
        if ($input['end_date'] < $input['start_date']) {
            throw new InvalidArgumentException('Tanggal akhir tidak boleh sebelum tanggal mulai.');
        }
        if (!preg_match('/\A[1-9][0-9]{0,8}\z/', $input['company_id'])
            || ($id === null && !preg_match('/\A[a-f0-9]{64}\z/', $input['creation_key']))) {
            throw new InvalidArgumentException('Perusahaan atau token form kampanye tidak valid.');
        }
        return $this->app->transaction(function (PDO $db) use ($input, $actorId, $id, $version, $name, $objective, $theme): int {
            $this->validateActor($db, $actorId);
            $company = $db->prepare("SELECT 1 FROM companies WHERE id = ? AND status = 'active'");
            $company->execute([(int) $input['company_id']]);
            if (!$company->fetchColumn()) {
                throw new InvalidArgumentException('Pilih perusahaan aktif yang terdaftar.');
            }
            $now = time();
            if ($id !== null) {
                $current = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
                $current->execute([$id]);
                $row = $current->fetch();
                if (!$row || $row['status'] === 'archived') {
                    throw new InvalidArgumentException('Kampanye tidak ditemukan atau sudah diarsipkan.');
                }
                if ((int) $row['company_id'] !== (int) $input['company_id']) {
                    throw new InvalidArgumentException('Perusahaan kampanye tidak dapat diganti.');
                }
                if ((int) $row['version'] !== $version) {
                    throw new InvalidArgumentException('Kampanye telah diubah pengguna lain. Muat ulang sebelum mengedit.');
                }
                $db->prepare('UPDATE campaigns SET name = ?, objective = ?, theme = ?, start_date = ?, end_date = ?,
                    status = ?, version = version + 1, updated_at = ? WHERE id = ?')
                    ->execute([$name, $objective, $theme, $input['start_date'], $input['end_date'], $input['status'], $now, $id]);
                $this->app->audit('campaign.updated', $actorId);
                return $id;
            }
            $existing = $db->prepare('SELECT id, owner_id FROM campaigns WHERE creation_key = ?');
            $existing->execute([$input['creation_key']]);
            $row = $existing->fetch();
            if ($row) {
                if ((int) $row['owner_id'] !== $actorId) {
                    throw new InvalidArgumentException('Token kampanye tidak sesuai dengan pemilik.');
                }
                return (int) $row['id'];
            }
            $db->prepare('INSERT INTO campaigns (company_id, owner_id, name, objective, theme, start_date, end_date,
                status, creation_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) $input['company_id'], $actorId, $name, $objective, $theme, $input['start_date'],
                    $input['end_date'], $input['status'], $input['creation_key'], $now, $now]);
            $id = (int) $db->lastInsertId();
            $this->app->audit('campaign.created', $actorId);
            return $id;
        });
    }

    public function archive(int $id, int $version, int $actorId): void
    {
        $this->app->transaction(function (PDO $db) use ($id, $version, $actorId): void {
            $this->validateActor($db, $actorId);
            $query = $db->prepare("UPDATE campaigns SET status = 'archived', version = version + 1, updated_at = ?
                WHERE id = ? AND version = ? AND status != 'archived'");
            $query->execute([time(), $id, $version]);
            if ($query->rowCount() !== 1) {
                throw new InvalidArgumentException('Kampanye tidak ditemukan, sudah diarsipkan, atau telah berubah. Muat ulang halaman.');
            }
            $this->app->audit('campaign.archived', $actorId);
        });
    }

    public function assign(int $campaignId, int $contentId, int $actorId): void
    {
        $this->app->transaction(function (PDO $db) use ($campaignId, $contentId, $actorId): void {
            $this->validateActor($db, $actorId);
            $query = $db->prepare("SELECT ca.company_id FROM campaigns ca JOIN companies co ON co.id = ca.company_id
                WHERE ca.id = ? AND ca.status != 'archived' AND co.status = 'active'");
            $query->execute([$campaignId]);
            $companyId = $query->fetchColumn();
            if ($companyId === false) {
                throw new InvalidArgumentException('Kampanye harus tersedia, tidak diarsipkan, dan perusahaan aktif.');
            }
            $update = $db->prepare("UPDATE content_items SET campaign_id = ?, updated_at = ?
                WHERE id = ? AND company_id = ? AND deleted_at IS NULL AND status IN ('idea', 'brief')
                AND campaign_id IS NULL");
            $update->execute([$campaignId, time(), $contentId, $companyId]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('Pilih Ide/Brief dari perusahaan yang sama, belum terkait kampanye, dan belum dihapus.');
            }
            $this->app->audit('campaign.content_linked', $actorId);
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(?int $companyId, string $status, ?int $detailId = null): array
    {
        if ($status !== 'all' && !isset(self::STATUSES[$status])) {
            throw new InvalidArgumentException('Filter status kampanye tidak valid.');
        }
        $db = $this->app->db();
        $db->beginTransaction();
        try {
            $companies = (new Dashboard($this->app))->companies();
            $selected = null;
            foreach ($companies as $company) {
                if ((int) $company['id'] === $companyId) {
                    $selected = $company;
                    break;
                }
            }
            if ($companyId !== null && $selected === null) {
                throw new InvalidArgumentException('Perusahaan tidak terdaftar.');
            }
            $where = [];
            $parameters = [];
            if ($companyId !== null) {
                $where[] = 'ca.company_id = ?';
                $parameters[] = $companyId;
            }
            if ($status !== 'all') {
                $where[] = 'ca.status = ?';
                $parameters[] = $status;
            }
            $query = $db->prepare('SELECT ca.*, co.name AS company_name, co.timezone, u.name AS owner_name,
                (SELECT COUNT(*) FROM content_items c WHERE c.campaign_id = ca.id AND c.deleted_at IS NULL) AS content_count,
                (SELECT COUNT(*) FROM content_items c WHERE c.campaign_id = ca.id AND c.deleted_at IS NULL AND c.status = \'published\') AS published_count
                FROM campaigns ca JOIN companies co ON co.id = ca.company_id JOIN users u ON u.id = ca.owner_id'
                . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY ca.updated_at DESC, ca.id DESC');
            $query->execute($parameters);
            $rows = $query->fetchAll();
            $detail = null;
            $content = [];
            $available = [];
            if ($detailId !== null) {
                $detailQuery = $db->prepare('SELECT ca.*, co.name AS company_name, co.timezone, co.status AS company_status
                    FROM campaigns ca JOIN companies co ON co.id = ca.company_id WHERE ca.id = ?');
                $detailQuery->execute([$detailId]);
                $detail = $detailQuery->fetch();
                if (!$detail || ($companyId !== null && (int) $detail['company_id'] !== $companyId)) {
                    throw new InvalidArgumentException('Kampanye tidak ditemukan pada perusahaan yang dipilih.');
                }
                $contentQuery = $db->prepare('SELECT id, title, status, COALESCE(scheduled_at, planned_at) AS calendar_at
                    FROM content_items WHERE campaign_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC, id DESC');
                $contentQuery->execute([$detailId]);
                $content = $contentQuery->fetchAll();
                $availableQuery = $db->prepare("SELECT id, title FROM content_items WHERE company_id = ?
                    AND campaign_id IS NULL AND deleted_at IS NULL AND status IN ('idea', 'brief') ORDER BY updated_at DESC, id DESC");
                $availableQuery->execute([$detail['company_id']]);
                $available = $availableQuery->fetchAll();
            }
            $db->commit();
            return ['companies' => $companies, 'selected_company' => $selected, 'status' => $status,
                'items' => $rows, 'detail' => $detail, 'content' => $content, 'available_content' => $available];
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
