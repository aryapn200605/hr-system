# DECISIONS: Keputusan bisnis modul HR (final)

Dokumen ini menggantikan SRS PT PDS jika ada perbedaan. Letakkan di root repo bersama CLAUDE.md dan SPEC.md. Setiap phase harus membaca file ini. Penomoran phase mengikuti SPEC.md.
Hal yang ditandai **[ASUMSI]** belum diputuskan penuh. Jangan diubah diam-diam; laporkan jika perlu menyimpang.

## 1. Prinsip umum
- Fokus fungsional dulu. Tampilan minimalis memakai komponen dan token desain yang ada. Tidak ada redesain dan tidak ada library baru sampai tahap desain di akhir.
- Docker, VPS, Nginx, backup server, dan deploy ditunda sampai development selesai (Phase 16, dimulai oleh pemilik).
- Semua pengujian manual dan browser lewat Laragon di `http://hr-system.test`. Jika Laragon belum menyala, minta pemilik menyalakannya; jangan dinyalakan atau dikonfigurasi sendiri.
- Auth, 2FA wajib untuk semua user, flow undangan, recovery code, dan username/email yang bisa diubah sendiri **dipertahankan seperti sekarang**.
- Reset 2FA hanya lewat konsol (`app:reset-two-factor`), tidak ada aksi di UI. 2FA tidak bisa dinonaktifkan.
- Dokumen SRS disesuaikan setelah aplikasi selesai.
- Ditunda: chatbot (FR23) dan FR25–FR28. Redesain (gaya ungu, login lebih menarik) adalah Phase 15 dan dimulai pemilik. Dashboard (FR20) dikerjakan di Phase 12 setelah data nyata ada. Notifikasi email (FR29) opsional di Phase 13.
- Bahasa (English/Indonesia) dipilih di Profile, teks disimpan di database agar bahasa baru bisa ditambah tanpa mengubah kode. **Dibangun lebih dulu (Phase 7)**, sebelum modul HR, supaya setiap kalimat baru langsung didaftarkan di kedua bahasa dalam perubahan yang sama. Tamu dan user tanpa pilihan memakai bahasa bawaan (English) [ASUMSI].

## 2. Zona waktu
- Semua waktu memakai **WIB (Asia/Jakarta)**: konfigurasi aplikasi, koneksi database, dan tampilan. Kantor hanya di WIB dan tidak ada waktu musim panas.
- Label "UTC" di tampilan diganti "WIB".
- Data waktu yang sudah ada digeser +7 jam lewat satu migrasi yang bisa dibatalkan. Kolom epoch (sesi, cache) dan kalkulasi TOTP (berbasis epoch) tidak boleh ikut digeser.
- Tanggal cuti/izin disimpan sebagai tipe DATE, bukan timestamp. Jam lembur disimpan sebagai datetime WIB.

## 3. Peran dan relasi atasan
- Tiga peran: Employee, Approver, HR Admin. **Ketiganya bisa mengajukan** izin dan lembur.
- Atasan adalah relasi di data karyawan (banyak ke banyak). Atasan harus berperan Approver dan berstatus ACTIVE.
- Hak setuju membutuhkan dua syarat sekaligus: peran Approver dan relasi atasan dengan pemohon. Keduanya dicek di server.
- Alur persetujuan (satu tingkat):

| Pemohon | Disetujui oleh |
|---|---|
| Employee | Salah satu atasan yang terhubung. Keputusan pertama yang masuk yang berlaku. |
| Approver | HR Admin mana pun, tanpa relasi. |
| HR Admin | Salah satu atasan yang ditunjuk (peran Approver). Boleh lebih dari satu. |

- Tidak ada self-approval.
- User boleh tidak punya atasan. Akibatnya hanya tidak bisa mengajukan. Beri penanda "belum punya atasan" di daftar User Management (dengan filter), dan tombol ajukan dinonaktifkan dengan pesan yang jelas.
- **Aturan penjaga global:** tidak ada aksi yang boleh membuat user yang saat ini punya atasan aktif menjadi tidak punya sama sekali. Berlaku untuk menonaktifkan atasan, mencabut peran Approver, melepas relasi, aksi massal, dan perintah konsol. Satu layanan pusat, dipanggil dari semua pintu masuk. Pesan penolakan menyebut siapa yang terdampak.
- Ganti atasan massal oleh HR Admin (pindahkan semua atau karyawan terpilih dari satu atasan ke atasan lain), satu transaksi, tercatat di audit log.

