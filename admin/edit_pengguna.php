<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/fungsi.php';
require_once '../config/database.php';

// Halaman ini hanya dapat diakses oleh administrator.
if (!isLoggedIn() || !isRole('admin')) {
    redirect('../login.php');
}

// Token CSRF untuk melindungi proses perubahan data.
if (empty($_SESSION['csrf_edit_pengguna'])) {
    $_SESSION['csrf_edit_pengguna'] = bin2hex(random_bytes(32));
}

function e_pengguna($nilai)
{
    return htmlspecialchars((string)($nilai ?? ''), ENT_QUOTES, 'UTF-8');
}

$idInput = $_GET['id'] ?? $_POST['id'] ?? '';
$id = filter_var($idInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$id) {
    redirect('anggota.php?pesan=anggota_tidak_ditemukan');
}

$pesan = '';
$jenisPesan = 'danger';

try {
    $stmt = $pdo->prepare(
        "SELECT id, username, nama, nik, divisi, status_kepegawaian
         FROM users
         WHERE id = ? AND role = 'user'
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $pengguna = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Ambil data edit pengguna: ' . $e->getMessage());
    http_response_code(500);
    exit('Terjadi kesalahan saat mengambil data pengguna.');
}

if (!$pengguna) {
    redirect('anggota.php?pesan=anggota_tidak_ditemukan');
}

// Nilai awal form berasal dari database.
$formData = [
    'username' => $pengguna['username'] ?? '',
    'nama' => $pengguna['nama'] ?? '',
    'nik' => $pengguna['nik'] ?? '',
    'divisi' => $pengguna['divisi'] ?? '',
    'status_kepegawaian' => $pengguna['status_kepegawaian'] ?? '',
];

$statusPilihan = [
    'PNS',
    'PPPK',
    'Honorer',
    'Pegawai Kontrak',
    'Pegawai Non-ASN'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $formData['username'] = trim($_POST['username'] ?? '');
    $formData['nama'] = trim($_POST['nama'] ?? '');
    $formData['nik'] = trim($_POST['nik'] ?? '');
    $formData['divisi'] = trim($_POST['divisi'] ?? '');
    $formData['status_kepegawaian'] = trim($_POST['status_kepegawaian'] ?? '');
    $passwordBaru = (string)($_POST['password_baru'] ?? '');
    $konfirmasiPassword = (string)($_POST['konfirmasi_password'] ?? '');

    if (!is_string($token) || !hash_equals($_SESSION['csrf_edit_pengguna'], $token)) {
        $pesan = 'Permintaan tidak valid atau sesi telah berubah. Muat ulang halaman, lalu coba kembali.';
    } elseif (
        $formData['username'] === '' ||
        $formData['nama'] === '' ||
        $formData['nik'] === '' ||
        $formData['divisi'] === '' ||
        $formData['status_kepegawaian'] === ''
    ) {
        $pesan = 'Username, nama, NIK, divisi, dan status kepegawaian wajib diisi.';
    } elseif (mb_strlen($formData['username']) > 100 || mb_strlen($formData['nama']) > 150 || mb_strlen($formData['divisi']) > 100) {
        $pesan = 'Panjang username, nama, atau divisi melebihi batas yang diizinkan.';
    } elseif (!preg_match('/^[0-9]{16}$/', $formData['nik'])) {
        $pesan = 'NIK harus terdiri dari tepat 16 angka.';
    } elseif (
        !in_array($formData['status_kepegawaian'], $statusPilihan, true) &&
        $formData['status_kepegawaian'] !== (string)($pengguna['status_kepegawaian'] ?? '')
    ) {
        $pesan = 'Status kepegawaian yang dipilih tidak valid.';
    } elseif ($passwordBaru !== '' && strlen($passwordBaru) < 8) {
        $pesan = 'Password baru minimal 8 karakter.';
    } elseif ($passwordBaru !== '' && $passwordBaru !== $konfirmasiPassword) {
        $pesan = 'Konfirmasi password baru tidak sama.';
    } else {
        try {
            // Pastikan username dan NIK tidak dipakai pengguna lain.
            $cek = $pdo->prepare(
                'SELECT id FROM users
                 WHERE (username = ? OR nik = ?) AND id <> ?
                 LIMIT 1'
            );
            $cek->execute([$formData['username'], $formData['nik'], $id]);

            if ($cek->fetch(PDO::FETCH_ASSOC)) {
                $pesan = 'Username atau NIK sudah digunakan oleh pengguna lain.';
            } else {
                if ($passwordBaru !== '') {
                    $passwordHash = password_hash($passwordBaru, PASSWORD_DEFAULT);
                    $update = $pdo->prepare(
                        "UPDATE users
                         SET username = ?, nama = ?, nik = ?, divisi = ?,
                             status_kepegawaian = ?, password = ?
                         WHERE id = ? AND role = 'user'"
                    );
                    $update->execute([
                        $formData['username'],
                        $formData['nama'],
                        $formData['nik'],
                        $formData['divisi'],
                        $formData['status_kepegawaian'],
                        $passwordHash,
                        $id
                    ]);
                } else {
                    // Password tetap seperti sebelumnya bila kolom password baru dikosongkan.
                    $update = $pdo->prepare(
                        "UPDATE users
                         SET username = ?, nama = ?, nik = ?, divisi = ?,
                             status_kepegawaian = ?
                         WHERE id = ? AND role = 'user'"
                    );
                    $update->execute([
                        $formData['username'],
                        $formData['nama'],
                        $formData['nik'],
                        $formData['divisi'],
                        $formData['status_kepegawaian'],
                        $id
                    ]);
                }

                // Mencegah pengiriman ulang form setelah berhasil disimpan.
                unset($_SESSION['csrf_edit_pengguna']);
                redirect('anggota.php?pesan=edit_berhasil');
            }
        } catch (PDOException $e) {
            error_log('Simpan edit pengguna: ' . $e->getMessage());
            $pesan = 'Data pengguna gagal diperbarui. Periksa kembali data dan struktur tabel users.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Pengguna - PLBN WINI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8;
            color: #1e293b;
        }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 250px; height: 100%;
            background: #2c3e50; color: white; padding: 20px; overflow-y: auto;
            z-index: 1000; transition: transform .3s ease;
        }
        .sidebar-brand { text-align: center; padding-top: 8px; margin-bottom: 15px; }
        .sidebar-logo {
            display: block; width: 90px; height: 90px; object-fit: contain;
            margin: 0 auto 7px;
        }
        .sidebar-brand h2 {
            text-align: center; font-size: 16px; font-weight: 700;
            padding: 9px 0; color: #ecf0f1;
        }
        .sidebar a {
            display: block; color: #ecf0f1; padding: 12px 15px;
            text-decoration: none; border-radius: 5px; margin-bottom: 5px;
            transition: background .2s, color .2s;
        }
        .sidebar a:hover, .sidebar a.active { background: #34495e; color: white; }
        .sidebar a .icon { display: inline-block; width: 24px; text-align: center; }
        .sidebar a.logout-link { margin-top: 20px; background: #e74c3c; }
        .sidebar a.logout-link:hover { background: #c0392b; }
        .close-sidebar {
            display: none; background: transparent; border: 0; color: #ecf0f1;
            font-size: 28px; position: absolute; top: 10px; right: 15px; cursor: pointer;
        }
        .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 999; }
        .overlay.active { display: block; }
        .main-content { margin-left: 250px; padding: 25px; min-width: 0; transition: margin-left .3s; }
        .header {
            background: white; padding: 18px 25px; border-radius: 14px; margin-bottom: 25px;
            display: flex; justify-content: space-between; align-items: center; gap: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,.05);
        }
        .header-title { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .header h1 { font-size: 22px; color: #0f172a; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .header h1 i { color: #3b82f6; }
        .user-info { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }
        .logout {
            display: inline-block; background: #e74c3c; color: white; padding: 8px 16px;
            border-radius: 8px; text-decoration: none; font-weight: 600; transition: background .2s;
        }
        .logout:hover { background: #c0392b; }
        .menu-toggle {
            display: none; background: transparent; border: 0; font-size: 28px;
            color: #0f172a; cursor: pointer; padding: 0 5px; flex-shrink: 0;
        }
        .panel {
            background: white; padding: 25px; border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,.05); margin-bottom: 25px; min-width: 0;
        }
        .panel h2 {
            font-size: 18px; color: #0f172a; margin-bottom: 12px;
            border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;
        }
        .panel h2 i { color: #3b82f6; margin-right: 6px; }
        .description { color: #64748b; font-size: 13px; line-height: 1.6; margin-bottom: 8px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 20px; margin-top: 20px; }
        .form-grid > div { min-width: 0; }
        label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 7px; }
        input, select {
            display: block; width: 100%; min-width: 0; padding: 12px;
            border: 1px solid #cbd5e1; border-radius: 8px; font: inherit;
            font-size: 14px; color: #1e293b; background: white;
            transition: border-color .2s, box-shadow .2s;
        }
        input:focus, select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
        .hint { color: #64748b; font-size: 12px; line-height: 1.5; margin-top: 5px; }
        .full { grid-column: 1 / -1; }
        .section-label {
            font-size: 14px; font-weight: 700; color: #334155;
            padding-top: 14px; border-top: 1px solid #e2e8f0;
        }
        .form-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            border: 0; border-radius: 8px; padding: 12px 18px; background: #3b82f6;
            color: white; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer;
            transition: background .2s, transform .2s;
        }
        .btn:hover { background: #2563eb; }
        .btn:active { transform: translateY(1px); }
        .btn-secondary { background: #64748b; }
        .btn-secondary:hover { background: #475569; }
        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; line-height: 1.5; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        @media (min-width: 769px) { .sidebar { transform: translateX(0) !important; } }
        @media (max-width: 768px) {
            .sidebar { width: 250px; transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .close-sidebar { display: block; }
            .main-content { margin-left: 0; padding: 15px; }
            .menu-toggle { display: block; }
            .header { padding: 18px; flex-wrap: wrap; }
            .header-title { flex: 1; }
            .header h1 { font-size: 21px; }
            .panel { padding: 20px; }
            .form-grid { grid-template-columns: 1fr; gap: 16px; }
            .full { grid-column: auto; }
        }
        @media (max-width: 480px) {
            .main-content { padding: 12px; }
            .header { padding: 15px; gap: 12px; }
            .header-title { width: 100%; flex: 1 1 100%; }
            .header h1 { font-size: 19px; gap: 8px; }
            .menu-toggle { font-size: 25px; }
            .user-info { margin-left: auto; }
            .logout { padding: 8px 12px; font-size: 13px; }
            .panel { padding: 16px; border-radius: 12px; }
            .panel h2 { font-size: 17px; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
<div class="overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <button type="button" class="close-sidebar" id="closeSidebarBtn" aria-label="Tutup menu">&times;</button>
    <div class="sidebar-brand">
        <img src="../assets/img/plbn-wini.png" alt="Logo PLBN Wini" class="sidebar-logo">
        <h2>PLBN WINI</h2>
    </div>
    <a href="dashboard.php"><span class="icon"><i class="fas fa-chart-pie"></i></span> <span>Dashboard</span></a>
    <a href="barang.php"><span class="icon"><i class="fas fa-boxes"></i></span> <span>Kelola Barang</span></a>
    <a href="pengajuan.php"><span class="icon"><i class="fas fa-file-alt"></i></span> <span>Kelola Pengajuan</span></a>
    <a href="distribusi.php"><span class="icon"><i class="fas fa-truck"></i></span> <span>Distribusi</span></a>
    <a href="laporan.php"><span class="icon"><i class="fas fa-file-lines"></i></span> <span>Laporan</span></a>
    <a href="anggota.php" class="active"><span class="icon"><i class="fas fa-users"></i></span> <span>Kelola Anggota</span></a>
    <a href="../logout.php" class="logout-link"><span class="icon"><i class="fas fa-right-from-bracket"></i></span> <span>Logout</span></a>
</aside>

<main class="main-content">
    <header class="header">
        <div class="header-title">
            <button type="button" class="menu-toggle" id="menuToggle" aria-label="Buka menu" aria-controls="sidebar" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <h1><i class="fas fa-user-pen"></i> Edit Pengguna</h1>
        </div>
        <div class="user-info">
            <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <?php if ($pesan !== ''): ?>
        <div class="alert <?= e_pengguna($jenisPesan) ?>" role="alert">
            <?= e_pengguna($pesan) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2><i class="fas fa-user-pen"></i> Form Edit Data Pengguna</h2>
        <p class="description">Perbarui informasi akun pengguna PLBN Wini. Kolom password baru boleh dikosongkan jika password tidak ingin diubah.</p>

        <form method="POST" action="edit_pengguna.php?id=<?= (int)$id ?>" autocomplete="off">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="csrf_token" value="<?= e_pengguna($_SESSION['csrf_edit_pengguna']) ?>">

            <div class="form-grid">
                <div>
                    <label for="username">Username <span aria-hidden="true">*</span></label>
                    <input type="text" id="username" name="username" maxlength="100" required autocomplete="username" value="<?= e_pengguna($formData['username']) ?>">
                </div>
                <div>
                    <label for="nama">Nama Lengkap <span aria-hidden="true">*</span></label>
                    <input type="text" id="nama" name="nama" maxlength="150" required value="<?= e_pengguna($formData['nama']) ?>">
                </div>
                <div>
                    <label for="nik">NIK <span aria-hidden="true">*</span></label>
                    <input type="text" id="nik" name="nik" inputmode="numeric" pattern="[0-9]{16}" minlength="16" maxlength="16" required value="<?= e_pengguna($formData['nik']) ?>">
                    <p class="hint">NIK harus terdiri dari 16 angka.</p>
                </div>
                <div>
                    <label for="divisi">Divisi <span aria-hidden="true">*</span></label>
                    <input type="text" id="divisi" name="divisi" maxlength="100" required value="<?= e_pengguna($formData['divisi']) ?>">
                </div>
                <div class="full">
                    <label for="status_kepegawaian">Status Kepegawaian <span aria-hidden="true">*</span></label>
                    <select id="status_kepegawaian" name="status_kepegawaian" required>
                        <option value="">-- Pilih Status --</option>
                        <?php if ($formData['status_kepegawaian'] !== '' && !in_array($formData['status_kepegawaian'], $statusPilihan, true)): ?>
                            <option value="<?= e_pengguna($formData['status_kepegawaian']) ?>" selected><?= e_pengguna($formData['status_kepegawaian']) ?> (status saat ini)</option>
                        <?php endif; ?>
                        <?php foreach ($statusPilihan as $pilihan): ?>
                            <option value="<?= e_pengguna($pilihan) ?>" <?= $formData['status_kepegawaian'] === $pilihan ? 'selected' : '' ?>><?= e_pengguna($pilihan) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="full section-label">Ubah Password (Opsional)</div>
                <div>
                    <label for="password_baru">Password Baru</label>
                    <input type="password" id="password_baru" name="password_baru" minlength="8" autocomplete="new-password">
                    <p class="hint">Minimal 8 karakter. Kosongkan jika tidak ingin mengganti password.</p>
                </div>
                <div>
                    <label for="konfirmasi_password">Konfirmasi Password Baru</label>
                    <input type="password" id="konfirmasi_password" name="konfirmasi_password" minlength="8" autocomplete="new-password">
                    <p class="hint">Isi sama dengan password baru jika password diubah.</p>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn"><i class="fas fa-save"></i> Simpan Perubahan</button>
                <a href="anggota.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
            </div>
        </form>
    </section>
</main>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuToggle = document.getElementById('menuToggle');
    const closeBtn = document.getElementById('closeSidebarBtn');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        if (menuToggle) menuToggle.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
    }

    if (menuToggle) menuToggle.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeSidebar();
    });
})();
</script>
</body>
</html>
