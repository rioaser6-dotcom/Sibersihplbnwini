<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once '../includes/fungsi.php';

require_once '../config/database.php';

if (!isLoggedIn() || !isRole('admin')) {

    redirect('../login.php');

}

if (empty($_SESSION['csrf_anggota'])) {

    $_SESSION['csrf_anggota'] = bin2hex(random_bytes(32));

}

// Token CSRF khusus untuk proses hapus anggota.
if (empty($_SESSION['csrf_hapus_anggota'])) {
    $_SESSION['csrf_hapus_anggota'] = bin2hex(random_bytes(32));
}

$pesan = '';

$jenisPesan = 'success';

// ================= TAMBAH ANGGOTA =================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    $username = trim($_POST['username'] ?? '');

    $nama = trim($_POST['nama'] ?? '');

    $password = $_POST['password'] ?? '';

    $nik = trim($_POST['nik'] ?? '');

    $divisi = trim($_POST['divisi'] ?? '');

    $status = trim($_POST['status_kepegawaian'] ?? '');

    if (
        !is_string($token) ||
        empty($_SESSION['csrf_anggota']) ||
        !is_string($_SESSION['csrf_anggota']) ||
        !hash_equals($_SESSION['csrf_anggota'], $token)
    ) {

        $pesan = 'Permintaan tidak valid. Muat ulang halaman.';

        $jenisPesan = 'danger';

    } elseif (

        $username === '' || $nama === '' || $password === '' ||

        $nik === '' || $divisi === '' || $status === ''

    ) {

        $pesan = 'Semua kolom wajib diisi.';

        $jenisPesan = 'danger';

    } elseif (
        mb_strlen($username) > 100 ||
        mb_strlen($nama) > 150 ||
        mb_strlen($divisi) > 100
    ) {

        $pesan = 'Panjang username, nama, atau divisi melebihi batas.';

        $jenisPesan = 'danger';

    } elseif (strlen($password) < 8 || strlen($password) > 72) {

        $pesan = 'Password harus terdiri dari 8 sampai 72 karakter.';

        $jenisPesan = 'danger';

    } elseif (!preg_match('/^[0-9]{16}$/', $nik)) {

        $pesan = 'NIK harus terdiri dari 16 angka.';

        $jenisPesan = 'danger';

    } elseif (!in_array($status, [
        'PNS',
        'PPPK',
        'Honorer',
        'Pegawai Kontrak',
        'Pegawai Non-ASN'
    ], true)) {

        $pesan = 'Status kepegawaian yang dipilih tidak valid.';

        $jenisPesan = 'danger';

    } else {

        try {

            // Periksa username dan NIK agar tidak ganda.

            $cek = $pdo->prepare(

                'SELECT id FROM users WHERE username = ? OR nik = ? LIMIT 1'

            );

            $cek->execute([$username, $nik]);

            if ($cek->fetch()) {

                $pesan = 'Username atau NIK sudah terdaftar.';

                $jenisPesan = 'danger';

            } else {

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $sql = "INSERT INTO users

                    (username, password, role, nama, nik,

                     divisi, status_kepegawaian, created_at)

                    VALUES (?, ?, 'user', ?, ?, ?, ?, NOW())";

                $stmt = $pdo->prepare($sql);

                $stmt->execute([

                    $username,

                    $hash,

                    $nama,

                    $nik,

                    $divisi,

                    $status

                ]);

                $_SESSION['csrf_anggota'] = bin2hex(random_bytes(32));

                $pesan = 'Anggota berhasil ditambahkan.';

            }

        } catch (PDOException $e) {

            $pesan = 'Gagal menambahkan anggota. Periksa struktur tabel users.';

            $jenisPesan = 'danger';

            error_log('Tambah anggota: ' . $e->getMessage());

        }

    }

}

// ================= DAFTAR ANGGOTA =================

