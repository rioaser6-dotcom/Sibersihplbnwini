<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

if (!isLoggedIn() || !isRole('user')) {
    redirect('../login.php');
}

$user_id = (int) $_SESSION['user_id'];
$activeMenu = 'pesan';
$flash = getFlash();

function ePesan($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

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
        d.tanggal_distribusi,
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
    WHERE m.user_id = ?
    ORDER BY m.created_at DESC, m.id DESC
");
$stmt->execute([$user_id]);
$pesanList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPesan = count($pesanList);
$totalBelumDibaca = 0;

foreach ($pesanList as $item) {
    if (empty($item['dibaca_at'])) {
        $totalBelumDibaca++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesan - PLBN WINI</title>
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

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 16px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .05);
        }

        .stat-card .stat-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 12px;
            color: #fff;
            font-size: 19px;
            flex-shrink: 0;
        }

        .stat-card.total .stat-icon {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .stat-card.unread .stat-icon {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .stat-card .number {
            color: #1e293b;
            font-size: 24px;
            font-weight: 800;
            line-height: 1.2;
        }

        .stat-card .label {
            margin-top: 2px;
            color: #64748b;
            font-size: 13px;
        }

        .message-list {
            display: flex;
            flex-direction: column;
            gap: 13px;
        }

        .message-card {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 18px;
            background: #fff;
            border: 1px solid #e9eef4;
            border-left: 5px solid #cbd5e1;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .04);
        }

        .message-card.unread {
            border-left-color: #3b82f6;
            background: #fbfdff;
        }

        .message-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #eef4ff;
            color: #3b82f6;
            font-size: 17px;
            flex-shrink: 0;
        }

        .message-card.unread .message-icon {
            background: #dbeafe;
        }

        .message-body { flex: 1; min-width: 0; }

        .message-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 7px;
        }

        .message-top h2 {
            margin-bottom: 4px;
            color: #2c3e50;
            font-size: 16px;
            overflow-wrap: anywhere;
        }

        .meta {
            color: #64748b;
            font-size: 12px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .meta i { width: 14px; color: #94a3b8; }

        .badge {
            display: inline-block;
            flex-shrink: 0;
            padding: 5px 9px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-unread { background: #fee2e2; color: #991b1b; }
        .badge-read { background: #dcfce7; color: #166534; }

        .excerpt {
            margin: 7px 0 10px;
            color: #475569;
            font-size: 14px;
            line-height: 1.65;
            overflow-wrap: anywhere;
        }

        .message-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .distribution-info {
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .distribution-info strong { color: #334155; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 13px;
            border: 0;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-primary { background: #3498db; color: #fff; }
        .btn-primary:hover { background: #2980b9; }

        .empty-state {
            padding: 50px 18px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .05);
            color: #64748b;
            text-align: center;
        }

        .empty-state i {
            display: block;
            margin-bottom: 13px;
            color: #bdc3c7;
            font-size: 44px;
        }

        .empty-state strong {
            display: block;
            margin-bottom: 6px;
            color: #334155;
            font-size: 16px;
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 14px; }
            .menu-toggle { display: inline-flex; align-items: center; justify-content: center; }
            .header { padding: 14px; }
            .header h1 { font-size: 17px; }
            .message-card { padding: 14px; gap: 11px; }
            .message-icon { width: 36px; height: 36px; font-size: 15px; }
        }

        @media (max-width: 480px) {
            .header h1 { font-size: 15px; }
            .logout { padding: 8px 9px; font-size: 12px; }
            .message-card { flex-direction: column; }
            .message-top { flex-direction: column; gap: 7px; }
            .message-footer { align-items: stretch; }
            .message-footer .btn { width: 100%; }
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
            <h1><i class="fas fa-envelope"></i> Pesan</h1>
        </div>
        <a href="../logout.php" class="logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </header>

    <?php if ($flash): ?>
        <?php
        $flashType = $flash['type'] ?? 'info';

        if (!in_array($flashType, ['success', 'danger', 'warning', 'info'], true)) {
            $flashType = 'info';
        }

        $flashIcons = [
            'success' => 'check-circle',
            'danger' => 'exclamation-circle',
            'warning' => 'exclamation-triangle',
            'info' => 'info-circle',
        ];
        ?>
        <div class="flash flash-<?= ePesan($flashType) ?>" role="alert">
            <i class="fas fa-<?= ePesan($flashIcons[$flashType]) ?>"></i>
            <span><?= ePesan($flash['msg'] ?? '') ?></span>
        </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat-card total">
            <div class="stat-icon"><i class="fas fa-inbox"></i></div>
            <div>
                <div class="number"><?= (int) $totalPesan ?></div>
                <div class="label">Total Pesan</div>
            </div>
        </div>

        <div class="stat-card unread">
            <div class="stat-icon"><i class="fas fa-envelope-open-text"></i></div>
            <div>
                <div class="number"><?= (int) $totalBelumDibaca ?></div>
                <div class="label">Belum Dibaca</div>
            </div>
        </div>
    </div>

    <?php if ($pesanList): ?>
        <div class="message-list">
            <?php foreach ($pesanList as $pesan): ?>
                <?php
                $idPesan = (int) $pesan['id'];
                $belumDibaca = empty($pesan['dibaca_at']);
                $isiPesan = trim((string) $pesan['pesan']);
                $ringkasan = mb_strlen($isiPesan) > 240
                    ? mb_substr($isiPesan, 0, 240) . '...'
                    : $isiPesan;
                $waktu = !empty($pesan['created_at'])
                    ? date('d-m-Y H:i', strtotime($pesan['created_at']))
                    : '-';
                ?>
                <article class="message-card <?= $belumDibaca ? 'unread' : '' ?>">
                    <div class="message-icon">
                        <i class="fas <?= $belumDibaca ? 'fa-envelope' : 'fa-envelope-open' ?>"></i>
                    </div>

                    <div class="message-body">
                        <div class="message-top">
                            <div>
                                <h2>Pesan Distribusi Pengajuan #<?= (int) $pesan['pengajuan_id'] ?></h2>
                                <div class="meta">
                                    <div>
                                        <i class="fas fa-user-shield"></i>
                                        Dari: <?= ePesan($pesan['nama_admin']) ?>
                                        (<?= ePesan($pesan['username_admin']) ?>)
                                    </div>
                                    <div>
                                        <i class="fas fa-clock"></i>
                                        Dikirim: <?= ePesan($waktu) ?>
                                    </div>
                                </div>
                            </div>

                            <span class="badge <?= $belumDibaca ? 'badge-unread' : 'badge-read' ?>">
                                <?= $belumDibaca ? 'Belum dibaca' : 'Sudah dibaca' ?>
                            </span>
                        </div>

                        <p class="excerpt"><?= ePesan($ringkasan) ?></p>

                        <div class="message-footer">
                            <div class="distribution-info">
                                <strong>Status pengajuan:</strong>
                                <?= ePesan(labelStatusPengajuan($pesan['status_pengajuan'])) ?>
                                <?php if (!empty($pesan['distribusi_id'])): ?>
                                    &middot;
                                    <strong>Distribusi #<?= (int) $pesan['distribusi_id'] ?></strong>
                                    <?php if (!empty($pesan['tanggal_distribusi'])): ?>
                                        (<?= ePesan(date('d-m-Y', strtotime($pesan['tanggal_distribusi']))) ?>)
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <a href="detail_pesan.php?id=<?= $idPesan ?>" class="btn btn-primary">
                                <i class="fas fa-eye"></i> Baca Pesan
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-envelope-open-text"></i>
            <strong>Belum ada pesan.</strong>
            Pesan dari Admin terkait distribusi barang akan muncul di halaman ini.
        </div>
    <?php endif; ?>
</main>

</body>
</html>
