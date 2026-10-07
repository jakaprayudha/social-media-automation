<?php
declare(strict_types=1);
$selectedCompany = $dashboard['selected_company'];
$scopeName = $selectedCompany === null ? 'Semua perusahaan' : $selectedCompany['name'];
?>
<div class="dashboard-heading">
    <div class="page-heading"><span>RINGKASAN OPERASIONAL</span><h1>Dashboard</h1><p>Satu pandangan untuk menjaga konten dan koordinasi tiga merek Anda.</p></div>
    <form class="company-filter" method="get" action="<?= escape($app->basePath() . '/index.php') ?>">
        <input type="hidden" name="page" value="admin">
        <input type="hidden" name="section" value="dashboard">
        <label for="company-filter">Lingkup perusahaan</label>
        <div><select name="company" id="company-filter">
            <option value="all" <?= $selectedCompany === null ? 'selected' : '' ?>>Semua perusahaan</option>
            <?php foreach ($dashboard['companies'] as $company): ?>
                <option value="<?= (int) $company['id'] ?>" <?= $selectedCompany !== null && (int) $selectedCompany['id'] === (int) $company['id'] ? 'selected' : '' ?>><?= escape($company['name']) ?></option>
            <?php endforeach ?>
        </select><button type="submit">Terapkan</button></div>
    </form>
</div>
<div class="dashboard-context"><span class="scope-chip"><?= icon('building') ?><?= escape($scopeName) ?></span><span>Data SQLite · <?= escape(dashboardTime($dashboard['captured_at'], 'Asia/Jakarta')) ?> WIB · seluruh periode</span></div>
<section class="metric-grid" aria-label="Ringkasan status konten">
    <?php foreach (Dashboard::METRICS as $key => $metric): ?>
        <article class="metric-card metric-<?= escape($key) ?>">
            <div><span><?= escape($metric['label']) ?></span><i><?= icon($metric['icon']) ?></i></div>
            <strong data-metric="<?= escape($key) ?>"><?= number_format($dashboard['metrics'][$key], 0, ',', '.') ?></strong>
            <small><?= $key === 'drafts' ? 'Brief, draft AI & perlu revisi' : 'Item konten' ?></small>
        </article>
    <?php endforeach ?>
