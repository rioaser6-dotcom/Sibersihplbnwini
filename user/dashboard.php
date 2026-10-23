
<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

if (!isLoggedIn() || !isRole('user')) {
    redirect('../login.php');
}

$user_id = $_SESSION['user_id'];
$activeMenu = 'dashboard';

// Statistik pengajuan user sesuai status yang benar-benar dipakai.
$stats = [
    'total' => 0,
    'pending' => 0,
    'disetujui' => 0,
    'ditolak' => 0,
    'didistribusi' => 0,
    'selesai' => 0,
];

$query = $pdo->prepare("
    SELECT status, COUNT(*) AS jumlah
    FROM pengajuan
    WHERE user_id = ?
    GROUP BY status
");
$query->execute([$user_id]);
$results = $query->fetchAll(PDO::FETCH_ASSOC);

foreach ($results as $row) {
    $status = (string) $row['status'];
    $jumlah = (int) $row['jumlah'];

    switch ($status) {
        case 'pending':
        case 'diajukan':
            $stats['pending'] += $jumlah;
            break;
        case 'disetujui':
        case 'disetujui_vendor':
            $stats['disetujui'] += $jumlah;
            break;
        case 'ditolak':
        case 'ditolak_vendor':
            $stats['ditolak'] += $jumlah;
            break;
        case 'didistribusi':
            $stats['didistribusi'] += $jumlah;
            break;
        case 'selesai':
            $stats['selesai'] += $jumlah;
            break;
        default:
            break;
    }

    $stats['total'] += $jumlah;
}

$menunggu = $stats['pending'];
$disetujui = $stats['disetujui'] + $stats['didistribusi'];
$selesai = $stats['selesai'];

// Ambil 5 barang terbaru tanpa relasi vendor_id
$barang = $pdo->query("
    SELECT *
    FROM barang
    ORDER BY id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$upload_dir = '../uploads/barang/';
$jumlahPesanBelumDibaca = hitungPesanBelumDibaca($pdo, $user_id);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - PLBN WINI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* ===== GAYA DASAR ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { overflow-x: hidden; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; overflow-x: hidden; }

        /* Sidebar memakai includes/user_sidebar.css */

        /* Tombol hamburger (khusus HP) */
        .menu-toggle {
            display: none;
            background: #2c3e50; color: white; border: none; border-radius: 6px;
            width: 38px; height: 38px; font-size: 18px; cursor: pointer; flex-shrink: 0;
        }

        .main-content { margin-left: 250px; padding: 20px; transition: margin-left 0.3s ease; }

        .header {
            background: white; padding: 15px 20px; border-radius: 10px; margin-bottom: 20px;
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .header .header-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .header h1 { font-size: 20px; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .header .user-info { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }
        .header .user-info .logout {
            background: #e74c3c; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;
            white-space: nowrap;
        }
        .header .user-info .logout:hover { background: #c0392b; }

        /* ===== STATISTIK MODERN ===== */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stats .card {
            background: white;
            border-radius: 16px;
            padding: 20px 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            display: flex;
            align-items: center;
            gap: 16px;
            border-left: 6px solid #ccc;
            position: relative;
            overflow: hidden;
            min-width: 0;
        }

        .stats .card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.10);
        }

        .stats .card .card-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            flex-shrink: 0;
        }

        .stats .card .card-content {
            flex: 1;
            min-width: 0;
        }

        .stats .card .number {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.2;
            color: #1e293b;
        }

        .stats .card .label {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
            margin-top: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stats .card.total     { border-left-color: #3b82f6; }
        .stats .card.waiting   { border-left-color: #f59e0b; }
        .stats .card.approved  { border-left-color: #22c55e; }
        .stats .card.rejected  { border-left-color: #ef4444; }
        .stats .card.done      { border-left-color: #8b5cf6; }

        .stats .card.total .card-icon     { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .stats .card.waiting .card-icon   { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stats .card.approved .card-icon  { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .stats .card.rejected .card-icon  { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .stats .card.done .card-icon      { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

        .stats .card::after {
            content: '';
            position: absolute;
            right: -20px;
            bottom: -20px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: rgba(0,0,0,0.02);
            pointer-events: none;
        }

        /* ===== WELCOME BOX ===== */
        .welcome-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;
        }
        .welcome-box h2 { font-size: 24px; }
        .welcome-box p { margin-top: 5px; opacity: 0.9; }
        .quick-actions {
            display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap;
        }
        .quick-actions .btn { color: white; }

        /* ===== NOTIFIKASI PESAN BARU ===== */
        .pesan-alert {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            flex-wrap: wrap; padding: 14px 17px; margin-bottom: 20px;
            border: 1px solid #bfdbfe; border-left: 5px solid #3b82f6;
            border-radius: 10px; background: #eff6ff; color: #1e40af;
            text-decoration: none; box-shadow: 0 2px 6px rgba(59,130,246,.08);
        }
        .pesan-alert:hover { background: #e0edff; }
        .pesan-alert .pesan-alert-text { display: flex; align-items: center; gap: 10px; font-size: 14px; line-height: 1.5; }
        .pesan-alert .pesan-alert-text i { font-size: 19px; flex-shrink: 0; }
        .pesan-alert .pesan-alert-cta {
            display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
            padding: 8px 12px; border-radius: 6px; background: #3b82f6; color: white;
            font-size: 12px; font-weight: 700;
        }

        .btn {
            display: inline-block; padding: 8px 16px; border: none; border-radius: 5px;
            text-decoration: none; font-weight: bold; cursor: pointer; font-size: 13px;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #1e8449; }

        /* ===== BARANG GRID ===== */
        .barang-list {
            background: white; padding: 20px; border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-top: 20px;
        }
        .barang-list h3 {
            margin-bottom: 15px; color: #333; border-bottom: 2px solid #f4f6f9; padding-bottom: 10px;
        }
        .barang-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 15px;
        }
        .barang-card {
            border: 1px solid #ecf0f1; border-radius: 8px; padding: 15px;
            background: #fafafa; transition: 0.2s;
            text-align: center;
        }
        .barang-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .barang-card .gambar {
            width: 100%; height: 150px; object-fit: cover; border-radius: 4px;
            background: #eee; margin-bottom: 8px;
        }
        .barang-card .nama { font-weight: bold; font-size: 16px; }
        .barang-card .vendor { color: #7f8c8d; font-size: 13px; }
        .barang-card .stok { margin-top: 6px; font-size: 14px; color: #2c3e50; }
        .barang-card .satuan { color: #7f8c8d; }
        .barang-list .btn-more { margin-top: 15px; text-align: right; }

        /* ===== RESPONSIVE: TABLET / HP ===== */
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 14px; }

            .menu-toggle { display: inline-flex; align-items: center; justify-content: center; }

            .header { padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; }
            .header h1 { font-size: 16px; }
            .header .user-info .logout { padding: 6px 10px; font-size: 12px; }

            .welcome-box { padding: 16px; margin-bottom: 14px; }
            .welcome-box h2 { font-size: 17px; }
            .welcome-box p { font-size: 13px; }

            .stats { grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px; }
            .stats .card { padding: 14px 12px; gap: 10px; border-radius: 12px; }
            .stats .card .card-icon { width: 40px; height: 40px; font-size: 18px; border-radius: 10px; }
            .stats .card .number { font-size: 20px; }
            .stats .card .label { font-size: 11px; }

            .barang-list { padding: 14px; }
            .barang-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
            .barang-card { padding: 10px; }
            .barang-card .gambar { height: 100px; }
            .barang-card .nama { font-size: 13px; }
            .barang-card .vendor, .barang-card .stok { font-size: 11px; }

            .quick-actions { flex-direction: column; align-items: stretch; }
            .quick-actions .btn { text-align: center; }
        }

        /* ===== RESPONSIVE: HP KECIL ===== */
        @media (max-width: 480px) {
            .header-left h1 { font-size: 15px; }
            .header .user-info .logout span.txt { display: none; }

            .pesan-alert { align-items: flex-start; }
            .pesan-alert .pesan-alert-cta { width: 100%; justify-content: center; }

            .stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .stats .card { padding: 10px 8px; gap: 8px; flex-direction: column; text-align: center; }
            .stats .card .card-icon { width: 34px; height: 34px; font-size: 15px; }
            .stats .card .number { font-size: 18px; }
            .stats .card .label { font-size: 10px; white-space: normal; }

            .barang-grid { grid-template-columns: repeat(2, 1fr); }
            .barang-card .gambar { height: 90px; }

            .btn-more { text-align: center !important; }
        }
    </style>
    <link rel="stylesheet" href="../includes/user_sidebar.css">
</head>
<body>

<?php require __DIR__ . '/../includes/user_sidebar.php'; ?>

<!-- MAIN -->
<div class="main-content">
    <!-- HEADER -->
    <div class="header">
        <div class="header-left">
            <button type="button" class="menu-toggle" id="menuToggle" aria-label="Buka menu" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Dashboard</h1>
        </div>
        <div class="user-info">
            <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> <span class="txt">Logout</span></a>
        </div>
    </div>

    <!-- WELCOME BOX -->
    <div class="welcome-box">
        <h2>Selamat Datang di Sistem Pengajuan & Distribusi PLBN Wini</h2>
        <p>Silahkan mengajukan permintaan perlengkapan Anda melalui sistem ini.</p>
        <div class="quick-actions"></div>
    </div>

    <?php if ($jumlahPesanBelumDibaca > 0): ?>
        <a href="pesan.php" class="pesan-alert">
            <span class="pesan-alert-text">
                <i class="fas fa-envelope"></i>
                <span>
                    Anda memiliki
                    <strong><?= (int) $jumlahPesanBelumDibaca ?> pesan baru</strong>
                    dari Admin terkait distribusi barang.
                </span>
            </span>
            <span class="pesan-alert-cta">
                <i class="fas fa-eye"></i> Lihat Pesan
            </span>
        </a>
    <?php endif; ?>

    <!-- ===== STATISTIK MODERN ===== -->
    <div class="stats">
        <!-- Total Pengajuan -->
        <div class="card total">
            <div class="card-icon"><i class="fas fa-file-alt"></i></div>
            <div class="card-content">
                <div class="number"><?= $stats['total'] ?></div>
                <div class="label">Total Pengajuan</div>
            </div>
        </div>

        <!-- Menunggu persetujuan -->
        <div class="card waiting">
            <div class="card-icon"><i class="fas fa-clock"></i></div>
            <div class="card-content">
                <div class="number"><?= $menunggu ?></div>
                <div class="label">Menunggu</div>
            </div>
        </div>

        <!-- Disetujui (termasuk yang sudah didistribusikan) -->
        <div class="card approved">
            <div class="card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="card-content">
                <div class="number"><?= $disetujui ?></div>
                <div class="label">Disetujui</div>
            </div>
        </div>

        <!-- Ditolak -->
        <div class="card rejected">
            <div class="card-icon"><i class="fas fa-times-circle"></i></div>
            <div class="card-content">
                <div class="number"><?= $stats['ditolak'] ?></div>
                <div class="label">Ditolak</div>
            </div>
        </div>

        <!-- Selesai -->
        <div class="card done">
            <div class="card-icon"><i class="fas fa-check-double"></i></div>
            <div class="card-content">
                <div class="number"><?= $selesai ?></div>
                <div class="label">Selesai</div>
            </div>
        </div>
    </div>

    <!-- DAFTAR BARANG TERBARU -->
    <div class="barang-list">
        <h3><i class="fas fa-boxes" style="color:#3b82f6;"></i> Barang Terbaru</h3>

        <?php if (count($barang) > 0): ?>
            <div class="barang-grid">
                <?php foreach ($barang as $b): ?>
                    <?php
                    $nama_gambar = basename((string) ($b['gambar'] ?? ''));
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
                                alt="<?= htmlspecialchars($b['nama_barang'] ?? 'Barang', ENT_QUOTES, 'UTF-8') ?>"
                            >
                        <?php else: ?>
                            <div class="gambar" style="display:flex; align-items:center; justify-content:center; color:#999; font-size:14px; background:#f0f0f0;">
                                <i class="fas fa-image" style="font-size:30px;"></i>
                            </div>
                        <?php endif; ?>

                        <div class="nama">
                            <?= htmlspecialchars($b['nama_barang'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <div class="stok">
                            Stok:
                            <strong><?= (int) ($b['stok'] ?? 0) ?></strong>
                            <span class="satuan">
                                <?= htmlspecialchars($b['satuan'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="btn-more">
                <a href="lihat_barang.php" class="btn btn-success">Lihat Semua Barang →</a>
            </div>
        <?php else: ?>
            <p style="text-align: center; padding: 10px 0; color: #7f8c8d;">
                Belum ada barang yang tersedia.
            </p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
