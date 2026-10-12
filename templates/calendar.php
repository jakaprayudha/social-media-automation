<?php
declare(strict_types=1);
$selectedCompany = $calendar['selected_company'];
$scopeName = $selectedCompany['name'] ?? 'Semua perusahaan';
$months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$monthLabel = $months[(int) $calendar['start']->format('n') - 1] . ' ' . $calendar['start']->format('Y');
$calendarUrl = $app->url('admin') . '&' . http_build_query([
    'section' => 'calendar', 'company' => $selectedCompany['id'] ?? 'all', 'status' => $calendar['status'],
    'campaign' => $calendar['selected_campaign']['id'] ?? 'all',
]);
$offset = (int) $calendar['start']->format('N') - 1;
$dayCount = (int) $calendar['start']->format('t');
$cellCount = (int) (ceil(($offset + $dayCount) / 7) * 7);
$automationEnabled = $calendar['automation'] !== null && (int) $calendar['automation']['enabled'] === 1;
$formAction = $calendarUrl . '&month=' . $calendar['month'];
?>
<div class="page-heading"><span>PERENCANAAN KONTEN</span><h1>Kalender konten</h1><p>Pantau tanggal konten dan status editorial dalam satu kalender.</p></div>
<?php $contentCatalog = $calendar; require __DIR__ . '/content-form.php'; ?>
<section class="dashboard-panel observance-automation" aria-labelledby="automation-title">
    <div class="panel-heading"><div><h2 id="automation-title">Ide otomatis dari hari peringatan</h2><p><?= $calendar['catalog_count'] ?> peringatan terkurasi nasional/internasional, tanggal tetap. Bukan daftar lengkap hari libur; hari raya bertanggal berubah belum tersedia.</p></div><span class="count-chip"><?= $automationEnabled ? 'Aktif' : 'Belum aktif / dijeda' ?></span></div>
    <div class="automation-body">
        <p>Satu ide per peringatan, tahun, dan perusahaan aktif. Rencana pukul 09:00 di zona perusahaan. Tidak menimpa konten dan tidak menerbitkan posting.</p>
        <?php if ($calendar['automation'] !== null): ?><p>Pemilik ide baru: <?= escape($calendar['automation']['owner_name']) ?>. Proses terakhir: <?= $calendar['automation']['last_run_at'] === null ? 'Belum dijalankan' : escape(dashboardTime((int) $calendar['automation']['last_run_at'], 'Asia/Jakarta')) . ' WIB' ?>.</p><?php endif ?>
        <p>Pengisian tahun berjalan tanpa membuka halaman memerlukan cron <code>php bin/console.php calendar:generate</code> (juga diproses oleh <code>mail:work</code>). Status aktif bukan jaminan cron sudah terpasang.</p>
        <form method="post" action="<?= escape($formAction) ?>">
            <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
            <?php if ($automationEnabled): ?>
                <button class="calendar-primary" type="submit" name="action" value="generate_ideas">Lengkapi ide tahun <?= escape(substr($calendar['month'], 0, 4)) ?></button>
                <button class="calendar-secondary" type="submit" name="action" value="automation_disable">Jeda ide otomatis</button>
            <?php else: ?>
                <button class="calendar-primary" type="submit" name="action" value="automation_enable">Aktifkan &amp; buat ide tahun <?= escape(substr($calendar['month'], 0, 4)) ?></button>
            <?php endif ?>
        </form>
    </div>
