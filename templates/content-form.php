<?php
declare(strict_types=1);
$contentEdit = $contentEdit ?? false;
?>
<details class="content-create dashboard-panel" id="content-create" <?= $contentFormOpen ? 'open' : '' ?>>
    <summary><?= $contentEdit ? 'Edit konten' : '+ Tambah konten' ?></summary>
    <form class="content-create-form" method="post" action="<?= escape($formAction) ?>">
        <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
        <input type="hidden" name="action" value="<?= $contentEdit ? 'update_content' : 'create_content' ?>">
        <input type="hidden" name="creation_key" value="<?= escape($contentForm['creation_key']) ?>">
        <?php if ($contentEdit): ?>
            <input type="hidden" name="content_id" value="<?= (int) $contentData['detail']['id'] ?>">
            <input type="hidden" name="version" value="<?= escape((string) $contentVersion) ?>">
        <?php endif ?>
        <p>Buat atau lengkapi ide dan brief. Tanggal rencana menggunakan zona waktu perusahaan dan tidak memicu publikasi.</p>
        <div class="content-form-grid">
            <?php if ($contentEdit): ?>
                <div><strong>Perusahaan</strong><p><?= escape($contentData['detail']['company_name']) ?> (tidak dapat diganti)</p><input type="hidden" name="company_id" value="<?= escape($contentForm['company_id']) ?>"></div>
                <div><strong>Kampanye</strong><p><?= escape($contentData['detail']['campaign_name'] ?? 'Tanpa kampanye') ?> (kaitan tetap)</p><input type="hidden" name="campaign_id" value="<?= escape($contentForm['campaign_id']) ?>"></div>
            <?php else: ?>
                <div><label for="content-company">Perusahaan</label><select id="content-company" name="company_id" required>
                    <option value="">Pilih perusahaan aktif</option>
                    <?php foreach ($contentCatalog['companies'] as $company): if ($company['status'] !== 'active') { continue; } ?>
                        <option value="<?= (int) $company['id'] ?>" <?= (string) $company['id'] === ($contentForm['company_id'] !== '' ? $contentForm['company_id'] : (string) ($selectedCompany['id'] ?? '')) ? 'selected' : '' ?>><?= escape($company['name']) ?> (<?= escape($company['timezone']) ?>)</option>
                    <?php endforeach ?>
                </select></div>
                <div><label for="content-campaign">Kampanye (opsional)</label><select id="content-campaign" name="campaign_id">
                    <option value="">Tanpa kampanye</option>
                    <?php foreach ($contentCatalog['campaigns'] as $campaign): if ($campaign['status'] === 'archived') { continue; } ?>
                        <option value="<?= (int) $campaign['id'] ?>" data-company-id="<?= (int) $campaign['company_id'] ?>" <?= (string) $campaign['id'] === $contentForm['campaign_id'] ? 'selected' : '' ?>><?= escape($campaign['name'] . ' — ' . $campaign['company_name']) ?></option>
                    <?php endforeach ?>
                </select><small>Harus dari perusahaan yang sama; kampanye arsip tidak menerima konten baru.</small></div>
            <?php endif ?>
            <div><label for="content-title">Judul konten</label><input id="content-title" name="title" maxlength="200" value="<?= escape($contentForm['title']) ?>" required></div>
            <div><label for="content-status"><?= $contentEdit ? 'Status konten' : 'Status awal' ?></label><select id="content-status" name="status">
                <?php foreach (ContentEditor::INITIAL_STATUSES as $value => $label): ?><option value="<?= escape($value) ?>" <?= $contentForm['status'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option><?php endforeach ?>
            </select></div>
            <div><label for="content-format">Format</label><select id="content-format" name="format">
                <?php foreach (ContentEditor::FORMATS as $value => $label): ?><option value="<?= escape($value) ?>" <?= $contentForm['format'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option><?php endforeach ?>
            </select></div>
            <div><label for="content-planned-at">Tanggal &amp; waktu rencana (opsional)</label><input id="content-planned-at" type="datetime-local" name="planned_at" min="2000-01-01T00:00" max="2099-12-31T23:59" value="<?= escape($contentForm['planned_at']) ?>"><small>Menggunakan zona waktu perusahaan, bukan zona browser.</small></div>
            <div class="campaign-wide"><label for="content-brief">Brief (opsional)</label><textarea id="content-brief" name="brief" rows="4" maxlength="5000"><?= escape($contentForm['brief']) ?></textarea></div>
        </div>
        <button class="calendar-primary" type="submit">Simpan konten</button>
        <?php if ($contentEdit): ?><a class="calendar-secondary" href="<?= escape($contentBase . '&id=' . (int) $contentData['detail']['id']) ?>">Batal edit</a><?php endif ?>
    </form>
</details>
