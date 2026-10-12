<?php
declare(strict_types=1);

final class ContentLibrary
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(?int $companyId, string $status, ?int $campaignId, string $search, int $page = 1, ?int $id = null): array
    {
        if (($status !== 'all' && !isset(Dashboard::STATUSES[$status]))
            || mb_strlen($search) > 200 || str_contains($search, "\0") || $page < 1 || $page > 100000) {
            throw new InvalidArgumentException('Filter konten tidak valid.');
        }
        $db = $this->app->db();
        $db->beginTransaction();
        try {
            $companies = (new Dashboard($this->app))->companies();
            $selectedCompany = null;
            foreach ($companies as $company) {
                if ((int) $company['id'] === $companyId) {
                    $selectedCompany = $company;
                }
            }
            if ($companyId !== null && $selectedCompany === null) {
                throw new InvalidArgumentException('Perusahaan tidak tersedia.');
            }
            $campaigns = $db->query('SELECT ca.id, ca.name, ca.company_id, ca.status, co.name AS company_name
                FROM campaigns ca JOIN companies co ON co.id = ca.company_id ORDER BY ca.name, ca.id')->fetchAll();
            $selectedCampaign = null;
            foreach ($campaigns as $campaign) {
                if ((int) $campaign['id'] === $campaignId
                    && ($companyId === null || (int) $campaign['company_id'] === $companyId)) {
                    $selectedCampaign = $campaign;
                }
            }
            if ($campaignId !== null && $selectedCampaign === null) {
                throw new InvalidArgumentException('Kampanye tidak tersedia untuk perusahaan ini.');
            }
            $conditions = ['c.deleted_at IS NULL'];
            $parameters = [];
            foreach (['company_id' => $companyId, 'campaign_id' => $campaignId] as $field => $value) {
                if ($value !== null) {
                    $conditions[] = 'c.' . $field . ' = ?';
                    $parameters[] = $value;
                }
            }
            if ($status !== 'all') {
                $conditions[] = 'c.status = ?';
                $parameters[] = $status;
            }
            if ($search !== '') {
                $conditions[] = "(c.title LIKE ? ESCAPE '\\' OR c.brief LIKE ? ESCAPE '\\')";
                $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                array_push($parameters, $term, $term);
            }
            $scope = implode(' AND ', $conditions);
            $count = $db->prepare('SELECT COUNT(*) FROM content_items c WHERE ' . $scope);
            $count->execute($parameters);
            $total = (int) $count->fetchColumn();
            $pages = max(1, (int) ceil($total / 20));
            if ($page > $pages) {
                throw new InvalidArgumentException('Halaman konten tidak tersedia.');
            }
            $select = 'SELECT c.*, co.name AS company_name, co.timezone AS company_timezone,
                co.status AS company_status, u.name AS owner_name, ca.name AS campaign_name
                FROM content_items c JOIN companies co ON co.id = c.company_id
                JOIN users u ON u.id = c.owner_id LEFT JOIN campaigns ca ON ca.id = c.campaign_id';
            $query = $db->prepare($select . ' WHERE ' . $scope . ' ORDER BY c.updated_at DESC, c.id DESC
                LIMIT 20 OFFSET ' . (($page - 1) * 20));
            $query->execute($parameters);
            $items = $query->fetchAll();
            $detail = null;
            if ($id !== null) {
                $query = $db->prepare($select . ' WHERE c.id = ? AND c.deleted_at IS NULL');
                $query->execute([$id]);
                $detail = $query->fetch();
                if (!$detail || ($companyId !== null && (int) $detail['company_id'] !== $companyId)
                    || ($campaignId !== null && (int) $detail['campaign_id'] !== $campaignId)) {
                    throw new InvalidArgumentException('Konten tidak tersedia dalam perusahaan atau kampanye yang dipilih.');
                }
            }
            $result = compact('companies', 'campaigns', 'items', 'detail', 'total', 'pages', 'page', 'status', 'search');
            $result['selected_company'] = $selectedCompany;
            $result['selected_campaign'] = $selectedCampaign;
            $db->commit();
            return $result;
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
