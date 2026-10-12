# Ruang Social

Aplikasi internal HTML/CSS/JavaScript + PHP + SQLite untuk Social Media Automation. Kebutuhan produk ada di [PRD](./prd.md).

## Cakupan saat ini

- Login, lupa kata sandi, kata sandi baru, dan logout.
- Setelah login menampilkan dashboard admin sistem dengan sidebar: dashboard, kalender, kampanye, editorial, publikasi, organisasi, laporan, audit, dan pengaturan. Dashboard, kalender, kampanye, Ide & draft, dan Studio AI sudah tersedia; menu lain tetap placeholder. RBAC per perusahaan, editor lengkap, generasi file media, dan publikasi **belum diimplementasikan**. Metrik engagement ditandai Fase 3 dan tidak dapat dibuka.
- Kampanye (`index.php?page=admin&section=campaigns`) menyediakan daftar/filter perusahaan/status, tambah/edit, detail tujuan/tema/periode/pemilik, jumlah konten/terbit, dan arsip dengan konfirmasi. Status kampanye: Draft, Aktif, Selesai, Diarsipkan; bukan status publikasi. Perusahaan wajib aktif saat tambah/edit dan tidak dapat diganti setelah dibuat. Periode adalah tanggal lokal perusahaan (2000–2099), bukan waktu UTC. Tidak ada kampanye demo atau publikasi otomatis.
- Kampanye dapat dikaitkan ke konten baru lewat form kalender atau Ide/Brief yang belum memiliki kampanye melalui detail kampanye. Database dan server memastikan perusahaan sama; hanya kampanye nonarsip/perusahaan aktif menerima konten baru. Kalender memiliki filter kampanye dan nama kampanye pada detail konten. Arsip tidak menghapus konten; konten terhapus tidak masuk jumlah/detail kampanye. Tidak tersedia pemindahan/pelepasan asosiasi, pemulihan arsip, atau otomatisasi konten berbasis kampanye.
- Semua perubahan kampanye memakai POST+CSRF dan audit. Token pembuatan mencegah duplikasi; versi edit/arsip menolak form lama yang bertabrakan dengan perubahan admin lain. Filter invalid ditolak, error form mempertahankan input yang di-escape. Periode kampanye merupakan panduan perencanaan, bukan pembatas tanggal konten; konten di luar periode tetap dapat dikaitkan.
- Kalender konten (`index.php?page=admin&section=calendar`) menampilkan bulan 2000–2099, navigasi bulan, filter perusahaan/status, detail, dan form **Tambah konten** tanpa JavaScript. Konten baru hanya berstatus Ide/Brief dengan perusahaan aktif, pemilik admin yang masuk, format, brief, dan tanggal rencana opsional. Form POST dilindungi CSRF dan token anti-duplikasi; validasi error mempertahankan input.
- Kalender memakai `scheduled_at` bila ada, selain itu `planned_at` (timestamp UTC). Tanggal rencana bukan jadwal publikasi. Tampilan gabungan memakai Asia/Jakarta; pilihan satu perusahaan memakai zona waktu perusahaan. Input tanggal rencana memakai zona perusahaan, bukan zona browser. Bulan awal mengikuti Asia/Jakarta. Konten tanpa tanggal dihitung terpisah sepanjang periode. Edit Ide/Brief tersedia dari tautan detail ke halaman konten; generasi teks melalui Studio AI memerlukan konfigurasi Gemini. Tidak ada publikasi otomatis.
- Klik nomor tanggal atau **+ Tambah** untuk membuka form dengan tanggal terisi pukul 09:00; dengan JavaScript, klik area kosong sel juga bisa dan input lain tetap dipertahankan. Jam yang sudah diisi dipertahankan saat memilih tanggal lain. Tanpa JavaScript, tautan GET membuka form dengan tanggal/perusahaan terpilih; input yang belum disimpan tidak dibawa saat berpindah halaman. Tanggal diinterpretasikan di zona perusahaan yang dipilih pada form.
- Buka detail konten berstatus **Ide/Brief**, pilih **Hapus konten**, centang konfirmasi, lalu **Ya, hapus konten**. Server memeriksa ulang status dalam transaksi; status lain tidak dapat dihapus. Penghapusan memakai POST+CSRF, menyimpan `deleted_at`/`deleted_by` dan audit, serta menyembunyikan konten dari kalender, hitungan tanpa tanggal, dan semua panel dashboard. Unique key ide peringatan dipertahankan agar generator tidak membuat ulang ide yang dihapus. Tidak tersedia pemulihan dari UI.
- Migrasi `003_calendar_ideas.sql` menambahkan 8 tanggal peringatan tetap: Pendidikan Nasional, Kemerdekaan RI, Batik Nasional, Sumpah Pemuda, Perempuan Internasional, Lingkungan Hidup Sedunia, Keterampilan Pemuda Sedunia, dan Perdamaian Internasional. Katalog terkurasi ini bukan daftar lengkap hari libur; setiap peringatan memiliki sumber (Wikipedia untuk nasional, PBB untuk internasional) dan tanggal pemeriksaan. Hari raya bertanggal berubah dan tema tahunan belum tersedia. Peringatan tetap terlihat meski filter status konten berubah.
- Ide peringatan dibuat setelah aktivasi: satu per perusahaan aktif/peringatan/tahun, tanggal rencana pukul 09:00 lokal perusahaan, status Ide, tidak disetujui/diterbitkan. Unique index dan transaksi melindungi proses paralel/ulang; perubahan manusia tidak ditimpa. Menjeda otomatisasi tidak menghapus ide lama. Perusahaan nonaktif dilewati; admin pemilik yang nonaktif menyebabkan error, bukan dialihkan diam-diam.
- Dashboard membaca SQLite: agregat tiga perusahaan atau filter satu perusahaan, enam ringkasan status konten, kartu perusahaan/koneksi akun, maksimal delapan konten terbaru, dan panel perhatian. Angka dihitung per item sepanjang periode, bukan per posting/platform. Status lain juga dicantumkan untuk rekonsiliasi total. Tidak ada akun sosial atau konten demo yang dibuat otomatis.
- Migrasi `002_dashboard.sql` membuat perusahaan Signal Prima Solusi, Netindo Persada Nusantara, dan Mega Data Link sekali saja beserta tabel dasar konten/akun. Jalankan `php bin/console.php migrate` sebelum membuka dashboard pada instalasi lama, setelah backup konsisten. Migrasi tidak mengganti database atau menghapus admin yang sudah ada. Default kosong akan menampilkan nol/Belum ditambahkan sampai fitur pengisian datanya dibuat.
- Area admin memerlukan session aktif dan role admin sistem. Sidebar responsif dapat dibuka/ditutup; tanpa JavaScript seluruh menu tetap tersedia. Logout tetap memakai POST + CSRF.
- Tidak ada pendaftaran publik atau kredensial admin default.
- Reset menggunakan token acak 256-bit, berlaku 30 menit, sekali pakai. Token disimpan sebagai hash; payload antrean email dienkripsi dengan Sodium.
- Kata sandi minimal 4 karakter, maksimal 72 byte untuk menghindari truncation bcrypt. Password pendek kurang aman; gunakan frasa sandi yang panjang dan unik. Session idle 30 menit dan umur maksimum 8 jam. Reset mencabut seluruh session lama.
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

