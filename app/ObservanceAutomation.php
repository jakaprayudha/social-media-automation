<?php
declare(strict_types=1);

final class ObservanceAutomation
{
    public function __construct(private readonly App $app)
    {
    }

    public function enable(int $ownerId): void
    {
        $this->app->transaction(function (PDO $db) use ($ownerId): void {
            $this->validateOwner($db, $ownerId);
            $db->prepare('INSERT INTO calendar_automation (id, enabled, owner_id, updated_at) VALUES (1, 1, ?, ?)
                ON CONFLICT(id) DO UPDATE SET enabled = 1, owner_id = excluded.owner_id, updated_at = excluded.updated_at')
                ->execute([$ownerId, time()]);
            $this->app->audit('calendar.automation_enabled', $ownerId);
        });
    }

    public function disable(int $actorId): void
    {
        $this->app->transaction(function (PDO $db) use ($actorId): void {
            $this->validateOwner($db, $actorId);
            $db->prepare('UPDATE calendar_automation SET enabled = 0, updated_at = ? WHERE id = 1')->execute([time()]);
            $this->app->audit('calendar.automation_disabled', $actorId);
        });
    }

    private function validateOwner(PDO $db, int $ownerId): void
    {
        $query = $db->prepare("SELECT 1 FROM users WHERE id = ? AND is_active = 1 AND role = 'system_admin'");
        $query->execute([$ownerId]);
        if (!$query->fetchColumn()) {
            throw new InvalidArgumentException('Pemilik ide otomatis harus administrator aktif.');
        }
    }

    public static function currentYear(): int
    {
        return (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y');
    }

    /** @return array{enabled: bool, created: int, year: int} */
    public function generate(int $year): array
    {
        if ($year < 2000 || $year > 2099) {
            throw new InvalidArgumentException('Tahun ide otomatis harus antara 2000 dan 2099.');
        }
        return $this->app->transaction(function (PDO $db) use ($year): array {
            $settings = $db->query('SELECT * FROM calendar_automation WHERE id = 1')->fetch();
            if (!$settings || !(int) $settings['enabled']) {
                return ['enabled' => false, 'created' => 0, 'year' => $year];
            }
            $ownerId = (int) $settings['owner_id'];
            $this->validateOwner($db, $ownerId);
            $companies = $db->query("SELECT id, name, timezone FROM companies WHERE status = 'active' ORDER BY id")->fetchAll();
            $observances = $db->prepare('SELECT * FROM observances WHERE first_year <= ? ORDER BY month, day, id');
            $observances->execute([$year]);
            $catalog = $observances->fetchAll();
            $created = 0;
            $now = time();
            $insert = $db->prepare("INSERT INTO content_items (company_id, owner_id, title, brief, status,
                planned_at, observance_id, observance_year, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'idea', ?, ?, ?, ?, ?)
                ON CONFLICT(company_id, observance_id, observance_year) DO NOTHING");
            foreach ($companies as $company) {
                $zone = new DateTimeZone($company['timezone']);
                foreach ($catalog as $observance) {
                    $date = sprintf('%04d-%02d-%02d', $year, $observance['month'], $observance['day']);
                    if (!checkdate((int) $observance['month'], (int) $observance['day'], $year)) {
                        throw new RuntimeException('Katalog peringatan memiliki tanggal tidak valid.');
                    }
                    $plannedAt = (new DateTimeImmutable($date . ' 09:00:00', $zone))->getTimestamp();
                    $brief = 'Ide otomatis untuk ' . $observance['name'] . ' (' . $date . ') di ' . $company['name']
                        . ". Sesuaikan dengan profil merek dan tinjau sebelum publikasi. Tema tahunan belum diverifikasi.\n"
                        . 'Rujukan tanggal: ' . $observance['source_url'] . "\nTanggal rencana, bukan jadwal publikasi.";
                    $insert->execute([$company['id'], $ownerId, 'Ide: ' . $observance['name'], $brief,
                        $plannedAt, $observance['id'], $year, $now, $now]);
                    $created += $insert->rowCount();
                }
            }
            $db->prepare('UPDATE calendar_automation SET last_run_at = ? WHERE id = 1')->execute([$now]);
            if ($created > 0) {
                $this->app->audit('calendar.ideas_generated', $ownerId);
            }
            return ['enabled' => true, 'created' => $created, 'year' => $year];
        });
    }
}
