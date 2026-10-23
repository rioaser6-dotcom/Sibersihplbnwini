<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';
if (!isLoggedIn() || !isRole('admin')) redirect('../login.php');

$admin_id = (int) $_SESSION['user_id'];
$flash = getFlash();

// ========== STATISTIK PENGAJUAN ==========
$stats = [
    'total'        => 0,
    'pending'      => 0,
    'disetujui'    => 0,
    'ditolak'      => 0,
    'didistribusi' => 0,
    'selesai'      => 0,
];

$query = $pdo->query("SELECT status, COUNT(*) as jumlah FROM pengajuan GROUP BY status");
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
            // Status yang tidak dikenal tidak dihitung sebagai pending.
            break;
    }

    $stats['total'] += $jumlah;
}

// ========== 5 PENGAJUAN TERBARU (LENGKAP) ==========
$recent = $pdo->query("
    SELECT p.*, u.nama as user_nama, u.divisi as user_divisi
    FROM pengajuan p 
    JOIN users u ON p.user_id = u.id 
    ORDER BY p.id DESC LIMIT 5
")->fetchAll();

// ========== TAMPILAN ==========
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - PLBN WINI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* ===== RESET & GLOBAL ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; }

        
/* ===== SIDEBAR ===== */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 250px;
    height: 100%;
    background: #2c3e50;
    color: white;
    padding: 20px;
    overflow-y: auto;
    z-index: 200;
    transition: transform 0.3s ease;
}

/* Logo dan judul */
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
    text-align: center;
    font-size: 16px;
    font-weight: bold;
    padding: 9px 0;
    margin: 0;
    border-bottom: none;
}

/* Menu sidebar */
.sidebar a {
    display: block;
    color: #ecf0f1;
    padding: 12px 15px;
    text-decoration: none;
    border-radius: 5px;
    margin-bottom: 5px;
    transition: background 0.2s;
}

.sidebar a:hover,
.sidebar a.active {
    background: #34495e;
    color: white;
}

/* Ikon dan tulisan */
.sidebar a .icon {
    display: inline-block;
    width: 24px;
    margin-right: 0;
    text-align: center;
}

/* Tombol logout */
.sidebar a.logout-link {
    margin-top: 20px;
    background: #e74c3c;
}

.sidebar a.logout-link:hover {
    background: #c0392b;
}

/* Tombol tutup pada perangkat mobile */
.close-sidebar {
    display: none;
    background: transparent;
    border: none;
    color: #ecf0f1;
    font-size: 28px;
    position: absolute;
    top: 10px;
    right: 15px;
    cursor: pointer;
}

/* Responsivitas sidebar */
@media (min-width: 769px) {
    .sidebar {
        transform: translateX(0) !important;
    }
}

