# DECISIONS: Keputusan bisnis modul HR (final)

Dokumen ini menggantikan SRS PT PDS jika ada perbedaan. Letakkan di root repo bersama CLAUDE.md dan SPEC.md. Setiap tahap harus membaca file ini.
Hal yang ditandai **[ASUMSI]** belum diputuskan penuh. Jangan diubah diam-diam; laporkan jika perlu menyimpang.

## 1. Prinsip umum
- Fokus fungsional dulu. Tampilan minimalis memakai komponen dan token desain yang ada. Tidak ada redesain dan tidak ada library baru sampai tahap desain di akhir.
- Docker, VPS, Nginx, backup server, dan deploy ditunda sampai development selesai.
- Auth, 2FA wajib untuk semua user, flow undangan, recovery code, dan username/email yang bisa diubah sendiri **dipertahankan seperti sekarang**.
- Reset 2FA hanya lewat konsol (`app:reset-two-factor`), tidak ada aksi di UI. 2FA tidak bisa dinonaktifkan.
- Dokumen SRS disesuaikan setelah aplikasi selesai.
- Ditunda ke akhir: dashboard (FR20), chatbot (FR23), FR25–FR28, redesain (gaya ungu, login lebih menarik), notifikasi email (FR29, boleh dikerjakan jika kecil).
- Bahasa (English/Indonesia) di Profile, teks disimpan di database agar bahasa baru bisa ditambah tanpa mengubah kode. Dikerjakan sebagai tahap sendiri setelah pengajuan dan persetujuan.

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

## 4. Pengajuan (Tahap 2 dan 3, untuk referensi)
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

## 6. Penguncian saat memutuskan (Tahap 3)
Dua lapis:
1. **Saat dibuka:** atasan yang membuka pengajuan untuk diputuskan memperoleh kunci lunak dengan masa berlaku singkat (sekitar 5 menit, diperpanjang saat aktif, dilepas saat ditutup atau kedaluwarsa). Atasan lain yang membukanya masuk dalam **mode lihat saja** dan melihat siapa yang sedang membuka.
2. **Saat submit:** keputusan diproses dalam transaksi dengan kunci baris dan status dicek ulang. Kalau sudah diputuskan, tampilkan "sudah diputuskan oleh X pada jam Y", bukan galat. Kunci lunak tidak menggantikan pengecekan ini.
Wajib ada tes race (dua atasan memutuskan bersamaan, pembatalan oleh pemohon berbarengan dengan putusan).

## 7. Hak akses audit dan lainnya
- Aktifkan ulang akun hanya oleh HR Admin.
- Akses audit log (user atau hanya konsol) masih dibahas; jangan dibuat dulu.
- Daftar sesi aktif dan riwayat login dipindah ke menu Profile.
