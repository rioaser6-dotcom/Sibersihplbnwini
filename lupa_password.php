
<?php
// =====================================================
// PEMULIHAN USERNAME & PASSWORD - PLBN WINI
// Database: db_plbn_wini
// Tabel: users
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

// Token CSRF
if (empty($_SESSION['csrf_pemulihan'])) {
    $_SESSION['csrf_pemulihan'] = bin2hex(random_bytes(32));
}

// Kembali ke tahap awal
if (isset($_GET['ulang'])) {
    unset(
        $_SESSION['pemulihan_mode'],
        $_SESSION['pemulihan_stage'],
        $_SESSION['pemulihan_user_id'],
        $_SESSION['pemulihan_username']
    );

    header('Location: lupa_password.php');
    exit;
}

$stage = $_SESSION['pemulihan_stage'] ?? 'pilih';
$mode = $_SESSION['pemulihan_mode'] ?? '';
$error = '';
$success = '';
$nik = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        empty($_SESSION['csrf_pemulihan']) ||
        !is_string($_SESSION['csrf_pemulihan']) ||
        !hash_equals($_SESSION['csrf_pemulihan'], $token)
    ) {
        $error = 'Permintaan tidak valid. Muat ulang halaman.';
    } else {
        $aksi = $_POST['aksi'] ?? '';

        // ---------------------------------------------
        // TAHAP 1: PILIH LUPA USERNAME ATAU PASSWORD
        // ---------------------------------------------
        if ($aksi === 'pilih') {
            $pilihan = $_POST['mode'] ?? '';

            if (!in_array($pilihan, ['username', 'password'], true)) {
                $error = 'Silakan pilih layanan pemulihan.';
            } else {
                $_SESSION['pemulihan_mode'] = $pilihan;
                $_SESSION['pemulihan_stage'] = 'verifikasi';

                header('Location: lupa_password.php');
                exit;
            }
        }

        // ---------------------------------------------
        // TAHAP 2: VERIFIKASI NIK DAN PERSETUJUAN
        // ---------------------------------------------
        elseif (
            $aksi === 'verifikasi' &&
            $stage === 'verifikasi'
        ) {
            $nik = trim($_POST['nik'] ?? '');
            $persetujuan = $_POST['persetujuan'] ?? '';

            if (!preg_match('/^[0-9]{16}$/', $nik)) {
                $error = 'NIK harus terdiri dari 16 angka.';
            } elseif ($persetujuan !== 'setuju') {
                $error = 'Anda wajib menyetujui pernyataan tanggung jawab.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT id, username
                         FROM users
                         WHERE nik = ?
                         LIMIT 1'
                    );

                    $stmt->execute([$nik]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$user) {
                        $error = 'NIK tidak ditemukan. Periksa kembali NIK Anda.';
                    } elseif ($mode === 'username') {
                        $_SESSION['pemulihan_username'] =
                            $user['username'];

                        $_SESSION['pemulihan_stage'] = 'hasil_username';

                        header('Location: lupa_password.php');
                        exit;
                    } elseif ($mode === 'password') {
                        $_SESSION['pemulihan_user_id'] =
                            (int) $user['id'];

                        $_SESSION['pemulihan_stage'] = 'reset';

                        header('Location: lupa_password.php');
                        exit;
                    } else {
                        $error = 'Pilihan pemulihan tidak valid.';
                    }
                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    $error = 'Terjadi kesalahan saat memeriksa NIK.';
                }
            }
        }

        // ---------------------------------------------
        // TAHAP 3: SIMPAN PASSWORD BARU
        // ---------------------------------------------
        elseif (
            $aksi === 'reset' &&
            $stage === 'reset' &&
            $mode === 'password' &&
            !empty($_SESSION['pemulihan_user_id'])
        ) {
            $passwordBaru = $_POST['password_baru'] ?? '';
            $konfirmasi = $_POST['konfirmasi_password'] ?? '';

            if (
                !is_string($passwordBaru) ||
                !is_string($konfirmasi) ||
                $passwordBaru === '' ||
                $konfirmasi === ''
            ) {
                $error = 'Semua kolom password wajib diisi.';
            } elseif (strlen($passwordBaru) < 8 || strlen($passwordBaru) > 72) {
                $error = 'Password harus terdiri dari 8 sampai 72 karakter.';
            } elseif ($passwordBaru !== $konfirmasi) {
                $error = 'Konfirmasi password tidak cocok.';
            } else {
                try {
                    $hash = password_hash(
                        $passwordBaru,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $pdo->prepare(
                        'UPDATE users SET password = ? WHERE id = ?'
                    );

                    $stmt->execute([
                        $hash,
                        $_SESSION['pemulihan_user_id']
                    ]);

                    // Hapus data pemulihan setelah berhasil.
                    unset(
                        $_SESSION['pemulihan_mode'],
                        $_SESSION['pemulihan_stage'],
                        $_SESSION['pemulihan_user_id'],
                        $_SESSION['pemulihan_username']
                    );

                    // Perbarui token CSRF.
                    $_SESSION['csrf_pemulihan'] =
                        bin2hex(random_bytes(32));

                    // Hapus token pemulihan lama yang mungkin
                    // dipakai oleh form lain.
                    session_regenerate_id(true);

                    $success =
                        'Password berhasil diubah. Silakan login menggunakan password baru.';
                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    $error = 'Terjadi kesalahan saat menyimpan password.';
                }
            }
        } else {
            $error = 'Tahap pemulihan tidak valid. Silakan mulai kembali.';
        }
    }
}

