<?php
declare(strict_types=1);
require_once __DIR__ . '/icons.php';
$assetBase = $app->basePath() . '/assets';
$title = $sections[$section]['label'];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= escape($title) ?> — Ruang Social</title>
    <link rel="icon" href="<?= escape($assetBase) ?>/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= escape($assetBase) ?>/admin.css">
    <script src="<?= escape($assetBase) ?>/admin.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Lewati navigasi</a>
<div class="admin-shell">
    <aside class="sidebar" id="admin-sidebar" aria-label="Sidebar admin">
        <a class="brand" href="<?= escape($app->url('admin')) ?>">
            <span class="brand-mark"><?= icon('layers') ?></span>
            <span>ruang<span class="accent">social</span><small>MARKETING WORKSPACE</small></span>
        </a>
        <div class="workspace-label"><?= icon('building') ?><div><strong>Admin sistem</strong><span>Ruang kerja lintas perusahaan</span></div></div>
        <nav aria-label="Menu admin">
            <?php foreach ($navigation as $group => $items): ?>
                <section class="nav-group" aria-label="<?= escape($group) ?>">
                    <h2><?= escape($group) ?></h2>
                    <ul>
                        <?php foreach ($items as $key => $item): ?>
                            <li>
                                <?php if ($item['later'] ?? false): ?>
                                    <span class="nav-link unavailable" aria-disabled="true"><?= icon($item['icon']) ?><span><?= escape($item['label']) ?></span><small>Fase 3</small></span>
                                <?php else: ?>
                                    <a class="nav-link<?= $section === $key ? ' active' : '' ?>" href="<?= escape($app->url('admin') . '&section=' . $key) ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>><?= icon($item['icon']) ?><span><?= escape($item['label']) ?></span></a>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </section>
            <?php endforeach ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-card"><span class="avatar"><?= escape(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span><div><strong><?= escape($user['name']) ?></strong><span><?= escape($user['email']) ?></span></div></div>
            <form action="<?= escape($app->url('logout')) ?>" method="post">
                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                <button class="logout-button" type="submit"><?= icon('logout') ?> Keluar dari akun</button>
            </form>
        </div>
    </aside>
    <div class="workspace">
        <header class="topbar">
            <button class="menu-toggle" type="button" aria-expanded="true" aria-controls="admin-sidebar" aria-label="Tutup menu admin" hidden><?= icon('menu') ?></button>
            <div class="breadcrumb"><span>Ruang kerja</span><span aria-hidden="true">/</span><strong><?= escape($title) ?></strong></div>
            <span class="shell-badge">PRATINJAU NAVIGASI</span>
        </header>
        <main id="main-content" tabindex="-1">
            <?php if ($error !== null): ?><p class="error-notice" role="alert"><?= escape($error) ?></p><?php endif ?>
            <?php if ($notice !== null): ?><p class="notice" role="status"><?= escape((string) $notice) ?></p><?php endif ?>
            <div class="page-heading"><span>ADMIN WORKSPACE</span><h1><?= escape($title) ?></h1><p>Menu sudah tersedia. Isi dan fungsi halaman ini akan dikembangkan pada tahap berikutnya.</p></div>
        </main>
    </div>
</div>
</body>
</html>