</section>
<p class="metric-footnote">Ringkasan dihitung per item konten, bukan jumlah posting per akun. Disetujui: <?= $dashboard['statuses']['approved'] ?> · Sedang diproses: <?= $dashboard['statuses']['processing'] ?> · Dibatalkan: <?= $dashboard['statuses']['cancelled'] ?>. Total: <?= $dashboard['total_content'] ?> item.</p>
<section class="dashboard-panel companies-panel" aria-labelledby="companies-title">
    <div class="panel-heading"><div><h2 id="companies-title">Ruang kerja perusahaan</h2><p>Status konten dan akun dipisahkan untuk setiap merek.</p></div><span class="count-chip"><?= count($dashboard['visible_companies']) ?> perusahaan</span></div>
    <div class="company-grid">
        <?php foreach ($dashboard['visible_companies'] as $company):
            $companyId = (int) $company['id'];
            $companyStatuses = $dashboard['by_company'][$companyId];
            $companyMetrics = Dashboard::metricCounts($companyStatuses);
            $initials = match ($company['slug']) {
                'signal-prima-solusi' => 'SPS', 'netindo-persada-nusantara' => 'NPN', 'mega-data-link' => 'MDL',
                default => mb_strtoupper(mb_substr($company['name'], 0, 3)),
            };
        ?>
            <article class="company-card">
                <div class="company-card-title"><span class="company-symbol company-<?= escape($company['slug']) ?>"><?= escape($initials) ?></span><div><h3><?= escape($company['name']) ?></h3><span><?= escape($company['timezone']) ?> · <?= $company['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span></div></div>
                <div class="company-summary"><span><strong><?= array_sum($companyStatuses) ?></strong> Total konten</span><span><strong><?= $companyMetrics['review'] ?></strong> Perlu review</span><span><strong><?= $companyMetrics['failed'] ?></strong> Gagal</span></div>
                <ul class="channel-list">
                    <?php foreach (Dashboard::PLATFORMS as $platform => $label):
                        $channel = $dashboard['channels'][$companyId][$platform];
                    ?>
                        <li><span><?= escape($label) ?></span><span class="<?= $channel['total'] === 0 ? 'channel-empty' : ($channel['connected'] === $channel['total'] ? 'channel-connected' : 'channel-attention') ?>"><?= $channel['total'] === 0 ? 'Belum ditambahkan' : $channel['connected'] . '/' . $channel['total'] . ' terhubung' ?></span></li>
                    <?php endforeach ?>
                </ul>
                <a class="company-detail-link" href="<?= escape($app->url('admin') . '&section=dashboard&company=' . $companyId) ?>">Lihat ringkasan merek <?= icon('arrow') ?></a>
            </article>
        <?php endforeach ?>
    </div>
    <?php if ($dashboard['visible_companies'] === []): ?><p class="dashboard-empty">Belum ada perusahaan yang terdaftar.</p><?php endif ?>
</section>
<div class="dashboard-bottom">
    <section class="dashboard-panel" aria-labelledby="recent-title">
        <div class="panel-heading"><div><h2 id="recent-title">Konten terbaru</h2><p>Maksimal 8 item, berdasarkan perubahan terakhir.</p></div><span class="count-chip"><?= $dashboard['total_content'] ?> item</span></div>
        <?php if ($dashboard['recent_content'] === []): ?>
            <div class="dashboard-empty"><?= icon('file') ?><h3>Belum ada konten</h3><p>Konten akan muncul di sini setelah fitur editorial digunakan untuk <?= escape($scopeName) ?>.</p></div>
        <?php else: ?>
            <div class="table-scroll"><table class="content-table"><caption class="visually-hidden">Konten terbaru untuk <?= escape($scopeName) ?></caption><thead><tr><th scope="col">Konten / perusahaan</th><th scope="col">Status</th><th scope="col">Pemilik / diperbarui</th></tr></thead><tbody>
                <?php foreach ($dashboard['recent_content'] as $content): ?>
                    <tr><td><strong><?= escape($content['title']) ?></strong><small><?= escape($content['company_name']) ?></small></td><td><span class="status-pill status-<?= escape($content['status']) ?>"><?= escape(Dashboard::STATUSES[$content['status']]) ?></span></td><td><?= escape($content['owner_name']) ?><small><?= escape(dashboardTime((int) $content['updated_at'], $content['timezone'])) ?><br><?= escape($content['timezone']) ?></small></td></tr>
                <?php endforeach ?>
            </tbody></table></div>
        <?php endif ?>
    </section>
    <section class="dashboard-panel attention-panel" aria-labelledby="attention-title">
        <div class="panel-heading"><div><h2 id="attention-title">Perlu perhatian</h2><p>Kondisi operasional dalam lingkup terpilih.</p></div></div>
        <ul class="attention-list">
            <li><?= icon('shield') ?><div><strong><?= $dashboard['metrics']['review'] ?> menunggu review</strong><span>Perlu persetujuan sebelum publikasi.</span></div></li>
            <li><?= icon('clock') ?><div><strong><?= $dashboard['metrics']['failed'] ?> konten gagal</strong><span><?= $dashboard['metrics']['failed'] > 0 ? 'Perlu pemeriksaan dan tindak lanjut tim.' : 'Tidak ada item berstatus gagal.' ?></span></div></li>
            <li><?= icon('link') ?><div><strong><?= $dashboard['connected_accounts'] ?>/<?= $dashboard['total_accounts'] ?> akun terhubung</strong><span><?= $dashboard['total_accounts'] === 0 ? 'Belum ada akun kanal yang ditambahkan.' : 'Status tersimpan; bukan pemeriksaan API live.' ?></span></div></li>
        </ul>
        <div class="implementation-note"><?= icon('lock') ?><p>Dashboard baca-saja. Editor konten, koneksi OAuth, persetujuan, dan publikasi belum diaktifkan. Tidak ada konten atau koneksi akun demo yang dibuat otomatis.</p></div>
    </section>
</div>
