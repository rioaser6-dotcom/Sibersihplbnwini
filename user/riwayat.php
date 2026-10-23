<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

if (!isLoggedIn() || !isRole('user')) {
    redirect('../login.php');
}

$user_id = (int) $_SESSION['user_id'];
$activeMenu = 'riwayat';
$folderBukti = '../uploads/bukti_pengambilan/';
$flash = getFlash();
$csrf_riwayat = csrfToken('riwayat');

function eRiwayat($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// =====================================================
// HAPUS PENGAJUAN YANG BELUM DIPROSES
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_pengajuan'])) {
    if (!csrfValid('riwayat', $_POST['csrf_token'] ?? '')) {
        flash('Permintaan tidak valid. Muat ulang halaman lalu coba kembali.', 'danger');
        redirect('riwayat.php');
    }

    $id = filter_var(
        $_POST['pengajuan_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$id) {
        flash('Pengajuan tidak ditemukan.', 'danger');
        redirect('riwayat.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT status
             FROM pengajuan
             WHERE id = ? AND user_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$id, $user_id]);
        $status = $stmt->fetchColumn();

        if ($status === false) {
            $pdo->rollBack();
            flash('Pengajuan tidak ditemukan.', 'danger');
            redirect('riwayat.php');
        }

        if (!in_array($status, ['pending', 'diajukan'], true)) {
            $pdo->rollBack();
            flash('Pengajuan tidak dapat dihapus karena sudah diproses.', 'warning');
            redirect('riwayat.php');
        }

        $stmt = $pdo->prepare(
            "DELETE FROM detail_pengajuan WHERE pengajuan_id = ?"
        );
        $stmt->execute([$id]);

        $stmt = $pdo->prepare(
            "DELETE FROM pengajuan
             WHERE id = ? AND user_id = ?
               AND status IN ('pending', 'diajukan')"
        );
        $stmt->execute([$id, $user_id]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Status pengajuan telah berubah.');
        }

        $pdo->commit();
        flash('Pengajuan berhasil dihapus.', 'success');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Gagal menghapus pengajuan: ' . $e->getMessage());
        flash('Gagal menghapus pengajuan. Muat ulang halaman lalu coba kembali.', 'danger');
    }

    redirect('riwayat.php');
}