// Perbarui tahap setelah pemrosesan POST
$stage = $_SESSION['pemulihan_stage'] ?? 'pilih';
$mode = $_SESSION['pemulihan_mode'] ?? '';

$bgImage = file_exists('assets/images/gedung-plbn.jpg')
    ? 'assets/images/gedung-plbn.jpg'
    : (file_exists('assets/images/gedung-plbn.png')
        ? 'assets/images/gedung-plbn.png'
        : '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Pemulihan Akun - PLBN Wini</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
          rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 0;
            font-family: 'Inter', Arial, sans-serif;
            background-color: #1a2a3a;
            background-image:
                linear-gradient(rgba(0,20,40,.45), rgba(0,20,40,.45))
                <?php if ($bgImage !== ''): ?>
                , url("<?= htmlspecialchars($bgImage, ENT_QUOTES, 'UTF-8') ?>")
                <?php endif; ?>;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .wrapper {
            width: 100%;
            max-width: 410px;
            padding: 15px;
            position: relative;
            z-index: 1;
        }

        .card-auth {
            padding: 27px;
            border-radius: 18px;
            background: rgba(255,255,255,.95);
            border: 1px solid rgba(255,255,255,.8);
            box-shadow: 0 20px 55px rgba(0,0,0,.30);
            backdrop-filter: blur(12px);
        }

        .header {
            text-align: center;
            margin-bottom: 22px;
        }

        .logo {
            width: 65px;
            height: 65px;
            margin: 0 auto 12px;
            border-radius: 15px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 5px 15px rgba(0,0,0,.12);
        }

        .logo img {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }

        .badge-plbn {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 30px;
            background: #eff6ff;
            color: #2563eb;
            font-size: 9px;
            font-weight: 800;
            margin-bottom: 9px;
        }

        .title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 7px;
        }

        .title span {
            color: #2563eb;
        }

        .subtitle {
            color: #64748b;
            font-size: 11px;
            line-height: 1.6;
        }

        .form-label {
            font-size: 11px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-control {
            min-height: 44px;
            border-radius: 9px;
            border: 1px solid #dbe3ec;
            font-size: 12px;
        }

        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.10);
        }

        .option {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 14px;
            margin-bottom: 12px;
            border: 1px solid #dbe3ec;
            border-radius: 11px;
            cursor: pointer;
            transition: .2s;
        }

        .option:hover {
            border-color: #2563eb;
            background: #f8fbff;
        }

        .option input {
            width: 17px;
            height: 17px;
            accent-color: #2563eb;
            flex-shrink: 0;
        }

        .option i {
            color: #2563eb;
            font-size: 20px;
            width: 24px;
            text-align: center;
        }

        .option strong {
            display: block;
            font-size: 12px;
            color: #0f172a;
        }

        .option small {
            display: block;
            font-size: 10px;
            color: #64748b;
            margin-top: 3px;
        }

        .consent {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            padding: 12px;
            margin: 16px 0;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            color: #475569;
            font-size: 10px;
            line-height: 1.7;
        }

        .consent input {
            margin-top: 3px;
            width: 16px;
            height: 16px;
            accent-color: #2563eb;
            flex-shrink: 0;
        }

        .btn-main {
            width: 100%;
            min-height: 44px;
            padding: 10px;
            border: none;
            border-radius: 9px;
            background: linear-gradient(135deg, #075985, #2563eb, #4f46e5);
            color: white;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(37,99,235,.25);
            transition: .2s;
        }

        .btn-main:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(37,99,235,.35);
        }

        .alert {
            font-size: 11px;
            border-radius: 9px;
            overflow-wrap: anywhere;
        }

        .result-box {
            padding: 16px;
            text-align: center;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            margin-bottom: 17px;
        }

        .result-box .username {
            display: block;
            margin-top: 8px;
            color: #1d4ed8;
            font-size: 17px;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .back-link {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            margin-top: 16px;
            color: #64748b;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
        }

        .back-link:hover {
            color: #2563eb;
        }

        .footer {
            margin-top: 17px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            color: #94a3b8;
            font-size: 9px;
            line-height: 1.7;
            text-align: center;
        }

        @media (max-width: 576px) {
            .wrapper {
                max-width: 350px;
                padding: 12px;
            }

            .card-auth {
                padding: 22px 20px;
            }
        }
    </style>
</head>

<body>
<div class="wrapper">
    <div class="card-auth">

        <div class="header">
            <div class="logo">
                <?php if (file_exists('assets/images/logo-bnpp.png')): ?>
                    <img src="assets/images/logo-bnpp.png" alt="Logo BNPP">
                <?php elseif (file_exists('assets/images/logo.png')): ?>
                    <img src="assets/images/logo.png" alt="Logo PLBN Wini">
                <?php else: ?>
                    <i class="fas fa-building"
                       style="font-size:28px;color:#2563eb;"></i>
                <?php endif; ?>
            </div>

            <div class="badge-plbn">
                <i class="fas fa-building-shield"></i> PLBN WINI
            </div>

            <h1 class="title">
                Pemulihan <span>Akun</span>
            </h1>

            <p class="subtitle">
                Sistem Informasi Manajemen Pengajuan dan Distribusi
                Perlengkapan PLBN Wini
            </p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="alert">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <a href="login.php" class="btn btn-success w-100">
                <i class="fas fa-right-to-bracket"></i>
                Kembali ke Login
            </a>

        <?php elseif ($stage === 'pilih'): ?>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_pemulihan'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="aksi" value="pilih">

                <p class="form-label mb-3">
                    Pilih layanan yang Anda butuhkan:
                </p>

                <label class="option">
                    <input type="radio" name="mode" value="username" required>
                    <i class="fas fa-user"></i>
                    <span>
                        <strong>Lupa Username</strong>
                        <small>Temukan username menggunakan NIK terdaftar.</small>
                    </span>
                </label>

                <label class="option">
                    <input type="radio" name="mode" value="password" required>
                    <i class="fas fa-lock"></i>
                    <span>
                        <strong>Lupa Password</strong>
                        <small>Verifikasi NIK untuk membuat password baru.</small>
                    </span>
                </label>

                <button type="submit" class="btn-main">
                    Lanjutkan <i class="fas fa-arrow-right ms-1"></i>
                </button>
            </form>

        <?php elseif ($stage === 'verifikasi'): ?>

            <p class="subtitle mb-3">
                Layanan yang dipilih:
                <strong>
                    <?= $mode === 'username' ? 'Lupa Username' : 'Lupa Password' ?>
                </strong>
            </p>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_pemulihan'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="aksi" value="verifikasi">

                <div class="form-group">
                    <label class="form-label" for="nik">
                        Nomor Induk Kependudukan (NIK)
                    </label>
                    <input
                        type="text"
                        id="nik"
                        name="nik"
                        class="form-control"
                        placeholder="Masukkan 16 digit NIK"
                        inputmode="numeric"
                        pattern="[0-9]{16}"
                        maxlength="16"
                        minlength="16"
                        value="<?= htmlspecialchars($nik, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <label class="consent">
                    <input type="checkbox"
                           name="persetujuan"
                           value="setuju"
                           required>
                    <span>
                        Saya menyatakan bahwa saya melakukan pemulihan
                        username atau reset password untuk akun saya
                        sendiri dan saya bersedia bertanggung jawab
                        apabila menyalahgunakan layanan ini.
                    </span>
                </label>

                <button type="submit" class="btn-main">
                    <i class="fas fa-shield-halved me-1"></i>
                    Verifikasi
                </button>
            </form>

        <?php elseif ($stage === 'hasil_username' && $mode === 'username'): ?>

            <div class="result-box">
                <i class="fas fa-circle-check fa-2x text-primary"></i>
                <p class="mt-2 mb-0" style="font-size:12px;">
                    Username akun Anda
                </p>
                <span class="username">
                    <?= htmlspecialchars(
                        $_SESSION['pemulihan_username'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>
            </div>

            <p class="subtitle text-center">
                Simpan username Anda dengan aman.
            </p>

            <a href="login.php" class="btn-main d-flex align-items-center justify-content-center text-decoration-none">
                <i class="fas fa-right-to-bracket me-2"></i>
                Kembali ke Login
            </a>

        <?php elseif ($stage === 'reset' && $mode === 'password'): ?>

            <p class="subtitle mb-3">
                NIK berhasil diverifikasi. Silakan buat password baru.
            </p>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_pemulihan'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="aksi" value="reset">

                <div class="form-group">
                    <label class="form-label" for="password_baru">
                        Password Baru
                    </label>
                    <input
                        type="password"
                        id="password_baru"
                        name="password_baru"
                        class="form-control"
                        placeholder="Minimal 8 karakter"
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="konfirmasi_password">
                        Konfirmasi Password Baru
                    </label>
                    <input
                        type="password"
                        id="konfirmasi_password"
                        name="konfirmasi_password"
                        class="form-control"
                        placeholder="Ulangi password baru"
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit" class="btn-main">
                    <i class="fas fa-key me-1"></i>
                    Simpan Password Baru
                </button>
            </form>

        <?php else: ?>

            <div class="alert alert-warning">
                Sesi pemulihan tidak valid atau sudah berakhir.
                Silakan mulai kembali.
            </div>

        <?php endif; ?>

        <?php if ($success === '' && $stage !== 'pilih'): ?>
            <a href="lupa_password.php?ulang=1" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Mulai Pemulihan dari Awal
            </a>
        <?php elseif ($success === ''): ?>
            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Kembali ke Halaman Login
            </a>
        <?php endif; ?>

        <div class="footer">
            <strong>SISTEM INFORMASI MANAJEMEN PLBN WINI</strong><br>
            Pengajuan &amp; Distribusi Perlengkapan<br>
            &copy; <?= date('Y') ?> PLBN Wini
        </div>

    </div>
</div>
</body>
</html>