### Konten dan ide peringatan otomatis

Pada instalasi lama, backup SQLite secara konsisten lalu jalankan `php bin/console.php migrate` (termasuk `004_calendar_delete.sql` untuk penghapusan, `005_campaigns.sql` untuk kampanye, dan `006_content_editor.sql` untuk versi edit konten). Dari kalender, gunakan **Tambah konten** untuk ide/brief manual. **Aktifkan & buat ide tahun ...** menetapkan admin yang masuk sebagai pemilik ide baru dan membuat ide untuk tahun yang sedang dilihat (semua perusahaan aktif, bukan hanya filter terpilih). **Lengkapi ide tahun ...** hanya menambahkan yang belum ada. GET kalender tidak mengubah database.

### Ide & draft konten

Menu `index.php?page=admin&section=content` menyediakan pencarian judul/brief, filter perusahaan/status/kampanye, daftar 20 item per halaman, detail, tambah Ide/Brief, dan penghapusan dengan konfirmasi. Seluruh status terlihat, termasuk konten tanpa tanggal; status tahap lanjut hanya dibaca, bukan editor draft AI atau alur review.

Edit hanya berlaku pada Ide/Brief di perusahaan aktif: judul, brief, format, status Ide/Brief, dan tanggal rencana lokal perusahaan. Pemilik, perusahaan, kaitan kampanye, jadwal publikasi, serta identitas ide otomatis tidak berubah. Kampanye yang sudah diarsipkan tetap dapat memiliki konten yang diedit; tidak menerima kaitan baru. Ide/Brief tanpa kampanye dapat dikaitkan melalui detail kampanye. Form versi lama ditolak jika konten telah diedit, dikaitkan, dihapus, atau diubah statusnya. Kalender dan halaman ini menggunakan form/validasi tambah yang sama. Penghapusan tetap soft-delete dan ide peringatan terhapus tidak dibuat ulang oleh scheduler. Tidak ada publikasi atau AI otomatis.

