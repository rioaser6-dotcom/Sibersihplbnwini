<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

if (!isLoggedIn() || !isRole('user')) {
    redirect('../login.php');
}

$user_id = (int) $_SESSION['user_id'];
$activeMenu = 'pesan';

function eDetailPesan($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$id = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$id) {
    flash('ID pesan tidak valid.', 'danger');
    redirect('pesan.php');
}

// Pesan hanya boleh dibaca oleh pemiliknya. Filter user_id wajib ada.
$stmt = $pdo->prepare("
    SELECT
        m.id,
        m.pengajuan_id,
        m.distribusi_id,
        m.pesan,
        m.dibaca_at,
        m.created_at,
        p.status AS status_pengajuan,
        p.status_bukti,
        p.nama_pengaju,
        p.divisi_pengaju,
        p.tanggal AS tanggal_pengajuan,
        d.tanggal_distribusi,
        d.keterangan AS keterangan_distribusi,
        COALESCE(ua.nama, 'Admin') AS nama_admin,
        COALESCE(ua.username, '-') AS username_admin
    FROM pesan_distribusi m
    JOIN pengajuan p
      ON p.id = m.pengajuan_id
     AND p.user_id = m.user_id
    LEFT JOIN users ua ON ua.id = m.admin_id
    LEFT JOIN distribusi d
      ON d.id = m.distribusi_id
     AND d.pengajuan_id = m.pengajuan_id
    WHERE m.id = ?
      AND m.user_id = ?
    LIMIT 1
");
$stmt->execute([(int) $id, $user_id]);
$pesan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pesan) {
    // Jangan membocorkan apakah pesan milik Pengguna lain ada atau tidak.
    flash('Pesan tidak ditemukan.', 'warning');
    redirect('pesan.php');
}

$sudahDibacaSebelumnya = !empty($pesan['dibaca_at']);

