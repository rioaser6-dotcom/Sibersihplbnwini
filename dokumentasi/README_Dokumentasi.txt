DOKUMENTASI SIBERSIH PLBN WINI
Sistem Informasi Pengajuan dan Distribusi Barang Kebersihan
Badan Nasional Pengelola Perbatasan - Pos Lintas Batas Negara Wini
Edisi 2026


1. ISI FOLDER INI
-----------------
Panduan_Penggunaan_SIBERSIH_PLBN_Wini.pdf
    Panduan penggunaan lengkap, 52 halaman ukuran A4.
    Berisi kata pengantar, daftar isi dengan nomor halaman,
    pendahuluan, persiapan dan akses, panduan per menu untuk
    Admin dan Pengguna, alur kerja, penanganan masalah, tips
    keamanan, penutup, serta catatan verifikasi dan keterbatasan.

Presentasi_Panduan_Sistem.pptx
    Presentasi pendamping, 18 slide, rasio 16:9.
    Berisi ringkasan sistem, peran, alur kerja, tangkapan layar
    beranotasi, panduan menu utama, tips, batasan, dan penutup.
    Dapat dibuka dengan PowerPoint 2013 atau versi lebih baru,
    LibreOffice Impress, atau WPS Office.

Sumber_Panduan.md
    Sumber teks panduan dalam format Markdown. PDF dibangun dari
    berkas ini. Sunting berkas ini bila ada perubahan materi,
    lalu jalankan ulang proses build.

screenshots/
    25 tangkapan layar asli aplikasi (JPG, 1600 x 1000 piksel
    atau lebih tinggi). Nama berkas memakai nomor urut agar mudah
    dirujuk: 01_landing, 02_login, 05_admin_dashboard, dan seterusnya.
    Seluruh gambar diambil dari aplikasi yang berjalan, bukan mockup.

assets/
    logo-bnpp.png
        Logo instansi untuk sampul PDF dan presentasi.
    alur_pengajuan.svg
        Diagram alur utama pengajuan dan distribusi.
    alur_status.svg
        Diagram status pengajuan dan status bukti penerimaan.

tools/
    build.js          Pembangun PDF (Markdown -> HTML -> PDF).
    build-pptx.js     Pembangun presentasi (PptxGenJS).
    capture.js        Otomasi pengambilan tangkapan layar.
    package.json      Daftar dependensi dan perintah npm.
    package-lock.json Kunci versi dependensi.


2. CARA MEMBUKA
---------------
- PDF: klik dua kali berkas PDF, atau buka dengan Adobe Acrobat
  Reader, Microsoft Edge, Google Chrome, atau Foxit Reader.
- PPTX: klik dua kali berkas PPTX. Bila muncul peringatan
  "Protected View", pilih Enable Editing. Presentasi tidak memakai
  animasi, jadi dapat langsung ditampilkan dengan Slide Show.
- Untuk hasil terbaik, gunakan font Segoe UI (tersedia pada Windows).
  Bila font tidak tersedia, PowerPoint akan memakai font pengganti.


3. CARA MEMBUAT ULANG
---------------------
Prasyarat:
- Node.js versi 20 atau lebih baru.
- Google Chrome atau Microsoft Edge (untuk build PDF).
- Untuk mengambil ulang tangkapan layar: aplikasi SIBERSIH berjalan
  (Apache/PHP dan MySQL/MariaDB aktif) serta akun Admin yang sah.

Langkah:
    cd dokumentasi\tools
    npm install
    npm run build       (membuat PDF)
    npm run pptx        (membuat PPTX)
    npm run capture     (opsional: mengambil ulang screenshot)

Catatan:
- `npm run build` memakai dua lintasan. Lintasan pertama menghitung
  nomor halaman, lintasan kedua menuliskan nomor tersebut ke daftar
  isi dan footer. Jumlah halaman dapat berubah bila isi Markdown
  berubah.
