# Ruang Social

Aplikasi internal HTML/CSS/JavaScript + PHP + SQLite untuk Social Media Automation. Kebutuhan produk ada di [PRD](./prd.md).

## Cakupan saat ini

- Login, lupa kata sandi, kata sandi baru, dan logout.
- Setelah login hanya menampilkan konfirmasi akun. Dashboard, RBAC per perusahaan, dan fitur konten **belum diimplementasikan**.
- Tidak ada pendaftaran publik atau kredensial admin default.
- Reset menggunakan token acak 256-bit, berlaku 30 menit, sekali pakai. Token disimpan sebagai hash; payload antrean email dienkripsi dengan Sodium.
- Kata sandi minimal 12 karakter, maksimal 72 byte untuk menghindari truncation bcrypt. Session idle 30 menit dan umur maksimum 8 jam. Reset mencabut seluruh session lama.
- Pembatasan login: 10 percobaan/email dan 30/IP per 15 menit, termasuk percobaan berhasil. Permintaan reset: 3/email dan 10/IP per 15 menit. Respons alamat terdaftar/tidak terdaftar sama. Header IP proxy tidak dipercaya secara otomatis.
- Form tetap berfungsi tanpa JavaScript. JavaScript hanya untuk melihat kata sandi dan indikator submit.

Ini merupakan fondasi autentikasi, **bukan pernyataan seluruh produk sudah siap production**. SMTP nyata, HTTPS, cron, permission, backup/restore, dan pengujian hosting tetap menjadi syarat rilis.

## Prasyarat

PHP 8.3+ yang masih mendapat pembaruan keamanan, PDO SQLite, mbstring, Sodium, OpenSSL, cURL dengan SMTP/SMTPS, filesystem lokal yang mendukung locking SQLite, dan storage persisten. Gunakan versi PHP yang sama untuk web dan CLI.

Satu host saja untuk SQLite. Jangan letakkan database di NFS atau bagikan file yang sama ke beberapa VPS. Deploy hanya direktori [public](./public) sebagai document root; source, konfigurasi, SQLite/WAL/SHM, session, email development, dan log harus berada di luar akses HTTP.

Tidak membutuhkan Composer, Node.js, Python, atau n8n untuk tahap ini.

## Menjalankan lokal

```sh
php bin/console.php init:development
php bin/console.php admin:create
php -S 127.0.0.1:8080 -t public
```

`init:development` membuat konfigurasi privat dengan key acak dan database, **tanpa membuat akun**. Tidak menimpa konfigurasi yang sudah ada. Buat akun dengan `admin:create`; kata sandi diminta lewat terminal tanpa echo, bukan melalui argumen command.

Buka `http://127.0.0.1:8080`. Untuk memproses reset:

```sh
php bin/console.php mail:work
```

Di development, email hanya ditulis ke `var/mail/reset-*.eml`, bukan dikirim ke internet. File tersebut berformat MIME; teks email/tautan berada dalam body base64. Buka dengan pembaca email lokal yang sesuai. Jangan upload atau bagikan file ini; hapus file development setelah pengujian. Mode `file` ditolak pada testing/production.

### Laravel Herd

Tambahkan/link proyek di Herd dengan domain `sosmed-automation.test`. Pastikan Herd melayani direktori `public`, bukan root source. Aset harus tersedia di `http://sosmed-automation.test/assets/auth.css`.

Pada konfigurasi privat `config/local.php`, gunakan `environment=development` dan `base_url=http://sosmed-automation.test` (atau HTTPS bila site sudah di-secure melalui Herd). `base_url` menentukan URL absolut untuk tautan reset dalam email, bukan memaksa browser berpindah domain.

CSS/JS/favicon, navigasi, form, dan redirect menggunakan path same-origin. Karena itu halaman dapat dibuka normal melalui Herd maupun `http://127.0.0.1:8080` tanpa mengubah konfigurasi setiap berpindah browser URL. Jika memakai subdirektori, path di `base_url` harus sesuai lokasi pemasangan aplikasi. Kebijakan CSP tetap `self`; tidak perlu mengizinkan aset lintas domain. Jalankan cron/`mail:work` tersendiri untuk memproses reset; Herd tidak otomatis menjalankan worker.

