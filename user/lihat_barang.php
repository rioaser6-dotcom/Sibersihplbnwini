<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

if (!isLoggedIn() || !isRole('user')) {
    redirect('../login.php');
}

function eBarang($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$upload_dir = '../uploads/barang/';
$activeMenu = 'lihat_barang';
$q = trim((string) ($_GET['q'] ?? ''));

if ($q !== '') {
    $stmt = $pdo->prepare(
        "SELECT b.id, b.nama_barang, b.satuan, b.stok, b.gambar,
                j.nama_jenis
         FROM barang b
         LEFT JOIN jenis_barang j ON j.id = b.jenis_id
         WHERE b.nama_barang LIKE ? OR j.nama_jenis LIKE ?
         ORDER BY b.nama_barang ASC
         LIMIT 200"
    );
    $stmt->execute(['%' . $q . '%', '%' . $q . '%']);
} else {
    $stmt = $pdo->query(
        "SELECT b.id, b.nama_barang, b.satuan, b.stok, b.gambar,
                j.nama_jenis
         FROM barang b
         LEFT JOIN jenis_barang j ON j.id = b.jenis_id
         ORDER BY b.nama_barang ASC
         LIMIT 200"
    );
}

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Barang - PLBN WINI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f4f6f9;
            color: #1e293b;
        }
        /* Sidebar memakai includes/user_sidebar.css */

        .main-content { margin-left: 250px; padding: 25px; min-width: 0; }
        .header {
            background: #fff; padding: 18px 25px; border-radius: 14px;
            margin-bottom: 25px; display: flex; justify-content: space-between;
            align-items: center; gap: 15px; box-shadow: 0 4px 12px rgba(0,0,0,.05);
        }
        .header-left { display: flex; align-items: center; gap: 12px; }
        .header h1 { font-size: 22px; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        .header h1 i { color: #3b82f6; }
        .menu-toggle {
            display: none; background: #2c3e50; color: #fff; border: 0;
            width: 38px; height: 38px; border-radius: 8px; cursor: pointer;
        }
        .logout {
            display: inline-block; background: #e74c3c; color: #fff;
            padding: 8px 16px; border-radius: 8px; text-decoration: none;
            font-weight: 600;
        }
        .logout:hover { background: #c0392b; }

        .panel {
            background: #fff; padding: 22px; border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,.05);
        }
        .panel h3 {
            font-size: 18px; color: #0f172a; margin-bottom: 18px;
            border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;
        }
        .toolbar {
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; flex-wrap: wrap; margin-bottom: 18px;
        }
        .search-form { display: flex; gap: 8px; flex-wrap: wrap; }
        .search-form input {
            border: 1px solid #cbd5e1; border-radius: 8px;
            padding: 9px 12px; min-width: 230px; font: inherit;
        }
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 15px; border: 0; border-radius: 8px;
            text-decoration: none; font-weight: 700; font-size: 13px;
            cursor: pointer;
        }
        .btn-primary { background: #3498db; color: #fff; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: #fff; }
        .btn-success:hover { background: #1e8449; }
        .btn-secondary { background: #64748b; color: #fff; }
        .btn-secondary:hover { background: #475569; }

        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 720px; }
        th {
            background: #f1f5f9; color: #1e293b; text-align: left;
            padding: 12px; border-bottom: 2px solid #e2e8f0;
        }
        td { padding: 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        tbody tr:hover { background: #f8fafc; }
        .thumb {
            width: 64px; height: 64px; object-fit: cover;
            border-radius: 8px; border: 1px solid #e2e8f0; background: #f8fafc;
        }
        .no-image {
            width: 64px; height: 64px; border-radius: 8px; background: #f1f5f9;
            display: inline-flex; align-items: center; justify-content: center;
            color: #94a3b8; font-size: 20px;
        }
        .badge {
            display: inline-block; padding: 4px 11px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
        }
        .badge-available { background: #d1fae5; color: #065f46; }
        .badge-empty { background: #fee2e2; color: #991b1b; }
        .empty-state { text-align: center; padding: 45px 15px; color: #94a3b8; }
        .empty-state i { display: block; font-size: 46px; margin-bottom: 14px; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 14px; }
            .menu-toggle { display: inline-flex; align-items: center; justify-content: center; }
            .header { padding: 12px 14px; }
            .header h1 { font-size: 16px; }
            .panel { padding: 15px; }
        }
    </style>
    <link rel="stylesheet" href="../includes/user_sidebar.css">
</head>
<body>

<?php require __DIR__ . '/../includes/user_sidebar.php'; ?>

<main class="main-content">
    <header class="header">
        <div class="header-left">
            <button type="button" class="menu-toggle" id="menuToggle" aria-label="Buka menu" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <h1><i class="fas fa-boxes"></i> Daftar Barang</h1>
        </div>
        <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </header>

    <section class="panel">
        <h3><i class="fas fa-warehouse" style="color:#3b82f6;"></i> Barang yang Tersedia</h3>

        <div class="toolbar">
            <form method="GET" action="lihat_barang.php" class="search-form">
                <input type="search" name="q" value="<?= eBarang($q) ?>" placeholder="Cari nama barang...">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Cari</button>
                <?php if ($q !== ''): ?>
                    <a href="lihat_barang.php" class="btn btn-secondary"><i class="fas fa-rotate-left"></i> Reset</a>
                <?php endif; ?>
            </form>
            <a href="buat_pengajuan.php" class="btn btn-success"><i class="fas fa-plus"></i> Buat Pengajuan</a>
        </div>

        <?php if ($data): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Gambar</th>
                            <th>Nama Barang</th>
                            <th>Jenis</th>
                            <th>Stok</th>
                            <th>Satuan</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $index => $b): ?>
                            <?php
                            $namaGambar = basename((string) ($b['gambar'] ?? ''));
                            $gambarAda = $namaGambar !== '' && is_file($upload_dir . $namaGambar);
                            $stok = (int) ($b['stok'] ?? 0);
                            ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <?php if ($gambarAda): ?>
                                        <img src="<?= eBarang($upload_dir . rawurlencode($namaGambar)) ?>"
                                             class="thumb"
                                             alt="<?= eBarang($b['nama_barang']) ?>">
                                    <?php else: ?>
                                        <span class="no-image"><i class="fas fa-image"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= eBarang($b['nama_barang']) ?></strong></td>
                                <td><?= $b['nama_jenis'] !== null && $b['nama_jenis'] !== '' ? eBarang($b['nama_jenis']) : '-' ?></td>
                                <td><?= $stok ?></td>
                                <td><?= eBarang($b['satuan']) ?></td>
                                <td>
                                    <?php if ($stok > 0): ?>
                                        <span class="badge badge-available">Tersedia</span>
                                    <?php else: ?>
                                        <span class="badge badge-empty">Stok Habis</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <p>
                    <?= $q !== ''
                        ? 'Tidak ada barang yang cocok dengan pencarian.'
                        : 'Belum ada barang yang terdaftar.' ?>
                </p>
            </div>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