## 4. Pengajuan (Phase 9 dan 10)
- Dua jenis: **Izin** dan **Lembur**.
- Izin punya kategori dari tabel acuan, kolom: kode, nama, memotong saldo (bool), aktif (bool). Tidak ada layar pengelolaan; developer yang mengubah lewat migrasi/seeder yang aman dijalankan berulang. Data dasar: Cuti tahunan (memotong), Sakit (tidak), Izin lainnya (tidak).
- Hari kerja Senin–Jumat dikurangi hari libur nasional/cuti bersama. Tabel libur diisi manual oleh HR.
- Tidak boleh mundur: hanya hari berjalan (WIB) dan sesudahnya.
- Tanggal izin tidak boleh bertumpuk dengan pengajuan lain yang berstatus pending atau disetujui. Yang ditolak atau dibatalkan tidak dihitung.
- Bukti opsional, ada batas ukuran unggahan, tanpa batas lama simpan sekarang. Berkas di penyimpanan privat.
- Lembur: waktu sampai ketelitian jam, boleh lintas tengah malam, **maksimal 12 jam per pengajuan**. Ujung satu pengajuan boleh sama dengan awal pengajuan berikutnya; hanya jam yang benar-benar menimpa yang ditolak. Tidak ada batas total per hari: halaman putusan atasan menampilkan total jam lembur pemohon pada hari itu.
- **Lembur ditolak** jika salah satu tanggal yang disentuhnya jatuh pada izin yang pending atau disetujui.
- Pemohon boleh membatalkan pengajuan yang masih pending (FR13). Penolakan wajib disertai alasan.
- Bukti lembur cukup alasan atau berkas unggahan; sisanya persetujuan biasa.

## 5. Saldo cuti
- Jatah 12 hari per tahun, **reset tiap tahun tanpa carry over** (sisa hangus).
- Tanggal acuan reset per karyawan: tanggal akun dibuat atau tanggal yang dipilih HR (misal tanggal masuk kerja).
- Saldo disimpan **per periode** (mulai sampai tanggal reset berikutnya) dengan buku besar perubahan (siapa, kapan, alasan, sebelum, sesudah). Riwayat tidak dihapus.
- Cuti yang melewati tanggal reset dipecah: hari sebelum reset memotong periode lama, hari sesudah memotong periode baru. Validasi saat mengajukan memeriksa kedua bagian terpisah. Persetujuan yang terjadi setelah reset untuk tanggal sebelum reset memotong periode lamanya.
- Reset otomatis harian yang idempotent, ditambah aksi manual HR. Penyesuaian manual wajib beralasan.
- **[ASUMSI]** Tahun pertama karyawan baru mendapat jatah penuh 12 hari.

## 6. Penguncian saat memutuskan (Phase 10)
Dua lapis:
1. **Saat dibuka:** atasan yang membuka pengajuan untuk diputuskan memperoleh kunci lunak dengan masa berlaku singkat (sekitar 5 menit, diperpanjang saat aktif, dilepas saat ditutup atau kedaluwarsa). Atasan lain yang membukanya masuk dalam **mode lihat saja** dan melihat siapa yang sedang membuka.
2. **Saat submit:** keputusan diproses dalam transaksi dengan kunci baris dan status dicek ulang. Kalau sudah diputuskan, tampilkan "sudah diputuskan oleh X pada jam Y", bukan galat. Kunci lunak tidak menggantikan pengecekan ini.
Wajib ada tes race (dua atasan memutuskan bersamaan, pembatalan oleh pemohon berbarengan dengan putusan).

## 7. Hak akses audit dan lainnya
- Aktifkan ulang akun hanya oleh HR Admin.
- Akses audit log (user atau hanya konsol) masih dibahas; jangan dibuat dulu. Pembatalan pengajuan yang sudah disetujui juga belum diputuskan; jangan dibuat.
- Daftar sesi aktif dan riwayat login dipindah ke menu Profile.

