<?php
declare(strict_types=1);

/** @return array<string, array<string, array{label: string, icon: string, later?: bool}>> */
function adminNavigation(): array
{
    return [
        'Ruang kerja' => [
            'dashboard' => ['label' => 'Dashboard', 'icon' => 'grid'],
            'calendar' => ['label' => 'Kalender konten', 'icon' => 'calendar'],
            'campaigns' => ['label' => 'Kampanye', 'icon' => 'flag'],
        ],
        'Editorial' => [
            'content' => ['label' => 'Ide & draft konten', 'icon' => 'file'],
            'ai' => ['label' => 'Studio AI', 'icon' => 'spark'],
            'media' => ['label' => 'Aset media', 'icon' => 'image'],
            'templates' => ['label' => 'Template konten', 'icon' => 'layers'],
            'approvals' => ['label' => 'Persetujuan', 'icon' => 'shield'],
        ],
        'Publikasi' => [
            'queue' => ['label' => 'Antrean & jadwal', 'icon' => 'clock'],
            'publications' => ['label' => 'Riwayat & kegagalan', 'icon' => 'send'],
            'accounts' => ['label' => 'Akun kanal', 'icon' => 'link'],
        ],
        'Organisasi' => [
            'companies' => ['label' => 'Perusahaan', 'icon' => 'building'],
            'brands' => ['label' => 'Profil merek', 'icon' => 'palette'],
            'users' => ['label' => 'Pengguna & hak akses', 'icon' => 'users'],
        ],
        'Laporan & sistem' => [
            'reports' => ['label' => 'Laporan & ekspor', 'icon' => 'chart'],
            'metrics' => ['label' => 'Metrik engagement', 'icon' => 'trend', 'later' => true],
            'audit' => ['label' => 'Audit log', 'icon' => 'list'],
            'settings' => ['label' => 'Pengaturan', 'icon' => 'settings'],
        ],
    ];
}
