<?php
declare(strict_types=1);

final class ContentCalendar
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(?int $companyId, string $month, string $status, ?int $campaignId = null): array
    {
        if (!preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/', $month)
            || ($status !== 'all' && !isset(Dashboard::STATUSES[$status]))) {
            throw new InvalidArgumentException('Invalid calendar filters.');
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
                throw new InvalidArgumentException('Unknown company.');
            }
            $timezone = $selected['timezone'] ?? 'Asia/Jakarta';
            $zone = new DateTimeZone($timezone);
            $start = new DateTimeImmutable($month . '-01 00:00:00', $zone);
            $end = $start->modify('+1 month');
            $conditions = ['c.deleted_at IS NULL'];
            $parameters = [];
            if ($companyId !== null) {
                $conditions[] = 'c.company_id = ?';
                $parameters[] = $companyId;
            }
            if ($status !== 'all') {
                $conditions[] = 'c.status = ?';
                $parameters[] = $status;
            }
            $campaigns = $db->query('SELECT ca.id, ca.name, ca.company_id, ca.status, co.name AS company_name
                FROM campaigns ca JOIN companies co ON co.id = ca.company_id ORDER BY ca.name, ca.id')->fetchAll();
            $selectedCampaign = null;
            if ($campaignId !== null) {
                foreach ($campaigns as $campaign) {
                    if ((int) $campaign['id'] === $campaignId
                        && ($companyId === null || (int) $campaign['company_id'] === $companyId)) {
                        $selectedCampaign = $campaign;
                        break;
                    }
                }
                if ($selectedCampaign === null) {
                    throw new InvalidArgumentException('Unknown campaign in this company.');
                }
                $conditions[] = 'c.campaign_id = ?';
                $parameters[] = $campaignId;
            }
            $scope = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);
            $query = $db->prepare('SELECT c.id, c.title, c.status, c.format, c.brief, c.scheduled_at, c.observance_id,
                COALESCE(c.scheduled_at, c.planned_at) AS calendar_at,
                co.name AS company_name, co.timezone AS company_timezone, u.name AS owner_name, ca.name AS campaign_name
                FROM content_items c JOIN companies co ON co.id = c.company_id
                JOIN users u ON u.id = c.owner_id LEFT JOIN campaigns ca ON ca.id = c.campaign_id
                WHERE COALESCE(c.scheduled_at, c.planned_at) >= CAST(? AS INTEGER)
                AND COALESCE(c.scheduled_at, c.planned_at) < CAST(? AS INTEGER)' . $scope
                . ' ORDER BY calendar_at, c.id');
            $query->execute([$start->getTimestamp(), $end->getTimestamp(), ...$parameters]);
            $items = $query->fetchAll();
            $days = [];
            foreach ($items as $item) {
                $day = (new DateTimeImmutable('@' . $item['calendar_at']))->setTimezone($zone)->format('Y-m-d');
                $days[$day][] = $item;
            }
            $unscheduled = $db->prepare('SELECT COUNT(*) FROM content_items c WHERE c.scheduled_at IS NULL AND c.planned_at IS NULL' . $scope);
            $unscheduled->execute($parameters);
            $observanceQuery = $db->prepare('SELECT * FROM observances WHERE month = ? AND first_year <= ? ORDER BY day, id');
            $observanceQuery->execute([(int) $start->format('n'), (int) $start->format('Y')]);
            $observanceDays = [];
            $observanceCount = 0;
            foreach ($observanceQuery->fetchAll() as $observance) {
                $date = sprintf('%s-%02d', $month, $observance['day']);
                $observanceDays[$date][] = $observance;
                $observanceCount++;
            }
            $result = [
                'companies' => $companies, 'selected_company' => $selected, 'month' => $month,
                'status' => $status, 'timezone' => $timezone, 'start' => $start,
                'previous_month' => $month === '2000-01' ? null : $start->modify('-1 month')->format('Y-m'),
                'next_month' => $month === '2099-12' ? null : $end->format('Y-m'),
                'days' => $days, 'total' => count($items),
                'campaigns' => $campaigns, 'selected_campaign' => $selectedCampaign,
                'observances' => $observanceDays, 'observance_count' => $observanceCount,
                'catalog_count' => (int) $db->query('SELECT COUNT(*) FROM observances')->fetchColumn(),
                'automation' => $db->query('SELECT a.*, u.name AS owner_name FROM calendar_automation a JOIN users u ON u.id = a.owner_id WHERE a.id = 1')->fetch() ?: null,
                'unscheduled' => (int) $unscheduled->fetchColumn(),
                'today' => (new DateTimeImmutable('now', $zone))->format('Y-m-d'),
            ];
            $db->commit();
            return $result;
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
