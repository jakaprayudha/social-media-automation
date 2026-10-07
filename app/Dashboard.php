<?php
declare(strict_types=1);

final class Dashboard
{
    public const STATUSES = [
        'idea' => 'Ide', 'brief' => 'Brief', 'ai_draft' => 'Draft AI',
        'pending_review' => 'Menunggu review', 'needs_revision' => 'Perlu revisi',
        'approved' => 'Disetujui', 'scheduled' => 'Terjadwal', 'processing' => 'Sedang diproses',
        'published' => 'Terbit', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan',
    ];
    public const METRICS = [
        'ideas' => ['label' => 'Ide', 'icon' => 'spark', 'statuses' => ['idea']],
        'drafts' => ['label' => 'Draft & revisi', 'icon' => 'file', 'statuses' => ['brief', 'ai_draft', 'needs_revision']],
        'review' => ['label' => 'Menunggu review', 'icon' => 'shield', 'statuses' => ['pending_review']],
        'scheduled' => ['label' => 'Terjadwal', 'icon' => 'calendar', 'statuses' => ['scheduled']],
        'published' => ['label' => 'Terbit', 'icon' => 'send', 'statuses' => ['published']],
        'failed' => ['label' => 'Gagal', 'icon' => 'clock', 'statuses' => ['failed']],
    ];
    public const PLATFORMS = ['tiktok' => 'TikTok', 'instagram' => 'Instagram', 'facebook' => 'Facebook Page'];

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array<string, mixed>> */
    public function companies(): array
    {
        return $this->app->db()->query('SELECT id, name, slug, timezone, status FROM companies ORDER BY id')->fetchAll();
    }

    /** @return array<string, mixed> */
    public function snapshot(?int $companyId): array
    {
        // A read transaction keeps all dashboard panels on the same database snapshot.
        $db = $this->app->db();
        $db->beginTransaction();
        try {
            $companies = $this->companies();
            $selected = null;
            if ($companyId !== null) {
                foreach ($companies as $company) {
                    if ((int) $company['id'] === $companyId) {
                        $selected = $company;
                        break;
                    }
                }
                if ($selected === null) {
                    throw new InvalidArgumentException('Unknown company.');
                }
            }
            $visible = $selected === null ? $companies : [$selected];
            $scope = $companyId === null ? '' : ' WHERE company_id = ?';
            $parameters = $companyId === null ? [] : [$companyId];
            $counts = $db->prepare('SELECT company_id, status, COUNT(*) AS count FROM content_items'
                . $scope . ' GROUP BY company_id, status');
            $counts->execute($parameters);
            $statusTotals = array_fill_keys(array_keys(self::STATUSES), 0);
            $byCompany = [];
            foreach ($visible as $company) {
                $byCompany[(int) $company['id']] = array_fill_keys(array_keys(self::STATUSES), 0);
            }
            foreach ($counts->fetchAll() as $row) {
                $statusTotals[$row['status']] += (int) $row['count'];
                $byCompany[(int) $row['company_id']][$row['status']] = (int) $row['count'];
            }
            $accounts = $db->prepare('SELECT company_id, platform, connection_status, COUNT(*) AS count FROM social_accounts'
                . $scope . ' GROUP BY company_id, platform, connection_status');
            $accounts->execute($parameters);
            $channels = [];
            foreach ($visible as $company) {
                foreach (self::PLATFORMS as $platform => $label) {
                    $channels[(int) $company['id']][$platform] = ['total' => 0, 'connected' => 0];
                }
            }
            $totalAccounts = 0;
            $connectedAccounts = 0;
            foreach ($accounts->fetchAll() as $row) {
                $channel = &$channels[(int) $row['company_id']][$row['platform']];
                $channel['total'] += (int) $row['count'];
                $totalAccounts += (int) $row['count'];
                if ($row['connection_status'] === 'connected') {
                    $channel['connected'] += (int) $row['count'];
                    $connectedAccounts += (int) $row['count'];
                }
                unset($channel);
            }
            $recent = $db->prepare('SELECT c.id, c.title, c.status, c.updated_at, co.name AS company_name,
                co.timezone, u.name AS owner_name FROM content_items c
                JOIN companies co ON co.id = c.company_id JOIN users u ON u.id = c.owner_id'
                . ($companyId === null ? '' : ' WHERE c.company_id = ?')
                . ' ORDER BY c.updated_at DESC, c.id DESC LIMIT 8');
            $recent->execute($parameters);
            $data = [
                'companies' => $companies, 'visible_companies' => $visible, 'selected_company' => $selected,
                'statuses' => $statusTotals, 'by_company' => $byCompany, 'channels' => $channels,
                'metrics' => self::metricCounts($statusTotals), 'total_content' => array_sum($statusTotals),
                'total_accounts' => $totalAccounts, 'connected_accounts' => $connectedAccounts,
                'recent_content' => $recent->fetchAll(), 'captured_at' => time(),
            ];
            $db->commit();
            return $data;
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    /** @param array<string, int> $statuses @return array<string, int> */
    public static function metricCounts(array $statuses): array
    {
        $counts = [];
        foreach (self::METRICS as $key => $metric) {
            $counts[$key] = 0;
            foreach ($metric['statuses'] as $status) {
                $counts[$key] += $statuses[$status];
            }
        }
        return $counts;
    }
}

function dashboardTime(int $timestamp, string $timezone): string
{
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($timezone))->format('d/m/Y H:i');
}
