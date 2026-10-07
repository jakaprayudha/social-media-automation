<?php
declare(strict_types=1);

require_once __DIR__ . '/icons.php';

$signedIn = $page === 'login' && $user !== null;
$title = match (true) {
    $signedIn => 'Anda sudah masuk',
    $page === 'forgot' => 'Lupa kata sandi?',
    $page === 'reset' => 'Buat kata sandi baru',
    default => 'Selamat datang kembali',
};
$description = match (true) {
    $signedIn => 'Autentikasi berhasil. Ruang kerja Anda akan dikembangkan pada tahap berikutnya.',
    $page === 'forgot' => 'Masukkan email akun Anda. Kami akan memproses permintaan tautan untuk mengatur ulang kata sandi.',
    $page === 'reset' => 'Pilih kata sandi yang kuat dan berbeda dari yang Anda gunakan di layanan lain.',
    default => 'Masuk untuk melanjutkan ke ruang kerja marketing Anda.',
};
$assetBase = $app->basePath() . '/assets';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title><?= escape($title) ?> — Ruang Social</title>
    <link rel="icon" href="<?= escape($assetBase) ?>/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= escape($assetBase) ?>/auth.css">
    <script src="<?= escape($assetBase) ?>/auth.js" defer></script>
</head>
<body>
<a class="skip-link" href="#auth-form">Lewati ke formulir</a>
<main class="auth-shell">
    <aside class="brand-panel" aria-label="Tentang Ruang Social">
        <a class="brand" href="<?= escape($app->url()) ?>" aria-label="Ruang Social, halaman masuk">
            <span class="brand-mark"><?= icon('layers') ?></span>
            <span>ruang<span class="brand-accent">social</span><small>MARKETING WORKSPACE</small></span>
        </a>
        <div class="brand-copy">
            <span class="eyebrow"><span class="status-dot"></span> TIGA MEREK. SATU RUANG KERJA.</span>
            <h1>Konten terarah.<br>Tim selaras.<br><span>Merek bertumbuh.</span></h1>
            <p>Ruang untuk merencanakan ide, menyelaraskan persetujuan, dan menjaga konsistensi setiap merek Anda.</p>
        </div>
        <div class="workspace-preview" aria-hidden="true">
            <div class="preview-top"><span class="preview-title"><?= icon('layers') ?> Ruang kolaborasi</span><span class="preview-badge">ALUR KONTEN</span></div>
            <div class="preview-flow">
                <span><i><?= icon('spark') ?></i>Ide &amp; draft</span>
                <b><?= icon('arrow') ?></b>
                <span><i><?= icon('check') ?></i>Review tim</span>
                <b><?= icon('arrow') ?></b>
                <span><i><?= icon('layers') ?></i>Publikasi</span>
            </div>
            <div class="preview-bottom"><span class="avatar-stack"><i>S</i><i>N</i><i>M</i></span><span>Kolaborasi lintas merek,<br><strong>dengan kontrol yang jelas.</strong></span></div>
        </div>
        <div class="company-list">
            <span>UNTUK TIM DI</span>
            <div><p>Signal Prima<br><strong>Solusi</strong></p><p>Netindo Persada<br><strong>Nusantara</strong></p><p>Mega Data<br><strong>Link</strong></p></div>
        </div>
        <div class="brand-footer"><span>Dirancang untuk tim marketing Indonesia.</span><span>© <?= date('Y') ?> Ruang Social</span></div>
    </aside>
    <section class="form-panel" aria-labelledby="form-title">
        <div class="panel-top"><span class="workspace-tag"><?= icon('layers') ?> Akses ruang kerja</span><span class="language-tag">ID <span>•</span> Indonesia</span></div>
        <div class="form-container" id="auth-form" tabindex="-1">
            <?php if ($page !== 'login'): ?>
                <a class="back-link" href="<?= escape($app->url()) ?>"><?= icon('back') ?> Kembali ke halaman masuk</a>
            <?php endif ?>
            <div class="form-emblem"><?= icon($signedIn ? 'check' : ($page === 'forgot' ? 'mail' : 'lock')) ?></div>
            <div class="form-heading">
                <span class="eyebrow form-eyebrow"><?= $signedIn ? 'AKSES TERVERIFIKASI' : ($page === 'login' ? 'MULAI DARI SINI' : 'PEMULIHAN AKUN') ?></span>
                <h2 id="form-title"><?= escape($title) ?></h2>
                <p><?= escape($description) ?></p>
            </div>
            <?php if ($notice !== null): ?>
                <div class="alert alert-success" role="status"><?= icon('check') ?><p><?= escape((string) $notice) ?></p></div>
            <?php endif ?>
            <?php if ($error !== null): ?>
                <div class="alert alert-error" role="alert"><?= icon('shield') ?><p><?= escape($error) ?></p></div>
            <?php endif ?>
            <?php if ($signedIn): ?>
                <div class="account-card">
                    <span class="account-avatar"><?= escape(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
                    <div><strong><?= escape($user['name']) ?></strong><span><?= escape($user['email']) ?></span><small>Admin sistem</small></div>
                    <?= icon('shield') ?>
                </div>
                <div class="scope-note">Tahap awal: login dan pemulihan akun. Kalender, konten, dan fitur lainnya belum diaktifkan.</div>
                <form action="<?= escape($app->url('logout')) ?>" method="post" data-auth-form>
                    <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                    <button class="primary-button" type="submit">Keluar dari akun <?= icon('arrow') ?></button>
                </form>
            <?php elseif ($page === 'reset' && !$resetUsable): ?>
                <div class="empty-state"><?= icon('shield') ?><h3>Tautan tidak dapat digunakan</h3><p>Tautan tidak valid, sudah digunakan, atau telah kedaluwarsa. Minta tautan baru untuk melanjutkan.</p></div>
                <a class="primary-button" href="<?= escape($app->url('forgot')) ?>">Minta tautan baru <?= icon('arrow') ?></a>
            <?php else: ?>
                <?php if ($page === 'forgot' && !$mailConfigured): ?>
                    <div class="alert alert-warning" role="status"><?= icon('mail') ?><p>Layanan email reset belum dikonfigurasi. Hubungi administrator untuk mengaktifkan pemulihan akun.</p></div>
                <?php endif ?>
                <form action="<?= escape($app->url($page)) ?>" method="post" data-auth-form>
                    <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                    <?php if ($page !== 'reset'): ?>
                        <div class="field">
                            <label for="email">Email kerja</label>
                            <div class="input-wrap"><?= icon('mail') ?><input id="email" name="email" type="email" autocomplete="username" placeholder="nama@perusahaan.com" maxlength="254" value="<?= escape($emailInput) ?>" required autocapitalize="none" spellcheck="false"></div>
                        </div>
                    <?php endif ?>
                    <?php if ($page !== 'forgot'): ?>
                        <div class="field">
                            <div class="label-row"><label for="password"><?= $page === 'reset' ? 'Kata sandi baru' : 'Kata sandi' ?></label>
                                <?php if ($page === 'login'): ?><a href="<?= escape($app->url('forgot')) ?>">Lupa kata sandi?</a><?php endif ?>
                            </div>
                            <div class="input-wrap password-wrap"><?= icon('lock') ?><input id="password" name="password" type="password" autocomplete="<?= $page === 'reset' ? 'new-password' : 'current-password' ?>" placeholder="<?= $page === 'reset' ? 'Minimal 12 karakter' : 'Masukkan kata sandi' ?>" <?= $page === 'reset' ? 'minlength="12" aria-describedby="password-help"' : '' ?> required><button class="reveal-button" type="button" data-toggle-password="password" aria-label="Tampilkan kata sandi" aria-pressed="false" hidden><?= icon('eye') ?></button></div>
                            <?php if ($page === 'reset'): ?><p class="field-help" id="password-help">Minimal 12 karakter, maksimal 72 byte. Frasa panjang dengan beberapa kata lebih mudah diingat.</p><?php endif ?>
                        </div>
                    <?php endif ?>
                    <?php if ($page === 'reset'): ?>
                        <div class="field">
                            <label for="confirmation">Konfirmasi kata sandi baru</label>
                            <div class="input-wrap password-wrap"><?= icon('lock') ?><input id="confirmation" name="confirmation" type="password" autocomplete="new-password" placeholder="Ulangi kata sandi baru" minlength="12" required><button class="reveal-button" type="button" data-toggle-password="confirmation" aria-label="Tampilkan konfirmasi kata sandi" aria-pressed="false" hidden><?= icon('eye') ?></button></div>
                        </div>
                    <?php endif ?>
                    <button class="primary-button" type="submit" <?= $page === 'forgot' && !$mailConfigured ? 'disabled' : '' ?>>
                        <span><?= match ($page) { 'forgot' => 'Minta tautan reset', 'reset' => 'Simpan kata sandi baru', default => 'Masuk ke ruang kerja' } ?></span><?= icon('arrow') ?>
                    </button>
                    <p class="submit-status" role="status" data-submit-status></p>
                </form>
                <?php if ($page === 'login'): ?>
                    <div class="access-note">Belum memiliki akses? <span>Hubungi admin perusahaan Anda.</span></div>
                <?php elseif ($page === 'forgot'): ?>
                    <div class="access-note">Sudah ingat kata sandi? <a href="<?= escape($app->url()) ?>">Masuk sekarang</a></div>
                <?php endif ?>
            <?php endif ?>
            <div class="security-note"><?= icon('shield') ?><span>Akses terlindungi. Jangan bagikan kata sandi<br>atau tautan pemulihan akun Anda.</span></div>
        </div>
        <footer class="form-footer"><span>Ruang Social · Workspace internal</span><span><?= icon('lock') ?> Akses khusus pengguna terdaftar</span></footer>
    </section>
</main>
</body>
</html>
