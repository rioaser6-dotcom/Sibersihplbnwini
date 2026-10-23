
<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

if (!isLoggedIn() || !isRole('user')) {
    redirect('../login.php');
}

$user_id = (int) $_SESSION['user_id'];

$flash = getFlash();
$csrf_pengajuan_user = csrfToken('pengajuan_user');

$formError = null;
$bukaForm = false;

$formData = [
    'nama_pengaju' => (string) ($_SESSION['nama'] ?? ''),
    'divisi' => (string) ($_SESSION['divisi'] ?? ''),
    'status_kepegawaian' => (string) ($_SESSION['status_kepegawaian'] ?? ''),
    'catatan' => '',
];

$formItems = [
    ['barang_id' => '', 'jumlah' => ''],
];

// Proses submit pengajuan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajukan'])) {
    $bukaForm = true;

    if (!csrfValid('pengajuan_user', $_POST['csrf_token'] ?? '')) {
        $formError = 'Permintaan tidak valid. Muat ulang halaman lalu coba kembali.';
    }

    $formData['nama_pengaju'] = trim((string) ($_POST['nama_pengaju'] ?? ''));
    $formData['divisi'] = trim((string) ($_POST['divisi'] ?? ''));
    $formData['status_kepegawaian'] = trim((string) ($_POST['status_kepegawaian'] ?? ''));
    $formData['catatan'] = trim((string) ($_POST['catatan'] ?? ''));

    $barang_ids = $_POST['barang_id'] ?? [];
    $jumlahs = $_POST['jumlah'] ?? [];
    $formItems = [];

    if ($formError === null) {
        if ($formData['nama_pengaju'] === '' || $formData['divisi'] === '' || $formData['status_kepegawaian'] === '') {
            $formError = 'Nama pengaju, divisi, dan status kepegawaian wajib diisi.';
        } elseif (
            mb_strlen($formData['nama_pengaju']) > 100 ||
            mb_strlen($formData['divisi']) > 100 ||
            mb_strlen($formData['status_kepegawaian']) > 50
        ) {
            $formError = 'Panjang nama, divisi, atau status kepegawaian melebihi batas.';
        } elseif (mb_strlen($formData['catatan']) > 5000) {
            $formError = 'Catatan maksimal 5.000 karakter.';
        } elseif (!is_array($barang_ids) || !is_array($jumlahs)) {
            $formError = 'Rincian barang tidak valid.';
        }
    }

    $items = [];

    if ($formError === null) {
        foreach ($barang_ids as $key => $barang_id) {
            $jumlah_nilai = $jumlahs[$key] ?? '';

            if (!is_scalar($barang_id) || !is_scalar($jumlah_nilai)) {
                $formError = 'Rincian barang tidak valid.';
                break;
            }

            $barang_id_raw = trim((string) $barang_id);
            $jumlah_raw = trim((string) $jumlah_nilai);

            // Simpan baris untuk ditampilkan kembali jika terjadi kesalahan.
            $formItems[] = [
                'barang_id' => $barang_id_raw,
                'jumlah' => $jumlah_raw,
            ];

            // Lewati baris kosong.
            if ($barang_id_raw === '' && $jumlah_raw === '') {
                continue;
            }

            $barang_id = filter_var($barang_id_raw, FILTER_VALIDATE_INT);
            $jumlah = filter_var($jumlah_raw, FILTER_VALIDATE_INT);

            if (!$barang_id || !$jumlah || $jumlah < 1) {
                $formError = 'Setiap barang yang dipilih harus memiliki jumlah minimal 1.';
                break;
            }

            // Gabungkan barang yang sama agar tidak membuat detail ganda.
            $items[$barang_id] = ($items[$barang_id] ?? 0) + $jumlah;
        }

        if ($formError === null && !$items) {
            $formError = 'Silakan pilih minimal satu barang dan masukkan jumlahnya.';
        }
    }

    if ($formError === null) {
        try {
            // Transaksi + SELECT ... FOR UPDATE mengunci baris barang. Dua
            // pengguna yang mengajukan barang sama akan diproses bergantian,
            // sehingga total pengajuan tidak dapat melampaui stok.
            $pdo->beginTransaction();

            $stokBarang = ambilStokTersediaBarang(
                $pdo,
                array_keys($items),
                null,
                true
            );

            if (count($stokBarang) !== count($items)) {
                $formError = 'Salah satu barang yang dipilih tidak ditemukan.';
            } else {
                foreach ($items as $barang_id => $jumlah) {
                    $barang_id = (int) $barang_id;
                    $jumlah = (int) $jumlah;

                    if ($jumlah < 1) {
                        $formError = 'Jumlah pengajuan harus berupa bilangan bulat minimal 1.';
                        break;
                    }

                    $tersedia = (int) ($stokBarang[$barang_id]['tersedia'] ?? 0);

                    if ($jumlah > $tersedia) {
                        $formError = pesanStokTidakCukup($stokBarang[$barang_id], $jumlah);
                        break;
                    }
                }
            }

            if ($formError === null) {
                $stmt = $pdo->prepare("
                    INSERT INTO pengajuan
                        (user_id, tanggal, nama_pengaju, divisi_pengaju,
                         status_kepegawaian, catatan, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')
                ");

                $stmt->execute([
                    $user_id,
                    date('Y-m-d'),
                    $formData['nama_pengaju'],
                    $formData['divisi'],
                    $formData['status_kepegawaian'],
                    $formData['catatan'],
                ]);

                $pengajuan_id = $pdo->lastInsertId();

                $stmtDetail = $pdo->prepare("
                    INSERT INTO detail_pengajuan
                        (pengajuan_id, barang_id, jumlah, satuan)
                    VALUES (?, ?, ?, ?)
                ");

                foreach ($items as $barang_id => $jumlah) {
                    $stmtDetail->execute([
                        $pengajuan_id,
                        $barang_id,
                        (int) $jumlah,
                        $stokBarang[(int) $barang_id]['satuan'],
                    ]);
                }

                $pdo->commit();

                flash('Pengajuan berhasil dikirim! Menunggu persetujuan admin.', 'success');
                redirect('riwayat.php');
            }

            $pdo->rollBack();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Gagal menyimpan pengajuan PLBN Wini: ' . $e->getMessage());
            $formError = 'Pengajuan gagal disimpan. Silakan periksa data dan coba kembali.';
        }
    }

    if (!$formItems) {
        $formItems = [['barang_id' => '', 'jumlah' => '']];
    }
}

// Ambil daftar barang tanpa relasi vendor_id
$barang = $pdo->query("
    SELECT *
    FROM barang
    ORDER BY nama_barang
")->fetchAll(PDO::FETCH_ASSOC);

// Hitung stok tersedia (stok fisik dikurangi pengajuan yang masih menunggu)
// agar tampilan formulir sama dengan aturan validasi di server.
$stokBarang = ambilStokTersediaBarang(
    $pdo,
    array_column($barang, 'id')
);

foreach ($barang as &$itemBarang) {
    $infoStok = $stokBarang[(int) $itemBarang['id']] ?? null;

    $itemBarang['stok_tersedia'] = $infoStok
        ? (int) $infoStok['tersedia']
        : max(0, (int) ($itemBarang['stok'] ?? 0));
    $itemBarang['stok_terpesan'] = $infoStok
        ? (int) $infoStok['terpesan']
        : 0;
}
unset($itemBarang);

$barang_dropdown = $barang;
$upload_dir = '../uploads/barang/';
$activeMenu = 'buat_pengajuan';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Buat Pengajuan - PLBN WINI</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>
        /* ===== GAYA DASAR ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            overflow-x: hidden;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f4f6f9;
            overflow-x: hidden;
        }

        /* Sidebar memakai includes/user_sidebar.css */

        /* Tombol hamburger di HP */
        .menu-toggle {
            display: none;
            background: #2c3e50;
            color: white;
            border: none;
            border-radius: 6px;
            width: 38px;
            height: 38px;
            font-size: 18px;
            cursor: pointer;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
        }

        /* ===== KONTEN UTAMA ===== */
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s ease;
        }

        /* ===== HEADER ===== */
        .header {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .header .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .header h1 {
            font-size: 20px;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .header h1 i {
            color: #3498db;
            margin-right: 8px;
        }

        .header .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .header .user-info .logout {
            background: #e74c3c;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            white-space: nowrap;
        }

        .header .user-info .logout:hover {
            background: #c0392b;
        }

        .header .btn-buat {
            background: #27ae60;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            font-size: 13px;
            white-space: nowrap;
            transition: background 0.3s;
        }

        .header .btn-buat:hover {
            background: #219150;
        }

        /* ===== NOTIFIKASI ===== */
        .flash {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .flash-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .flash-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .flash-warning {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde68a;
        }

        .flash-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        /* ===== PANEL ===== */
        .panel {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .panel h3 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            border-bottom: 2px solid #f4f6f9;
            padding-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ===== FORM ===== */
        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #1e293b;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: 0.2s;
            background: #f8fafc;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15);
            background: white;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 35px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .col-md-4 {
            flex: 1 1 calc(33.333% - 15px);
            min-width: 200px;
        }

        .col-md-5 {
            flex: 1 1 calc(41.666% - 15px);
            min-width: 200px;
        }

        .col-md-3 {
            flex: 1 1 calc(25% - 15px);
            min-width: 120px;
        }

        .col-md-2 {
            flex: 0 0 auto;
            min-width: 80px;
        }

        /* ===== TOMBOL ===== */
        .btn {
            display: inline-block;
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
            font-size: 13px;
            transition: background 0.2s;
            text-align: center;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-success:hover {
            background: #1e8449;
        }

        .btn-secondary {
            background: #94a3b8;
            color: white;
        }

        .btn-secondary:hover {
            background: #64748b;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 13px;
            border-radius: 5px;
        }

        .btn-kembali {
            background: #94a3b8;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 15px;
            font-weight: bold;
        }

        .btn-kembali:hover {
            background: #64748b;
        }

        /* ===== GRID BARANG ===== */
        .barang-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 15px;
        }

        .barang-card {
            border: 1px solid #ecf0f1;
            border-radius: 8px;
            padding: 15px;
            background: #fafafa;
            transition: 0.2s;
            text-align: center;
            min-width: 0;
        }

        .barang-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .barang-card .gambar {
            display: block;
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 4px;
            background: #eee;
            margin-bottom: 8px;
        }

        .barang-card .nama {
            font-weight: bold;
            font-size: 16px;
            color: #333;
            overflow-wrap: anywhere;
        }

        .barang-card .stok {
            margin-top: 6px;
            font-size: 14px;
            color: #2c3e50;
        }

        .barang-card .satuan {
            color: #7f8c8d;
        }

        /* ===== BARIS DETAIL BARANG ===== */
        .baris-barang {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
        }

        .baris-barang .col-md-5 {
            flex: 3;
            min-width: 180px;
        }

        .baris-barang .col-md-3 {
            flex: 1;
            min-width: 100px;
        }

        .baris-barang .col-md-2 {
            flex: 0 0 auto;
            min-width: 80px;
        }

        /* ===== RESPONSIVE TABLET / HP ===== */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 14px;
            }

            .menu-toggle {
                display: inline-flex;
            }

            .header {
                padding: 12px 14px;
                border-radius: 10px;
                margin-bottom: 14px;
            }

            .header h1 {
                font-size: 16px;
            }

            .header .user-info {
                gap: 8px;
            }

            .header .user-info .logout {
                padding: 6px 10px;
                font-size: 12px;
            }

            .header .btn-buat {
                padding: 7px 10px;
                font-size: 12px;
            }

            .panel {
                padding: 14px;
            }

            .panel h3 {
                font-size: 16px;
            }

            .col-md-4,
            .col-md-5,
            .col-md-3 {
                flex: 1 1 100%;
                min-width: 0;
            }

            .baris-barang .col-md-5,
            .baris-barang .col-md-3,
            .baris-barang .col-md-2 {
                flex: 1 1 100%;
                min-width: 0;
            }

            .baris-barang .col-md-2 .btn {
                width: 100%;
            }

            .barang-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 10px;
            }

            .barang-card {
                padding: 10px;
            }

            .barang-card .gambar {
                height: 110px;
            }

            .barang-card .nama {
                font-size: 14px;
            }
        }

        /* ===== RESPONSIVE HP KECIL ===== */
        @media (max-width: 480px) {
            .header {
                gap: 10px;
            }

            .header .header-left {
                width: 100%;
            }

            .header .header-left h1 {
                font-size: 15px;
            }

            .header .user-info {
                width: 100%;
                flex-wrap: wrap;
            }

            .header .user-info .btn-buat,
            .header .user-info .logout {
                flex: 1 1 100%;
                text-align: center;
                justify-content: center;
            }

            .barang-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .barang-card .gambar {
                height: 90px;
            }

            .barang-card .nama {
                font-size: 13px;
            }

            .barang-card .stok {
                font-size: 12px;
            }

            .baris-barang {
                padding: 10px;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }
        }
        /* ===== INFORMASI STOK ===== */
        .stok-note {
            display: block;
            margin-top: 4px;
            color: #94a3b8;
            font-size: 11px;
        }

        .baris-barang .stok-info {
            margin-top: 5px;
            color: #475569;
            font-size: 12px;
            line-height: 1.4;
        }

        .baris-barang .stok-info.stok-habis {
            color: #b91c1c;
            font-weight: 600;
        }

        .baris-barang .stok-error {
            min-height: 16px;
            margin-top: 4px;
            color: #b91c1c;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.35;
        }

        .baris-barang .form-control.input-invalid {
            border-color: #e74c3c;
            background: #fff5f5;
        }

        .btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            opacity: 0.8;
        }
    </style>
    <link rel="stylesheet" href="../includes/user_sidebar.css">
</head>

<body>

<?php require __DIR__ . '/../includes/user_sidebar.php'; ?>

<!-- KONTEN UTAMA -->
<div class="main-content">

    <!-- HEADER -->
    <div class="header">

        <div class="header-left">
            <button
                type="button"
                class="menu-toggle"
                id="menuToggle"
                aria-label="Buka menu"
                aria-expanded="false"
            >
                <i class="fas fa-bars"></i>
            </button>

            <h1>
                <i class="fas fa-pen-alt"></i>
                Buat Pengajuan
            </h1>
        </div>

        <div class="user-info">
            <button
                type="button"
                id="btnTampilkanForm"
                class="btn-buat"
            >
                <i class="fas fa-plus-circle"></i>
                Buat Pengajuan Baru
            </button>

            <a href="../logout.php" class="logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>

    </div>

    <!-- PESAN NOTIFIKASI -->
    <?php if (!empty($flash)): ?>
        <div class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <i class="fas fa-<?= $flash['type'] === 'success'
                ? 'check-circle'
                : ($flash['type'] === 'danger'
                    ? 'exclamation-circle'
                    : 'info-circle') ?>"></i>

            <span><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <?php if ($formError !== null): ?>
        <div class="flash flash-danger">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <!-- DAFTAR BARANG -->
    <div id="daftarBarang" style="display:<?= $bukaForm ? 'none' : 'block' ?>;">
        <div class="panel">

            <h3>
                <i class="fas fa-warehouse" style="color:#3498db;"></i>
                Daftar Barang
            </h3>

            <?php if (count($barang) > 0): ?>

                <div class="barang-grid">

                    <?php foreach ($barang as $b): ?>
                        <?php
                        $nama_gambar = basename(
                            (string) ($b['gambar'] ?? '')
                        );

                        $path_gambar = $upload_dir . $nama_gambar;

                        $gambar_tersedia = (
                            $nama_gambar !== '' &&
                            is_file($path_gambar)
                        );
                        ?>

                        <div class="barang-card">

                            <?php if ($gambar_tersedia): ?>

                                <img
                                    src="<?= htmlspecialchars(
                                        $upload_dir . rawurlencode($nama_gambar),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    class="gambar"
                                    alt="<?= htmlspecialchars(
                                        $b['nama_barang'] ?? 'Barang',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >

                            <?php else: ?>

                                <div
                                    class="gambar"
                                    style="display:flex; align-items:center; justify-content:center; color:#999; font-size:14px; background:#f0f0f0;"
                                >
                                    <i
                                        class="fas fa-image"
                                        style="font-size:30px;"
                                    ></i>
                                </div>

                            <?php endif; ?>

                            <div class="nama">
                                <?= htmlspecialchars(
                                    $b['nama_barang'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </div>

                            <div class="stok">
                                Stok tersedia:
                                <strong><?= (int) ($b['stok_tersedia'] ?? 0) ?></strong>

                                <span class="satuan">
                                    <?= htmlspecialchars(
                                        $b['satuan'] ?? '',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                                <?php if ((int) ($b['stok_terpesan'] ?? 0) > 0): ?>
                                    <small class="stok-note">
                                        Total stok <?= (int) ($b['stok'] ?? 0) ?>,
                                        sedang diajukan <?= (int) $b['stok_terpesan'] ?>
                                    </small>
                                <?php endif; ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div style="text-align:center; padding:40px 0; color:#7f8c8d;">
                    <i
                        class="fas fa-box-open"
                        style="font-size:48px; margin-bottom:15px; display:block;"
                    ></i>

                    <p style="font-size:16px;">
                        Belum ada barang yang tersedia.
                    </p>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <!-- FORM PENGAJUAN -->
    <div id="formPengajuan" style="display:<?= $bukaForm ? 'block' : 'none' ?>;">

        <div class="panel">

            <button
                type="button"
                class="btn-kembali"
                onclick="kembaliKeDaftar()"
            >
                <i class="fas fa-arrow-left"></i>
                Kembali ke Daftar Barang
            </button>

            <h3>
                <i class="fas fa-file-signature" style="color:#27ae60;"></i>
                Form Pengajuan Perlengkapan
            </h3>

            <form method="POST" id="formPengajuanBarang">
                <input type="hidden" name="ajukan" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_pengajuan_user, ENT_QUOTES, 'UTF-8') ?>">

                <!-- DATA PENGAJU -->
                <div class="row">

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>
                                Nama Lengkap
                                <span style="color:#e74c3c;">*</span>
                            </label>

                            <input
                                type="text"
                                name="nama_pengaju"
                                class="form-control"
                                placeholder="Nama lengkap Anda"
                                maxlength="100"
                                value="<?= htmlspecialchars($formData['nama_pengaju'], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>
                                Divisi / Bagian
                                <span style="color:#e74c3c;">*</span>
                            </label>

                            <input
                                type="text"
                                name="divisi"
                                class="form-control"
                                placeholder="Misal: HRD, Keuangan, IT"
                                maxlength="100"
                                value="<?= htmlspecialchars($formData['divisi'], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>
                                Status Kepegawaian
                                <span style="color:#e74c3c;">*</span>
                            </label>

                            <input
                                type="text"
                                name="status_kepegawaian"
                                class="form-control"
                                placeholder="Misal: PNS, Non-PNS, Kontrak"
                                maxlength="50"
                                value="<?= htmlspecialchars($formData['status_kepegawaian'], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                        </div>
                    </div>

                </div>

                <!-- CATATAN -->
                <div class="form-group">
                    <label>Catatan (opsional)</label>

                    <textarea
                        name="catatan"
                        class="form-control"
                        rows="3"
                        maxlength="5000"
                        placeholder="Tambahkan keterangan jika diperlukan"
                    ><?= htmlspecialchars($formData['catatan'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <!-- DETAIL BARANG -->
                <h5 style="margin:20px 0 10px; color:#2c3e50; font-size:16px;">
                    <i class="fas fa-list-ul" style="color:#3498db;"></i>
                    Daftar Barang yang Diajukan
                </h5>

                <div id="detailPengajuan">

                    <?php foreach ($formItems as $item): ?>
                        <div class="baris-barang">

                            <div class="col-md-5">
                                <select
                                    name="barang_id[]"
                                    class="form-control"
                                    required
                                >
                                    <option value="">-- Pilih Barang --</option>

                                    <?php foreach ($barang_dropdown as $b): ?>
                                        <?php $stokTersediaOpsi = (int) ($b['stok_tersedia'] ?? 0); ?>
                                        <option
                                            value="<?= (int) $b['id'] ?>"
                                            data-stok="<?= $stokTersediaOpsi ?>"
                                            data-satuan="<?= htmlspecialchars($b['satuan'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            <?= (string) $item['barang_id'] === (string) $b['id'] ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars(
                                                $b['nama_barang'] ?? '',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                            (Stok tersedia: <?= $stokTersediaOpsi ?>)
                                        </option>
                                    <?php endforeach; ?>

                                </select>

                                <div class="stok-info" data-stok-info>
                                    Pilih barang untuk melihat stok tersedia.
                                </div>
                            </div>

                            <div class="col-md-3">
                                <input
                                    name="jumlah[]"
                                    type="number"
                                    class="form-control"
                                    placeholder="Jumlah"
                                    min="1"
                                    step="1"
                                    inputmode="numeric"
                                    value="<?= htmlspecialchars((string) $item['jumlah'], ENT_QUOTES, 'UTF-8') ?>"
                                    required
                                >
                                <div class="stok-error" data-stok-error role="alert"></div>
                            </div>

                            <div class="col-md-2">
                                <button
                                    type="button"
                                    class="btn btn-success btn-sm"
                                    onclick="tambahBarisPengajuan()"
                                >
                                    <i class="fas fa-plus"></i>
                                    Tambah
                                </button>
                            </div>

                        </div>
                    <?php endforeach; ?>

                </div>

                <!-- TOMBOL FORM -->
                <div
                    class="form-actions"
                    style="margin-top:25px; display:flex; gap:12px; flex-wrap:wrap;"
                >
                    <button type="submit" class="btn btn-primary" id="btnAjukan">
                        <i class="fas fa-paper-plane"></i>
                        Ajukan
                    </button>

                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Batal
                    </a>
                </div>

            </form>

        </div>
    </div>

</div>

<script>
// Menampilkan form pengajuan
document.getElementById('btnTampilkanForm').addEventListener('click', function() {
    document.getElementById('daftarBarang').style.display = 'none';
    document.getElementById('formPengajuan').style.display = 'block';

    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
});

// Kembali ke daftar barang
function kembaliKeDaftar() {
    document.getElementById('daftarBarang').style.display = 'block';
    document.getElementById('formPengajuan').style.display = 'none';

    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

// =====================================================
// VALIDASI STOK DI FRONTEND
// Server tetap memvalidasi ulang; JavaScript hanya membantu
// pengguna melihat kesalahan lebih cepat.
// =====================================================
(function () {
    const form = document.getElementById('formPengajuanBarang');
    const container = document.getElementById('detailPengajuan');
    const tombolAjukan = document.getElementById('btnAjukan');

    if (!form || !container || !tombolAjukan) {
        return;
    }

    function ambilBaris() {
        return Array.from(container.querySelectorAll('.baris-barang'));
    }

    function dataBaris(row) {
        const select = row.querySelector('select[name="barang_id[]"]');
        const input = row.querySelector('input[name="jumlah[]"]');
        const info = row.querySelector('[data-stok-info]');
        const error = row.querySelector('[data-stok-error]');
        const opsi = select && select.selectedIndex >= 0
            ? select.options[select.selectedIndex]
            : null;

        let tersedia = null;
        let satuan = 'unit';

        if (opsi && opsi.value !== '') {
            const nilai = parseInt(opsi.getAttribute('data-stok') || '0', 10);
            tersedia = Number.isNaN(nilai) ? 0 : Math.max(0, nilai);

            const satuanOpsi = (opsi.getAttribute('data-satuan') || '').trim();
            if (satuanOpsi !== '') {
                satuan = satuanOpsi;
            }
        }

        return { row, select, input, info, error, tersedia, satuan };
    }

    function validasiStok() {
        const semuaBaris = ambilBaris().map(dataBaris);
        const totalPerBarang = new Map();

        // Jumlahkan seluruh baris dengan barang yang sama.
        semuaBaris.forEach(function (data) {
            if (!data.select || !data.input) return;

            const id = data.select.value;
            const jumlah = parseInt(data.input.value.trim(), 10);

            if (id !== '' && /^\d+$/.test(data.input.value.trim()) && jumlah > 0) {
                totalPerBarang.set(id, (totalPerBarang.get(id) || 0) + jumlah);
            }
        });

        let adaValid = false;
        let adaKesalahan = false;

        semuaBaris.forEach(function (data) {
            if (!data.select || !data.input) return;

            const id = data.select.value;
            const jumlahRaw = data.input.value.trim();
            let pesan = '';

            if (id === '' && jumlahRaw === '') {
                // Baris kosong boleh diabaikan.
                if (data.info) {
                    data.info.textContent = 'Pilih barang untuk melihat stok tersedia.';
                    data.info.classList.remove('stok-habis');
                }
            } else if (id === '') {
                pesan = 'Pilih barang terlebih dahulu.';
            } else {
                const tersedia = data.tersedia === null ? 0 : data.tersedia;

                if (data.info) {
                    data.info.textContent = 'Stok tersedia: ' + tersedia + ' ' + data.satuan;
                    data.info.classList.toggle('stok-habis', tersedia <= 0);
                }

                if (jumlahRaw === '') {
                    pesan = 'Jumlah pengajuan wajib diisi.';
                } else if (!/^\d+$/.test(jumlahRaw) || parseInt(jumlahRaw, 10) < 1) {
                    pesan = 'Jumlah pengajuan harus berupa bilangan bulat positif.';
                } else if (parseInt(jumlahRaw, 10) > tersedia) {
                    pesan = 'Jumlah pengajuan melebihi stok yang tersedia. Stok saat ini hanya ' + tersedia + ' ' + data.satuan + '.';
                } else if ((totalPerBarang.get(id) || 0) > tersedia) {
                    pesan = 'Total jumlah untuk barang ini melebihi stok yang tersedia (' + tersedia + ' ' + data.satuan + ').';
                } else {
                    adaValid = true;
                }
            }

            if (pesan !== '') {
                adaKesalahan = true;
            }

            if (data.error) {
                data.error.textContent = pesan;
            }

            data.input.classList.toggle('input-invalid', pesan !== '');
            data.input.setCustomValidity(pesan);

            if (data.tersedia === null) {
                data.input.removeAttribute('max');
            } else {
                data.input.max = String(data.tersedia);
            }
        });

        tombolAjukan.disabled = !adaValid || adaKesalahan;

        return adaValid && !adaKesalahan;
    }

    container.addEventListener('input', validasiStok);
    container.addEventListener('change', validasiStok);

    form.addEventListener('submit', function (event) {
        if (!validasiStok()) {
            event.preventDefault();
            const barisPertama = container.querySelector('.input-invalid');
            if (barisPertama) {
                barisPertama.focus();
            }
        }
    });

    validasiStok();
})();

// Menambahkan baris barang
function tambahBarisPengajuan() {
    const container = document.getElementById('detailPengajuan');
    const template = container.querySelector('.baris-barang');
    const newRow = template.cloneNode(true);

    // Kosongkan pilihan barang, jumlah, dan pesan validasi.
    newRow.querySelectorAll('input, select').forEach(function(el) {
        el.value = '';
        el.removeAttribute('max');
        el.setCustomValidity('');
        el.classList.remove('input-invalid');
    });

    newRow.querySelectorAll('[data-stok-info]').forEach(function(el) {
        el.textContent = 'Pilih barang untuk melihat stok tersedia.';
        el.classList.remove('stok-habis');
    });

    newRow.querySelectorAll('[data-stok-error]').forEach(function(el) {
        el.textContent = '';
    });

    container.appendChild(newRow);
}
</script>

</body>
</html>