## Shared hosting untuk UAT client

1. Upload basis kode di luar `public_html`; arahkan document root domain/subdomain ke direktori `public`. Bila hosting menggunakan `public_html` tetap, taruh **isi** direktori `public` di sana dan sesuaikan path `require` bootstrap/template ke source privat di `index.php`. Jangan upload seluruh repository ke `public_html`.
2. Salin [config/example.php](./config/example.php) menjadi `config/local.php` di source privat. Set `environment` ke `testing`, `base_url` ke URL HTTPS kanonis, `storage_path` absolut, dan SMTP. URL kanonis mendukung subdirektori; link menggunakan `index.php?page=...` sehingga tidak memerlukan rewrite.
3. Buat key menggunakan `php bin/console.php key:generate` lalu simpan di konfigurasi privat. Jangan kirim key/kredensial lewat chat. Key tidak boleh berubah saat deployment biasa.
4. Jalankan `php bin/console.php migrate`, `php bin/console.php admin:create`, dan `php bin/console.php preflight` lewat terminal/SSH hosting. Bila tidak ada terminal interaktif, persiapkan database di lingkungan privat lalu transfer **snapshot konsisten** melalui kanal aman saat aplikasi belum aktif; jangan membuat installer/admin web terbuka.
5. Pasang cron setiap menit, dengan path PHP CLI yang sesuai:

   ```cron
   * * * * * /usr/bin/php /home/ACCOUNT/ruang-social/bin/console.php mail:work >> /home/ACCOUNT/ruang-social/var/logs/cron.log 2>&1
   ```

6. Verifikasi HTTPS, SMTP nyata, interval cron, serta penolakan akses HTTP terhadap konfigurasi/database/log/session. `.htaccess` adalah tambahan proteksi Apache, bukan pengganti pemisahan document root. Hosting harus mengizinkan directive yang digunakan; minta provider menyesuaikan VirtualHost bila override dibatasi.
7. Isolasi akun, data, key, SMTP, dan konfigurasi UAT dari production.

Tanpa cron/PHP CLI, request email dapat masuk antrean tetapi **tidak terkirim**. Jangan mengaktifkan pemulihan client sebelum worker teruji. Bila SMTP belum dikonfigurasi, halaman lupa kata sandi menampilkan layanan belum tersedia. Jika SMTP gagal saat worker berjalan, job retry terkontrol dan error tercatat, bukan status terkirim palsu. Respons form hanya menyatakan permintaan diproses, tidak menjamin email sudah diterima.

## Production di VPS