// =====================================================
// UNGGAH / KIRIM ULANG BUKTI PENERIMAAN
// =====================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['unggah_bukti'])
) {
    if (!csrfValid('riwayat', $_POST['csrf_token'] ?? '')) {
        flash('Permintaan tidak valid. Muat ulang halaman lalu coba kembali.', 'danger');
        redirect('riwayat.php');
    }

    $pengajuan_id = filter_var(
        $_POST['pengajuan_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$pengajuan_id || !isset($_FILES['bukti_foto'])) {
        flash('Data unggahan tidak valid.', 'danger');
        redirect('riwayat.php');
    }

    $stmt = $pdo->prepare("
        SELECT id, status, bukti_pengambilan, status_bukti
        FROM pengajuan
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$pengajuan_id, $user_id]);
    $pengajuan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pengajuan) {
        flash('Pengajuan tidak ditemukan.', 'danger');
        redirect('riwayat.php');
    }

    if ($pengajuan['status'] !== 'didistribusi') {
        flash(
            'Bukti penerimaan hanya dapat dikirim setelah Admin memulai distribusi.',
            'warning'
        );
        redirect('riwayat.php');
    }

    $statusBukti = $pengajuan['status_bukti'] ?? 'belum_dikirim';

    if ($statusBukti === 'terverifikasi') {
        flash('Bukti sudah disetujui Admin. Anda tidak perlu mengirim ulang.', 'info');
        redirect('riwayat.php');
    }

    if (!in_array($statusBukti, ['belum_dikirim', 'perlu_perbaikan'], true)) {
        flash('Bukti sedang menunggu pemeriksaan Admin.', 'warning');
        redirect('riwayat.php');
    }

    $file = $_FILES['bukti_foto'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash('Foto gagal diunggah. Silakan pilih kembali.', 'danger');
        redirect('riwayat.php');
    }

    if ($file['size'] <= 0 || $file['size'] > 5 * 1024 * 1024) {
        flash('Ukuran foto maksimal 5 MB.', 'danger');
        redirect('riwayat.php');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        flash('File yang dikirim tidak valid.', 'danger');
        redirect('riwayat.php');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $jenisFile = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($jenisFile[$mime])) {
        flash('Format foto harus JPG, PNG, atau WEBP.', 'danger');
        redirect('riwayat.php');
    }

    if (
        !is_dir($folderBukti)
        && !mkdir($folderBukti, 0755, true)
        && !is_dir($folderBukti)
    ) {
        flash('Folder penyimpanan foto tidak dapat dibuat.', 'danger');
        redirect('riwayat.php');
    }

    $namaFile = bin2hex(random_bytes(16)) . '.' . $jenisFile[$mime];
    $tujuan = $folderBukti . $namaFile;

    if (!move_uploaded_file($file['tmp_name'], $tujuan)) {
        flash('Foto gagal disimpan. Silakan coba kembali.', 'danger');
        redirect('riwayat.php');
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE pengajuan
            SET bukti_pengambilan = ?,
                status_bukti = 'menunggu_verifikasi',
                catatan_bukti = NULL
            WHERE id = ?
              AND user_id = ?
              AND status = 'didistribusi'
              AND status_bukti IN ('belum_dikirim', 'perlu_perbaikan')
        ");

        $stmt->execute([$namaFile, $pengajuan_id, $user_id]);

        if ($stmt->rowCount() !== 1) {
            @unlink($tujuan);
            flash(
                'Status pengajuan telah berubah. Muat ulang halaman dan periksa kembali.',
                'warning'
            );
            redirect('riwayat.php');
        }

        $fotoLama = basename(
            (string) ($pengajuan['bukti_pengambilan'] ?? '')
        );

        if (
            $fotoLama !== ''
            && $fotoLama !== $namaFile
            && is_file($folderBukti . $fotoLama)
        ) {
            @unlink($folderBukti . $fotoLama);
        }

        flash(
            'Bukti penerimaan berhasil dikirim. Tunggu verifikasi Admin.',
            'success'
        );
    } catch (Throwable $e) {
        if (is_file($tujuan)) {
            @unlink($tujuan);
        }

        error_log('Gagal menyimpan bukti penerimaan: ' . $e->getMessage());
        flash('Gagal menyimpan bukti. Silakan coba kembali.', 'danger');
    }

    redirect('riwayat.php');
}

// =====================================================
// AMBIL DATA PENGAJUAN
// =====================================================
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.tanggal,
        p.nama_pengaju,
        p.divisi_pengaju,
        p.status,
        p.catatan,
        p.bukti_pengambilan,
        p.status_bukti,
        p.catatan_bukti,
        dp.id AS detail_id,
        dp.jumlah,
        dp.satuan,
        b.nama_barang
    FROM pengajuan p
    LEFT JOIN detail_pengajuan dp ON p.id = dp.pengajuan_id
    LEFT JOIN barang b ON dp.barang_id = b.id
    WHERE p.user_id = ?
    ORDER BY p.id DESC, dp.id ASC
