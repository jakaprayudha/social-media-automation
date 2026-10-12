<?php
declare(strict_types=1);
$selectedCompany = $studioData['selected_company'];
$detail = $studioData['detail'];
$studioBase = $app->url('admin') . '&' . http_build_query(['section' => 'ai', 'company' => $selectedCompany['id'] ?? 'all']);
?>
<div class="page-heading"><span>KREASI MULTIPLATFORM</span><h1>Studio AI</h1><p>Satu brief untuk blog, konten umum, Instagram, YouTube, TikTok, dan Facebook. Buat draft teks dengan Google Gemini, lalu periksa sebelum posting.</p></div>
<section class="dashboard-panel ai-capabilities" aria-labelledby="ai-capability-title">
    <div class="panel-heading"><div><h2 id="ai-capability-title">Teks sekarang, media final bertahap</h2><p>Generasi tidak berjalan saat membuka halaman.</p></div><span class="count-chip"><?= $studioData['configured'] ? 'Gemini terkonfigurasi' : 'Gemini belum dikonfigurasi' ?></span></div>
    <div class="ai-capability-grid">
        <div><?= icon('file') ?><h3>Blog &amp; caption</h3><p>Artikel, judul SEO, hook, CTA, hashtag, dan variasi per platform.</p><small><?= $studioData['configured'] ? 'Tersedia melalui antrean worker' : 'Memerlukan API key Gemini' ?></small></div>
        <div><?= icon('image') ?><h3>Gambar</h3><p>Brief visual, arahan komposisi, dan alt text.</p><small>Belum menghasilkan file gambar</small></div>
        <div><?= icon('calendar') ?><h3>Video</h3><p>Skrip, storyboard, hook, narasi, dan caption.</p><small>Belum menghasilkan file video</small></div>
        <div><?= icon('file') ?><h3>Audio</h3><p>Naskah voice-over dan arahan audio.</p><small>Belum menghasilkan file audio</small></div>
    </div>
    <div class="automation-body"><p>Hasil adalah draft, bukan jaminan siap post. Periksa fakta, hak pakai aset/musik, disclosure AI, dan format platform. Upload/analisis gambar, video, audio serta publikasi langsung belum tersedia pada tahap ini.</p>
        <?php if (!$studioData['configured']): ?><p class="error-notice">API Gemini belum dikonfigurasi. Anda bisa menyimpan brief, tetapi generate dinonaktifkan. Administrator perlu mengatur <code>ai</code> di konfigurasi privat; jangan kirim API key lewat chat.</p><?php endif ?>
        <p>Generasi memakai worker <code>php bin/console.php ai:work</code>, satu brief per eksekusi. Belum ada scheduler AI yang dipasang; antrean tidak otomatis diproses hanya dengan membuka halaman. Model/kuota bergantung akun Gemini Anda dan dapat dikenai biaya.</p>
    </div>
