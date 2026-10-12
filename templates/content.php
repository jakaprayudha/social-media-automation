<?php
declare(strict_types=1);
$selectedCompany = $contentData['selected_company'];
$detail = $contentData['detail'];
$contentBase = $app->url('admin') . '&' . http_build_query([
    'section' => 'content', 'company' => $selectedCompany['id'] ?? 'all',
    'campaign' => $contentData['selected_campaign']['id'] ?? 'all',
    'status' => $contentData['status'], 'q' => $contentData['search'], 'p' => $contentData['page'],
]);
$formAction = $contentEdit ? $contentBase . '&id=' . (int) $detail['id'] : $contentBase;
$contentCatalog = $contentData;
?>
<div class="page-heading"><span>EDITORIAL &amp; PERENCANAAN</span><h1>Ide &amp; draft konten</h1><p>Kumpulkan ide, lengkapi brief, dan pantau konten lintas perusahaan. Tidak ada AI atau publikasi otomatis dari halaman ini.</p></div>
<?php require __DIR__ . '/content-form.php'; ?>
<form class="calendar-filters company-filter content-filters" method="get" action="<?= escape($app->basePath() . '/index.php') ?>">
    <input type="hidden" name="page" value="admin"><input type="hidden" name="section" value="content">
    <div class="calendar-filter-field"><label for="library-search">Cari judul / brief</label><input id="library-search" type="search" name="q" maxlength="200" value="<?= escape($contentData['search']) ?>" placeholder="Cari ide konten..."></div>
    <div class="calendar-filter-field"><label for="library-company">Perusahaan</label><select id="library-company" name="company">
        <option value="all">Semua perusahaan</option>
        <?php foreach ($contentData['companies'] as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompany !== null && (int) $company['id'] === (int) $selectedCompany['id'] ? 'selected' : '' ?>><?= escape($company['name']) ?></option><?php endforeach ?>
    </select></div>
    <div class="calendar-filter-field"><label for="library-status">Status</label><select id="library-status" name="status">
        <option value="all">Semua status</option>
        <?php foreach (Dashboard::STATUSES as $value => $label): ?><option value="<?= escape($value) ?>" <?= $contentData['status'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option><?php endforeach ?>
    </select></div>
    <div class="calendar-filter-field"><label for="library-campaign">Kampanye</label><select id="library-campaign" name="campaign">
        <option value="all">Semua kampanye</option>
        <?php foreach ($contentData['campaigns'] as $campaign): if ($selectedCompany !== null && (int) $campaign['company_id'] !== (int) $selectedCompany['id']) { continue; } ?>
            <option value="<?= (int) $campaign['id'] ?>" <?= $contentData['selected_campaign'] !== null && (int) $campaign['id'] === (int) $contentData['selected_campaign']['id'] ? 'selected' : '' ?>><?= escape($campaign['name'] . ' — ' . $campaign['company_name']) ?></option>
        <?php endforeach ?>
    </select></div>
    <button type="submit">Terapkan filter</button>
    <a class="campaign-detail-link" href="<?= escape($app->url('admin') . '&section=content') ?>">Reset filter</a>
</form>
<section class="dashboard-panel campaign-list" aria-labelledby="library-title">
    <div class="panel-heading"><div><h2 id="library-title">Daftar konten</h2><p>Ide / Brief dapat diedit; status tahap lanjut hanya dibaca.</p></div><span class="count-chip"><?= $contentData['total'] ?> konten</span></div>
    <?php if ($contentData['items'] === []): ?>
        <div class="dashboard-empty"><?= icon('file') ?><h3>Belum ada konten sesuai filter</h3><p>Tambahkan ide baru atau ubah pencarian dan filter.</p></div>
    <?php else: ?>
        <div class="table-scroll"><table class="content-table"><caption class="visually-hidden">Konten sesuai pencarian dan filter</caption><thead><tr><th scope="col">Konten / perusahaan</th><th scope="col">Status / format</th><th scope="col">Kampanye</th><th scope="col">Rencana</th><th scope="col">Detail</th></tr></thead><tbody>
            <?php foreach ($contentData['items'] as $item): ?>
                <tr><td><strong><?= escape($item['title']) ?></strong><small><?= escape($item['company_name']) ?></small><small>Pemilik: <?= escape($item['owner_name']) ?></small></td>
                    <td><span class="status-pill"><?= escape(Dashboard::STATUSES[$item['status']]) ?></span><small><?= escape(ContentEditor::FORMATS[$item['format']] ?? $item['format']) ?></small></td>
                    <td><?= escape($item['campaign_name'] ?? 'Tanpa kampanye') ?><small><?= $item['ai_draft_id'] !== null ? 'Studio AI' : ($item['observance_id'] === null ? 'Manual' : 'Ide peringatan otomatis') ?></small></td>
                    <td><?= $item['planned_at'] === null ? 'Belum bertanggal' : escape(dashboardTime((int) $item['planned_at'], $item['company_timezone'])) ?><small><?= escape($item['company_timezone']) ?></small></td>
                    <td><a class="campaign-detail-link" href="<?= escape($contentBase . '&id=' . (int) $item['id'] . '#content-detail') ?>">Lihat detail</a></td></tr>
            <?php endforeach ?>
        </tbody></table></div>
    <?php endif ?>
    <nav class="content-pagination" aria-label="Halaman daftar konten">
        <?php if ($contentData['page'] > 1): ?><a class="calendar-secondary" href="<?= escape(preg_replace('/&p=[0-9]+$/', '&p=' . ($contentData['page'] - 1), $contentBase)) ?>">Sebelumnya</a><?php endif ?>
        <span>Halaman <?= $contentData['page'] ?> dari <?= $contentData['pages'] ?></span>
        <?php if ($contentData['page'] < $contentData['pages']): ?><a class="calendar-secondary" href="<?= escape(preg_replace('/&p=[0-9]+$/', '&p=' . ($contentData['page'] + 1), $contentBase)) ?>">Berikutnya</a><?php endif ?>
    </nav>
</section>
<?php if ($detail !== null):
    $detailAction = $contentBase . '&id=' . (int) $detail['id'];
    $canEdit = isset(ContentEditor::INITIAL_STATUSES[$detail['status']]) && $detail['company_status'] === 'active';
    $calendarAt = $detail['scheduled_at'] ?? $detail['planned_at'];
    $calendarMonth = (new DateTimeImmutable($calendarAt === null ? 'now' : '@' . $calendarAt))
        ->setTimezone(new DateTimeZone($detail['company_timezone']))->format('Y-m');
?>
<section class="dashboard-panel campaign-detail" id="content-detail" aria-labelledby="content-detail-title">
    <div class="panel-heading"><div><h2 id="content-detail-title"><?= escape($detail['title']) ?></h2><p><?= escape($detail['company_name']) ?> · <?= escape(Dashboard::STATUSES[$detail['status']]) ?></p></div>
        <?php if ($canEdit): ?><a class="calendar-secondary" href="<?= escape($detailAction . '&edit=1#content-create') ?>">Edit konten</a><?php endif ?>
    </div>
    <div class="automation-body">
        <dl class="campaign-description">
            <dt>Brief</dt><dd><?= escape($detail['brief'] === '' ? 'Belum ada brief.' : $detail['brief']) ?></dd>
            <dt>Pemilik / format</dt><dd><?= escape($detail['owner_name'] . ' / ' . (ContentEditor::FORMATS[$detail['format']] ?? $detail['format'])) ?></dd>
            <dt>Tanggal rencana</dt><dd><?= $detail['planned_at'] === null ? 'Belum bertanggal' : escape(dashboardTime((int) $detail['planned_at'], $detail['company_timezone'])) ?> · <?= escape($detail['company_timezone']) ?></dd>
            <dt>Jadwal publikasi tersimpan</dt><dd><?= $detail['scheduled_at'] === null ? 'Belum dijadwalkan' : escape(dashboardTime((int) $detail['scheduled_at'], $detail['company_timezone'])) ?></dd>
            <dt>Kampanye</dt><dd><?= escape($detail['campaign_name'] ?? 'Tanpa kampanye') ?></dd>
            <dt>Asal konten</dt><dd><?= $detail['ai_draft_id'] !== null ? 'Draft teks dari Studio AI; aset final dan persetujuan belum tersedia.' : ($detail['observance_id'] === null ? 'Manual' : 'Ide otomatis dari peringatan tahunan; menghapusnya tidak membuat ide ini muncul kembali.') ?></dd>
        </dl>
        <a class="calendar-secondary" href="<?= escape($app->url('admin') . '&' . http_build_query(['section' => 'calendar', 'company' => $detail['company_id'], 'month' => $calendarMonth])) ?>">Buka kalender perusahaan</a>
        <?php if ($detail['ai_draft_id'] !== null): ?><a class="calendar-secondary" href="<?= escape($app->url('admin') . '&section=ai&id=' . (int) $detail['ai_draft_id'] . '#ai-detail') ?>">Lihat hasil AI per platform</a><?php endif ?>
        <?php if ($detail['campaign_id'] !== null): ?><a class="calendar-secondary" href="<?= escape($app->url('admin') . '&section=campaigns&id=' . (int) $detail['campaign_id']) ?>">Buka kampanye</a>
        <?php else: ?><p>Kaitkan Ide/Brief ke kampanye melalui detail kampanye. Perusahaan dan kaitan yang sudah ada tidak diubah saat edit.</p><?php endif ?>
        <?php if (isset(ContentEditor::INITIAL_STATUSES[$detail['status']])): ?>
            <form class="content-delete-form" method="post" action="<?= escape($detailAction) ?>">
                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="delete_content"><input type="hidden" name="content_id" value="<?= (int) $detail['id'] ?>">
                <label><input type="checkbox" name="confirm_delete" value="1" required> Saya yakin menghapus konten ini dari daftar dan kalender.</label>
                <button class="calendar-danger" type="submit">Hapus konten</button>
            </form>
        <?php else: ?><p>Konten tahap lanjut hanya dapat dibaca. Hasil Studio AI dapat dibuka melalui tautan di atas bila tersedia; pengeditan hasil, review, dan publikasi belum tersedia.</p><?php endif ?>
    </div>
</section>
<?php endif ?>