");
$stmt->execute([$user_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pengajuanList = [];

foreach ($rows as $row) {
    $id = (int) $row['id'];

    if (!isset($pengajuanList[$id])) {
        $pengajuanList[$id] = [
            'id' => $id,
            'tanggal' => $row['tanggal'],
            'nama_pengaju' => $row['nama_pengaju'],
            'divisi_pengaju' => $row['divisi_pengaju'],
            'status' => $row['status'],
            'catatan' => $row['catatan'],
            'bukti_pengambilan' => $row['bukti_pengambilan'],
            'status_bukti' => $row['status_bukti'] ?? 'belum_dikirim',
            'catatan_bukti' => $row['catatan_bukti'] ?? '',
            'barang' => []
        ];
    }

    if ($row['detail_id'] !== null) {
        $pengajuanList[$id]['barang'][] = [
            'nama_barang' => $row['nama_barang'],
            'jumlah' => $row['jumlah'],
            'satuan' => $row['satuan']
        ];
    }
}

$labelStatus = [
    'pending' => 'Menunggu Admin',
    'diajukan' => 'Diajukan',
    'diteruskan' => 'Diproses',
    'disetujui_vendor' => 'Disetujui',
    'disetujui' => 'Disetujui',
    'ditolak_vendor' => 'Ditolak',
    'ditolak' => 'Ditolak',
    'didistribusi' => 'Dalam Distribusi',
    'selesai' => 'Selesai'
];

$labelBukti = [
    'belum_dikirim' => 'Belum dikirim',
    'menunggu_verifikasi' => 'Menunggu verifikasi',
    'perlu_perbaikan' => 'Perlu dikirim ulang',
    'terverifikasi' => 'Terverifikasi'
];

$steps = [
    1 => 'Diajukan',
    2 => 'Diproses',
    3 => 'Distribusi',
    4 => 'Verifikasi bukti',
    5 => 'Selesai'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Pengajuan - PLBN WINI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; color: #263445; }

        /* Sidebar memakai includes/user_sidebar.css */

        .main-content { min-height: 100vh; margin-left: 250px; padding: 22px; }
        .header {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 16px 20px; margin-bottom: 20px; background: white;
            border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,.06);
        }
        .header-left { display: flex; align-items: center; gap: 12px; }
        .header h1 { color: #2c3e50; font-size: 20px; }
        .header h1 i { color: #3498db; }
        .menu-toggle { display: none; border: 0; background: none; font-size: 23px; cursor: pointer; color: #2c3e50; }
        .logout {
            padding: 9px 13px; border-radius: 5px; background: #e74c3c;
            color: white; text-decoration: none; font-size: 13px; white-space: nowrap;
        }

        .flash {
            display: flex; align-items: flex-start; gap: 10px; padding: 12px 15px;
            margin-bottom: 16px; border-radius: 6px; line-height: 1.5; font-size: 14px;
        }
        .flash-success { background: #d4edda; color: #155724; }
        .flash-danger { background: #f8d7da; color: #721c24; }
        .flash-warning { background: #fff3cd; color: #856404; }
        .flash-info { background: #d1ecf1; color: #0c5460; }

        .card {
            padding: 20px; margin-bottom: 18px; background: white; border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,.05); min-width: 0;
        }
        .card-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 12px; padding-bottom: 15px; border-bottom: 1px solid #edf0f2;
        }
        .card-header h2 { margin-bottom: 7px; font-size: 18px; color: #2c3e50; }
        .meta { font-size: 13px; line-height: 1.8; color: #64748b; overflow-wrap: anywhere; }
        .badge {
            display: inline-block; padding: 6px 9px; border-radius: 5px;
            font-size: 12px; font-weight: bold; background: #e9ecef; color: #475569;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #dbeafe; color: #1e40af; }

        .section-title { margin: 19px 0 11px; color: #2c3e50; font-size: 15px; }
        .progress-scroll { overflow-x: auto; padding: 10px 0; }
        .progress-track { display: flex; min-width: 510px; }
        .progress-step { flex: 1; position: relative; text-align: center; }
        .progress-step:not(:last-child)::after {
            content: ""; position: absolute; top: 15px; left: calc(50% + 17px);
            width: calc(100% - 34px); height: 3px; border-radius: 3px; background: #e2e8f0;
        }
        .progress-step.done:not(:last-child)::after { background: #27ae60; }
        .step-circle {
            position: relative; z-index: 1; display: flex; align-items: center;
            justify-content: center; width: 32px; height: 32px; margin: 0 auto 7px;
            border-radius: 50%; background: #e2e8f0; color: #64748b; font-size: 12px; font-weight: bold;
        }
        .progress-step.done .step-circle { background: #27ae60; color: white; }
        .progress-step.current .step-circle { background: #3498db; color: white; box-shadow: 0 0 0 4px #dbeafe; }
        .progress-step strong { display: block; color: #64748b; font-size: 11px; }
        .progress-step.done strong, .progress-step.current strong { color: #263445; }

        .progress-note {
            padding: 12px 14px; margin: 10px 0 16px; border-left: 3px solid #3498db;
            border-radius: 5px; background: #f8fafc; color: #475569;
            font-size: 13px; line-height: 1.6; overflow-wrap: anywhere;
        }
        .progress-note.rejected { border-left-color: #e74c3c; background: #fff5f5; }
        .success-summary {
            display: flex; align-items: flex-start; gap: 12px; margin-top: 15px;
            padding: 14px; border: 1px solid #ccebd6; border-radius: 7px;
            background: #f2fbf5; color: #246b3b; font-size: 13px; line-height: 1.6;
        }
        .success-summary i { font-size: 20px; margin-top: 2px; }
        .success-summary strong { display: block; margin-bottom: 3px; font-size: 14px; }

        .table-wrapper { overflow-x: auto; border: 1px solid #e9ecef; border-radius: 6px; }
        .table { width: 100%; min-width: 420px; border-collapse: collapse; font-size: 13px; }
        .table th { padding: 11px; background: #f1f5f9; text-align: left; white-space: nowrap; }
        .table td { padding: 11px; border-top: 1px solid #edf0f2; }
        .table td:first-child, .table th:first-child { text-align: center; }
        .note { margin-top: 12px; font-size: 13px; color: #64748b; line-height: 1.6; overflow-wrap: anywhere; }

        .proof-box { margin-top: 18px; padding: 16px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fbfcfe; }
        .proof-box h3 { margin-bottom: 8px; font-size: 15px; }
        .proof-box p { margin: 8px 0 12px; color: #64748b; font-size: 13px; line-height: 1.6; }
        .proof-image {
            display: block; width: 100%; max-width: 320px; max-height: 280px; margin: 12px 0;
            object-fit: contain; object-position: left; border: 1px solid #e2e8f0;
            border-radius: 6px; background: white;
        }
        .form-control {
            display: block; width: 100%; max-width: 460px; padding: 10px; margin: 8px 0 13px;
            border: 1px solid #cbd5e1; border-radius: 6px; background: white; font: inherit;
        }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            padding: 10px 13px; border: 0; border-radius: 5px; cursor: pointer;
            font-size: 13px; font-weight: bold; text-decoration: none;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-outline { background: white; color: #2c6eaa; border: 1px solid #3498db; }
        .btn-outline:hover { background: #eff8ff; }

        .empty-state { text-align: center; padding: 45px 15px; color: #64748b; }
        .empty-state i { display: block; margin-bottom: 12px; color: #bdc3c7; font-size: 42px; }

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
            .card-header { gap: 8px; }
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
            <h1><i class="fas fa-history"></i> Riwayat Pengajuan</h1>
        </div>
        <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </header>

    <?php if (isset($flash) && $flash): ?>
        <?php
        $flashType = $flash['type'] ?? 'info';
        if (!in_array($flashType, ['success', 'danger', 'warning', 'info'], true)) {
            $flashType = 'info';
        }
        $flashMessage = $flash['message'] ?? $flash['msg'] ?? '';
        $flashIcons = [
            'success' => 'check-circle',
            'danger' => 'exclamation-circle',
            'warning' => 'exclamation-triangle',
            'info' => 'info-circle'
        ];
        ?>
        <div class="flash flash-<?= eRiwayat($flashType) ?>" role="alert">
            <i class="fas fa-<?= eRiwayat($flashIcons[$flashType]) ?>"></i>
            <span><?= eRiwayat($flashMessage) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($pengajuanList): ?>
        <?php foreach ($pengajuanList as $p): ?>
            <?php
            $status = (string) $p['status'];
            $statusBukti = (string) $p['status_bukti'];
            $progres = progresPengajuan($status, $statusBukti);
            $selesai = $status === 'selesai';
            $bisaDetail = in_array(
                $status,
                ['selesai', 'disetujui', 'disetujui_vendor', 'ditolak', 'ditolak_vendor'],
                true
            );

            $labelPengajuan = $labelStatus[$status]
                ?? ucfirst(str_replace('_', ' ', $status));

            $buktiLabel = $labelBukti[$statusBukti] ?? 'Belum dikirim';

            $badgeStatus = $progres['ditolak']
                ? 'badge-danger'
                : ($selesai ? 'badge-success' : 'badge-info');

            $buktiClass = 'badge-warning';
            if ($statusBukti === 'terverifikasi') {
                $buktiClass = 'badge-success';
            } elseif ($statusBukti === 'menunggu_verifikasi') {
                $buktiClass = 'badge-info';
            } elseif ($statusBukti === 'perlu_perbaikan') {
                $buktiClass = 'badge-danger';
            }

            $bolehUnggah = (
                $status === 'didistribusi'
                && in_array($statusBukti, ['belum_dikirim', 'perlu_perbaikan'], true)
            );

            $foto = basename((string) ($p['bukti_pengambilan'] ?? ''));
            $fotoAda = $foto !== '' && is_file($folderBukti . $foto);
            ?>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2>Pengajuan #<?= (int) $p['id'] ?></h2>
                        <div class="meta">
                            <div><i class="fas fa-user"></i> Nama: <?= eRiwayat($p['nama_pengaju']) ?></div>
                            <div><i class="fas fa-building"></i> Bagian: <?= eRiwayat($p['divisi_pengaju']) ?></div>
                            <div><i class="fas fa-calendar"></i> Tanggal: <?= eRiwayat(!empty($p['tanggal']) ? date('d-m-Y', strtotime($p['tanggal'])) : '-') ?></div>
                        </div>
                    </div>
                    <span class="badge <?= $badgeStatus ?>"><?= eRiwayat($labelPengajuan) ?></span>
                </div>

                <?php if ($selesai): ?>
                    <!-- PENGAJUAN SELESAI: TAMPILAN RINGKAS -->
                    <div class="success-summary">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Pengajuan berhasil diselesaikan.</strong>
                            Bukti penerimaan barang telah disetujui Admin.
                        </div>
                    </div>

                <?php else: ?>
                    <!-- PENGAJUAN BELUM SELESAI: TAMPILAN LENGKAP -->
                    <h3 class="section-title"><i class="fas fa-route"></i> Progres Pengajuan</h3>

                    <?php if ($progres['ditolak']): ?>
                        <div class="progress-note rejected">
                            <strong>Pengajuan ditolak.</strong><br>
                            <?= eRiwayat($progres['keterangan']) ?>
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
                                        <strong><?= eRiwayat($namaStep) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="progress-note">
                            <strong>Status saat ini:</strong>
                            <?= eRiwayat($progres['keterangan']) ?>
                        </div>
                    <?php endif; ?>

                    <h3 class="section-title"><i class="fas fa-boxes"></i> Barang yang Diajukan</h3>

                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr><th>No.</th><th>Nama Barang</th><th>Jumlah</th><th>Satuan</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($p['barang']): ?>
                                    <?php foreach ($p['barang'] as $i => $barang): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= eRiwayat($barang['nama_barang'] ?? '-') ?></td>
                                            <td><?= eRiwayat($barang['jumlah']) ?></td>
                                            <td><?= eRiwayat($barang['satuan']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4">Detail barang tidak tersedia.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (trim((string) $p['catatan']) !== ''): ?>
                        <p class="note">
                            <strong>Catatan pengajuan:</strong> <?= eRiwayat($p['catatan']) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!$progres['ditolak'] && $status === 'didistribusi'): ?>
                        <div class="proof-box">
                            <h3><i class="fas fa-camera"></i> Bukti Penerimaan Barang</h3>
                            <p>Setelah menerima barang, kirim foto bukti penerimaan. Pengajuan baru dinyatakan selesai setelah Admin menyetujui foto.</p>

                            <p>
                                <strong>Status bukti:</strong>
                                <span class="badge <?= $buktiClass ?>"><?= eRiwayat($buktiLabel) ?></span>
                            </p>

                            <?php if ($fotoAda): ?>
                                <a href="<?= eRiwayat($folderBukti . rawurlencode($foto)) ?>" target="_blank" rel="noopener">
                                    <img src="<?= eRiwayat($folderBukti . rawurlencode($foto)) ?>"
                                         class="proof-image" alt="Bukti penerimaan barang">
                                </a>
                            <?php endif; ?>

                            <?php if (
                                $statusBukti === 'perlu_perbaikan'
                                && trim((string) $p['catatan_bukti']) !== ''
                            ): ?>
                                <div class="progress-note rejected">
                                    <strong><i class="fas fa-exclamation-circle"></i> Catatan perbaikan dari Admin</strong><br>
                                    <?= nl2br(eRiwayat($p['catatan_bukti'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($bolehUnggah): ?>
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= eRiwayat($csrf_riwayat) ?>">
                                    <input type="hidden" name="unggah_bukti" value="1">
                                    <input type="hidden" name="pengajuan_id" value="<?= (int) $p['id'] ?>">

                                    <label for="bukti-<?= (int) $p['id'] ?>">
                                        <?= $statusBukti === 'perlu_perbaikan' ? 'Pilih foto pengganti' : 'Pilih foto penerimaan' ?>
                                    </label>

                                    <input
                                        type="file"
                                        id="bukti-<?= (int) $p['id'] ?>"
                                        name="bukti_foto"
                                        class="form-control"
                                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                        required
                                    >

                                    <small class="note">Format JPG, PNG, atau WEBP. Ukuran maksimal 5 MB.</small>

                                    <button type="submit" class="btn btn-primary" style="margin-top:12px">
                                        <i class="fas fa-upload"></i>
                                        <?= $statusBukti === 'perlu_perbaikan' ? 'Kirim Ulang Bukti' : 'Kirim Bukti Penerimaan' ?>
                                    </button>
                                </form>
                            <?php elseif ($statusBukti === 'menunggu_verifikasi'): ?>
                                <div class="progress-note">
                                    <i class="fas fa-clock"></i>
                                    Bukti sudah dikirim. Tunggu pemeriksaan Admin.
                                </div>
                            <?php elseif ($statusBukti === 'terverifikasi'): ?>
                                <div class="progress-note">
                                    <i class="fas fa-check-circle"></i>
                                    Bukti telah diverifikasi. Status akhir masih menunggu pembaruan dari Admin.
                                </div>
                            <?php else: ?>
                                <div class="progress-note">
                                    Menunggu Anda mengirimkan foto bukti penerimaan barang.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php elseif (!$progres['ditolak']): ?>
                        <div class="progress-note">
                            Bukti penerimaan dapat dikirim setelah Admin memulai distribusi barang.
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($status, ['pending', 'diajukan'], true)): ?>
                        <div style="margin-top:16px">
                            <form method="POST" onsubmit="return confirm('Yakin ingin menghapus pengajuan ini?')">
                                <input type="hidden" name="csrf_token" value="<?= eRiwayat($csrf_riwayat) ?>">
                                <input type="hidden" name="hapus_pengajuan" value="1">
                                <input type="hidden" name="pengajuan_id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-trash-alt"></i> Hapus Pengajuan
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($bisaDetail): ?>
                    <div style="display:flex;justify-content:flex-end;margin-top:15px">
                        <a href="detail_riwayat.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline">
                            <i class="fas fa-eye"></i> Detail Riwayat Pengajuan
                        </a>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

    <?php else: ?>
        <section class="card empty-state">
            <i class="fas fa-inbox"></i>
            <p>Anda belum memiliki pengajuan.</p>
            <p class="note">Pengajuan yang dibuat akan ditampilkan di halaman ini.</p>
            <a href="buat_pengajuan.php" class="btn btn-primary" style="margin-top:16px">
                <i class="fas fa-plus"></i> Buat Pengajuan
            </a>
        </section>
    <?php endif; ?>
</main>

</body>
</html>
