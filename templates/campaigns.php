<?php
declare(strict_types=1);
$detail = $campaignData['detail'];
$selectedCompany = $campaignData['selected_company'];
$campaignBase = $app->url('admin') . '&' . http_build_query([
    'section' => 'campaigns', 'company' => $selectedCompany['id'] ?? 'all', 'status' => $campaignData['status'],
]);
$campaignFormAction = $campaignEdit && $detail !== null ? $campaignBase . '&id=' . (int) $detail['id'] : $campaignBase;
?>
<div class="page-heading"><span>STRATEGI &amp; PERENCANAAN</span><h1>Kampanye</h1><p>Kelompokkan ide dan konten sesuai tujuan, tema, serta periode setiap perusahaan.</p></div>
<details class="content-create dashboard-panel" id="campaign-form" <?= $campaignFormOpen ? 'open' : '' ?>>
    <summary><?= $campaignEdit ? 'Edit kampanye' : '+ Tambah kampanye' ?></summary>
    <form class="content-create-form" method="post" action="<?= escape($campaignFormAction) ?>">
        <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
        <input type="hidden" name="action" value="<?= $campaignEdit ? 'update_campaign' : 'create_campaign' ?>">
        <input type="hidden" name="creation_key" value="<?= escape($campaignForm['creation_key']) ?>">
        <?php if ($campaignEdit): ?>
            <input type="hidden" name="campaign_id" value="<?= (int) $detail['id'] ?>">
            <input type="hidden" name="version" value="<?= escape((string) $campaignVersion) ?>">
        <?php endif ?>
        <p>Periode mengikuti tanggal lokal perusahaan. Status kampanye tidak mengubah status atau jadwal publikasi konten.</p>
        <div class="content-form-grid">
            <div><label for="campaign-company">Perusahaan</label>
                <?php if ($campaignEdit): ?>
                    <input type="hidden" name="company_id" value="<?= (int) $detail['company_id'] ?>">
                    <p><?= escape($detail['company_name']) ?> (tidak dapat diganti)</p>
                <?php else: ?>
                    <select id="campaign-company" name="company_id" required>
                        <option value="">Pilih perusahaan aktif</option>
                        <?php foreach ($campaignData['companies'] as $company): if ($company['status'] !== 'active') { continue; } ?>
                            <option value="<?= (int) $company['id'] ?>" <?= (string) $company['id'] === ($campaignForm['company_id'] ?: (string) ($selectedCompany['id'] ?? '')) ? 'selected' : '' ?>><?= escape($company['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                <?php endif ?>
            </div>
            <div><label for="campaign-name">Nama kampanye</label><input id="campaign-name" name="name" maxlength="200" value="<?= escape($campaignForm['name']) ?>" required></div>
            <div><label for="campaign-start">Tanggal mulai</label><input type="date" id="campaign-start" name="start_date" min="2000-01-01" max="2099-12-31" value="<?= escape($campaignForm['start_date']) ?>" required></div>
            <div><label for="campaign-end">Tanggal akhir</label><input type="date" id="campaign-end" name="end_date" min="2000-01-01" max="2099-12-31" value="<?= escape($campaignForm['end_date']) ?>" required></div>
            <div><label for="campaign-status">Status kampanye</label><select id="campaign-status" name="status">
                <?php foreach (Campaigns::STATUSES as $value => $label): if ($value === 'archived') { continue; } ?><option value="<?= escape($value) ?>" <?= $campaignForm['status'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option><?php endforeach ?>
            </select></div>
            <div><label for="campaign-theme">Tema (opsional)</label><input id="campaign-theme" name="theme" maxlength="500" value="<?= escape($campaignForm['theme']) ?>"></div>
            <div class="campaign-wide"><label for="campaign-objective">Tujuan kampanye (opsional)</label><textarea id="campaign-objective" name="objective" rows="4" maxlength="5000"><?= escape($campaignForm['objective']) ?></textarea></div>
        </div>
        <button class="calendar-primary" type="submit">Simpan kampanye</button>
        <?php if ($campaignEdit): ?><a class="calendar-secondary" href="<?= escape($campaignBase . '&id=' . (int) $detail['id']) ?>">Batal edit</a><?php endif ?>
    </form>
</details>
<form class="calendar-filters company-filter" method="get" action="<?= escape($app->basePath() . '/index.php') ?>">
    <input type="hidden" name="page" value="admin"><input type="hidden" name="section" value="campaigns">
    <div class="calendar-filter-field"><label for="campaign-company-filter">Perusahaan</label><select id="campaign-company-filter" name="company">
        <option value="all" <?= $selectedCompany === null ? 'selected' : '' ?>>Semua perusahaan</option>
        <?php foreach ($campaignData['companies'] as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompany !== null && (int) $company['id'] === (int) $selectedCompany['id'] ? 'selected' : '' ?>><?= escape($company['name']) ?></option><?php endforeach ?>
    </select></div>
    <div class="calendar-filter-field"><label for="campaign-status-filter">Status</label><select id="campaign-status-filter" name="status">
        <option value="all" <?= $campaignData['status'] === 'all' ? 'selected' : '' ?>>Semua status</option>
        <?php foreach (Campaigns::STATUSES as $value => $label): ?><option value="<?= escape($value) ?>" <?= $campaignData['status'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option><?php endforeach ?>
    </select></div>
    <button type="submit">Terapkan filter</button>
</form>
<section class="dashboard-panel campaign-list" aria-labelledby="campaign-list-title">
    <div class="panel-heading"><div><h2 id="campaign-list-title">Daftar kampanye</h2><p><?= escape($selectedCompany['name'] ?? 'Semua perusahaan') ?> · Data SQLite</p></div><span class="count-chip"><?= count($campaignData['items']) ?> kampanye</span></div>
    <?php if ($campaignData['items'] === []): ?>
        <div class="dashboard-empty"><?= icon('flag') ?><h3>Belum ada kampanye</h3><p>Tambahkan kampanye atau ubah filter untuk melihat rencana perusahaan.</p></div>
    <?php else: ?>
        <div class="table-scroll"><table class="content-table"><caption class="visually-hidden">Daftar kampanye sesuai filter</caption><thead><tr><th scope="col">Kampanye / perusahaan</th><th scope="col">Periode</th><th scope="col">Status</th><th scope="col">Konten</th><th scope="col">Detail</th></tr></thead><tbody>
            <?php foreach ($campaignData['items'] as $campaign): ?>
                <tr><td><strong><?= escape($campaign['name']) ?></strong><small><?= escape($campaign['company_name']) ?></small><small>Pemilik: <?= escape($campaign['owner_name']) ?></small></td>
                    <td><?= escape($campaign['start_date']) ?> &ndash; <?= escape($campaign['end_date']) ?><small><?= escape($campaign['timezone']) ?></small></td>
                    <td><span class="status-pill"><?= escape(Campaigns::STATUSES[$campaign['status']]) ?></span></td>
                    <td><?= (int) $campaign['content_count'] ?> item<small><?= (int) $campaign['published_count'] ?> terbit</small></td>
                    <td><a class="campaign-detail-link" href="<?= escape($campaignBase . '&id=' . (int) $campaign['id'] . '#campaign-detail') ?>">Lihat detail</a></td></tr>
            <?php endforeach ?>
        </tbody></table></div>
    <?php endif ?>
</section>
<?php if ($detail !== null):
    $detailAction = $campaignBase . '&id=' . (int) $detail['id'];
    $canManage = $detail['status'] !== 'archived' && $detail['company_status'] === 'active';
    $calendarLink = $app->url('admin') . '&' . http_build_query([
        'section' => 'calendar', 'company' => $detail['company_id'], 'campaign' => $detail['id'],
        'month' => substr($detail['start_date'], 0, 7),
    ]);
?>
<section class="dashboard-panel campaign-detail" id="campaign-detail" aria-labelledby="campaign-detail-title">
    <div class="panel-heading"><div><h2 id="campaign-detail-title"><?= escape($detail['name']) ?></h2><p><?= escape($detail['company_name']) ?> · <?= escape(Campaigns::STATUSES[$detail['status']]) ?></p></div>
        <?php if ($canManage): ?><a class="calendar-secondary" href="<?= escape($detailAction . '&edit=1#campaign-form') ?>">Edit kampanye</a><?php endif ?>
    </div>
    <div class="automation-body">
        <dl class="campaign-description"><dt>Tujuan</dt><dd><?= $detail['objective'] === '' ? 'Belum ditambahkan' : escape($detail['objective']) ?></dd><dt>Tema</dt><dd><?= $detail['theme'] === '' ? 'Belum ditambahkan' : escape($detail['theme']) ?></dd><dt>Periode</dt><dd><?= escape($detail['start_date'] . ' — ' . $detail['end_date'] . ' · ' . $detail['timezone']) ?></dd></dl>
        <a class="calendar-secondary" href="<?= escape($calendarLink) ?>">Lihat konten di kalender</a>
        <?php if ($canManage): ?><a class="calendar-primary" href="<?= escape($calendarLink . '&date=' . $detail['start_date'] . '#content-create') ?>">+ Tambah konten kampanye</a><?php endif ?>
        <h3 class="campaign-content-heading">Konten terkait (<?= count($campaignData['content']) ?>)</h3>
        <?php if ($campaignData['content'] === []): ?><p>Belum ada konten terkait. Kaitkan Ide/Brief yang sudah ada atau buat konten dari kalender.</p><?php else: ?>
            <ul class="campaign-content-list"><?php foreach ($campaignData['content'] as $content): ?><li><a class="campaign-detail-link" href="<?= escape($app->url('admin') . '&section=content&id=' . (int) $content['id']) ?>"><strong><?= escape($content['title']) ?></strong></a> <span class="status-pill"><?= escape(Dashboard::STATUSES[$content['status']]) ?></span><small><?= $content['calendar_at'] === null ? 'Belum memiliki tanggal' : escape(dashboardTime((int) $content['calendar_at'], $detail['timezone'])) ?></small></li><?php endforeach ?></ul>
        <?php endif ?>
        <?php if ($canManage): ?>
            <form class="campaign-link-form" method="post" action="<?= escape($detailAction) ?>">
                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="assign_content"><input type="hidden" name="campaign_id" value="<?= (int) $detail['id'] ?>">
                <label for="campaign-content">Kaitkan Ide/Brief perusahaan ini yang belum memiliki kampanye</label>
                <?php if ($campaignData['available_content'] === []): ?><p>Tidak ada konten yang tersedia untuk dikaitkan.</p><?php else: ?>
                    <select id="campaign-content" name="content_id" required><option value="">Pilih konten</option><?php foreach ($campaignData['available_content'] as $content): ?><option value="<?= (int) $content['id'] ?>"><?= escape($content['title']) ?></option><?php endforeach ?></select>
                    <button class="calendar-primary" type="submit">Kaitkan konten</button>
                <?php endif ?>
            </form>
        <?php endif ?>
        <?php if ($detail['status'] !== 'archived'): ?>
            <details class="calendar-delete"><summary>Arsipkan kampanye</summary>
                <form method="post" action="<?= escape($detailAction) ?>">
                    <input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="archive_campaign"><input type="hidden" name="campaign_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="version" value="<?= (int) $detail['version'] ?>">
                    <p>Arsip mempertahankan konten dan riwayat. Kampanye tidak bisa diedit atau menerima konten baru. Tidak tersedia pemulihan arsip di UI.</p>
                    <label><input type="checkbox" name="confirm_archive" value="1" required> Saya yakin ingin mengarsipkan kampanye ini.</label><button class="calendar-danger" type="submit">Ya, arsipkan kampanye</button>
                </form>
            </details>
        <?php endif ?>
    </div>
</section>
<?php endif ?>