// Tandai sudah dibaca hanya setelah kepemilikan pesan terverifikasi.
if (!$sudahDibacaSebelumnya) {
    $update = $pdo->prepare("
        UPDATE pesan_distribusi
        SET dibaca_at = NOW()
        WHERE id = ?
          AND user_id = ?
          AND dibaca_at IS NULL
    ");
    $update->execute([(int) $id, $user_id]);

    $stmt = $pdo->prepare("
        SELECT dibaca_at
        FROM pesan_distribusi
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([(int) $id, $user_id]);
    $dibacaAt = $stmt->fetchColumn();
    $pesan['dibaca_at'] = $dibacaAt !== false ? $dibacaAt : null;
}

$waktuKirim = !empty($pesan['created_at'])
    ? date('d-m-Y H:i', strtotime($pesan['created_at']))
    : '-';

$waktuBaca = !empty($pesan['dibaca_at'])
    ? date('d-m-Y H:i', strtotime($pesan['dibaca_at']))
    : null;

$statusPengajuan = (string) $pesan['status_pengajuan'];
$statusBukti = (string) $pesan['status_bukti'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Pesan - PLBN WINI</title>
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
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .06);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .header h1 {
            color: #2c3e50;
            font-size: 20px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .header h1 i { color: #3498db; }

        .menu-toggle {
            display: none;
            border: 0;
            background: none;
            color: #2c3e50;
            font-size: 23px;
            cursor: pointer;
        }

        .logout {
            padding: 9px 13px;
            border-radius: 5px;
            background: #e74c3c;
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            white-space: nowrap;
        }

        .flash {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 15px;
            margin-bottom: 16px;
            border-radius: 6px;
            font-size: 14px;
            line-height: 1.5;
        }

        .flash-success { background: #d4edda; color: #155724; }
        .flash-danger { background: #f8d7da; color: #721c24; }
        .flash-warning { background: #fff3cd; color: #856404; }
        .flash-info { background: #d1ecf1; color: #0c5460; }

        .card {
            padding: 22px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .05);
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            padding-bottom: 15px;
            border-bottom: 1px solid #edf0f2;
        }

        .card-head h2 {
            margin-bottom: 7px;
            color: #2c3e50;
            font-size: 19px;
            overflow-wrap: anywhere;
        }

        .meta {
            color: #64748b;
            font-size: 13px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .meta i {
            width: 15px;
            color: #94a3b8;
        }

        .badge {
            display: inline-block;
            flex-shrink: 0;
            padding: 5px 9px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-read { background: #dcfce7; color: #166534; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-warning { background: #fef3c7; color: #92400e; }

        .message-content {
            margin-top: 18px;
            padding: 18px;
            border: 1px solid #dbeafe;
            border-left: 4px solid #3b82f6;
            border-radius: 8px;
            background: #f8fbff;
            color: #1e293b;
            font-size: 15px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .info-item {
            padding: 13px 14px;
            border: 1px solid #e9eef4;
            border-radius: 8px;
            background: #fafcff;
        }

        .info-item .label {
            margin-bottom: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .info-item .value {
            color: #1e293b;
            font-size: 14px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .card-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
            padding-top: 17px;
            border-top: 1px solid #edf0f2;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 13px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .btn-outline {
            border: 1px solid #3498db;
            background: #fff;
            color: #2c6eaa;
        }

        .btn-outline:hover { background: #eff8ff; }

        .btn-primary { background: #3498db; color: #fff; }
        .btn-primary:hover { background: #2980b9; }

        .read-note {
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 14px; }
            .menu-toggle { display: inline-flex; align-items: center; justify-content: center; }
            .header { padding: 14px; }
            .header h1 { font-size: 17px; }
            .card { padding: 15px; }
        }

        @media (max-width: 480px) {
            .header h1 { font-size: 15px; }
            .logout { padding: 8px 9px; font-size: 12px; }
            .card-head { flex-direction: column; gap: 8px; }
        }
    </style>
    <link rel="stylesheet" href="../includes/user_sidebar.css">
</head>
<body>

<?php require __DIR__ . '/../includes/user_sidebar.php'; ?>

<main class="main-content">
    <header class="header">
        <div class="header-left">
            <button type="button"
                    class="menu-toggle"
                    id="menuToggle"
                    aria-label="Buka menu"
                    aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <h1><i class="fas fa-envelope-open-text"></i> Detail Pesan</h1>
        </div>
        <a href="../logout.php" class="logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </header>

    <section class="card">
        <div class="card-head">
            <div>
                <h2>Pesan Distribusi Pengajuan #<?= (int) $pesan['pengajuan_id'] ?></h2>
                <div class="meta">
                    <div>
                        <i class="fas fa-user-shield"></i>
                        Dari: <?= eDetailPesan($pesan['nama_admin']) ?>
                        (<?= eDetailPesan($pesan['username_admin']) ?>)
                    </div>
                    <div>
                        <i class="fas fa-clock"></i>
                        Dikirim: <?= eDetailPesan($waktuKirim) ?>
                    </div>
                    <?php if ($waktuBaca): ?>
                        <div>
                            <i class="fas fa-check-circle"></i>
                            Dibaca: <?= eDetailPesan($waktuBaca) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <span class="badge badge-read">
                <i class="fas fa-check"></i> Sudah dibaca
            </span>
        </div>

        <div class="message-content">
            <?= nl2br(eDetailPesan($pesan['pesan'])) ?>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <div class="label">Status Pengajuan</div>
                <div class="value">
                    <?= eDetailPesan(labelStatusPengajuan($statusPengajuan)) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="label">Status Bukti Penerimaan</div>
                <div class="value">
                    <?= eDetailPesan(labelStatusBukti($statusBukti)) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="label">Tanggal Pengajuan</div>
                <div class="value">
                    <?= eDetailPesan(
                        !empty($pesan['tanggal_pengajuan'])
                            ? date('d-m-Y', strtotime($pesan['tanggal_pengajuan']))
                            : '-'
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="label">Distribusi</div>
                <div class="value">
                    <?php if (!empty($pesan['distribusi_id'])): ?>
                        #<?= (int) $pesan['distribusi_id'] ?>
                        <?php if (!empty($pesan['tanggal_distribusi'])): ?>
                            &middot;
                            <?= eDetailPesan(
                                date('d-m-Y', strtotime($pesan['tanggal_distribusi']))
                            ) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        Belum dicatat
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($pesan['keterangan_distribusi'])): ?>
            <div class="info-item" style="margin-top:12px;">
                <div class="label">Keterangan Distribusi dari Admin</div>
                <div class="value">
                    <?= nl2br(eDetailPesan($pesan['keterangan_distribusi'])) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($statusPengajuan === 'didistribusi'): ?>
            <div class="info-item" style="margin-top:12px;">
                <div class="label">Tindakan Berikutnya</div>
                <div class="value">
                    Setelah barang benar-benar diterima, unggah bukti penerimaan
                    melalui halaman Riwayat Pengajuan. Pengajuan baru dinyatakan
                    selesai setelah Admin memverifikasi bukti tersebut.
                </div>
            </div>
        <?php endif; ?>

        <div class="card-actions">
            <a href="pesan.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Kembali ke Pesan
            </a>

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="riwayat.php" class="btn btn-primary">
                    <i class="fas fa-history"></i> Riwayat Pengajuan
                </a>

                <?php if ($statusPengajuan === 'selesai'): ?>
                    <a href="detail_riwayat.php?id=<?= (int) $pesan['pengajuan_id'] ?>"
                       class="btn btn-outline">
                        <i class="fas fa-file-lines"></i> Detail Pengajuan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

</body>
</html>