</section>
<form class="calendar-filters company-filter" method="get" action="<?= escape($app->basePath() . '/index.php') ?>">
    <input type="hidden" name="page" value="admin">
    <input type="hidden" name="section" value="calendar">
    <div class="calendar-filter-field"><label for="calendar-campaign">Kampanye</label><select id="calendar-campaign" name="campaign">
        <option value="all">Semua kampanye</option>
        <?php foreach ($calendar['campaigns'] as $campaign): if ($selectedCompany !== null && (int) $campaign['company_id'] !== (int) $selectedCompany['id']) { continue; } ?>
            <option value="<?= (int) $campaign['id'] ?>" <?= $calendar['selected_campaign'] !== null && (int) $campaign['id'] === (int) $calendar['selected_campaign']['id'] ? 'selected' : '' ?>><?= escape($campaign['name'] . ' — ' . $campaign['company_name']) ?></option>
        <?php endforeach ?>
    </select></div>
    <div class="calendar-filter-field"><label for="calendar-company">Perusahaan</label><select id="calendar-company" name="company">
        <option value="all" <?= $selectedCompany === null ? 'selected' : '' ?>>Semua perusahaan</option>
        <?php foreach ($calendar['companies'] as $company): ?>
            <option value="<?= (int) $company['id'] ?>" <?= $selectedCompany !== null && (int) $selectedCompany['id'] === (int) $company['id'] ? 'selected' : '' ?>><?= escape($company['name']) ?></option>
        <?php endforeach ?>
    </select></div>
    <div class="calendar-filter-field"><label for="calendar-status">Status konten</label><select id="calendar-status" name="status">
        <option value="all" <?= $calendar['status'] === 'all' ? 'selected' : '' ?>>Semua status</option>
        <?php foreach (Dashboard::STATUSES as $status => $label): ?>
            <option value="<?= escape($status) ?>" <?= $calendar['status'] === $status ? 'selected' : '' ?>><?= escape($label) ?></option>
        <?php endforeach ?>
    </select></div>
    <div class="calendar-filter-field"><label for="calendar-month">Bulan</label><input id="calendar-month" name="month" type="month" min="2000-01" max="2099-12" value="<?= escape($calendar['month']) ?>" required></div>
    <button type="submit">Terapkan filter</button>