$stmt = $pdo->query("

    SELECT id, username, nama, nik, divisi,

           status_kepegawaian, created_at

    FROM users

    WHERE role = 'user'

    ORDER BY id DESC

");

$anggota = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($nilai) {

    return htmlspecialchars((string)($nilai ?? ''), ENT_QUOTES, 'UTF-8');

}

?>

<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Kelola Anggota - PLBN WINI</title>

    <link rel="stylesheet"

        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>

        /* ===== RESET & GLOBAL ===== */

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

        /* ===== SIDEBAR: MENGIKUTI DASHBOARD ===== */

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

            z-index: 1000;

            transition: transform 0.3s ease;

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

            text-align: center;

            font-size: 16px;

            font-weight: bold;

            padding: 9px 0;

            margin: 0;

            border-bottom: none;

            color: #ecf0f1;

        }

        .sidebar a {

            display: block;

            color: #ecf0f1;

            padding: 12px 15px;

            text-decoration: none;

            border-radius: 5px;

            margin-bottom: 5px;

            transition: background 0.2s, color 0.2s;

        }

        .sidebar a:hover,

        .sidebar a.active {

            background: #34495e;

            color: white;

        }

        .sidebar a .icon {

            display: inline-block;

            width: 24px;

            margin-right: 0;

            text-align: center;

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

            background: transparent;

            border: none;

            color: #ecf0f1;

            font-size: 28px;

            position: absolute;

            top: 10px;

            right: 15px;

            cursor: pointer;

        }

        /* ===== OVERLAY ===== */

        .overlay {

            display: none;

            position: fixed;

            inset: 0;

            background: rgba(0, 0, 0, 0.5);

            z-index: 999;

        }

        .overlay.active {

            display: block;

        }

        /* ===== KONTEN UTAMA ===== */

        .main-content {

            margin-left: 250px;

            padding: 25px;

            min-width: 0;

            transition: margin-left 0.3s;

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

            gap: 15px;

            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);

        }

        .header-title {

            display: flex;

            align-items: center;

            gap: 12px;

            min-width: 0;

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

            flex-shrink: 0;

        }

        .header .user-info .logout {

            display: inline-block;

            background: #e74c3c;

            color: white;

            padding: 8px 16px;

            border-radius: 8px;

            text-decoration: none;

            font-weight: 600;

            transition: background 0.2s;

        }

        .header .user-info .logout:hover {

            background: #c0392b;

        }

        .menu-toggle {

            display: none;

            background: transparent;

            border: none;

            font-size: 28px;

            color: #0f172a;

            cursor: pointer;

            padding: 0 5px;

            flex-shrink: 0;

        }

        /* ===== PANEL ===== */

        .panel {

            background: white;

            padding: 25px;

            border-radius: 14px;

            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);

            margin-bottom: 25px;

            min-width: 0;

        }

        .panel h2 {

            font-size: 18px;

            color: #0f172a;

            margin-bottom: 20px;

            border-bottom: 2px solid #f1f5f9;

            padding-bottom: 12px;

        }

        .panel h2 i {

            color: #3b82f6;

            margin-right: 6px;

        }

        .muted {

            color: #64748b;

            font-size: 12px;

            line-height: 1.6;

        }

        /* ===== FORM TAMBAH ANGGOTA ===== */

        .form-grid {

            display: grid;

            grid-template-columns: repeat(2, minmax(0, 1fr));

            gap: 18px 20px;

            margin-top: 20px;

        }

        .form-grid > div {

            min-width: 0;

        }

        label {

            display: block;

            font-size: 13px;

            font-weight: 600;

            color: #334155;

            margin-bottom: 7px;

        }

        input,

        select {

            display: block;

            width: 100%;

            min-width: 0;

            padding: 12px;

            border: 1px solid #cbd5e1;

            border-radius: 8px;

            font: inherit;

            font-size: 14px;

            color: #1e293b;

            background: white;

            transition: border-color 0.2s, box-shadow 0.2s;

        }

        input:focus,

        select:focus {

            outline: none;

            border-color: #3b82f6;

            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);

        }

        .form-grid input:disabled,

        .form-grid select:disabled {

            background: #f8fafc;

        }

        .form-grid .muted {

            margin-top: 5px;

        }

        .full {

            grid-column: 1 / -1;

        }

        /* ===== TOMBOL ===== */

        .btn {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 8px;

            border: 0;

            border-radius: 8px;

            padding: 12px 18px;

            background: #3b82f6;

            color: white;

            font-size: 14px;

            font-weight: 600;

            text-decoration: none;

            cursor: pointer;

            transition: background 0.2s, transform 0.2s;

        }

        .btn:hover {

            background: #2563eb;

        }

        .btn:active {

            transform: translateY(1px);

        }

        /* ===== NOTIFIKASI ===== */

        .alert {

            padding: 14px 18px;

            border-radius: 10px;

            margin-bottom: 20px;

            font-weight: 500;

            line-height: 1.5;

        }

        .success {

            background: #dcfce7;

            color: #166534;

            border: 1px solid #bbf7d0;

        }

        .danger {

            background: #fee2e2;

            color: #991b1b;

            border: 1px solid #fecaca;

        }

        /* ===== TOMBOL AKSI TABEL ===== */
        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            white-space: nowrap;
        }

        .btn-edit,
        .btn-delete {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 7px 11px;
            border: 0;
            border-radius: 6px;
            color: #fff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }

        .btn-edit {
            background: #3b82f6;
        }

        .btn-edit:hover {
            background: #2563eb;
        }

        .btn-delete {
            background: #ef4444;
        }

        .btn-delete:hover {
            background: #dc2626;
        }

        .action-buttons form {
            display: inline;
            margin: 0;
        }

        /* ===== TABEL DAFTAR ANGGOTA ===== */

        .table-wrap {

            width: 100%;

            overflow-x: auto;

            border-radius: 8px;

        }

        table {

            width: 100%;

            min-width: 850px;

            border-collapse: collapse;

            font-size: 14px;

        }

        th {

            background: #f1f5f9;

            color: #1e293b;

            font-weight: 700;

            padding: 12px;

            text-align: left;

            border-bottom: 2px solid #e2e8f0;

            white-space: nowrap;

        }

        td {

            padding: 12px;

            border-bottom: 1px solid #f1f5f9;

            text-align: left;

            vertical-align: middle;

            color: #334155;

        }

        tbody tr:hover {

            background: #f8fafc;

        }

        tbody tr:last-child td {

            border-bottom: none;

        }

        .empty-row {

            text-align: center;

            padding: 30px 12px;

            color: #94a3b8;

        }

        /* ===== RESPONSIVE DESKTOP ===== */

        @media (min-width: 769px) {

            .sidebar {

                transform: translateX(0) !important;

            }

        }

        /* ===== RESPONSIVE TABLET & HP ===== */

        @media (max-width: 768px) {

            .sidebar {

                width: 250px;

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

                padding: 18px;

                flex-wrap: wrap;

            }

            .header-title {

                flex: 1;

            }

            .header h1 {

                font-size: 21px;

            }

            .panel {

                padding: 20px;

            }

            .form-grid {

                grid-template-columns: 1fr;

                gap: 16px;

            }

            .full {

                grid-column: auto;

            }

        }

        /* ===== RESPONSIVE HP KECIL ===== */

        @media (max-width: 480px) {

            .main-content {

                padding: 12px;

            }

            .header {

                padding: 15px;

                gap: 12px;

            }

            .header-title {

                width: 100%;

                flex: 1 1 100%;

            }

            .header h1 {

                font-size: 19px;

                gap: 8px;

            }

            .menu-toggle {

                font-size: 25px;

            }

            .header .user-info {

                margin-left: auto;

            }

            .header .user-info .logout {

                padding: 8px 12px;

                font-size: 13px;

            }

            .panel {

                padding: 16px;

                border-radius: 12px;

            }

            .panel h2 {

                font-size: 17px;

            }

            .btn {

                width: 100%;

            }

            table {

                min-width: 850px;

                font-size: 12px;

            }

            th,

            td {

                padding: 8px;

            }

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

    <a href="dashboard.php">

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

    <a href="anggota.php" class="active">

        <span class="icon"><i class="fas fa-users"></i></span> <span>Kelola Anggota</span>

    </a>

    <a href="../logout.php" class="logout-link">

        <span class="icon"><i class="fas fa-right-from-bracket"></i></span> <span>Logout</span>

    </a>

</div>

<!-- MAIN CONTENT -->

<main class="main-content">

    <!-- HEADER -->

    <div class="header">

        <div class="header-title">

            <button

                type="button"

                class="menu-toggle"

                id="menuToggle"

                aria-label="Buka menu"

                aria-controls="sidebar"

                aria-expanded="false"

            >

                <i class="fas fa-bars"></i>

            </button>

            <h1>

                <i class="fas fa-users"></i>

                Kelola Anggota

            </h1>

        </div>

        <div class="user-info">

            <a href="../logout.php" class="logout">

                <i class="fas fa-sign-out-alt"></i> Logout

            </a>

        </div>

    </div>

    <!-- PESAN HASIL PROSES -->

    <?php if ($pesan !== ''): ?>

        <div class="alert <?= h($jenisPesan) ?>">

            <?= h($pesan) ?>

        </div>

    <?php endif; ?>

    <?php
    $notifikasi = [
        'edit_berhasil' => ['Data anggota berhasil diperbarui.', 'success'],
        'hapus_berhasil' => ['Anggota berhasil dihapus.', 'success'],
        'anggota_tidak_ditemukan' => ['Anggota tidak ditemukan.', 'danger'],
        'hapus_gagal' => ['Gagal menghapus anggota. Silakan periksa relasi data.', 'danger'],
        'hapus_gagal_relasi' => ['Anggota tidak dapat dihapus karena masih memiliki riwayat pengajuan.', 'danger'],
        'permintaan_tidak_valid' => ['Permintaan tidak valid. Silakan coba kembali.', 'danger'],
    ];

    $kodePesan = $_GET['pesan'] ?? '';
    if (isset($notifikasi[$kodePesan])):
        [$teksNotifikasi, $kelasNotifikasi] = $notifikasi[$kodePesan];
    ?>
        <div class="alert <?= h($kelasNotifikasi) ?>">
            <?= h($teksNotifikasi) ?>
        </div>
    <?php endif; ?>

    <!-- FORM TAMBAH ANGGOTA -->

    <section class="panel">

        <h2>

            <i class="fas fa-user-plus"></i>

            Tambah Anggota Baru

        </h2>

        <p class="muted">

            Akun baru otomatis diberi role user dan dapat digunakan untuk login.

        </p>

        <form method="POST" autocomplete="off">

            <input

                type="hidden"

                name="csrf_token"

                value="<?= h($_SESSION['csrf_anggota']) ?>"

            >

            <div class="form-grid">

                <div>

                    <label for="username">Username</label>

                    <input

                        type="text"

                        id="username"

                        name="username"

                        maxlength="100"

                        required

                        value="<?= h(

                            $_SERVER['REQUEST_METHOD'] === 'POST'

                                ? ($_POST['username'] ?? '')

                                : ''

                        ) ?>"

                    >

                </div>

                <div>

                    <label for="nama">Nama Lengkap</label>

                    <input

                        type="text"

                        id="nama"

                        name="nama"

                        maxlength="150"

                        required

                        value="<?= h(

                            $_SERVER['REQUEST_METHOD'] === 'POST'

                                ? ($_POST['nama'] ?? '')

                                : ''

                        ) ?>"

                    >

                </div>

                <div>

                    <label for="password">Password</label>

                    <input

                        type="password"

                        id="password"

                        name="password"

                        minlength="8"

                        maxlength="72"

                        autocomplete="new-password"

                        required

                    >

                    <p class="muted">Minimal 8 karakter.</p>

                </div>

                <div>

                    <label for="nik">NIK</label>

                    <input

                        type="text"

                        id="nik"

                        name="nik"

                        inputmode="numeric"

                        pattern="[0-9]{16}"

                        minlength="16"

                        maxlength="16"

                        required

                        value="<?= h(

                            $_SERVER['REQUEST_METHOD'] === 'POST'

                                ? ($_POST['nik'] ?? '')

                                : ''

                        ) ?>"

                    >

                </div>

                <div>

                    <label for="divisi">Divisi</label>

                    <input

                        type="text"

                        id="divisi"

                        name="divisi"

                        maxlength="100"

                        required

                        value="<?= h(

                            $_SERVER['REQUEST_METHOD'] === 'POST'

                                ? ($_POST['divisi'] ?? '')

                                : ''

                        ) ?>"

                    >

                </div>

                <div>

                    <label for="status_kepegawaian">

                        Status Kepegawaian

                    </label>

                    <select

                        id="status_kepegawaian"

                        name="status_kepegawaian"

                        required

                    >

                        <option value="">-- Pilih Status --</option>

                        <?php

                        $statusPilihan = [

                            'PNS',

                            'PPPK',

                            'Honorer',

                            'Pegawai Kontrak',

                            'Pegawai Non-ASN'

                        ];

                        $statusTerpilih =

                            $_POST['status_kepegawaian'] ?? '';

                        foreach ($statusPilihan as $pilihan):

                        ?>

                            <option

                                value="<?= h($pilihan) ?>"

                                <?= $statusTerpilih === $pilihan

                                    ? 'selected'

                                    : '' ?>

                            >

                                <?= h($pilihan) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="full">

                    <button type="submit" class="btn">

                        <i class="fas fa-user-plus"></i>

                        Simpan Anggota

                    </button>

                </div>

            </div>

        </form>

    </section>

    <!-- TABEL DAFTAR ANGGOTA -->

    <section class="panel">

        <h2>

            <i class="fas fa-list-ul"></i>

            Daftar Anggota

        </h2>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>No.</th>

                        <th>Username</th>

                        <th>Nama Lengkap</th>

                        <th>NIK</th>

                        <th>Divisi</th>

                        <th>Status Kepegawaian</th>
                        <th>Aksi</th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (!$anggota): ?>

                        <tr>

                            <td colspan="7" class="empty-row">

                                <i class="fas fa-inbox"

                                   style="font-size:28px; display:block; margin-bottom:8px;"></i>

                                Belum ada anggota.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($anggota as $i => $a): ?>

                            <tr>

                                <td><?= $i + 1 ?></td>

                                <td><?= h($a['username']) ?></td>

                                <td><?= h($a['nama']) ?></td>

                                <td><?= h($a['nik']) ?></td>

                                <td><?= h($a['divisi']) ?></td>

                                <td><?= h($a['status_kepegawaian']) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="edit_pengguna.php?id=<?= (int) $a['id'] ?>"
                                           class="btn-edit">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>

                                        <form action="hapus_anggota.php" method="POST"
                                              onsubmit="return confirm('Yakin ingin menghapus anggota ini? Data yang dihapus tidak dapat dikembalikan.');">
                                            <input type="hidden" name="id"
                                                   value="<?= (int) $a['id'] ?>">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= h($_SESSION['csrf_hapus_anggota']) ?>">
                                            <button type="submit" class="btn-delete">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<!-- JAVASCRIPT SIDEBAR -->

<script>

(function () {

    const sidebar = document.getElementById('sidebar');

    const overlay = document.getElementById('sidebarOverlay');

    const menuToggle = document.getElementById('menuToggle');

    const closeBtn = document.getElementById('closeSidebarBtn');

    function openSidebar() {

        sidebar.classList.add('open');

        overlay.classList.add('active');

        menuToggle.setAttribute('aria-expanded', 'true');

        document.body.style.overflow = 'hidden';

    }

    function closeSidebar() {

        sidebar.classList.remove('open');

        overlay.classList.remove('active');

        menuToggle.setAttribute('aria-expanded', 'false');

        document.body.style.overflow = '';

    }

    menuToggle.addEventListener('click', function (event) {

        event.stopPropagation();

        if (sidebar.classList.contains('open')) {

            closeSidebar();

        } else {

            openSidebar();

        }

    });

    closeBtn.addEventListener('click', closeSidebar);

    overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function (event) {

        if (

            event.key === 'Escape' &&

            sidebar.classList.contains('open')

        ) {

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
