# SIBERSIH PLBN Wini

Sistem Informasi Pengajuan dan Distribusi Barang Kebersihan  
Badan Nasional Pengelola Perbatasan - Pos Lintas Batas Negara Wini

## Teknologi

- PHP 8.x (tanpa framework)
- MySQL/MariaDB
- Bootstrap 5 (CDN)
- JavaScript dan CSS bawaan aplikasi

## Struktur Utama

| Path | Keterangan |
| --- | --- |
| `index.php` | Halaman utama |
| `login.php`, `logout.php`, `lupa_password.php` | Autentikasi pengguna |
| `admin/` | Halaman dan proses untuk Admin |
| `user/` | Halaman dan proses untuk Pengguna |
| `includes/` | Fungsi bersama, header, dan sidebar |
| `config/` | Konfigurasi koneksi database |
| `assets/` | Gambar dan aset statis |
| `database/` | Skema dan migrasi database |
| `uploads/` | Penyimpanan berkas unggahan pengguna (tidak disertakan) |
| `dokumentasi/` | Panduan penggunaan, presentasi, dan tangkapan layar |

## Persiapan Lokal

1. Salin konfigurasi database:

   ```bash
   cp config/database.example.php config/database.php
   ```

   Sesuaikan `$host`, `$dbname`, `$username`, dan `$password`.

2. Buat database dan impor skema:

   ```bash
   mysql -u root -p < database/schema.sql
   ```

3. Buat akun awal (opsional):

   ```bash
   cp seed.example.php seed.php
   ```

   Ganti password contoh di `seed.php`, lalu jalankan:

   ```bash
   php seed.php
   ```

4. Letakkan project di direktori web server (misalnya `C:\xampp\htdocs\sistem_informasi_plbn_wini`),
   kemudian buka `http://localhost/sistem_informasi_plbn_wini/`.

## Catatan Keamanan

- `config/database.php`, `seed.php`, folder `backup/`, dan berkas unggahan pengguna
  tidak diikutsertakan ke repository karena bersifat lokal atau mengandung data sensitif.
- Ganti password akun bawaan sebelum aplikasi digunakan di lingkungan produksi.
- Folder `uploads/` harus tetap memiliki berkas `.htaccess` untuk memblokir eksekusi skrip.

## Dokumentasi

Panduan lengkap tersedia di:

- `dokumentasi/Panduan_Penggunaan_SIBERSIH_PLBN_Wini.pdf`
- `dokumentasi/Presentasi_Panduan_Sistem.pptx`