</form>
<div class="dashboard-context"><span class="scope-chip"><?= icon('building') ?><?= escape($scopeName) ?></span><span>Zona waktu kalender: <?= escape($calendar['timezone']) ?> · Data SQLite</span></div>
<section class="dashboard-panel calendar-panel" aria-labelledby="calendar-title">
    <div class="panel-heading calendar-toolbar">
        <div><h2 id="calendar-title"><?= escape($monthLabel) ?></h2><p><?= $calendar['total'] ?> konten bertanggal · <?= $calendar['observance_count'] ?> peringatan · <?= $calendar['unscheduled'] ?> belum memiliki tanggal (seluruh periode, sesuai filter)</p></div>
        <nav class="calendar-navigation" aria-label="Navigasi bulan">
            <?php if ($calendar['previous_month'] !== null): ?><a href="<?= escape($calendarUrl . '&month=' . $calendar['previous_month']) ?>" aria-label="Bulan sebelumnya">&larr;</a><?php endif ?>
            <a href="<?= escape($calendarUrl . '&month=' . substr($calendar['today'], 0, 7)) ?>">Bulan ini</a>
            <?php if ($calendar['next_month'] !== null): ?><a href="<?= escape($calendarUrl . '&month=' . $calendar['next_month']) ?>" aria-label="Bulan berikutnya">&rarr;</a><?php endif ?>
        </nav>
    </div>
    <?php if ($calendar['total'] === 0): ?><p class="calendar-empty" role="status">Belum ada konten bertanggal pada bulan ini untuk filter yang dipilih.</p><?php endif ?>
    <p class="calendar-hint">Klik tanggal atau + Tambah untuk membuat konten. Buka detail konten untuk menghapus Ide/Brief.</p>
    <div class="calendar-scroll" tabindex="0" role="region" aria-label="Kalender <?= escape($monthLabel) ?>">
        <table class="calendar-table">
            <caption class="visually-hidden"><?= escape($monthLabel . ' — ' . $scopeName . ' — ' . $calendar['timezone']) ?></caption>
            <thead><tr><?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as $weekday): ?><th scope="col"><?= $weekday ?></th><?php endforeach ?></tr></thead>
            <tbody>
            <?php for ($cell = 0; $cell < $cellCount; $cell++):
                $day = $cell - $offset + 1;
                $inMonth = $day >= 1 && $day <= $dayCount;
                $date = $inMonth ? sprintf('%s-%02d', $calendar['month'], $day) : '';
            ?>
                <?php if ($cell % 7 === 0): ?><tr><?php endif ?>
                <td class="<?= !$inMonth ? 'calendar-outside' : ($date === $calendar['today'] ? 'calendar-today' : '') ?>" <?= $inMonth ? 'data-calendar-date="' . escape($date) . '"' : '' ?>>
                    <?php if ($inMonth): ?>
                        <div class="calendar-day-heading">
                            <a class="calendar-date-link" data-add-date="<?= escape($date) ?>" href="<?= escape($formAction . '&date=' . $date . '#content-create') ?>" aria-label="Tambah konten tanggal <?= escape($date) ?>"><time class="calendar-day" datetime="<?= escape($date) ?>" <?= $date === $calendar['today'] ? 'aria-current="date"' : '' ?>><?= $day ?><span class="visually-hidden"> <?= escape($monthLabel) ?></span></time></a>
                            <a class="calendar-add" data-add-date="<?= escape($date) ?>" href="<?= escape($formAction . '&date=' . $date . '#content-create') ?>" aria-label="Tambah konten tanggal <?= escape($date) ?>">+<span> Tambah</span></a>
                        </div>
                        <?php foreach ($calendar['observances'][$date] ?? [] as $observance): ?>
                            <details class="calendar-observance">
                                <summary><?= escape($observance['name']) ?><small><?= $observance['category'] === 'national' ? 'Nasional' : 'Internasional' ?> · peringatan</small></summary>
                                <p>Tanggal tetap; tidak selalu hari libur. Rujukan diperiksa <?= escape($observance['verified_on']) ?>.</p>
                                <a href="<?= escape($observance['source_url']) ?>" target="_blank" rel="noopener noreferrer">Lihat sumber tanggal</a>
                            </details>
                        <?php endforeach ?>
                        <?php foreach ($calendar['days'][$date] ?? [] as $content): ?>
                            <details class="calendar-event status-<?= escape($content['status']) ?>">
                                <summary><span class="calendar-event-time"><?= escape((new DateTimeImmutable('@' . $content['calendar_at']))->setTimezone(new DateTimeZone($calendar['timezone']))->format('H:i')) ?> · <?= $content['scheduled_at'] === null ? 'Rencana' : 'Jadwal' ?></span><strong><?= escape($content['title']) ?></strong><small><?= escape($content['company_name']) ?><?= $content['observance_id'] !== null ? ' · Ide otomatis' : '' ?></small><span class="status-pill"><?= escape(Dashboard::STATUSES[$content['status']]) ?></span></summary>
                                <dl class="calendar-detail">
                                    <dt>Pemilik</dt><dd><?= escape($content['owner_name']) ?></dd>
                                    <dt>Kampanye</dt><dd><?= $content['campaign_name'] === null ? 'Tanpa kampanye' : escape($content['campaign_name']) ?></dd>
                                    <dt>Format</dt><dd><?= escape(ContentEditor::FORMATS[$content['format']] ?? $content['format']) ?></dd>
                                    <dt>Waktu perusahaan</dt><dd><?= escape(dashboardTime((int) $content['calendar_at'], $content['company_timezone'])) ?><br><?= escape($content['company_timezone']) ?></dd>
                                    <dt>Brief</dt><dd><?= $content['brief'] === '' ? 'Belum ditambahkan' : escape($content['brief']) ?></dd>
                                </dl>
                                <a class="campaign-detail-link" href="<?= escape($app->url('admin') . '&section=content&id=' . (int) $content['id']) ?>">Detail / edit konten</a>
                                <?php if (isset(ContentEditor::INITIAL_STATUSES[$content['status']])): ?>
                                    <details class="calendar-delete">
                                        <summary>Hapus konten</summary>
                                        <form method="post" action="<?= escape($formAction) ?>">
                                            <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                                            <input type="hidden" name="action" value="delete_content">
                                            <input type="hidden" name="content_id" value="<?= (int) $content['id'] ?>">
                                            <p>Hapus &ldquo;<?= escape($content['title']) ?>&rdquo;? Ide otomatis tidak akan dibuat ulang. Tidak tersedia tombol pulihkan.</p>
                                            <label><input type="checkbox" name="confirm_delete" value="1" required> Saya yakin ingin menghapus konten ini.</label>
                                            <button class="calendar-danger" type="submit">Ya, hapus konten</button>
                                        </form>
                                    </details>
                                <?php endif ?>
                            </details>
                        <?php endforeach ?>
                    <?php endif ?>
                </td>
                <?php if ($cell % 7 === 6): ?></tr><?php endif ?>
            <?php endfor ?>
            </tbody>
        </table>
    </div>
    <div class="implementation-note"><?= icon('shield') ?><p>Kalender memakai tanggal rencana atau jadwal tersimpan per item, bukan jadwal per akun/platform. Peringatan tetap tampil meski filter status berubah. Klik konten/peringatan untuk detail. Edit jadwal, AI generatif, persetujuan, dan publikasi belum tersedia.</p></div>
</section>