### Studio AI (Gemini, tahap teks)

Menu `index.php?page=admin&section=ai` menyimpan brief lokal untuk Blog, Konten umum, Instagram, YouTube, TikTok, dan Facebook. Pilih perusahaan/kampanye, topik, brief, audiens, gaya, fakta publik, platform, serta jenis hasil yang direncanakan. Brief immutable; revisi/regenerasi hasil sukses dilakukan dengan brief baru. Daftar menampilkan maksimal 50 brief terbaru sesuai perusahaan; detail yang lebih lama tetap tersedia lewat ID.

Generasi menghasilkan artikel Markdown, caption/deskripsi, judul SEO, hook, skrip/storyboard, CTA, hashtag, alt text, brief gambar/video, naskah audio, dan pemeriksaan wajib per platform. **Tidak menghasilkan file gambar, video, atau audio; tidak menerima upload/analisis media dan tidak mempublikasikan posting.** Hasil masih perlu kurasi fakta, lisensi/disclosure, serta aset final. Hasil tampil sebagai teks ter-escape yang bisa disalin dari textarea atau diunduh sebagai `.txt`.

Pada instalasi lama, backup konsisten lalu jalankan `php bin/console.php migrate` untuk `007_ai_studio.sql`. Tambahkan blok ini ke array konfigurasi privat `config/local.php` (jangan mengganti konfigurasi autentikasi/mail atau app key):

```php
'ai' => [
    'provider' => 'gemini',
    'api_key' => getenv('GEMINI_API_KEY') ?: '',
    'model' => getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash',
    'timeout_seconds' => 60,
],
```

