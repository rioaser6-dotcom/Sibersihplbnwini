
<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

if (!isLoggedIn() || !isRole('admin')) {
    redirect('../login.php');
}

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function namaBulanIndo($angkaBulan)
{
    $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];

    return $bulan[(int) $angkaBulan] ?? '';
}

function e($nilai)
{
    return htmlspecialchars((string) ($nilai ?? ''), ENT_QUOTES, 'UTF-8');
}

function tanggalValid($tanggal)
{
    if (!is_string($tanggal)) {
        return false;
    }

    $obj = DateTime::createFromFormat('!Y-m-d', $tanggal);

    return $obj && $obj->format('Y-m-d') === $tanggal;
}

/*
|--------------------------------------------------------------------------
| FILTER LAPORAN
|--------------------------------------------------------------------------
| Laporan menampilkan pengajuan dengan status "selesai".
| Filter berdasarkan kolom tanggal pada tabel pengajuan.
*/

$jenis_filter = $_GET['jenis_filter'] ?? 'bulan';

if (!in_array($jenis_filter, ['tanggal', 'bulan', 'tahun'], true)) {
    $jenis_filter = 'bulan';
}

$dari_input   = trim((string) ($_GET['dari'] ?? ''));
$sampai_input = trim((string) ($_GET['sampai'] ?? ''));
$bulan_input  = trim((string) ($_GET['bulan'] ?? ''));
$tahun_input  = trim((string) ($_GET['tahun'] ?? ''));

$dari = '';
$sampai = '';
$filter_aktif = false;
$teks_periode = '';
$pesan_filter = '';

switch ($jenis_filter) {
    case 'bulan':
        if ($bulan_input !== '') {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan_input)) {
                $dari = $bulan_input . '-01';
                $sampai = date('Y-m-t', strtotime($dari));
                $filter_aktif = true;

                [$tahunBulan, $angkaBulan] = explode('-', $bulan_input);

                $teks_periode = 'Bulan ' .
                    namaBulanIndo($angkaBulan) . ' ' . $tahunBulan;
            } else {
                $pesan_filter = 'Format bulan tidak valid.';
            }
        }
        break;

    case 'tahun':
        if ($tahun_input !== '') {
            if (preg_match('/^\d{4}$/', $tahun_input)) {
                $dari = $tahun_input . '-01-01';
                $sampai = $tahun_input . '-12-31';
                $filter_aktif = true;
                $teks_periode = 'Tahun ' . $tahun_input;
            } else {
                $pesan_filter = 'Format tahun tidak valid.';
            }
        }
        break;

    case 'tanggal':
        if ($dari_input !== '' && !tanggalValid($dari_input)) {
            $pesan_filter = 'Tanggal awal tidak valid.';
        } elseif ($sampai_input !== '' && !tanggalValid($sampai_input)) {
            $pesan_filter = 'Tanggal akhir tidak valid.';
        } elseif (
            $dari_input !== '' &&
            $sampai_input !== '' &&
            $dari_input > $sampai_input
        ) {
            $pesan_filter = 'Tanggal awal tidak boleh melewati tanggal akhir.';
        } else {
            $dari = $dari_input;
            $sampai = $sampai_input;

            if ($dari !== '' || $sampai !== '') {
                $filter_aktif = true;

                $teks_periode =
                    ($dari !== '' ? date('d-m-Y', strtotime($dari)) : 'Awal data')
                    . ' s/d ' .
                    ($sampai !== '' ? date('d-m-Y', strtotime($sampai)) : 'Sekarang');
            }
        }
        break;
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA PENGAJUAN SELESAI
|--------------------------------------------------------------------------
| Tidak menggunakan JOIN distribusi agar satu pengajuan tidak tampil
| berulang jika memiliki lebih dari satu catatan distribusi.
*/

$data = [];
$detailBarang = [];
$totalPengajuan = 0;
$totalJenisBarang = 0;
$errorDatabase = false;

