<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

if (!isLoggedIn() || !isRole('user')) {
    redirect('../login.php');
}

$user_id = (int) $_SESSION['user_id'];
$activeMenu = 'riwayat';
$folderBukti = '../uploads/bukti_pengambilan/';

function eDetailRiwayat($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$id = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$id) {
    flash('ID pengajuan tidak valid.', 'danger');
    redirect('riwayat.php');
}

// Hanya pemilik pengajuan yang boleh melihat detailnya. Status yang
// dapat dibuka adalah pengajuan yang sudah diputuskan (disetujui/ditolak)
// ataupun yang sudah selesai.
$stmt = $pdo->prepare("
    SELECT
        id,
        tanggal,
        nama_pengaju,
        divisi_pengaju,
        status,
        catatan,
        bukti_pengambilan,
        status_bukti,
        catatan_bukti
    FROM pengajuan
    WHERE id = ?
      AND user_id = ?
      AND status IN ('selesai', 'disetujui', 'disetujui_vendor', 'ditolak', 'ditolak_vendor')
    LIMIT 1
");
$stmt->execute([$id, $user_id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) {
    flash('Detail pengajuan tidak ditemukan atau tidak dapat ditampilkan.', 'danger');
    redirect('riwayat.php');
}

// Ambil daftar barang dalam pengajuan.
$stmt = $pdo->prepare("
    SELECT
        b.nama_barang,
        dp.jumlah,
        dp.satuan
    FROM detail_pengajuan dp
    LEFT JOIN barang b ON dp.barang_id = b.id
    WHERE dp.pengajuan_id = ?
    ORDER BY dp.id ASC
");
$stmt->execute([$id]);
$barangList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$steps = [
    1 => 'Diajukan',
    2 => 'Diproses',
    3 => 'Distribusi',
    4 => 'Verifikasi bukti',
    5 => 'Selesai'
];

$status = (string) $p['status'];
$statusBukti = (string) ($p['status_bukti'] ?? 'belum_dikirim');
$progres = progresPengajuan($status, $statusBukti);
$ditolak = (bool) $progres['ditolak'];
$selesai = $status === 'selesai';
$disetujui = in_array($status, ['disetujui', 'disetujui_vendor'], true);
$tampilkanBukti = in_array($status, ['selesai', 'didistribusi'], true);

$labelPengajuan = labelStatusPengajuan($status);

if ($ditolak) {
    $kelasBadge = 'badge-danger';
} elseif ($selesai || $disetujui) {
    $kelasBadge = 'badge-success';
} else {
    $kelasBadge = 'badge-info';
}

$buktiLabel = labelStatusBukti($statusBukti);
$foto = basename((string) ($p['bukti_pengambilan'] ?? ''));
$fotoAda = $foto !== '' && is_file($folderBukti . $foto);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Riwayat Pengajuan - PLBN WINI</title>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f4f6f9;
            color: #263445;
        }

        /* Sidebar memakai includes/user_sidebar.css */

        .main-content {
            min-height: 100vh;
            margin-left: 250px;
            padding: 22px;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,.06);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 {
            color: #2c3e50;
            font-size: 20px;
        }

        .header h1 i { color: #3498db; }

        .menu-toggle {
            display: none;
            border: 0;
            background: none;
            font-size: 23px;
            cursor: pointer;
            color: #2c3e50;
        }

        .logout {
            padding: 9px 13px;
            border-radius: 5px;
            background: #e74c3c;
            color: white;
            text-decoration: none;
            font-size: 13px;
            white-space: nowrap;
        }

        .card {
            padding: 20px;
            margin-bottom: 18px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,.05);
            min-width: 0;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            padding-bottom: 15px;
            border-bottom: 1px solid #edf0f2;
        }

        .card-header h2 {
            margin-bottom: 7px;
            font-size: 18px;
            color: #2c3e50;
        }

        .meta {
            font-size: 13px;
            line-height: 1.9;
            color: #64748b;
            overflow-wrap: anywhere;
        }

        .badge {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #dbeafe; color: #1e40af; }

        .section-title {
            margin: 21px 0 12px;
            color: #2c3e50;
            font-size: 15px;
        }

        .success-note {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px;
            margin-top: 16px;
            border: 1px solid #ccebd6;
            border-radius: 7px;
            background: #f2fbf5;
            color: #246b3b;
            font-size: 13px;
            line-height: 1.7;
        }

        .success-note i {
            margin-top: 2px;
            font-size: 20px;
        }

        .progress-note {
            padding: 12px 14px;
            margin: 10px 0 16px;
            border-left: 3px solid #3498db;
            border-radius: 5px;
            background: #f8fafc;
            color: #475569;
            font-size: 13px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .progress-note.rejected {
            border-left-color: #e74c3c;
            background: #fff5f5;
        }

        .progress-scroll {
            overflow-x: auto;
            padding: 10px 0;
        }

        .progress-track {
            display: flex;
            min-width: 510px;
        }

        .progress-step {
            position: relative;
            flex: 1;
            text-align: center;
        }

        .progress-step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 15px;
            left: calc(50% + 17px);
            width: calc(100% - 34px);
            height: 3px;
            border-radius: 3px;
            background: #e2e8f0;
        }

        .progress-step.done:not(:last-child)::after {
            background: #27ae60;
        }

        .step-circle {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            margin: 0 auto 7px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #64748b;
            font-size: 12px;
            font-weight: bold;
        }

        .progress-step.done .step-circle {
            background: #27ae60;
            color: white;
        }

        .progress-step.current .step-circle {
            background: #3498db;
            color: white;
            box-shadow: 0 0 0 4px #dbeafe;
        }

        .progress-step strong {
            display: block;
            color: #64748b;
            font-size: 11px;
        }

        .progress-step.done strong,
        .progress-step.current strong {
            color: #263445;
        }

        .table-wrapper {
            overflow-x: auto;
            border: 1px solid #e9ecef;
            border-radius: 6px;
        }

        .table {
            width: 100%;
            min-width: 420px;
            border-collapse: collapse;
            font-size: 13px;
        }

        .table th {
            padding: 11px;
            background: #f1f5f9;
            text-align: left;
            white-space: nowrap;
        }

        .table td {
            padding: 11px;
            border-top: 1px solid #edf0f2;
        }

        .table th:first-child,
        .table td:first-child { text-align: center; }

        .note {
            margin-top: 12px;
            font-size: 13px;
            color: #64748b;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .proof-box {
            padding: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fbfcfe;
        }

        .proof-box p {
            margin: 10px 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.7;
        }

        .proof-image {
            display: block;
            width: 100%;
            max-width: 420px;
            max-height: 350px;
            margin-top: 14px;
            object-fit: contain;
            object-position: left;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: white;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 10px 13px;
            border: 0;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
        }

        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 14px; }
            .menu-toggle { display: block; }
            .header { padding: 14px; }
            .header h1 { font-size: 17px; }
            .card { padding: 15px; }
        }

        @media (max-width: 480px) {
            .header { align-items: flex-start; }
            .header h1 { font-size: 15px; }
            .logout { padding: 8px 9px; font-size: 12px; }
            .card { padding: 12px; }
            .card-header h2 { font-size: 16px; }
        }
    </style>
    <link rel="stylesheet" href="../includes/user_sidebar.css">
</head>
<body>

<?php require __DIR__ . '/../includes/user_sidebar.php'; ?>

<main class="main-content">
    <header class="header">
        <div class="header-left">
            <button type="button" class="menu-toggle" id="menuToggle"
                    aria-label="Buka menu" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <h1><i class="fas fa-file-alt"></i> Detail Riwayat Pengajuan</h1>
        </div>
        <a href="../logout.php" class="logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </header>

    <section class="card">
        <div class="card-header">
            <div>
                <h2>Pengajuan #<?= (int) $p['id'] ?></h2>
                <div class="meta">
                    <div><i class="fas fa-user"></i> Nama: <?= eDetailRiwayat($p['nama_pengaju']) ?></div>
                    <div><i class="fas fa-building"></i> Bagian: <?= eDetailRiwayat($p['divisi_pengaju']) ?></div>
                    <div><i class="fas fa-calendar"></i> Tanggal: <?= eDetailRiwayat(!empty($p['tanggal']) ? date('d-m-Y', strtotime($p['tanggal'])) : '-') ?></div>
                </div>
            </div>
            <span class="badge <?= eDetailRiwayat($kelasBadge) ?>"><?= eDetailRiwayat($labelPengajuan) ?></span>
        </div>

        <?php if ($selesai): ?>
            <div class="success-note">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Pengajuan berhasil diselesaikan.</strong><br>
                    Bukti penerimaan barang telah disetujui Admin.
                </div>
            </div>
        <?php endif; ?>

        <h3 class="section-title"><i class="fas fa-route"></i> Progres Pengajuan</h3>

        <?php if ($ditolak): ?>
            <div class="progress-note rejected">
                <strong>Pengajuan ditolak.</strong><br>
                <?= eDetailRiwayat($progres['keterangan']) ?>
            </div>
        <?php else: ?>
            <div class="progress-scroll">
                <div class="progress-track">
                    <?php foreach ($steps as $nomor => $namaStep): ?>
                        <?php
                        $done = $progres['step'] >= $nomor;
                        $current = $progres['step'] === $nomor;
                        ?>
                        <div class="progress-step <?= $done ? 'done' : '' ?> <?= $current ? 'current' : '' ?>">
                            <div class="step-circle">
                                <?php if ($done): ?>
                                    <i class="fas fa-check"></i>
                                <?php else: ?>
                                    <?= (int) $nomor ?>
                                <?php endif; ?>
                            </div>
                            <strong><?= eDetailRiwayat($namaStep) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!$selesai): ?>
                <div class="progress-note">
                    <strong>Status saat ini:</strong>
                    <?= eDetailRiwayat($progres['keterangan']) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <h3 class="section-title"><i class="fas fa-boxes"></i> Barang yang Diajukan</h3>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Barang</th>
                        <th>Jumlah</th>
                        <th>Satuan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($barangList): ?>
                        <?php foreach ($barangList as $i => $barang): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= eDetailRiwayat($barang['nama_barang'] ?? '-') ?></td>
                                <td><?= eDetailRiwayat($barang['jumlah']) ?></td>
                                <td><?= eDetailRiwayat($barang['satuan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4">Detail barang tidak tersedia.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (trim((string) ($p['catatan'] ?? '')) !== ''): ?>
            <p class="note">
                <strong>Catatan pengajuan:</strong>
                <?= nl2br(eDetailRiwayat($p['catatan'])) ?>
            </p>
        <?php endif; ?>

        <?php if ($tampilkanBukti): ?>
            <h3 class="section-title"><i class="fas fa-camera"></i> Bukti Penerimaan Barang</h3>
            <div class="proof-box">
                <p>
                    <strong>Status bukti:</strong>
                    <span class="badge badge-success"><?= eDetailRiwayat($buktiLabel) ?></span>
                </p>

                <?php if ($fotoAda): ?>
                    <a href="<?= eDetailRiwayat($folderBukti . rawurlencode($foto)) ?>"
                       target="_blank" rel="noopener">
                        <img
                            src="<?= eDetailRiwayat($folderBukti . rawurlencode($foto)) ?>"
                            class="proof-image"
                            alt="Bukti penerimaan barang"
                        >
                    </a>
                    <p>Klik foto untuk melihat ukuran penuh.</p>
                <?php else: ?>
                    <p>File foto bukti penerimaan tidak ditemukan di folder penyimpanan.</p>
                <?php endif; ?>

                <?php if (trim((string) ($p['catatan_bukti'] ?? '')) !== ''): ?>
                    <p>
                        <strong>Catatan bukti:</strong><br>
                        <?= nl2br(eDetailRiwayat($p['catatan_bukti'])) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top:20px">
            <a href="riwayat.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Kembali ke Riwayat Pengajuan
            </a>
        </div>
    </section>
</main>

</body>
</html>