## 8. Validasi pengajuan (FR12, untuk Phase 9)
Semua dicek di server, bukan hanya di form.
- Pemohon berstatus ACTIVE dan punya jalur persetujuan: Employee dan HR Admin butuh minimal satu atasan aktif; Approver butuh minimal satu HR Admin aktif.
- Alasan/deskripsi wajib (minimal 10 karakter, maksimal 1000).
- Izin: tanggal mulai tidak lebih awal dari hari ini (WIB), tanggal selesai tidak lebih awal dari tanggal mulai, minimal 1 hari kerja, kategori aktif, tidak bertumpuk dengan izin pending/disetujui, tidak bertumpuk dengan lembur pending/disetujui pada tanggal yang disentuh.
- Izin yang memotong saldo: jumlah hari kerja dialokasikan per periode saldo; tiap bagian tidak boleh melebihi sisa periode itu setelah dikurangi alokasi pengajuan pending.
- Lembur: menit harus :00 (ketelitian jam), selesai setelah mulai, maksimal 12 jam per pengajuan, tanggal mulai tidak lebih awal dari hari ini (WIB), tidak menimpa lembur pending/disetujui lain (ujung sama dengan awal boleh), tidak menyentuh tanggal izin pending/disetujui.
- Bukti opsional: pdf/jpg/png, batas ukuran (default 5 MB, bisa dikonfigurasi), tipe diperiksa dari isi berkas, disimpan di penyimpanan privat dengan nama acak dan hanya bisa diunduh oleh pihak yang berwenang.
- Jumlah hari kerja dibekukan saat pengajuan dibuat. Hari libur yang ditambahkan kemudian tidak mengubah pengajuan yang sudah ada. **[ASUMSI]**
- HR Admin tidak boleh menyesuaikan saldonya sendiri; harus HR Admin lain (lihat bagian 9).

## 9. Keputusan rinci yang diambil saat pengerjaan percobaan sebelumnya (tetap dipakai)
Pekerjaan percobaan Phase 6 sampai 10 versi lama dibatalkan (repo dikembalikan ke Phase 5), tetapi keputusan berikut sudah disetujui pemilik dan dipakai lagi.
- **Reset manual saldo:** periode berjalan ditutup kemarin, periode baru 12 hari dibuka hari ini sampai ulang tahun anchor berikutnya. Anchor tidak berubah. Ditolak jika periode terbuka dimulai hari ini.
- **Bawahan SUSPENDED tidak dihitung** oleh aturan penjaga atasan. Relasi atasan tetap tersimpan saat atasan disuspend dan berlaku lagi saat diaktifkan kembali.
- **Penanda "belum punya atasan"** hanya untuk Employee dan HR Admin. Approver tidak butuh atasan (pengajuannya ke HR mana pun), tetapi tetap boleh punya atasan dan tetap dijaga aturan penjaga.
- **Saldo bilangan bulat hari**, tanpa setengah hari. Penyesuaian manual hanya untuk periode berjalan dan sisa tidak boleh negatif. Periode yang sudah ditutup hanya berubah lewat jalur persetujuan cuti (Phase 10), dengan entri buku besar.
- **HR Admin tidak boleh menyesuaikan saldonya sendiri.**
- **Tanggal periode dihitung dari anchor**, bukan dari periode sebelumnya, supaya anchor 29 Februari benar di tahun kabisat (28 Februari di tahun biasa). Pengecekan harian mengejar periode yang terlewat jika server mati.
- Reset tetap berjalan untuk user PENDING dan SUSPENDED supaya periodenya tidak putus.
- Departemen nonaktif tetap melekat pada karyawan lama tetapi tidak bisa dipilih untuk penugasan baru.
- Audit penolakan oleh aturan penjaga ditulis tepat setelah rollback, bukan di dalam transaksi yang di-rollback.
- Rute sesi aktif dan riwayat login pindah ke Profile; rute lama dihapus tanpa redirect.
- Isi `leave_anchor_date` dilakukan setelah data waktu digeser ke WIB, supaya user yang dibuat pukul 17:00–24:00 WIB tidak mendapat tanggal satu hari lebih awal.

## 10. Cara kerja dengan Laragon dan database
- `hr_system` adalah database pengembangan di balik `hr-system.test` dan berisi akun nyata pemilik (jangan diubah). `hr_system_test` hanya untuk tes otomatis.
- Migrasi ke `hr_system` hanya di akhir phase, setelah tes hijau dan backup `mysqldump` yang terbukti terbaca. Hanya `migrate` biasa; `migrate:fresh`, rollback, atau `db:wipe` butuh izin eksplisit.
- Akun uji sementara untuk cek browser memakai awalan `zz-check-` dan dibersihkan dengan bukti jumlah akun nyata tidak berubah.
- Setelah laporan akhir phase, pemilik yang menjalankan langkah yang tertulis di README (misalnya scheduler Windows) jika diperlukan.