try {
    $sql = "
        SELECT
            p.*,
            u.nama AS nama_user
        FROM pengajuan p
        INNER JOIN users u ON p.user_id = u.id
        WHERE p.status = ?
    ";

    $params = ['selesai'];

    if ($dari !== '') {
        $sql .= " AND p.tanggal >= ?";
        $params[] = $dari;
    }

    if ($sampai !== '') {
        $sql .= " AND p.tanggal <= ?";
        $params[] = $sampai;
    }

    $sql .= " ORDER BY p.tanggal DESC, p.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPengajuan = count($data);

    /*
    |--------------------------------------------------------------------------
    | AMBIL SELURUH DETAIL BARANG SEKALIGUS
    |--------------------------------------------------------------------------
    */

    if ($totalPengajuan > 0) {
        $idsPengajuan = array_column($data, 'id');
        $placeholders = implode(',', array_fill(0, count($idsPengajuan), '?'));

        $sqlDetail = "
            SELECT
                dp.pengajuan_id,
                dp.jumlah,
                dp.satuan,
                b.nama_barang
            FROM detail_pengajuan dp
            INNER JOIN barang b ON dp.barang_id = b.id
            WHERE dp.pengajuan_id IN ($placeholders)
            ORDER BY dp.pengajuan_id ASC, b.nama_barang ASC
        ";

        $stmtDetail = $pdo->prepare($sqlDetail);
        $stmtDetail->execute($idsPengajuan);

        foreach ($stmtDetail->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $idPengajuan = $item['pengajuan_id'];

            if (!isset($detailBarang[$idPengajuan])) {
                $detailBarang[$idPengajuan] = [];
            }

            $detailBarang[$idPengajuan][] = $item;
        }

        foreach ($detailBarang as $items) {
            $totalJenisBarang += count($items);
        }
    }
} catch (PDOException $e) {
    error_log('Kesalahan laporan PLBN Wini: ' . $e->getMessage());
    $errorDatabase = true;
}

/*
|--------------------------------------------------------------------------
| PERIODE CETAK
|--------------------------------------------------------------------------
*/

if (!$filter_aktif) {
    $teks_periode = 'Semua periode';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Laporan Pengajuan - PLBN WINI</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8;
            color: #1e293b;
        }

        /* SIDEBAR */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 250px;
            height: 100%;
            background: #2c3e50;
            color: #ecf0f1;
            padding: 20px;
            overflow-y: auto;
            z-index: 1000;
            transition: transform .3s ease;
        }

        .sidebar-brand {
            text-align: center;
            padding-top: 8px;
            margin-bottom: 15px;
        }

        .sidebar-logo {
            display: block;
            width: 90px;
            height: 90px;
            object-fit: contain;
            margin: 0 auto 7px;
        }

        .sidebar-brand h2 {
            font-size: 16px;
            font-weight: bold;
            padding: 9px 0;
            color: #ecf0f1;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            color: #ecf0f1;
            padding: 12px 15px;
            text-decoration: none;
            border-radius: 5px;
            margin-bottom: 5px;
            transition: background .2s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #34495e;
            color: #fff;
        }

        .sidebar a .icon {
            display: inline-block;
            width: 24px;
            margin-right: 4px;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar a.logout-link {
            margin-top: 20px;
            background: #e74c3c;
        }

        .sidebar a.logout-link:hover {
            background: #c0392b;
        }

        .close-sidebar {
            display: none;
            position: absolute;
            top: 10px;
            right: 15px;
            background: transparent;
            border: none;
            color: #ecf0f1;
            font-size: 28px;
            cursor: pointer;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        /* KONTEN UTAMA */
        .main-content {
            margin-left: 250px;
            padding: 25px;
        }

        .header {
            background: white;
            padding: 18px 25px;
            border-radius: 14px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .05);
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 {
            font-size: 22px;
            color: #0f172a;
            font-weight: 700;
        }

        .header h1 i {
            color: #3b82f6;
        }

        .user-info .logout {
            background: #e74c3c;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            white-space: nowrap;
        }

        .menu-toggle {
            display: none;
            background: transparent;
            border: none;
            font-size: 26px;
            color: #0f172a;
            cursor: pointer;
        }

        /* FILTER */
        .filter-panel,
        .panel {
            background: white;
            padding: 22px;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .05);
        }

        .filter-panel {
            margin-bottom: 20px;
        }

        .filter-panel form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
        }

        .filter-group input,
        .filter-group select {
            min-height: 40px;
            padding: 9px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 9px;
            font-family: inherit;
            font-size: 14px;
            background: #f8fafc;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .filter-inputs-dinamis {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 40px;
            padding: 9px 17px;
            border: none;
            border-radius: 9px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: .2s;
        }

        .btn-filter {
            background: #2563eb;
        }

        .btn-filter:hover {
            background: #1d4ed8;
        }

        .btn-reset {
            background: #64748b;
        }

        .btn-reset:hover {
            background: #475569;
        }

        .btn-cetak {
            background: #2563eb;
            margin-top: 18px;
        }

        .btn-cetak:hover {
            background: #1d4ed8;
        }

        /* INFORMASI */
        .filter-info {
            margin-top: 13px;
            font-size: 13px;
            color: #64748b;
        }

        .alert {
            padding: 13px 16px;
            border-radius: 9px;
            margin-bottom: 18px;
            line-height: 1.5;
        }

        .alert-warning {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
        }

        .alert-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .04);
            border-left: 4px solid #3b82f6;
        }

        .summary-card .label {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 8px;
        }

        .summary-card .value {
            font-size: 26px;
            font-weight: 700;
            color: #0f172a;
        }

        /* TABEL */
        .panel h3 {
            font-size: 18px;
            color: #0f172a;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f5f9;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
            font-size: 14px;
        }

        .table th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            background: #dcfce7;
            color: #166534;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .barang-list {
            margin: 0;
            padding-left: 18px;
        }

        .barang-list li {
            margin-bottom: 4px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 15px;
            color: #64748b;
        }

        .empty-state i {
            font-size: 42px;
            display: block;
            margin-bottom: 12px;
            color: #94a3b8;
        }

        