@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        width: 250px;
    }

    .sidebar.open {
        transform: translateX(0);
    }

    .close-sidebar {
        display: block;
    }
}



        /* ===== OVERLAY ===== */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        .overlay.active {
            display: block;
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: 250px;
            padding: 25px;
            transition: margin-left 0.3s;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }

        /* ===== HEADER ===== */
        .header {
            background: white;
            padding: 18px 25px;
            border-radius: 14px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .header h1 {
            font-size: 22px;
            color: #0f172a;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header h1 i {
            color: #3b82f6;
        }
        .header .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .header .user-info .logout {
            background: #e74c3c;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: 0.2s;
        }
        .header .user-info .logout:hover { background: #c0392b; }

        .menu-toggle {
            display: none;
            background: transparent;
            border: none;
            font-size: 28px;
            color: #0f172a;
            cursor: pointer;
            padding: 0 5px;
        }
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
        }

        /* ===== FLASH ===== */
        .flash {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .flash-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .flash-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .flash-warning { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }
        .flash-info { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }

        /* ===== WELCOME BOX ===== */
        .welcome-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .welcome-box h2 { font-size: 24px; }
        .welcome-box p { margin-top: 5px; opacity: 0.9; }

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

        .stats .card .card-content { flex: 1; }
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
        }

        .stats .card.total     { border-left-color: #3b82f6; }
        .stats .card.pending   { border-left-color: #f59e0b; }
        .stats .card.approved  { border-left-color: #22c55e; }
        .stats .card.rejected  { border-left-color: #ef4444; }
        .stats .card.distributed { border-left-color: #8b5cf6; }
        .stats .card.completed   { border-left-color: #0ea5e9; }
        .stats .card.percent   { border-left-color: #ec4899; }

        .stats .card.total .card-icon     { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .stats .card.pending .card-icon   { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stats .card.approved .card-icon  { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .stats .card.rejected .card-icon  { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .stats .card.distributed .card-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .stats .card.completed .card-icon   { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
        .stats .card.percent .card-icon   { background: linear-gradient(135deg, #ec4899, #db2777); }

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

        /* ===== PANEL TABEL ===== */
        .panel {
            background: white;
            padding: 25px;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .panel h3 {
            font-size: 18px;
            color: #0f172a;
            margin-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 12px;
        }
        .panel .table-wrapper { overflow-x: auto; }
        .panel table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 700px;
        }
        .panel table th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        .panel table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .panel table tbody tr:hover { background: #f8fafc; }

        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-diajukan { background: #fef3c7; color: #92400e; }
        .badge-disetujui { background: #d1fae5; color: #065f46; }
        .badge-disetujui_vendor { background: #d1fae5; color: #065f46; }
        .badge-ditolak { background: #fee2e2; color: #991b1b; }
        .badge-ditolak_vendor { background: #fee2e2; color: #991b1b; }
        .badge-didistribusi { background: #ede9fe; color: #5b21b6; }
        .badge-selesai { background: #dbeafe; color: #1e40af; }

        .btn-detail {
            display: inline-block;
            padding: 5px 14px;
            border-radius: 6px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
        }
        .btn-detail:hover { background: #2563eb; }

        .barang-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .barang-list li {
            font-size: 13px;
            margin-bottom: 2px;
        }
        .text-muted { color: #94a3b8; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 480px) {
            .header {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 10px;
            }
            .header .user-info { margin-left: auto; }
            .stats { grid-template-columns: 1fr; }
            .panel table { font-size: 12px; min-width: 600px; }
            .panel table th, .panel table td { padding: 6px; }
        }
    </style>
</head>
<body>

<!-- OVERLAY -->
<div class="overlay" id="sidebarOverlay"></div>


<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <button
        type="button"
        class="close-sidebar"
        id="closeSidebarBtn"
        aria-label="Tutup menu"
    >&times;</button>

    <div class="sidebar-brand">
        <img
            src="../assets/img/plbn-wini.png"
            alt="Logo PLBN Wini"
            class="sidebar-logo"
        >
        <h2>PLBN WINI</h2>
    </div>

    <a href="dashboard.php" class="active">
        <span class="icon"><i class="fas fa-chart-pie"></i></span> <span>Dashboard</span>
    </a>

    <a href="barang.php">
        <span class="icon"><i class="fas fa-boxes"></i></span> <span>Kelola Barang</span>
    </a>

    <a href="pengajuan.php">
        <span class="icon"><i class="fas fa-file-alt"></i></span> <span>Kelola Pengajuan</span>
    </a>

    <a href="distribusi.php">
        <span class="icon"><i class="fas fa-truck"></i></span> <span>Distribusi</span>
    </a>

    <a href="laporan.php">
        <span class="icon"><i class="fas fa-file-lines"></i></span> <span>Laporan</span>
    </a>

    <a href="anggota.php">
        <span class="icon"><i class="fas fa-users"></i></span> <span>Kelola Anggota</span>
    </a>

    <a href="../logout.php" class="logout-link">
        <span class="icon"><i class="fas fa-right-from-bracket"></i></span> <span>Logout</span>
    </a>
</div>


<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- HEADER -->
    <div class="header">
        <div style="display: flex; align-items: center; gap: 12px;">
            <button class="menu-toggle" id="menuToggle" aria-label="Buka menu">
                <i class="fas fa-bars"></i>
            </button>
            <h1><i class="fas fa-chart-pie"></i> Dashboard Admin</h1>
        </div>
        <div class="user-info">
            <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Flash Message -->
    <?php if ($flash): ?>
        <div class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'danger' ? 'exclamation-circle' : 'info-circle') ?>"></i>
            <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- WELCOME BOX -->
    <div class="welcome-box">
        <h2>Dashboard Admin Sistem Pengajuan & Distribusi PLBN Wini</h2>
        <p>Silahkan Kelola Pengajuan, Distribusi, dan Laporan User</p>
    </div>

    <!-- ===== STATISTIK MODERN ===== -->
    <div class="stats">
        <div class="card total">
            <div class="card-icon"><i class="fas fa-file-alt"></i></div>
            <div class="card-content">
                <div class="number"><?= $stats['total'] ?></div>
                <div class="label">Total Pengajuan</div>
            </div>
        </div>

        <div class="card pending">
            <div class="card-icon"><i class="fas fa-clock"></i></div>
            <div class="card-content">
                <div class="number"><?= $stats['pending'] ?></div>
                <div class="label">Pending / Menunggu</div>
            </div>
        </div>

        <div class="card approved">
            <div class="card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="card-content">
                <div class="number"><?= $stats['disetujui'] ?></div>
                <div class="label">Disetujui</div>
            </div>
        </div>

        <div class="card rejected">
            <div class="card-icon"><i class="fas fa-times-circle"></i></div>
            <div class="card-content">
                <div class="number"><?= $stats['ditolak'] ?></div>
                <div class="label">Ditolak</div>
            </div>
        </div>

        <div class="card distributed">
            <div class="card-icon"><i class="fas fa-truck"></i></div>
            <div class="card-content">
                <div class="number"><?= $stats['didistribusi'] ?></div>
                <div class="label">Didistribusi</div>
            </div>
        </div>

        <div class="card completed">
            <div class="card-icon"><i class="fas fa-check-double"></i></div>
            <div class="card-content">
                <div class="number"><?= $stats['selesai'] ?></div>
                <div class="label">Selesai</div>
            </div>
        </div>

        <div class="card percent">
            <div class="card-icon"><i class="fas fa-chart-line"></i></div>
            <div class="card-content">
                <div class="number">
                    <?php
                        $total = $stats['total'];
                        $persen = $total > 0 ? round(($stats['selesai'] / $total) * 100) : 0;
                        echo $persen . '%';
                    ?>
                </div>
                <div class="label">Penyelesaian</div>
            </div>
        </div>
    </div>

    <!-- PENGAJUAN TERBARU -->
    <div class="panel">
        <h3><i class="fas fa-list-ul" style="color:#3b82f6;"></i> Pengajuan Terbaru</h3>
        <?php if (count($recent) > 0): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Pengaju</th>
                            <th>Divisi</th>
                            <th>Status Kepegawaian</th>
                            <th>Catatan</th>
                            <th>Barang & Jumlah</th>
                            <th>Tanggal</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($recent as $p): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= htmlspecialchars($p['nama_pengaju']) ?></td>
                            <td><?= htmlspecialchars($p['divisi_pengaju']) ?></td>
                            <td><?= htmlspecialchars($p['status_kepegawaian']) ?></td>
                            <td><?= htmlspecialchars($p['catatan'] ?: '-') ?></td>
                            <td>
                                <?php
                                $detailStmt = $pdo->prepare("
                                    SELECT b.nama_barang, d.jumlah, d.satuan 
                                    FROM detail_pengajuan d
                                    JOIN barang b ON d.barang_id = b.id
                                    WHERE d.pengajuan_id = ?
                                ");
                                $detailStmt->execute([$p['id']]);
                                $items = $detailStmt->fetchAll();
                                if ($items) {
                                    echo '<ul class="barang-list">';
                                    foreach ($items as $item) {
                                        echo '<li>• ' . htmlspecialchars($item['nama_barang']) 
                                             . ' (' . $item['jumlah'] . ' ' . htmlspecialchars($item['satuan']) . ')</li>';
                                    }
                                    echo '</ul>';
                                } else {
                                    echo '<span class="text-muted">-</span>';
                                }
                                ?>
                            </td>
                            <td><?= date('d-m-Y', strtotime($p['tanggal'])) ?></td>
                            <td>
                                <span class="badge badge-<?= $p['status'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', $p['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <a href="pengajuan.php?detail=<?= $p['id'] ?>" class="btn-detail">
                                    <i class="fas fa-eye"></i> Detail
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px 0; color: #94a3b8;">
                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                <p style="font-size: 16px;">Belum ada pengajuan.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- JAVASCRIPT TOGGLE SIDEBAR -->
<script>
    (function() {
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

        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        closeBtn.addEventListener('click', closeSidebar);
        overlay.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    })();
</script>

</body>
</html>