</section>
<details class="content-create dashboard-panel" id="ai-create" <?= $aiFormOpen ? 'open' : '' ?>>
    <summary>+ Buat brief Studio AI</summary>
    <form class="content-create-form" method="post" action="<?= escape($studioBase) ?>">
        <input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="create_ai_brief"><input type="hidden" name="creation_key" value="<?= escape($aiForm['creation_key']) ?>">
        <p>Brief tersimpan lokal terlebih dahulu. Gunakan informasi publik yang disetujui, bukan password, data pelanggan, atau rahasia perusahaan. Brief tersimpan tidak dapat diedit; buat brief baru untuk revisi atau regenerasi.</p>
        <div class="content-form-grid">
            <div><label for="content-company">Perusahaan</label><select id="content-company" name="company_id" required><option value="">Pilih perusahaan aktif</option>
                <?php foreach ($studioData['companies'] as $company): if ($company['status'] !== 'active') { continue; } ?><option value="<?= (int) $company['id'] ?>" <?= (string) $company['id'] === ($aiForm['company_id'] ?: (string) ($selectedCompany['id'] ?? '')) ? 'selected' : '' ?>><?= escape($company['name']) ?></option><?php endforeach ?>
            </select></div>
            <div><label for="content-campaign">Kampanye (opsional)</label><select id="content-campaign" name="campaign_id"><option value="">Tanpa kampanye</option>
                <?php foreach ($studioData['campaigns'] as $campaign): if ($campaign['status'] === 'archived') { continue; } ?><option value="<?= (int) $campaign['id'] ?>" data-company-id="<?= (int) $campaign['company_id'] ?>" <?= (string) $campaign['id'] === $aiForm['campaign_id'] ? 'selected' : '' ?>><?= escape($campaign['name'] . ' — ' . $campaign['company_name']) ?></option><?php endforeach ?>
            </select></div>
            <div><label for="ai-title">Judul / topik</label><input id="ai-title" name="title" maxlength="200" value="<?= escape($aiForm['title']) ?>" required></div>
            <div><label for="ai-media">Jenis hasil yang direncanakan</label><select id="ai-media" name="media_type"><?php foreach (AiStudio::MEDIA as $value => $label): ?><option value="<?= escape($value) ?>" <?= $aiForm['media_type'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option><?php endforeach ?></select></div>
            <div><label for="ai-audience">Audiens</label><input id="ai-audience" name="audience" maxlength="500" value="<?= escape($aiForm['audience']) ?>" placeholder="Contoh: pemilik UMKM Indonesia" required></div>
            <div><label for="ai-tone">Gaya bahasa</label><input id="ai-tone" name="tone" maxlength="200" value="<?= escape($aiForm['tone']) ?>" required></div>
            <div class="campaign-wide"><label for="ai-brief">Brief &amp; tujuan</label><textarea id="ai-brief" name="brief" rows="5" maxlength="5000" required><?= escape($aiForm['brief']) ?></textarea></div>
            <div class="campaign-wide"><label for="ai-facts">Fakta publik yang disetujui (opsional)</label><textarea id="ai-facts" name="facts" rows="3" maxlength="3000"><?= escape($aiForm['facts']) ?></textarea><small>Masukkan hanya fakta yang boleh dikirim ke Gemini. Tanpa fakta, AI tidak boleh mengarang klaim produk.</small></div>
        </div>
        <fieldset class="ai-platforms"><legend>Platform tujuan (pilih minimal satu)</legend><?php foreach (AiStudio::PLATFORMS as $value => $label): ?><label><input type="checkbox" name="platforms[]" value="<?= escape($value) ?>" <?= in_array($value, $aiPlatforms, true) ? 'checked' : '' ?>> <?= escape($label) ?></label><?php endforeach ?></fieldset>
        <button class="calendar-primary" type="submit">Simpan brief</button>
    </form>
</details>
<form class="calendar-filters company-filter" method="get" action="<?= escape($app->basePath() . '/index.php') ?>">
    <input type="hidden" name="page" value="admin"><input type="hidden" name="section" value="ai">
    <div class="calendar-filter-field"><label for="ai-company-filter">Perusahaan</label><select id="ai-company-filter" name="company"><option value="all">Semua perusahaan</option><?php foreach ($studioData['companies'] as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompany !== null && (int) $company['id'] === (int) $selectedCompany['id'] ? 'selected' : '' ?>><?= escape($company['name']) ?></option><?php endforeach ?></select></div><button type="submit">Terapkan filter</button>
</form>
<section class="dashboard-panel campaign-list" aria-labelledby="ai-history-title">
    <div class="panel-heading"><div><h2 id="ai-history-title">Riwayat Studio AI</h2><p>Maksimal 50 brief terbaru sesuai perusahaan. Semua brief tetap tersimpan.</p></div><span class="count-chip"><?= count($studioData['items']) ?> ditampilkan</span></div>
    <?php if ($studioData['items'] === []): ?><div class="dashboard-empty"><?= icon('spark') ?><h3>Belum ada brief AI</h3><p>Mulai dari topik, audiens, dan platform tujuan. Tidak ada hasil AI contoh yang dibuat otomatis.</p></div>
    <?php else: ?><div class="table-scroll"><table class="content-table"><caption class="visually-hidden">Riwayat brief Studio AI</caption><thead><tr><th scope="col">Brief / perusahaan</th><th scope="col">Hasil</th><th scope="col">Status</th><th scope="col">Detail</th></tr></thead><tbody><?php foreach ($studioData['items'] as $item): ?><tr><td><strong><?= escape($item['title']) ?></strong><small><?= escape($item['company_name']) ?></small><small><?= escape($item['owner_name']) ?></small></td><td><?= escape(AiStudio::MEDIA[$item['media_type']]) ?></td><td><span class="status-pill"><?= escape(AiStudio::STATUSES[$item['status']]) ?></span><small><?= $item['content_id'] === null ? 'Belum disimpan ke konten' : 'Tersimpan sebagai Draft AI' ?></small></td><td><a class="campaign-detail-link" href="<?= escape($studioBase . '&id=' . (int) $item['id'] . '#ai-detail') ?>">Lihat detail</a></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
</section>
<?php if ($detail !== null): $detailAction = $studioBase . '&id=' . (int) $detail['id']; ?>
<section class="dashboard-panel campaign-detail" id="ai-detail" aria-labelledby="ai-detail-title">
    <div class="panel-heading"><div><h2 id="ai-detail-title"><?= escape($detail['title']) ?></h2><p><?= escape($detail['company_name']) ?> · <?= escape(AiStudio::STATUSES[$detail['status']]) ?></p></div><a class="calendar-secondary" href="<?= escape($detailAction . '#ai-detail') ?>">Muat ulang status</a></div>
    <div class="automation-body">
        <dl class="campaign-description"><dt>Brief</dt><dd><?= escape($detail['brief']) ?></dd><dt>Audiens / gaya</dt><dd><?= escape($detail['audience'] . ' / ' . $detail['tone']) ?></dd><dt>Fakta publik</dt><dd><?= escape($detail['facts'] ?: 'Tidak diberikan; jangan mengarang klaim.') ?></dd><dt>Platform / jenis hasil</dt><dd><?= escape(implode(', ', array_map(fn (string $platform): string => AiStudio::PLATFORMS[$platform], $detail['platform_list'])) . ' / ' . AiStudio::MEDIA[$detail['media_type']]) ?></dd><dt>Kampanye</dt><dd><?= escape($detail['campaign_name'] ?? 'Tanpa kampanye') ?></dd><dt>Model / percobaan</dt><dd><?= escape($detail['model'] ?? 'Belum dijalankan') ?> / <?= (int) $detail['attempt_count'] ?> dari 3 · <?= escape($detail['prompt_version']) ?></dd></dl>
        <?php if ($detail['last_error'] !== null): ?><p class="error-notice" role="alert"><?= escape(AiStudio::ERRORS[$detail['last_error']]) ?></p><?php endif ?>
        <?php if (in_array($detail['status'], ['brief', 'failed'], true) && (int) $detail['attempt_count'] < 3): ?>
            <form class="ai-action-form" method="post" action="<?= escape($detailAction) ?>">
                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="generate_ai"><input type="hidden" name="draft_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="version" value="<?= (int) $detail['version'] ?>">
                <label><input type="checkbox" name="consent" value="1" required <?= $studioData['configured'] ? '' : 'disabled' ?>> Saya menyetujui pengiriman nama perusahaan, brief, audiens, gaya, fakta publik, dan platform ke Google Gemini serta kemungkinan biaya API. Tidak memuat data rahasia.</label>
                <button class="calendar-primary" type="submit" <?= $studioData['configured'] ? '' : 'disabled' ?>><?= $detail['status'] === 'failed' ? 'Coba ulang generasi' : 'Generate draft teks' ?></button>
            </form>
        <?php elseif ($detail['status'] === 'queued' || $detail['status'] === 'processing'): ?><p>Menunggu / sedang diproses worker. Muat ulang status setelah worker selesai. Tidak ada retry otomatis untuk menghindari biaya ganda.</p>
        <?php elseif ($detail['status'] === 'failed'): ?><p>Batas tiga percobaan tercapai. Perbaiki konfigurasi atau brief sebelum membuat brief baru.</p><?php endif ?>
        <?php if ($detail['result'] !== null): ?>
            <p class="notice">Draft teks berhasil dihasilkan. Gambar, video, dan audio di bawah adalah brief/naskah, bukan file final. Belum disetujui atau diposting.</p>
            <a class="calendar-secondary" href="<?= escape($detailAction . '&download=1') ?>">Unduh paket teks (.txt)</a>
            <?php foreach ($detail['result']['variants'] as $variant): ?>
                <details class="ai-result" open><summary><?= escape(AiStudio::PLATFORMS[$variant['platform']]) ?> · <?= escape($variant['title']) ?></summary><div class="content-form-grid">
                    <?php foreach (AiStudio::OUTPUT_FIELDS as $field => $label): if ($variant[$field] === '') { continue; } $fieldId = 'result-' . $variant['platform'] . '-' . $field; ?>
                        <div class="campaign-wide"><label for="<?= escape($fieldId) ?>"><?= escape($label) ?></label><textarea id="<?= escape($fieldId) ?>" rows="<?= in_array($field, ['body', 'script'], true) ? '8' : '3' ?>" readonly><?= escape($variant[$field]) ?></textarea></div>
                    <?php endforeach ?>
                </div><h3>Pemeriksaan sebelum posting</h3><ul><?php foreach ($variant['checks'] as $check): ?><li><?= escape($check) ?></li><?php endforeach ?></ul></details>
            <?php endforeach ?>
            <?php if ($detail['content_id'] === null): ?>
                <form class="ai-action-form" method="post" action="<?= escape($detailAction) ?>">
                    <input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="save_ai_content"><input type="hidden" name="draft_id" value="<?= (int) $detail['id'] ?>">
                    <label><input type="checkbox" name="confirm_draft" value="1" required> Simpan sebagai Draft AI yang masih perlu review, bukan persetujuan atau publikasi.</label><button class="calendar-primary" type="submit">Simpan ke Ide &amp; Draft</button>
                </form>
            <?php else: ?><a class="calendar-primary" href="<?= escape($app->url('admin') . '&section=content&id=' . (int) $detail['content_id']) ?>">Buka Draft AI tersimpan</a><?php endif ?>
        <?php endif ?>
    </div>
</section>
<?php endif ?>