/* KOP LAPORAN CETAK */
.kop-surat {
    display: none;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 0 12px;
    border-bottom: 3px double #000;
    margin-bottom: 12px;
    width: 100%;
}

.kop-surat .logo {
    width: 75px;
    height: 85px;
    object-fit: contain;
    flex-shrink: 0;
    margin: 0;
}

.kop-surat .teks-instansi {
    text-align: center;
    flex: 0 1 auto;
    min-width: 0;
}

.kop-surat .instansi {
    font-size: 12px;
    font-weight: bold;
    margin-bottom: 3px;
    line-height: 1.25;
}

.kop-surat .alamat,
.kop-surat .contact {
    font-size: 10px;
    margin-top: 3px;
    line-height: 1.3;
}

.kop-surat-periode {
    display: none;
    text-align: center;
    font-size: 11px;
    font-weight: 600;
    margin: 12px 0;
}


        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0) !important;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .close-sidebar {
                display: block;
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .menu-toggle {
                display: block;
            }

            .header {
                padding: 16px;
                flex-wrap: wrap;
            }

            .header h1 {
                font-size: 18px;
            }

            .filter-panel,
            .panel {
                padding: 17px;
            }

            .filter-panel form,
            .filter-inputs-dinamis {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group,
            .filter-group input,
            .filter-group select {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .summary {
                grid-template-columns: 1fr;
            }

            .header .user-info {
                margin-left: auto;
            }

            .btn-filter,
            .btn-reset {
                width: 100%;
            }
        }

        
/* CETAK LAPORAN A4 PORTRAIT */
@media print {
    @page {
        size: A4 portrait;
        margin: 12mm;
    }

    html,
    body {
        width: 100%;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        color: #000 !important;
        font-family: 'Segoe UI', Arial, sans-serif;
        font-size: 10px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .sidebar,
    .overlay,
    .header,
    .filter-panel,
    .btn-cetak,
    .no-print {
        display: none !important;
    }

    .main-content {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .kop-surat {
        display: flex !important;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 0 0 10px;
        margin: 0 0 10px;
        border-bottom: 3px double #000;
        page-break-inside: avoid;
    }

    .kop-surat .logo {
        display: block !important;
        width: 75px;
        height: 85px;
        object-fit: contain;
        flex-shrink: 0;
    }

    .kop-surat .teks-instansi {
        flex: 0 1 auto;
        min-width: 0;
        text-align: center;
    }

    .kop-surat .instansi {
        font-size: 11px;
        font-weight: bold;
        line-height: 1.25;
        margin-bottom: 3px;
    }

    .kop-surat .alamat,
    .kop-surat .contact {
        font-size: 9px;
        line-height: 1.3;
        margin-top: 3px;
    }

    .kop-surat-periode {
        display: block !important;
        text-align: center;
        font-size: 11px;
        font-weight: bold;
        line-height: 1.5;
        margin: 10px 0 14px;
    }

    .summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 12px;
    }

    .summary-card {
        padding: 8px;
        border: 1px solid #777;
        border-left: 3px solid #000;
        border-radius: 0;
        box-shadow: none;
        background: #fff !important;
    }

    .summary-card .label {
        font-size: 9px;
        margin-bottom: 4px;
    }

    .summary-card .value {
        font-size: 15px;
    }

    .panel {
        width: 100% !important;
        padding: 0 !important;
        border: none !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
    }

    .panel h3 {
        display: none !important;
    }

    .table-wrapper {
        width: 100% !important;
        overflow: visible !important;
    }

    .table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed;
        border-collapse: collapse;
        font-size: 8px;
    }

    .table th,
    .table td {
        padding: 5px 4px;
        border: 1px solid #000 !important;
        color: #000 !important;
        overflow-wrap: break-word;
        word-wrap: break-word;
        vertical-align: top;
    }

    .table th {
        background: #eee !important;
        font-weight: bold;
    }

    .table th:nth-child(1),
    .table td:nth-child(1) {
        width: 5%;
        text-align: center;
    }

    .table th:nth-child(2),
    .table td:nth-child(2) {
        width: 15%;
    }

    .table th:nth-child(3),
    .table td:nth-child(3) {
        width: 12%;
    }

    .table th:nth-child(4),
    .table td:nth-child(4) {
        width: 16%;
    }

    .table th:nth-child(5),
    .table td:nth-child(5) {
        width: 24%;
    }

    .table th:nth-child(6),
    .table td:nth-child(6) {
        width: 13%;
    }

    .table th:nth-child(7),
    .table td:nth-child(7) {
        width: 15%;
    }

    .barang-list {
        margin: 0;
        padding-left: 12px;
    }

    .barang-list li {
        margin-bottom: 2px;
    }

    .status-badge {
        background: #fff !important;
        color: #000 !important;
        border: 1px solid #000;
        border-radius: 0;
        padding: 2px 4px;
        font-size: 8px;
    }

    .empty-state {
        padding: 20px 5px;
        color: #000 !important;
    }

    thead {
        display: table-header-group;
    }

    tfoot {
        display: table-footer-group;
    }

    tr {
        page-break-inside: avoid;
        break-inside: avoid;
    }
}


    </style>
</head>
<body>

<div class="overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <button type="button" class="close-sidebar" id="closeSidebarBtn"
            aria-label="Tutup menu">&times;</button>

    <div class="sidebar-brand">
        <img src="../assets/img/plbn-wini.png"
             alt="Logo PLBN Wini"
             class="sidebar-logo">
        <h2>PLBN WINI</h2>
    </div>

    <a href="dashboard.php">
        <span class="icon"><i class="fas fa-chart-pie"></i></span><span>Dashboard</span>
    </a>

    <a href="barang.php">
        <span class="icon"><i class="fas fa-boxes"></i></span><span>Kelola Barang</span>
    </a>

    <a href="pengajuan.php">
        <span class="icon"><i class="fas fa-file-alt"></i></span><span>Kelola Pengajuan</span>
    </a>

    <a href="distribusi.php">
        <span class="icon"><i class="fas fa-truck"></i></span><span>Distribusi</span>
    </a>

    <a href="laporan.php" class="active">
        <span class="icon"><i class="fas fa-file-lines"></i></span><span>Laporan</span>
    </a>

    <a href="anggota.php">
        <span class="icon"><i class="fas fa-users"></i></span><span>Kelola Anggota</span>
    </a>

    <a href="../logout.php" class="logout-link">
        <span class="icon"><i class="fas fa-right-from-bracket"></i></span><span>Logout</span>
    </a>
</div>

<!-- KONTEN UTAMA -->
<div class="main-content">

    <div class="header">
        <div class="header-title">
            <button class="menu-toggle" id="menuToggle"
                    aria-label="Buka menu">
                <i class="fas fa-bars"></i>
            </button>

            <h1>
                <i class="fas fa-file-alt"></i>
                Laporan Pengajuan Selesai
            </h1>
        </div>

        <div class="user-info">
            <a href="../logout.php" class="logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- FILTER -->
    <div class="filter-panel no-print">
        <form method="GET" action="laporan.php">

            <div class="filter-group">
                <label for="jenis_filter">Jenis Filter</label>
                <select name="jenis_filter" id="jenis_filter"
                        onchange="tampilkanInputFilter()">
                    <option value="bulan"
                        <?= $jenis_filter === 'bulan' ? 'selected' : '' ?>>
                        Per Bulan
                    </option>

                    <option value="tahun"
                        <?= $jenis_filter === 'tahun' ? 'selected' : '' ?>>
                        Per Tahun
                    </option>

                    <option value="tanggal"
                        <?= $jenis_filter === 'tanggal' ? 'selected' : '' ?>>
                        Rentang Tanggal
                    </option>
                </select>
            </div>

            <div class="filter-inputs-dinamis">

                <div class="filter-group" id="grup-tanggal">
                    <label for="dari">Dari Tanggal Pengajuan</label>
                    <input type="date" name="dari" id="dari"
                           value="<?= e($dari_input) ?>">
                </div>

                <div class="filter-group" id="grup-tanggal-sampai">
                    <label for="sampai">Sampai Tanggal Pengajuan</label>
                    <input type="date" name="sampai" id="sampai"
                           value="<?= e($sampai_input) ?>">
                </div>

                <div class="filter-group" id="grup-bulan">
                    <label for="bulan">Pilih Bulan</label>
                    <input type="month" name="bulan" id="bulan"
                           value="<?= e($bulan_input) ?>">
                </div>

                <div class="filter-group" id="grup-tahun">
                    <label for="tahun">Pilih Tahun</label>
                    <input type="number" name="tahun" id="tahun"
                           min="2000" max="2100"
                           placeholder="Contoh: <?= date('Y') ?>"
                           value="<?= e($tahun_input) ?>">
                </div>

            </div>

            <button type="submit" class="btn btn-filter">
                <i class="fas fa-filter"></i> Terapkan Filter
            </button>

            <a href="laporan.php" class="btn btn-reset">
                <i class="fas fa-sync-alt"></i> Reset
            </a>
        </form>

        <p class="filter-info">
            Laporan hanya menampilkan pengajuan yang berstatus selesai.
            <?= $filter_aktif ? 'Periode: ' . e($teks_periode) : 'Periode: semua data.' ?>
        </p>
    </div>

    <?php if ($pesan_filter !== ''): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <?= e($pesan_filter) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorDatabase): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            Data laporan gagal diambil dari database.
            Periksa konfigurasi database dan struktur tabel pengajuan,
            users, detail_pengajuan, serta barang. Rincian teknis dicatat
            pada log PHP.
        </div>
    <?php else: ?>

    

        <!-- TABEL LAPORAN -->
        <div class="panel">

            <div class="kop-surat">
                <?php
                $logo_path = '../assets/images/logo-bnpp.png';

                if (file_exists($logo_path)):
                ?>
                    <img src="<?= e($logo_path) ?>"
                         alt="Logo PLBN Wini" class="logo">
                <?php else: ?>
                    <div style="font-size:24px;font-weight:bold;text-align:center;">
                        PLBN<br>WINI
                    </div>
                <?php endif; ?>

                <div class="teks-instansi">
                    <div class="instansi">BADAN NASIONAL PENGELOLA PERBATASAN</div>
                    <div class="instansi">DEPUTI BIDANG PENGELOLAAN BATAS NEGARA</div>
                    <div class="instansi">ASISTEN DEPUTI PENGELOLAAN LINTAS BATAS NEGARA</div>
                    <div class="instansi">POS LINTAS BATAS NEGARA WINI</div>
                    <div class="alamat">
                        Jl. Miguel Lopez Desa Humusu Wini, Kec. Insana Utara,
                        Kab. TTU, Provinsi NTT
                    </div>
                    <div class="contact">
                        Email: plbnwini2017@gmail.com | Kode Pos: 85671
                    </div>
                </div>
            </div>

            <div class="kop-surat-periode">
                LAPORAN PENGAJUAN SELESAI<br>
                Periode: <?= e($teks_periode) ?>
            </div>

            <h3>
                <i class="fas fa-table"></i>
                Data Laporan Pengajuan
            </h3>

            <?php if (!$data): ?>

                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <strong>Belum ada data laporan.</strong>
                    <p>
                        Tidak ditemukan pengajuan berstatus selesai
                        <?= $filter_aktif ? 'pada periode ' . e($teks_periode) : 'dalam database' ?>.
                    </p>
                    <p style="margin-top:8px;font-size:13px;">
                        Pastikan status pada tabel pengajuan bernilai
                        <code>selesai</code> dan data pengguna terkait masih tersedia.
                    </p>
                </div>

            <?php else: ?>

                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Nama Pengaju</th>
                                <th>Divisi</th>
                                <th>Status Kepegawaian</th>
                                <th>Daftar Barang</th>
                                <th>Catatan</th>
                                <th>Tanggal Pengajuan</th>
                                
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($data as $index => $p): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>

                                <td><?= e($p['nama_pengaju'] ?? $p['nama_user'] ?? '-') ?></td>

                                <td><?= e($p['divisi_pengaju'] ?? '-') ?></td>

                                <td><?= e($p['status_kepegawaian'] ?? '-') ?></td>

                                <td>
                                    <?php
                                    $items = $detailBarang[$p['id']] ?? [];

                                    if ($items):
                                    ?>
                                        <ul class="barang-list">
                                            <?php foreach ($items as $item): ?>
                                                <li>
                                                    <?= e($item['nama_barang'] ?? 'Barang') ?>
                                                    (<?= e($item['jumlah'] ?? 0) ?>
                                                    <?= e($item['satuan'] ?? '') ?>)
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span>- Tidak ada detail barang -</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= e(
                                        trim((string) ($p['catatan'] ?? '')) !== ''
                                            ? $p['catatan']
                                            : '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?php
                                    $tanggalPengajuan = $p['tanggal'] ?? '';

                                    echo tanggalValid((string) $tanggalPengajuan)
                                        ? e(date('d-m-Y', strtotime($tanggalPengajuan)))
                                        : '-';
                                    ?>
                                </td>

                                
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="button" onclick="window.print()"
                        class="btn btn-cetak no-print">
                    <i class="fas fa-print"></i> Cetak Laporan
                </button>

            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<script>
function tampilkanInputFilter() {
    const jenis = document.getElementById('jenis_filter').value;

    const grupTanggal = document.getElementById('grup-tanggal');
    const grupTanggalSampai = document.getElementById('grup-tanggal-sampai');
    const grupBulan = document.getElementById('grup-bulan');
    const grupTahun = document.getElementById('grup-tahun');

    grupTanggal.style.display = 'none';
    grupTanggalSampai.style.display = 'none';
    grupBulan.style.display = 'none';
    grupTahun.style.display = 'none';

    if (jenis === 'tanggal') {
        grupTanggal.style.display = 'flex';
        grupTanggalSampai.style.display = 'flex';
    } else if (jenis === 'bulan') {
        grupBulan.style.display = 'flex';
    } else if (jenis === 'tahun') {
        grupTahun.style.display = 'flex';
    }
}

(function () {
    tampilkanInputFilter();

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuToggle = document.getElementById('menuToggle');
    const closeBtn = document.getElementById('closeSidebarBtn');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', function (event) {
            event.stopPropagation();

            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }

    overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });
})();
</script>

</body>
</html>