Model contoh mengikuti [dokumentasi Gemini](https://ai.google.dev/gemini-api/docs/generate-content/structured-output); gunakan model teks dengan structured output yang tersedia bagi akun Anda. Endpoint HTTPS dikunci ke Google Gemini, tidak menerima endpoint dari form; API key dikirim lewat header server dan tidak masuk prompt/UI/log. API memakai `generateContent` + JSON schema, output lengkap `STOP`, validasi bidang/platform/ukuran, dan tidak memakai model pengganti atau hasil simulasi bila gagal. Dokumentasi dan REST discovery diverifikasi 12 Oktober 2026. Pengujian otomatis memakai fixture lokal, bukan API berbayar.

Konfigurasi harus tersedia di **web dan CLI**. Bila memakai environment variables, pastikan Herd/PHP-FPM dan worker memiliki variabel tersebut; `config/example.php` saja tidak mengubah konfigurasi lokal lama. Jangan menempelkan key ke chat, source publik, shell history, atau command argument. Halaman tanpa konfigurasi tetap menyimpan brief tetapi tombol generate dinonaktifkan; “terkonfigurasi” bukan bukti key/model/kuota sudah diverifikasi.

Alur: simpan brief → buka detail → setujui pengiriman nama perusahaan/brief/audiens/gaya/fakta/platform ke Google dan kemungkinan biaya → **Generate draft teks** → proses antrean:

```sh
php bin/console.php ai:work
```

Worker memproses satu brief per eksekusi, tanpa menahan transaksi database selama jaringan. Untuk hosting, cron terpisah dengan path absolut dapat memanggil per menit. **Tidak ada scheduler AI yang otomatis dipasang**, dan scheduler peringatan yang sudah ada tidak memproses AI. Jika worker tidak berjalan, status tetap Menunggu worker; tombol muat ulang tidak memulai generasi.

Klaim antrean memakai transaksi/lease 180 detik sehingga worker paralel tidak menggandakan permintaan. Kegagalan/timeout tercatat dengan kode aman dan alasan di UI; job melewati lease menjadi gagal saat worker berikutnya berjalan. Tidak ada retry otomatis: timeout mungkin tetap dikenai biaya oleh provider, periksa akun sebelum retry manual (maksimal tiga percobaan per brief). Worker memeriksa ulang admin/perusahaan/kampanye sebelum permintaan; tidak mengirim kredensial atau data pengguna internal ke prompt.

**Simpan ke Ide & Draft** membutuhkan konfirmasi dan menyimpan satu item `ai_draft` dengan varian per platform, model/prompt metadata dan tautan kembali ke Studio. Simpan ulang tidak menggandakan item/varian; tidak memberi tanggal rencana, persetujuan, jadwal, atau status Terbit. Kampanye wajib nonarsip pada kaitan baru; arsip setelah generasi tidak membolehkan kaitan baru. Audio disimpan sebagai format Teks karena yang tersedia baru naskah, bukan berkas audio. Pengeditan hasil, template, approval, dan file media final merupakan tahap berikutnya.

Alternatif CLI:

```sh
php bin/console.php calendar:enable EMAIL_ADMIN
php bin/console.php calendar:generate
php bin/console.php calendar:generate 2027
```

Aktivasi CLI membuat ide tahun berjalan berdasarkan Asia/Jakarta. Generator tidak memakai AI atau menebak tema tahunan; brief awal harus dikurasi sebelum publikasi. Bila otomatisasi dijeda, CLI `calendar:generate` tidak membuat ide dan mengembalikan `enabled: false` agar scheduler tidak menganggap penjedaan sebagai kegagalan.

Agar tahun baru/perusahaan baru terisi tanpa membuka halaman, pasang cron harian memakai path PHP dan source absolut sesuai hosting:

```cron
5 0 * * * /PATH/TO/php /PATH/TO/sosmed-automation/bin/console.php calendar:generate >> /PATH/TO/sosmed-automation/var/logs/calendar-cron.log 2>&1
```

Cron `mail:work` yang sudah terpasang juga menjalankan generator tahun berjalan sebelum email; tidak perlu memasang kedua cron. Generator aman dijalankan berulang/paralel dan tetap mati bila belum diaktifkan. Status Aktif di UI menunjukkan konfigurasi, bukan bukti cron terpasang; proses terakhir ditampilkan untuk pemeriksaan. Tanpa cron, pengisian hanya terjadi melalui tombol/CLI. Aktivasi lokal tidak otomatis mengaktifkan database UAT/production.

Di Mac development ini, scheduler lokal dipasang melalui LaunchAgent `com.ruangsocial.calendar`, pukul 00:05 zona waktu Mac dan sekali saat login/load. File berada di `~/Library/LaunchAgents/com.ruangsocial.calendar.plist`, menggunakan PHP/source/config absolut. Mac harus menyala dan user login; jadwal yang terlewat saat sleep dijalankan saat bangun. Log privat ada di `var/logs/calendar-launchd.log` dan `calendar-launchd-error.log`. Scheduler ini tidak ikut deployment; hosting/VPS perlu cron sendiri.

```sh
launchctl print gui/$(id -u)/com.ruangsocial.calendar
launchctl kickstart gui/$(id -u)/com.ruangsocial.calendar
```

Tombol **Jeda ide otomatis** menghentikan pembuatan ide, bukan membongkar scheduler. Untuk melepas scheduler lokal tanpa menghapus source/database:

```sh
launchctl bootout gui/$(id -u) "$HOME/Library/LaunchAgents/com.ruangsocial.calendar.plist"
```

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
