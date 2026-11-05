# HR Management System

Aplikasi web internal untuk mengelola karyawan, saldo cuti, pengajuan izin dan lembur, serta persetujuannya. Dokumen ini adalah panduan bagi pemilik dan tim PT yang baru memegang proyek: cara memasang, menjalankan, mengonfigurasi, menguji, dan merawat aplikasi.

Catatan teknis yang rinci (keputusan desain, urutan kunci database, matriks otorisasi, dan sebagainya) ada di [docs/TECHNICAL-DECISIONS.md](docs/TECHNICAL-DECISIONS.md) dalam bahasa Inggris. Aturan bisnis ada di [DECISIONS.md](DECISIONS.md).

## Daftar isi

1. [Ringkasan](#1-ringkasan)
2. [Teknologi dan versi](#2-teknologi-dan-versi)
3. [Prasyarat](#3-prasyarat)
4. [Instalasi dari clone baru](#4-instalasi-dari-clone-baru)
5. [Pemakaian harian](#5-pemakaian-harian)
6. [Konfigurasi .env](#6-konfigurasi-env)
7. [Peran, hak akses, dan alur persetujuan](#7-peran-hak-akses-dan-alur-persetujuan)
8. [Modul dan aturan bisnis ringkas](#8-modul-dan-aturan-bisnis-ringkas)
9. [Perintah artisan khusus aplikasi](#9-perintah-artisan-khusus-aplikasi)
10. [Bahasa dan terjemahan](#10-bahasa-dan-terjemahan)
11. [Pengujian](#11-pengujian)
12. [Backup dan migrasi yang aman](#12-backup-dan-migrasi-yang-aman)
13. [Keamanan dan catatan operasional](#13-keamanan-dan-catatan-operasional)
14. [Pemecahan masalah](#14-pemecahan-masalah)
15. [Struktur folder dan alur Git](#15-struktur-folder-dan-alur-git)
16. [Dokumen lain](#16-dokumen-lain)
17. [Belum terverifikasi](#17-belum-terverifikasi)

---

## 1. Ringkasan

**Apa aplikasinya.** Sistem HR berbasis web untuk PT: data karyawan dan departemen, relasi atasan, saldo cuti tahunan, pengajuan izin dan lembur, persetujuan satu tingkat, riwayat, rekap, ekspor CSV, dan dashboard. Semua akun wajib memakai autentikasi dua faktor (2FA, kode 6 digit dari aplikasi autentikator).

**Siapa penggunanya.** Seluruh karyawan PT. Setiap akun punya satu dari tiga peran: Employee (Karyawan), Approver (Penyetuju), atau HR Admin (Admin HR). Aplikasi berjalan di komputer kantor lewat Laragon di alamat `http://hr-system.test`. Deploy ke server (VPS) belum dikerjakan (Phase 16).

**Fitur:**
- Login dengan username atau email + kata sandi, lalu kode 2FA. Kode pemulihan (recovery code) sekali pakai jika ponsel hilang.
- Akun dibuat HR Admin lewat undangan email; pengguna mengaktifkan akun sendiri (kata sandi + 2FA). Lupa kata sandi lewat email.
- Manajemen pengguna: buat, ubah, ganti peran, tangguhkan (suspend), aktifkan kembali, kirim ulang undangan.
- Departemen dan relasi atasan (banyak ke banyak), dengan aturan penjaga agar tidak ada karyawan yang tiba-tiba kehilangan semua atasannya.
- Saldo cuti 12 hari per periode tahunan, reset otomatis harian, penyesuaian manual oleh HR dengan buku besar.
- Pengajuan izin (cuti tahunan, sakit, izin lainnya) dan lembur, dengan bukti (pdf/jpg/png) opsional.
- Persetujuan dengan kunci tinjau (soft lock) supaya dua penyetuju tidak memutuskan bersamaan.
- Riwayat pengajuan, rekap saldo dan rekap per departemen per bulan, ekspor CSV.
- Dashboard dengan angka nyata sesuai hak pengguna.
- Profil, sesi aktif, riwayat login, pengaturan keamanan.
- Bahasa Inggris dan Indonesia (dipilih per pengguna), teks disimpan di database.
- Email notifikasi pengajuan dan keputusan.
- Audit log untuk aksi penting.

**Tiga peran dan haknya:**

| Peran | Hak utama |
|---|---|
| Employee | Dashboard, mengajukan izin dan lembur, membatalkan pengajuan sendiri yang masih menunggu, melihat riwayat dan rekap miliknya, profil dan keamanan akun sendiri. |
| Approver | Semua hak Employee, ditambah memutuskan (setujui/tolak) pengajuan bawahan yang terhubung dengannya, serta melihat riwayat dan rekap bawahannya. |
| HR Admin | Semua hak, termasuk manajemen pengguna, departemen, atasan, saldo cuti, hari libur, melihat semua pengajuan, dan memutuskan pengajuan para Approver. |

Rincian per izin ada di [bagian 7](#7-peran-hak-akses-dan-alur-persetujuan).

## 2. Teknologi dan versi

| Komponen | Versi | Keterangan |
|---|---|---|
| PHP | 8.5.12 (ZTS, x64) | dari Laragon, `C:\laragon\bin\php\php-8.5.12-Win32-vs17-x64` |
| Laravel | 13.39.0 | `composer.json`: `laravel/framework ^13.17` |
| Livewire | 4 (Alpine.js ikut dari Livewire) | `livewire/livewire ^4.0` |
| Tailwind CSS | 4 | lewat `@tailwindcss/vite` |
| Vite | 8 | `npm run build` |
| MySQL | 8.0.30 | dari Laragon, host `127.0.0.1`, port 3306. Satu-satunya database (tidak ada SQLite). |
| Apache | 2.4 | dari Laragon, melayani `http://hr-system.test` |
| spatie/laravel-permission | ^8.3 | peran dan izin |
| pragmarx/google2fa | ^9.1 | TOTP (2FA) |
| bacon/bacon-qr-code | ^3.1 | kode QR dibuat lokal (SVG) |
| Node.js / npm | 22.14.0 / 10.9.2 | hanya untuk membangun aset |
| Composer | 2.8 | |

Paket baru hanya boleh ditambah dengan persetujuan pemilik (lihat CLAUDE.md).

## 3. Prasyarat

- Windows dengan **Laragon** terpasang di `C:\laragon`, berisi PHP 8.5.12, MySQL 8.0.30, dan Apache. Di Laragon, versi PHP yang dipilih harus 8.5.12.
- Ekstensi PHP aktif: `pdo_mysql`, `mbstring`, `openssl`, `intl`, `fileinfo`, `zip`, `sodium`, `bcmath`, `curl` (semuanya sudah aktif di mesin pengembangan).
- **Zona waktu server MySQL +07:00 (WIB).** Di mesin pengembangan ini MySQL mengikuti zona Windows (SYSTEM) yang sudah WIB. Jika tidak, migrasi menolak berjalan (lihat [bagian 14](#14-pemecahan-masalah)).
- Composer (Laragon menyediakannya di `C:\laragon\bin\composer`), Node.js 22 dengan npm, dan Git.
- Akses ke repositori GitHub `aryapn200605/hr-system`.
- Ponsel dengan aplikasi autentikator (Google Authenticator atau Microsoft Authenticator) untuk setiap pengguna.

**Terminal.** PHP Laragon belum tentu ada di PATH Windows. Jalankan semua perintah di **Terminal Laragon** (menu Laragon → Terminal), yang sudah memuat PHP, MySQL, dan Composer milik Laragon, dari folder `C:\laragon\www\hr-system`. Jika memakai terminal lain, panggil PHP dengan path lengkap, misalnya `C:\laragon\bin\php\php-8.5.12-Win32-vs17-x64\php.exe artisan ...`.

## 4. Instalasi dari clone baru

Langkah ini untuk komputer baru. Lakukan berurutan. Contoh perintah ditulis untuk Terminal Laragon (cmd).

**1. Nyalakan Laragon.** Buka Laragon, klik **Start All**.
Hasil: Apache dan MySQL berstatus berjalan.

**2. Clone repositori ke folder yang tetap.** Path harus persis `C:\laragon\www\hr-system` (jangan dipindah).

```bash
git clone https://github.com/aryapn200605/hr-system.git C:\laragon\www\hr-system
```

```bash
cd C:\laragon\www\hr-system
```

Hasil: folder berisi `artisan`, `composer.json`, `README.md`, dan seterusnya.

**3. Pasang dependensi PHP.**

```bash
composer install
```

Hasil: folder `vendor` terbentuk, diakhiri baris `package:discover` tanpa error.

**4. Pasang dependensi JavaScript dan bangun aset.**

```bash
npm install
```

```bash
npm run build
```

Hasil: `vite build` selesai dan folder `public/build` berisi `manifest.json`. Peringatan bahwa paket opsional `fontaine` tidak ada boleh diabaikan. Jangan menjalankan `npm run dev` untuk pemakaian biasa.

**5. Buat berkas `.env` dari contoh.**

```bash
copy .env.example .env
```

Lalu buka `.env` dengan editor teks dan isi minimal `DB_USERNAME` dan `DB_PASSWORD` dengan akun MySQL Laragon Anda. Variabel lain dijelaskan di [bagian 6](#6-konfigurasi-env). Jangan pernah meng-commit `.env`.

**6. Buat kunci aplikasi.**

```bash
php artisan key:generate
```

Hasil: `INFO  Application key set successfully.` dan `APP_KEY` di `.env` terisi. Simpan salinan `.env` (terutama `APP_KEY`) di tempat aman: kehilangan atau mengganti `APP_KEY` membuat semua 2FA tidak bisa dipakai (lihat [bagian 12](#12-backup-dan-migrasi-yang-aman)).

**7. Periksa zona waktu MySQL.**

```bash
mysql -h 127.0.0.1 -u <user_mysql> -p -e "SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS selisih;"
```

Hasil yang diharapkan: `07:00:00`. Jika berbeda, perbaiki dulu (bagian 14, "Migrasi menolak karena zona waktu").

**8. Buat dua database.** `hr_system` untuk aplikasi, `hr_system_test` khusus untuk tes otomatis.

```bash
mysql -h 127.0.0.1 -u <user_mysql> -p -e "CREATE DATABASE hr_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE hr_system_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Hasil: tidak ada pesan error. `SHOW DATABASES;` menampilkan keduanya.

**9. Jalankan migrasi.** Pada database baru yang masih kosong ini aman. Untuk database yang sudah berisi data, ikuti [bagian 12](#12-backup-dan-migrasi-yang-aman).

```bash
php artisan migrate
```

Hasil: `INFO  Running migrations.` lalu 30 migrasi berstatus `DONE`. Migrasi juga memuat teks terjemahan, kategori cuti, dan periode saldo.

**10. Isi data dasar (peran, izin, terjemahan, kategori cuti).** Seeder ini aman dijalankan berulang dan tidak membuat pengguna.

```bash
php artisan db:seed
```

Hasil: `INFO  Seeding database.` dengan `RolePermissionSeeder`, `TranslationSeeder`, dan `LeaveCategorySeeder` berstatus `DONE`.

**11. Buat HR Admin pertama.** Hanya sekali; perintah menolak jika sudah ada HR Admin aktif.

```bash
php artisan app:create-admin
```

Perintah menanyakan `Full name`, `Username`, `Email`, `Password`, dan `Confirm password` (kata sandi tidak tampil saat diketik). Kata sandi minimal 12 karakter dengan huruf besar, huruf kecil, dan angka. Username hanya huruf, angka, titik, garis bawah, dan tanda hubung.
Hasil: `HR Admin <username> created. 2FA enrollment is required at first login.`

**12. Buka aplikasi.** Kunjungi http://hr-system.test di browser.
Hasil: halaman login. Laragon membuat virtual host `hr-system.test` otomatis dari nama folder; jika alamat tidak terbuka, lihat bagian 14.

**13. Login pertama dan pendaftaran 2FA.**
1. Masukkan username atau email dan kata sandi HR Admin.
2. Aplikasi membuka halaman pendaftaran 2FA dengan kode QR. Pindai dengan Google Authenticator atau Microsoft Authenticator.
3. Ketik kode 6 digit dari aplikasi dan kirim.
4. Halaman menampilkan **10 kode pemulihan sekali saja**. Simpan di tempat aman (dicetak atau di pengelola kata sandi), lalu klik "I have saved them".
Hasil: dashboard terbuka. Login berikutnya selalu meminta kode 6 digit.

**Langkah berikutnya untuk HR Admin:** buat departemen (menu Departments), undang pengguna (User Management → create), tetapkan atasan untuk Employee dan HR Admin di halaman detail pengguna, isi hari libur (menu Holidays), dan pasang scheduler ([bagian 5](#5-pemakaian-harian)). Dengan `MAIL_MAILER=log`, email undangan tidak terkirim; tautannya tertulis di `storage/logs/laravel.log`.

> Catatan: `composer.json` punya skrip `composer setup` bawaan Laravel yang menjalankan `migrate --force` tanpa backup. Jangan dipakai pada `hr_system` yang sudah berisi data.

## 5. Pemakaian harian

**Menyalakan aplikasi.** Buka Laragon → **Start All**. Aplikasi ada di **http://hr-system.test**. Cek cepat bahwa aplikasi hidup: http://hr-system.test/up harus menjawab (HTTP 200). Jangan menjalankan `php artisan serve`, `php -S`, atau server lain: semuanya lewat Apache Laragon.

**Setelah `git pull` (pembaruan kode):**

```bash
composer install
```

```bash
npm install
```

```bash
npm run build
```

```bash
php artisan view:clear
```

Jika ada migrasi baru (`php artisan migrate:status` menampilkan `Pending`), ikuti urutan aman di [bagian 12](#12-backup-dan-migrasi-yang-aman). Jika `.env` diubah dan konfigurasi pernah di-cache, jalankan `php artisan config:clear`.

**Scheduler (tugas harian).** Dua perintah dijadwalkan di `routes/console.php`, waktu WIB:

| Jam (WIB) | Perintah | Fungsi |
|---|---|---|
| 00:05 | `app:leave-periods-sync` | membuka periode cuti baru (reset tahunan per karyawan) |
| 02:00 | `app:prune-expired-tokens` | menghapus token reset dan undangan kedaluwarsa |

Jadwal hanya berjalan jika ada yang memanggil `php artisan schedule:run` setiap menit. Di Windows, pasang sekali lewat Task Scheduler dari Command Prompt:

```bat
schtasks /Create /SC MINUTE /MO 1 /TN "hr-system scheduler" /TR "\"C:\laragon\bin\php\php-8.5.12-Win32-vs17-x64\php.exe\" \"C:\laragon\www\hr-system\artisan\" schedule:run"
```

Memeriksa dan menghapus tugas:

```bat
schtasks /Query /TN "hr-system scheduler"
```

```bat
schtasks /Delete /TN "hr-system scheduler" /F
```

Melihat jadwal yang terdaftar:

```bash
php artisan schedule:list
```

Hasil yang diharapkan: dua baris, `0 2 * * * php artisan app:prune-expired-tokens` dan `5 0 * * * php artisan app:leave-periods-sync`. Tugas butuh MySQL, jadi hanya bekerja saat Laragon menyala. Hari yang terlewat (komputer mati) dikejar oleh sinkronisasi berikutnya. Saat ini tugas Task Scheduler **belum dipasang** di mesin pengembangan.

**Siapa menjalankan apa:**

| Siapa | Tugas |
|---|---|
| Pemilik / pengelola komputer | Menyalakan Laragon, memasang scheduler, mengisi `.env` (termasuk SMTP), backup, menjalankan migrasi akhir phase, `app:create-admin`, `app:reset-two-factor`. Satu-satunya yang mengubah konfigurasi Laragon dan php.ini. |
| HR Admin (di aplikasi) | Mengundang dan mengelola pengguna, departemen, atasan, saldo cuti, hari libur; memutuskan pengajuan Approver; melihat semua riwayat dan rekap. |
| Approver (di aplikasi) | Memutuskan pengajuan bawahannya. |
| Semua pengguna | Mengajukan izin dan lembur, mengelola profil dan keamanan akun sendiri. |
| Developer / agen | Menulis kode dan tes per phase sesuai CLAUDE.md, tes hanya ke `hr_system_test`, commit lokal tanpa push. |

## 6. Konfigurasi .env

`.env` berisi rahasia dan tidak pernah di-commit. Salin dari `.env.example`, lalu sesuaikan. Nilai di bawah adalah contoh aman, bukan nilai nyata.

| Variabel | Fungsi | Contoh |
|---|---|---|
| `APP_NAME` | Nama aplikasi (judul, email) | `"HR System"` |
| `APP_ENV` | Lingkungan | `local` |
| `APP_KEY` | Kunci enkripsi. Dibuat oleh `key:generate`. **Jangan diganti setelah ada pengguna.** | (dibuat otomatis) |
| `APP_DEBUG` | Tampilkan detail error. `false` di server produksi. | `true` |
| `APP_URL` | Alamat dasar; dipakai di semua tautan email | `http://hr-system.test` |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | Bahasa bawaan (tamu dan pengguna yang belum memilih) | `en` |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT` | Koneksi MySQL | `mysql`, `127.0.0.1`, `3306` |
| `DB_DATABASE` | Database aplikasi | `hr_system` |
| `DB_USERNAME`, `DB_PASSWORD` | Akun MySQL | `your_db_user`, `your_db_password` |
| `SESSION_DRIVER` | Harus `database` (mengakhiri sesi bergantung padanya) | `database` |
| `SESSION_LIFETIME` | Menit sebelum sesi tidak aktif berakhir | `120` |
| `SESSION_ENCRYPT` | Enkripsi isi sesi | `true` |
| `SESSION_SECURE_COOKIE` | `false` untuk HTTP lokal; **wajib `true` jika memakai HTTPS** | `false` |
| `INVITATION_EXPIRE_MINUTES` | Masa berlaku tautan undangan (menit) | `4320` (3 hari) |
| `AUTH_PRUNE_RETENTION_DAYS` | Berapa hari token/undangan selesai disimpan sebelum dihapus (minimal 1) | `30` |
| `EVIDENCE_MAX_KB` | Ukuran maksimal berkas bukti (KB) | `5120` (5 MB) |
| `CSV_DELIMITER` | Pemisah kolom CSV (satu karakter) | `";"` (cocok untuk Excel berbahasa Indonesia) |
| `CACHE_STORE`, `QUEUE_CONNECTION` | Cache dan antrean di database | `database` |
| `MAIL_MAILER` | `log` (lokal: email ditulis ke log) atau `smtp` (kirim sungguhan) | `log` |
| `MAIL_SCHEME` | `smtps` untuk port 465; kosong/`null` untuk STARTTLS (587) | `null` |
| `MAIL_HOST`, `MAIL_PORT` | Server SMTP | `127.0.0.1`, `2525` |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Login SMTP | `null` |
| `MAIL_TIMEOUT` | Detik menunggu server email per email | `10` |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Pengirim; harus alamat yang boleh dipakai login SMTP | `"hello@example.com"`, `"${APP_NAME}"` |

**Email: log atau SMTP.**
- `MAIL_MAILER=log` menulis seluruh email, **termasuk tautan aktivasi dan reset beserta tokennya**, ke `storage/logs/laravel.log`. Hanya untuk lokal. Tidak ada email yang benar-benar terkirim.
- `MAIL_MAILER=smtp` mengirim sungguhan. Email dikirim langsung (tidak lewat antrean), jadi server SMTP yang lambat membuat halaman menunggu sampai `MAIL_TIMEOUT` per penerima. Kegagalan kirim tidak membatalkan pengajuan atau keputusan; halaman menampilkan peringatan dan audit log mencatatnya.
- Alamat `hello@example.com` di `.env.example` adalah placeholder dan akan ditolak server (550).

**Contoh Gmail dengan App Password** (akun Google harus sudah memakai Verifikasi 2 Langkah; buat App Password di pengaturan keamanan akun Google, 16 karakter):

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=alamat-pengirim@gmail.com
MAIL_PASSWORD=app-password-16-karakter
MAIL_TIMEOUT=10
MAIL_FROM_ADDRESS="alamat-pengirim@gmail.com"
MAIL_FROM_NAME="${APP_NAME}"
```

Alternatif port 465: `MAIL_SCHEME=smtps` dan `MAIL_PORT=465`. Setelah mengubah `.env`, jalankan `php artisan config:clear`. Pengiriman ke kotak surat nyata belum pernah diuji (lihat bagian 17).

## 7. Peran, hak akses, dan alur persetujuan

Peran dan izin didefinisikan di `App\Enums\RoleName` dan `App\Enums\PermissionName`, dan diisi ke database oleh `RolePermissionSeeder`. Semua izin dicek di server (rute, komponen Livewire, dan layanan); label peran di layar hanya informasi.

| Izin | Fungsi | Employee | Approver | HR Admin |
|---|---|:-:|:-:|:-:|
| `dashboard.view` | Dashboard | ya | ya | ya |
| `requests.submit` | Mengajukan izin/lembur, halaman My requests, riwayat dan rekap milik sendiri | ya | ya | ya |
| `requests.decide` | Halaman Approvals: menyetujui/menolak; melihat riwayat bawahan | | ya | ya |
| `requests.view-all` | Melihat semua pengajuan dan saldo di riwayat, rekap, ekspor | | | ya |
| `users.view`, `users.create`, `users.update`, `users.suspend`, `users.invite` | User Management | | | ya |
| `departments.manage` | Departemen | | | ya |
| `supervisors.manage` | Menambah/melepas atasan, memindahkan bawahan | | | ya |
| `leave-balance.view`, `leave-balance.adjust` | Melihat dan menyesuaikan saldo cuti | | | ya |
| `holidays.manage` | Hari libur | | | ya |

**Alur persetujuan (satu tingkat):**

| Pemohon | Diputuskan oleh |
|---|---|
| Employee | Salah satu Approver aktif yang terhubung sebagai atasannya. Keputusan pertama yang masuk berlaku. |
| HR Admin | Salah satu Approver aktif yang ditunjuk sebagai atasannya. |
| Approver | HR Admin aktif mana pun (atasan Approver itu sendiri tidak memutuskan). |

- Tidak ada yang memutuskan pengajuannya sendiri.
- Atasan harus Approver berstatus ACTIVE. Employee dan HR Admin tanpa atasan aktif tidak bisa mengajukan (tombol nonaktif dengan alasan, ditandai "No supervisor" di User Management). Approver tidak butuh atasan, tetapi butuh minimal satu HR Admin aktif.
- Hak memutuskan dicek ulang dari database setiap kali tombol ditekan, bukan dari keadaan saat pengajuan dibuat.
- Keputusan bersifat final: pengajuan yang disetujui atau ditolak tidak bisa dibatalkan atau diubah.
- HR Admin tidak bisa mengubah perannya sendiri atau menangguhkan dirinya sendiri, dan HR Admin aktif terakhir tidak bisa ditangguhkan atau diturunkan.

Matriks lengkap per rute: [docs/TECHNICAL-DECISIONS.md, "Authorization matrix"](docs/TECHNICAL-DECISIONS.md#authorization-matrix).

## 8. Modul dan aturan bisnis ringkas

Rincian dan alasan setiap aturan ada di [DECISIONS.md](DECISIONS.md) (nomor bagian disebut di tiap butir) dan di [docs/TECHNICAL-DECISIONS.md](docs/TECHNICAL-DECISIONS.md).

**Departemen dan atasan** (DECISIONS.md bagian 3 dan 9)
- Nama departemen unik. Departemen yang masih dipakai hanya bisa dinonaktifkan; departemen nonaktif tetap melekat pada karyawan lama tetapi tidak bisa dipilih untuk penugasan baru.
- Relasi atasan banyak ke banyak, tanpa diri sendiri, tanpa duplikat, tanpa lingkaran.
- **Aturan penjaga:** tidak ada aksi (suspend, ganti peran, melepas atasan, pindah massal, perintah konsol) yang boleh membuat pengguna yang punya atasan aktif menjadi tanpa atasan aktif. Penolakan menyebut siapa yang terdampak. Bawahan SUSPENDED tidak dihitung.
- Kehilangan peran Approver melepas semua relasinya sebagai atasan. Suspend tidak melepas relasi.
- HR Admin bisa memindahkan semua atau sebagian bawahan dari satu atasan ke atasan lain dalam satu transaksi.

**Saldo cuti dan reset** (DECISIONS.md bagian 5 dan 9)
- 12 hari per periode, bilangan bulat, tanpa carry over. Periode dihitung dari **tanggal acuan** (anchor) tiap karyawan; anchor 29 Februari menjadi 28 Februari di tahun biasa.
- Akun baru langsung mendapat anchor (hari ini, WIB) dan periode pertama penuh 12 hari [ASUMSI].
- Reset otomatis lewat `app:leave-periods-sync` (harian, idempotent, mengejar hari yang terlewat, juga untuk akun PENDING dan SUSPENDED).
- HR bisa menyesuaikan (dengan alasan, sisa tidak boleh negatif), mereset periode manual, atau mengganti anchor. **Tidak ada yang mengubah saldonya sendiri**, termasuk HR Admin.
- Semua perubahan tercatat di buku besar yang tidak bisa diubah atau dihapus.

**Pengajuan izin dan lembur** (DECISIONS.md bagian 4 dan 8)
- Izin: kategori Cuti tahunan (memotong saldo), Sakit, Izin lainnya (tidak memotong). Hari kerja Senin–Jumat dikurangi hari libur; jumlahnya dibekukan saat pengajuan dibuat.
- Lembur: jam bulat, maksimal 12 jam per pengajuan, boleh melewati tengah malam.
- Pembatalan oleh pemohon hanya selama masih menunggu.

**Validasi utama** (semua di server)
- Tidak boleh mundur: tanggal mulai paling awal hari ini (WIB), termasuk cuti sakit.
- Alasan 10–1000 karakter. Minimal satu hari kerja untuk izin.
- Tidak boleh bertumpuk dengan izin atau lembur lain yang menunggu atau disetujui (ujung lembur yang bersentuhan boleh). Lembur ditolak pada tanggal izin, dan sebaliknya.
- Cuti tahunan dibagi per periode; tiap bagian harus muat di sisa periode itu setelah dikurangi pengajuan lain yang menunggu. Tanggal akhir paling jauh akhir periode berikutnya.
- Bukti opsional pdf/jpg/png, maksimal `EVIDENCE_MAX_KB`, jenis diperiksa dari isi berkas, disimpan privat dengan nama acak.
- Maksimal 10 pengajuan per menit per pengguna.

**Persetujuan dan kunci** (DECISIONS.md bagian 6 dan 11)
- Menyetujui: catatan opsional. Menolak: alasan wajib 5–1000 karakter.
- Membuka pengajuan memberi **kunci tinjau 5 menit** (diperpanjang tiap menit selama halaman terbuka). Penyetuju lain masuk mode lihat saja dan melihat siapa yang sedang meninjau.
- Saat dikirim, keputusan dicek ulang dalam transaksi dengan kunci baris. Jika sudah diputuskan, muncul "sudah diputuskan oleh X pada ...", bukan error.
- Menyetujui cuti tahunan memotong saldo per periode; jika saldo tidak cukup, pengajuan tetap menunggu.
- **Suspend menolak otomatis** semua pengajuan menunggu milik pengguna itu, tercatat "System" sebagai pemutus, tanpa email. Reaktivasi tidak memulihkannya.

**Riwayat, rekap, ekspor**
- Riwayat (`/history`): milik sendiri; Approver juga bawahannya; HR Admin semua. Filter karyawan, departemen, jenis, kategori, status, rentang tanggal.
- Rekap (`/recap`): saldo per karyawan per periode, dan jumlah pengajuan per departemen per bulan (default hanya yang disetujui).
- Ekspor CSV: baris yang sama dengan halaman, UTF-8 dengan BOM, waktu WIB, terlindung dari formula injection. **Tidak pernah** berisi alasan, catatan, bukti, username, atau email. Maksimal 10 ekspor per menit.

**Dashboard**
- Saldo cuti tersedia, pengajuan saya yang menunggu, keputusan terakhir, jumlah yang menunggu keputusan saya (Approver/HR), total menunggu di organisasi dan karyawan tanpa atasan (HR Admin). Angka dihitung saat halaman dimuat; klik Refresh untuk memperbarui.

**Bahasa:** lihat [bagian 10](#10-bahasa-dan-terjemahan).

**Email** (Phase 13)
- Saat pengajuan dibuat: ke semua orang yang saat itu berhak memutuskan. Saat diputuskan: ke pemohon (jika masih ACTIVE).
- Isi minimal (jenis, tanggal/jam, nama pemohon atau hasil, tombol tautan ke `APP_URL`), dalam bahasa penerima. Tanpa alasan, catatan, atau bukti.
- Tidak ada email untuk pembatalan dan penolakan otomatis. Email akun (undangan, reset kata sandi) dikirim dengan mekanisme yang sama.

**Belum dibuat (backlog):** cuti sakit mundur yang dicatat HR, pembatalan setelah disetujui, kebijakan bawahan saat atasannya disuspend, penampil audit log, redesain UI (Phase 15), deploy (Phase 16).

## 9. Perintah artisan khusus aplikasi

Diperiksa dengan `php artisan list app` dan `--help` masing-masing.

| Perintah | Fungsi | Aman diulang? |
|---|---|---|
| `app:create-admin` | Membuat HR Admin pertama secara interaktif. Menjalankan seeder peran dulu, menolak jika sudah ada HR Admin aktif. Akun ACTIVE dengan 2FA wajib didaftarkan saat login pertama. | Menolak sendiri jika admin aktif sudah ada |
| `app:reset-two-factor <username>` | Menghapus 2FA seorang pengguna (secret, kode pemulihan) dan mengakhiri semua sesinya, setelah konfirmasi. Kata sandi, status, dan peran tetap. Login berikutnya wajib daftar 2FA lagi. Dicatat di audit log sebagai `two_factor.reset`. | Tidak; gunakan hanya setelah memastikan identitas peminta |
| `app:leave-periods-sync` | Membuka periode cuti yang seharusnya sudah mulai, untuk semua pengguna. Terjadwal 00:05 WIB. | Ya (idempotent) |
| `app:prune-expired-tokens` | Menghapus token reset dan undangan yang selesai/kedaluwarsa lebih dari `AUTH_PRUNE_RETENTION_DAYS` hari. Terjadwal 02:00 WIB. | Ya |
| `app:translations-missing [--path=...]` | Menampilkan kunci terjemahan yang belum ada di bahasa aktif; keluar dengan kode 1 jika ada. | Ya (hanya membaca) |
| `app:translation-set <locale> <key> <value> [--group=...]` | Menambah atau mengubah satu teks terjemahan dan menyegarkan cache. Menolak HTML dan placeholder yang berbeda dari bahasa Inggris. Dicatat di audit log. | Ya |
| `app:translations-cache-clear` | Menyegarkan cache terjemahan setelah tabel diubah langsung di database. | Ya |

Contoh:

```bash
php artisan app:create-admin
```

```bash
php artisan app:reset-two-factor budi.santoso
```

```bash
php artisan app:leave-periods-sync
```

Hasil: `Checked N users.`

```bash
php artisan app:prune-expired-tokens
```

Hasil: `Removed N reset tokens and M invitations older than 30 days.`

```bash
php artisan app:translations-missing
```

```bash
php artisan app:translation-set id "Log in" "Masuk"
```

```bash
php artisan app:translation-set id required ":Attribute wajib diisi." --group=validation
```

```bash
php artisan app:translations-cache-clear
```

Perintah Laravel lain yang sering dipakai: `migrate:status`, `migrate`, `db:seed --class=RolePermissionSeeder --force`, `schedule:list`, `view:clear`, `config:clear`, `test`.

## 10. Bahasa dan terjemahan

- **Memilih bahasa:** setiap pengguna memilih di **My Profile → Language** (English atau Bahasa Indonesia). Tamu dan pengguna yang belum memilih memakai `APP_FALLBACK_LOCALE` (English). Email mengikuti bahasa penerima; undangan selalu dalam bahasa bawaan.
- **Tempat teks:** tabel `languages` dan `translations` di database. Teks dasar ada di `database/translations/en.php` dan `id.php`, dimuat oleh `TranslationSeeder` (hanya menyisipkan yang belum ada, tidak menimpa). Tidak ada folder `lang/`.
- **Mengubah satu terjemahan:** jalankan `app:translation-set` (contoh di bagian 9), lalu ubah juga teks yang sama di `database/translations/id.php` supaya instalasi baru ikut benar. Jika tabel diubah langsung di database, jalankan `php artisan app:translations-cache-clear`.
- **Menambah kalimat baru (developer):** tulis `__('Kalimat dalam bahasa Inggris')` di kode, tambahkan kunci yang sama ke `en.php` dan terjemahannya ke `id.php`, lalu buat migrasi yang menjalankan `TranslationSeeder`. Tes gagal sampai kedua bahasa lengkap.
- **Menambah bahasa** (contoh Melayu, `ms`):
  1. Di `php artisan tinker`: `App\Models\Language::create(['code' => 'ms', 'name' => 'Malay', 'native_name' => 'Bahasa Melayu', 'is_active' => false, 'sort' => 3]);`
  2. Salin `database/translations/id.php` menjadi `ms.php`, terjemahkan, lalu jalankan `php artisan db:seed --class=TranslationSeeder --force`.
  3. Aktifkan: `App\Models\Language::whereKey('ms')->update(['is_active' => true]);`, lalu `php artisan app:translations-missing` harus tidak melaporkan apa pun.
- **Memeriksa kunci yang hilang:** `php artisan app:translations-missing` (juga dijalankan oleh tes `LanguageTest`).
- Istilah baku Indonesia (misalnya pengajuan, atasan, saldo cuti) ada di [docs/TECHNICAL-DECISIONS.md, "Standard Indonesian terms"](docs/TECHNICAL-DECISIONS.md#standard-indonesian-terms). Teks Indonesia masih menunggu tinjauan pemilik.

## 11. Pengujian

**Aturan utama: tes tidak pernah dijalankan ke `hr_system`.** `phpunit.xml` mengarahkan tes ke database `hr_system_test` (dengan mailer `array`, cache `array`, sesi `array`). Jangan menjalankan `php artisan config:cache` di mesin pengembangan: konfigurasi yang di-cache mengabaikan pengaturan `phpunit.xml`.

**Menjalankan seluruh tes** (cara paling aman, membersihkan cache konfigurasi dulu):

```bash
composer test
```

atau

```bash
php artisan config:clear
```

```bash
php artisan test
```

Hasil terakhir yang tercatat (Phase 14): 1136 tes lulus. Database `hr_system_test` harus sudah ada; tes membuat tabelnya sendiri.

**Satu berkas atau satu kelompok tes:**

```bash
php artisan test --filter=LeaveRequestTest
```

**Tes race (proses nyata).** Kelas `*ConcurrencyTest` (misalnya `ApprovalConcurrencyTest`, `RequestConcurrencyTest`) menjalankan beberapa proses PHP terpisah lewat `tests/Support/race.php` terhadap baris yang sudah di-commit, untuk membuktikan tidak ada keputusan ganda, saldo terlampaui, atau deadlock. Tes ini tidak memakai `RefreshDatabase`.

```bash
php artisan test --filter=ConcurrencyTest
```

**Uji mutasi.** Tidak ada alat di repositori. Caranya manual per phase: hapus satu pengaman (misalnya satu pengecekan izin atau satu kunci), jalankan tes terkait, pastikan tes gagal, lalu kembalikan berkas dan cocokkan hash-nya. Pengaman yang "selamat" berarti tesnya lemah atau kodenya berlebih. Hasil tiap phase dicatat di PROGRESS.md.

**Pemeriksaan browser (puppeteer).** Skrip ada **di luar repositori**, di `C:\laragon\tmp\browser-check` (puppeteer 25.14.0, tidak ada di `package.json`), dan berjalan terhadap http://hr-system.test pada lebar 1024px dan 375px. Pola dari Phase 10: `php phaseN-setup.php <setup.json>`, `node phaseN.mjs <setup.json>`, lalu `php phaseN-cleanup.php`; tangkapan layar ada di folder `shots`. Akun sementara memakai awalan username `zz-check-` dan dihapus setelahnya dengan bukti jumlah akun nyata tidak berubah. Di komputer baru folder ini tidak ada.

**Pemeriksaan lain sebelum commit:**

```bash
npm run build
```

```bash
vendor\bin\pint
```

```bash
composer audit
```

```bash
npm audit
```

## 12. Backup dan migrasi yang aman

`hr_system` berisi akun nyata. Migrasi ke database ini hanya dilakukan di akhir phase dengan urutan berikut:

1. Tes hijau di `hr_system_test` (bagian 11).
2. Lihat migrasi yang tertunda:
   ```bash
   php artisan migrate:status
   ```
3. Buat backup **di luar repositori**:
   ```bash
   C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe -h 127.0.0.1 -u <user_mysql> -p --single-transaction --routines --result-file=C:\laragon\backup\hr-system\hr_system-before-phaseN-YYYYMMDD-HHMMSS.sql hr_system
   ```
   Pakai `--result-file`, bukan `>`: di PowerShell 5.1 tanda `>` menulis UTF-16 sehingga berkas sulit dipulihkan.
4. Baca kembali backup untuk membuktikan isinya: ukurannya bukan nol, ada baris `INSERT INTO`, dan baris terakhir berbunyi `-- Dump completed`.
   ```bash
   findstr /C:"Dump completed" C:\laragon\backup\hr-system\hr_system-before-phaseN-YYYYMMDD-HHMMSS.sql
   ```
5. Jalankan migrasi biasa:
   ```bash
   php artisan migrate
   ```
6. Jika phase menambah izin, beri izin ke peran (aman diulang):
   ```bash
   php artisan db:seed --class=RolePermissionSeeder --force
   ```
7. Pastikan terjemahan lengkap dan bersihkan view:
   ```bash
   php artisan app:translations-missing
   ```
   ```bash
   php artisan view:clear
   ```

**Dilarang tanpa izin eksplisit pemilik:** `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `migrate:refresh`, `db:wipe`, atau truncate pada `hr_system`.

**Memulihkan backup** (hanya dengan keputusan pemilik): `mysql -h 127.0.0.1 -u <user_mysql> -p hr_system < berkas.sql` di cmd (bukan PowerShell).

**Yang juga harus dibackup** selain database: berkas `.env` (terutama `APP_KEY`) dan folder bukti `storage/app/private/evidence` (mysqldump tidak menyertakannya).

**Peringatan APP_KEY.** Secret 2FA dienkripsi dan kode pemulihan di-hash dengan kunci turunan `APP_KEY`. Mengganti atau kehilangan `APP_KEY` membuat **semua 2FA dan kode pemulihan tidak berlaku**; setiap pengguna harus di-reset dengan `app:reset-two-factor` dan mendaftar ulang. Backup database tanpa `APP_KEY` yang cocok tidak bisa dipakai untuk login.

**Peringatan zona waktu MySQL.** Aplikasi dan koneksi database memakai WIB (`Asia/Jakarta`, koneksi `+07:00`). Migrasi `2026_11_04_100000_shift_timestamps_to_wib` menolak berjalan jika aplikasi, koneksi, atau **server MySQL** tidak di +07:00. Jangan mengubah zona Windows atau MySQL setelah data ada.

## 13. Keamanan dan catatan operasional

**Keamanan:**
- 2FA wajib untuk semua akun dan tidak bisa dimatikan dari aplikasi. Reset hanya lewat konsol (`app:reset-two-factor`).
- Kata sandi minimal 12 karakter (huruf besar, huruf kecil, angka). Login, kode 2FA, aktivasi, lupa kata sandi, dan kirim ulang undangan dibatasi jumlah percobaannya.
- Token undangan dan reset disimpan dalam bentuk hash, sekali pakai, dan kedaluwarsa. Secret 2FA dienkripsi; kode pemulihan di-hash.
- Sesi terenkripsi dan disimpan di database. Aksi sensitif (kata sandi, email, kode pemulihan, autentikator) meminta konfirmasi identitas ulang.
- Berkas bukti tidak bisa diakses lewat URL publik, hanya diunduh oleh pemohon dan yang berhak memutuskan.
- Audit log tidak bisa diubah atau dihapus lewat aplikasi (akses langsung ke database tetap bisa; tidak ada penampil audit di UI).
- Jangan pernah meng-commit `.env` atau rahasia. Jangan menyebarkan isi `storage/logs/laravel.log`: dengan `MAIL_MAILER=log` berkas itu berisi tautan aktivasi dan reset.
- Log akses web server mencatat tautan undangan/reset pertama kali dibuka; lindungi dan rotasi seperti log sensitif.
- Saat memakai HTTPS (deploy), set `SESSION_SECURE_COOKIE=true` dan `APP_DEBUG=false`.

**Catatan operasional:**
- Semua waktu tampil dan tersimpan dalam WIB. Tanggal cuti disimpan sebagai DATE.
- Email dikirim langsung; SMTP lambat membuat halaman ikut lambat.
- Jumlah di menu dan dashboard dihitung saat halaman dimuat; muat ulang untuk angka terbaru.
- Tabel lebar bergeser ke samping di layar ponsel.
- Unggahan sementara Livewire di `storage/app/private/livewire-tmp` dibersihkan otomatis setelah 24 jam.
- `storage/logs/laravel.log` sebaiknya dirotasi berkala oleh pemilik (berisi tautan lama dari fase pengembangan).
- Setelah mengubah view, jalankan `php artisan view:clear` (view lama yang dikompilasi pernah membuat bagian teks email tetap berbahasa Inggris).

## 14. Pemecahan masalah

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| Halaman 500 setelah `git pull` | Dependensi, aset, migrasi, atau view lama | Jalankan `composer install`, `npm install`, `npm run build`, `php artisan view:clear`, `php artisan config:clear`. Cek `php artisan migrate:status`; jika ada `Pending`, ikuti bagian 12. Baca error pertama di `storage/logs/laravel.log`. |
| Halaman tidak terbuka, atau error `SQLSTATE[HY000] [2002]` | Laragon, Apache, atau MySQL mati | Buka Laragon → **Start All**. Cek http://hr-system.test/up harus 200. |
| `hr-system.test` tidak ditemukan | Virtual host belum dibuat atau berkas hosts belum diperbarui | Pastikan folder persis `C:\laragon\www\hr-system`. Di Laragon, aktifkan "Auto virtual hosts" dan klik **Reload**/Restart All (Laragon perlu izin administrator untuk menulis berkas hosts Windows). |
| Halaman tampil tanpa gaya (CSS hilang) | Aset belum dibangun, atau `public/hot` tertinggal dari `npm run dev` | Jalankan `npm run build`. Jika berkas `public/hot` ada dan Vite dev server tidak berjalan, hapus berkas itu. |
| Email gagal (toast peringatan, audit `request.notification_failed`) | Pengaturan SMTP salah, App Password salah, atau `MAIL_FROM_ADDRESS` masih placeholder | Periksa variabel `MAIL_*` (bagian 6), pastikan alamat pengirim sama dengan akun SMTP, lalu `php artisan config:clear`. Log hanya mencatat nama kelas error. Pengajuan/keputusan tetap tersimpan. |
| Undangan "terkirim" tetapi tidak ada email | `MAIL_MAILER=log` | Tautan ada di `storage/logs/laravel.log`; atau pasang SMTP. |
| Pengguna kehilangan ponsel (2FA terkunci) | Autentikator hilang | Pengguna memakai **Use a recovery code** di halaman kode 2FA. Jika kode pemulihan juga hilang: pastikan identitasnya di luar aplikasi, lalu pemilik menjalankan `php artisan app:reset-two-factor <username>`. Pengguna login dengan kata sandi dan mendaftar 2FA lagi. |
| "Too many attempts" saat login | Batas percobaan (5 per menit) | Tunggu sekitar satu menit. |
| Semua 2FA tiba-tiba gagal setelah mengganti `.env` | `APP_KEY` berubah | Kembalikan `APP_KEY` lama dari backup `.env`. Jika hilang, reset 2FA setiap pengguna (bagian 12). |
| Migrasi menolak: `Refusing to shift times: the MySQL server is not at +07:00.` (atau `connection` / `application timezone`) | Zona waktu MySQL atau Windows bukan WIB | Pemilik mengatur zona Windows ke (UTC+07:00) Bangkok, Hanoi, Jakarta, atau menambahkan `default-time-zone='+07:00'` di bagian `[mysqld]` pada my.ini Laragon, lalu me-restart MySQL. Periksa ulang dengan perintah di langkah 7 bagian 4. |
| Teks muncul dalam bahasa Inggris di tampilan Indonesia | Kunci terjemahan hilang atau cache lama | `php artisan app:translations-missing`, lalu `php artisan app:translations-cache-clear`. |

## 15. Struktur folder dan alur Git

**Folder penting:**

| Folder / berkas | Isi |
|---|---|
| `app/Actions` | Aksi akun (buat dan undang pengguna, kirim ulang undangan, ubah data) |
| `app/Console/Commands` | Perintah `app:*` |
| `app/Enums` | Peran, izin, status, event audit |
| `app/Http/Controllers` | Halaman publik dan halaman sensitif (login, aktivasi, keamanan), ekspor, unduh bukti |
| `app/Livewire` | Halaman yang login penuh (dashboard, pengguna, pengajuan, persetujuan, riwayat, rekap, profil) |
| `app/Models`, `app/Policies` | Model Eloquent dan aturan otorisasi |
| `app/Services` | Aturan bisnis: atasan dan penjaga, saldo cuti, `Requests/` (pengajuan, keputusan, kunci), `Reports/` (riwayat, rekap, dashboard) |
| `app/Support`, `app/Translation` | 2FA, sesi, email, CSV; pemuat terjemahan dari database |
| `config/requests.php` | Batas pengajuan, kunci tinjau, ekspor CSV |
| `database/migrations`, `database/seeders` | Skema dan data dasar |
| `database/translations` | Teks dasar `en.php` dan `id.php` |
| `resources/views` | Blade: `layouts`, `components/ui` (komponen bersama), `livewire`, `auth`, `mail` |
| `routes/web.php`, `routes/console.php` | Rute web dan jadwal scheduler |
| `tests/Feature`, `tests/Unit`, `tests/Support/race.php` | Tes dan pembantu tes race |
| `storage/app/private/evidence` | Berkas bukti pengajuan (privat, tidak di Git) |
| `storage/logs/laravel.log` | Log aplikasi (tidak di Git) |
| `docs/` | Dokumentasi teknis |

**Alur Git:**
- Cabang utama `main`. Cabang `archive/phase6-hr-modules` menyimpan pekerjaan lama yang dibatalkan; jangan dipakai.
- Pekerjaan dilakukan per phase sesuai SPEC.md dan di-commit **lokal** dengan pesan yang jelas. Untuk percobaan, buat cabang terpisah.
- Sebelum commit: `git status` dan periksa diff yang di-stage (`git diff --cached`). Jangan meng-commit `.env`, backup, atau rahasia.
- **Tidak ada push tanpa izin pemilik.** Tidak ada force push dan tidak ada `git reset` yang membuang riwayat.

## 16. Dokumen lain

| Berkas | Fungsi |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Aturan kerja untuk developer dan agen AI: lingkungan Laragon, aturan database, langkah tiap phase, dan pelajaran yang tidak boleh diulang. |
| [DECISIONS.md](DECISIONS.md) | Aturan bisnis yang disepakati pemilik; menggantikan SRS jika berbeda. |
| [SPEC.md](SPEC.md) | Daftar phase dan apa yang dibangun di tiap phase, beserta statusnya. |
| [PROGRESS.md](PROGRESS.md) | Log kemajuan per phase: yang selesai, hasil verifikasi, bug, masalah yang diketahui, langkah pemilik. |
| [docs/TECHNICAL-DECISIONS.md](docs/TECHNICAL-DECISIONS.md) | Keputusan dan catatan teknis rinci (bahasa Inggris), dipindahkan utuh dari README lama. |

## 17. Belum terverifikasi

Hal berikut ditulis dari kode, konfigurasi, dan catatan phase sebelumnya, tetapi belum dibuktikan dengan menjalankannya:

- **Instalasi lengkap dari clone baru di komputer bersih** (bagian 4) belum dicoba dari awal sampai akhir. Perintah diperiksa terhadap `composer.json`, `package.json`, `.env.example`, `php artisan list`, dan `--help`; `migrate`, `db:seed`, `key:generate`, `app:create-admin` tidak dijalankan untuk dokumen ini.
- Pembuatan virtual host otomatis `hr-system.test` oleh Laragon untuk clone baru, dan ketersediaan `php`/`mysql`/`composer` di Terminal Laragon.
- Perintah `schtasks /Create` belum pernah dijalankan (sintaksnya dicek dengan `schtasks /Create /?`). Tanpa opsi `/RU`, tugas hanya berjalan saat pengguna Windows itu login.
- Pengiriman email ke kotak surat nyata, termasuk contoh Gmail dengan App Password. Jalur kegagalan SMTP sudah terbukti; pengiriman yang berhasil belum.
- Perbaikan zona waktu MySQL lewat zona Windows atau `default-time-zone` di my.ini.
- Perintah `mysqldump` dan pemulihan di bagian 12 tidak dijalankan untuk dokumen ini (opsinya dicek dengan `mysqldump --help`); backup di phase sebelumnya berhasil tetapi perintah persisnya tidak dicatat.
- `app:reset-two-factor` dan `app:create-admin` hanya diperiksa lewat `--help` dan kodenya.
- Langkah menambah bahasa baru (bagian 10) belum pernah dijalankan dengan bahasa ketiga.
- Skrip puppeteer berada di luar repositori dan tidak ikut di clone baru.
- Teks bahasa Indonesia ditulis oleh agen dan masih menunggu tinjauan pemilik.