- `capture.js` memakai Puppeteer dan profil Chrome. Variabel `ONLY`
  dapat dipakai untuk mengambil sebagian gambar, misalnya:
      set ONLY=02_login,05_admin_dashboard
      npm run capture
- Bila Chrome/Edge tidak terdeteksi otomatis, set variabel
  lingkungan CHROME_PATH ke lokasi chrome.exe atau msedge.exe.
- Jangan menaruh password, token, atau kredensial di dalam berkas
  Markdown, skrip, atau dokumentasi ini.


4. PRIVASI DAN KEAMANAN DATA
----------------------------
- Password tidak pernah ditampilkan pada tangkapan layar atau teks.
- Username, nama, NIK, dan isi pesan contoh disamarkan pada gambar.
- Tangkapan layar diambil dari data uji yang sudah ada. Tidak ada
  data produksi yang ditambah, diubah, atau dihapus untuk membuat
  dokumentasi.
- Kredensial database dan kunci aplikasi tidak dimasukkan ke dalam
  PDF, PPTX, maupun README ini.
- Tetap perlakukan PDF dan PPTX sebagai dokumen internal. PDF memuat
  alamat surel dan kop instansi; bagikan hanya kepada pihak yang
  berkepentingan.


5. VERIFIKASI YANG SUDAH DILAKUKAN
----------------------------------
PDF:
- Berkas dihasilkan oleh Chromium/Puppeteer dan pdf-lib, bukan berkas
  teks yang diubah ekstensinya.
- Jumlah halaman: 52. Tidak ada halaman kosong.
- Daftar isi memuat nomor halaman cetak. Nomor pada daftar isi sudah
  dicocokkan dengan footer setiap bab.
- Sampul tidak bernomor; halaman Daftar Isi adalah halaman cetak 1.
- Seluruh gambar dan diagram tampil; Tidak ada teks yang terpotong
  pada pemeriksaan halaman render.
- Font tertanam (Segoe UI, Segoe UI Bold, Segoe UI Black).
- Metadata judul, subjek, kata kunci, dan pembuat sudah diisi.

PPTX:
- Berkas dibuka dengan PowerPoint 2013 dan diekspor menjadi 18 gambar
  slide tanpa kesalahan.
- Rasio 16:9 (13,333 x 7,5 inci), 18 slide, tidak ada animasi.
- Tidak ada slide kosong, gambar rusak, atau teks yang keluar dari
  kotak pada pemeriksaan render.
- Tangkapan layar memakai berkas asli dari folder screenshots/.
- Anotasi bernomor, panah alur, dan keterangan gambar konsisten.


6. BATASAN ISI DOKUMENTASI
--------------------------
- Peran `vendor` ada pada struktur basis data, tetapi tidak memiliki
  halaman, alur kerja, atau hak login. Login dengan peran ini ditolak.
- Data uji tidak memiliki pengajuan berstatus Pending, Disetujui, atau
  Didistribusi. Karena itu, tabel untuk status tersebut tidak dapat
  ditampilkan sebagai tangkapan layar. Alurnya dijelaskan melalui
  diagram dan tabel.
- Tombol Setujui/Tolak tidak tampak pada tangkapan layar karena semua
  pengajuan pada data uji sudah diproses. Kemunculan tombol dijelaskan
  berdasarkan aturan status pada kode.
- Menu Laporan hanya menyediakan Cetak Laporan. Ekspor Excel/PDF
  langsung tidak tersedia; PDF dibuat melalui "Save as PDF" pada
  jendela cetak browser.
- Tidak ada notifikasi otomatis (email, WhatsApp, push), tidak ada
  menu ubah password untuk pengguna yang sudah login, tidak ada
  halaman riwayat mutasi stok, dan pesan hanya satu arah.
- Tampilan dapat sedikit berbeda tergantung versi browser, ukuran
  layar, dan pengaturan zoom.

Dokumen ini dibuat untuk keperluan dokumentasi internal PLBN Wini.
Tidak ada perubahan pada kode aplikasi, basis data, atau konfigurasi
produksi dalam proses pembuatan dokumentasi.
