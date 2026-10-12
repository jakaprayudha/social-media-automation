<?php
declare(strict_types=1);
?>
<h3>Riwayat &amp; preview gambar</h3>
<?php if ($aiImages === []): ?><p>Belum ada gambar. Buka Generate gambar di bawah brief visual platform.</p><?php endif ?>
<?php foreach ($aiImages as $image): ?>
    <section class="ai-result" id="ai-image-<?= (int) $image['id'] ?>">
        <h3><?= escape(AiStudio::PLATFORMS[$image['platform']]) ?> · <?= escape($image['aspect_ratio']) ?> · <?= escape($image['style']) ?></h3>
        <p><?= escape($image['prompt']) ?></p>
        <?php if ($image['status'] === 'generated'): ?>
            <img class="ai-image-preview" src="<?= escape($detailAction . '&image=' . (int) $image['id']) ?>" alt="<?= escape('Gambar AI: ' . $detail['title']) ?>" loading="lazy">
            <p><?= (int) $image['width'] ?> × <?= (int) $image['height'] ?> · <?= escape($image['model']) ?> · Gambar AI, perlu pemeriksaan.</p>
            <a class="calendar-secondary" href="<?= escape($detailAction . '&image=' . (int) $image['id'] . '&image_download=1') ?>">Unduh gambar</a>
            <?php if ($image['content_id'] !== null): ?><p>Sudah dikaitkan ke konten.</p>
            <?php elseif ($detail['content_id'] !== null): ?>
                <form class="ai-action-form" method="post" action="<?= escape($detailAction) ?>">
                    <input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="attach_image"><input type="hidden" name="draft_id" value="<?= (int) $detail['id'] ?>"><input type="hidden" name="image_id" value="<?= (int) $image['id'] ?>">
                    <label><input type="checkbox" name="confirm_image" value="1" required> Saya telah memeriksa visual, hak penggunaan, dan disclosure; simpan sebagai aset draft, bukan publikasi.</label>
                    <button class="calendar-primary" type="submit">Simpan sebagai aset konten</button>
                </form>
            <?php else: ?><p>Simpan hasil teks ke Ide &amp; Draft terlebih dahulu untuk mengaitkan gambar sebagai aset konten.</p><?php endif ?>
        <?php elseif ($image['status'] === 'failed'): ?><p class="error-notice" role="alert"><?= escape($image['last_error'] === 'storage' ? 'Penyimpanan gambar gagal. Hubungi administrator.' : AiStudio::ERRORS[$image['last_error']]) ?></p>
        <?php else: ?><p>Sedang diproses. Muat ulang setelah selesai; bila terhenti, periksa biaya API sebelum membuat permintaan baru.</p><?php endif ?>
    </section>
<?php endforeach ?>
