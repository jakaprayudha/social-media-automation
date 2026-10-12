<?php
declare(strict_types=1);
$imageValues = isset($imageForm) && $imageForm['platform'] === $variant['platform'] ? $imageForm : [
    'prompt' => $variant['image_brief'], 'aspect_ratio' => '1:1', 'style' => 'photo',
];
$prefix = 'image-' . $variant['platform'];
?>
<details class="ai-result ai-image-generator">
    <summary>Generate gambar dari brief <?= escape(AiStudio::PLATFORMS[$variant['platform']]) ?></summary>
    <form class="ai-action-form" method="post" action="<?= escape($detailAction) ?>">
        <input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="generate_image">
        <input type="hidden" name="draft_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="image_platform" value="<?= escape($variant['platform']) ?>">
        <input type="hidden" name="image_creation_key" value="<?= escape($_SESSION['ai_image_key']) ?>">
        <p>Revisi prompt untuk variasi baru; hasil sebelumnya tetap tersimpan. Maksimal 10 permintaan per brief. Tanpa teks/logo agar headline dan identitas resmi bisa ditambahkan terpisah.</p>
        <p>AI membaca judul/topik, brief, audiens, gaya bahasa, fakta, serta seluruh hasil platform ini: judul, caption, hook, CTA, alt text, artikel/naskah, hashtag, SEO, dan brief media. Teks tersebut menjadi konteks visual, bukan otomatis ditulis pada gambar.</p>
        <div class="content-form-grid">
            <div class="campaign-wide"><label for="<?= escape($prefix) ?>-prompt">Prompt / brief gambar</label><textarea id="<?= escape($prefix) ?>-prompt" name="image_prompt" maxlength="4000" rows="5" required><?= escape($imageValues['prompt']) ?></textarea></div>
            <div><label for="<?= escape($prefix) ?>-ratio">Rasio gambar</label><select id="<?= escape($prefix) ?>-ratio" name="image_aspect_ratio"><?php foreach (AiImages::RATIOS as $value => $label): ?><option value="<?= escape($value) ?>" <?= $value === $imageValues['aspect_ratio'] ? 'selected' : '' ?>><?= escape($label) ?></option><?php endforeach ?></select></div>
            <div><label for="<?= escape($prefix) ?>-style">Gaya visual</label><select id="<?= escape($prefix) ?>-style" name="image_style"><?php foreach (AiImages::STYLES as $value => $label): ?><option value="<?= escape($value) ?>" <?= $value === $imageValues['style'] ? 'selected' : '' ?>><?= escape($label) ?></option><?php endforeach ?></select></div>
        </div>
        <label><input type="checkbox" name="image_consent" value="1" required> Saya menyetujui biaya API dan pengiriman prompt, topik, brief, audiens, gaya bahasa, fakta, seluruh hasil teks platform ini, gaya visual, serta rasio ke Gemini. Tidak memuat rahasia atau permintaan yang melanggar hak orang lain.</label>
        <p>Proses langsung dapat memerlukan hingga 90 detik. Jangan klik ulang / refresh saat memproses. Timeout bisa tetap dikenai biaya; tidak ada retry otomatis.</p>
        <button class="calendar-primary" type="submit" <?= GeminiImage::configured($app) ? '' : 'disabled' ?>><?= $aiImages === [] ? 'Generate gambar' : 'Generate gambar baru / ulang' ?></button>
    </form>
</details>