- Gunakan Linux, Nginx, PHP-FPM, HTTPS, dan storage lokal. Contoh [Nginx](./deploy/nginx.conf) harus disesuaikan dengan domain, path, sertifikat dan socket PHP aktual. `log_format` berada dalam konteks `http`, sebelum blok `server`.
- Simpan source sebagai read-only untuk user web. Berikan user PHP-FPM/worker akses tulis hanya ke storage privat; konfigurasi dapat dibaca tetapi tidak ditulis oleh user web. Directory privat `0700`/`0750`, file secret `0600`/`0640` dengan owner/group yang sesuai. Jalankan migrasi/bootstrap admin dengan user operasional yang benar, bukan menghasilkan database milik root yang tidak bisa ditulis worker.
- Set `environment=production` dan `base_url=https://...`, SMTP berautentikasi TLS/STARTTLS. TLS SMTP diverifikasi; tidak ada transport SMTP plaintext. Pastikan provider mengizinkan sender dan outbound SMTP.
- `HTTPS` harus diteruskan dengan benar ke PHP-FPM. Jika ada reverse proxy, terminasi TLS dan konfigurasi HTTPS dilakukan oleh administrator; aplikasi tidak mempercayai `X-Forwarded-Proto` atau `X-Forwarded-For` dari request.
- Terapkan migrasi setelah backup konsisten; migrasi tidak menghapus data. Jalankan `preflight` lalu smoke test login/reset/logout menggunakan akun uji.
- Untuk worker, gunakan cron atau contoh [service](./deploy/ruang-mail.service) + [timer](./deploy/ruang-mail.timer). Sesuaikan path/user, lalu install unit, `systemctl daemon-reload`, dan `systemctl enable --now ruang-mail.timer`. Worker batch sama dengan shared hosting; tidak bergantung pada browser. Cron dan timer tidak perlu dijalankan sekaligus.
- Worker memproses maksimum 10 job per batch, berhenti mengambil job baru setelah 50 detik. SMTP timeout maksimum 60 detik; lease 120 detik. Claim atomik dan lease mencegah double-claim. Pengiriman SMTP bisa berulang bila proses crash setelah server menerima email tetapi sebelum status tersimpan; Message-ID dan tautan yang sama dipertahankan, dan token tetap sekali pakai.
- Kegagalan SMTP mendapat maksimum 5 upaya dengan backoff/jitter. Job terminal kehilangan payload; catatan error berupa kategori/kode saja. `mail:work` exit nonzero saat ada retry/gagal. Pantau cron/systemd, heartbeat `mail.worker_completed` di log privat, dan `php bin/console.php mail:status` untuk antrean tertunda/gagal.
- Session disimpan di direktori privat. Tidak ada endpoint health/admin/setup yang membuka secret.
- URL email reset berisi token. **Jangan simpan query string** di access log, reverse-proxy log, error/APM trace, atau analytics. Log hanya method/path/status; konfigurasi contoh Nginx tidak mencatat query di access log. Tinjau juga error log/provider hosting karena pesan server dapat menyertakan request lengkap. Jangan aktifkan debug/request capture pada route reset. Halaman segera menghapus token dari address bar lewat redirect, memakai `Referrer-Policy: no-referrer` dan tanpa resource pihak ketiga.
- Aplikasi tidak mengaktifkan publikasi sosial atau provider AI pada tahap ini.

## Backup, retensi, dan pemindahan

- Gunakan SQLite online backup/snapshot konsisten, atau hentikan worker dan bekukan penulisan untuk backup. Jangan hanya menyalin `app.sqlite` ketika WAL aktif. Uji restore, termasuk kemampuan login dan reset.
- Backup key secara terpisah dan terenkripsi. Mengganti/menghilangkan key membuat payload email lama tidak dapat didekripsi; rencanakan pembatalan antrean sebelum rotasi key.
- Catatan reset/email kedaluwarsa dihapus worker setelah 30 hari; rate-limit bucket kedaluwarsa juga dibersihkan. Token/payload hilang pada pemakaian/status terminal. Audit login/reset tetap disimpan; kebijakan retensi audit dan rotasi log harus disepakati serta dijalankan sebelum production, bukan diasumsikan otomatis.
- Untuk pindah shared hosting ke VPS: hentikan job/penulisan, backup konsisten, transfer aman sesuai persetujuan data, ubah URL dan SMTP, migrasi/preflight, pasang worker, lalu smoke test. Jangan menimpa database aktif dengan database kosong dari paket rilis.

## Validasi

```sh
php tests/run.php
find app bin config public templates tests -name '*.php' -exec php -l {} \;
```

Test runner tanpa framework menggunakan SQLite/konfigurasi terisolasi dan server HTTP lokal pada port acak. Menguji login, CSRF, session, batas percobaan, respons reset tanpa enumerasi, token kedaluwarsa/sekali pakai, reset paralel, worker paralel, gagal SMTP, escaping, resource privat, dan HTTP end-to-end. Server dan file test dibersihkan setelah selesai, termasuk ketika test gagal.

Test tidak mengirim email eksternal dan tidak membuktikan deliverability provider SMTP atau konfigurasi Apache/Nginx target. Keduanya harus diuji di deployment sebenarnya. Minimum PHP 8.3 harus diuji pada target karena lingkungan pengembangan dapat menggunakan versi lebih baru.
