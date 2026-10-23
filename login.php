<?php

// =====================================================
// MULAI SESSION (hanya jika belum aktif)
// =====================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// =====================================================
// LOAD FILE
// =====================================================
require_once 'config/database.php';
require_once 'includes/fungsi.php';

// Token CSRF untuk form login.
$csrf_login = csrfToken('login');


// =====================================================
// CEK FILE BACKGROUND (otomatis menyesuaikan ekstensi)
// =====================================================
$bgImage = '';
if (file_exists('assets/images/gedung-plbn.jpg')) {
    $bgImage = 'assets/images/gedung-plbn.jpg';
} elseif (file_exists('assets/images/gedung-plbn.png')) {
    $bgImage = 'assets/images/gedung-plbn.png';
} else {
    // Jika tidak ada, gunakan warna solid sebagai fallback
    $bgImage = ''; 
}


// =====================================================
// PROSES LOGIN
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    // Ambil input
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Validasi token, input, lalu kredensial.
    if (!csrfValid('login', $token)) {
        flash('Sesi login tidak valid. Muat ulang halaman lalu coba kembali.', 'danger');
    } elseif ($username === '' || $password === '') {
        flash('Username dan password wajib diisi.', 'danger');
    } else {
        try {
            // Cari username
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // =================================================
            // CEK USER DAN PASSWORD
            // =================================================
            if ($user && password_verify($password, $user['password'])) {
                $role = (string) $user['role'];

                // Hanya role yang memiliki dashboard di aplikasi ini yang diizinkan.
                if (!in_array($role, ['admin', 'user'], true)) {
                    error_log('Login ditolak untuk role tidak didukung: ' . $role);
                    flash('Akun ini tidak memiliki akses ke sistem. Hubungi administrator.', 'danger');
                } else {
                    // Cegah session fixation.
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['role'] = $role;
                    $_SESSION['nama'] = $user['nama'];
                    $_SESSION['divisi'] = $user['divisi'] ?? '';
                    $_SESSION['status_kepegawaian'] = $user['status_kepegawaian'] ?? '';

                    // Token login tidak diperlukan lagi setelah berhasil.
                    csrfRotate('login');

                    if ($role === 'admin') {
                        header('Location: admin/dashboard.php');
                    } else {
                        header('Location: user/dashboard.php');
                    }
                    exit;
                }
            } else {
                flash('Username atau password salah.', 'danger');
            }

        } catch (PDOException $e) {
            error_log('Login gagal: ' . $e->getMessage());
            flash('Terjadi kesalahan pada sistem. Silakan coba kembali.', 'danger');
        }
    }
}

?>


<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PLBN Wini</title>

    <!-- BOOTSTRAP -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- FONT AWESOME -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- GOOGLE FONT -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            width: 100%;
            font-family: 'Inter', Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;

            /* BACKGROUND GEDUNG PLBN - DINAMIS */
            background-image: 
                linear-gradient(
                    rgba(0, 20, 40, 0.35),
                    rgba(0, 20, 40, 0.35)
                ),
                <?php if ($bgImage): ?>
                    url("<?= $bgImage; ?>");
                <?php else: ?>
                    none;
                    background-color: #1a2a3a;
                <?php endif; ?>

            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        /* OVERLAY */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, rgba(0,0,0,.10), rgba(0,60,120,.12));
            z-index: 1;
            pointer-events: none;
        }

        /* LOGIN WRAPPER */
        .login-wrapper {
            width: 100%;
            max-width: 370px;
            padding: 15px;
            position: relative;
            z-index: 5;
        }

        /* LOGIN CARD */
        .login-card {
            width: 100%;
            padding: 25px 27px 20px;
            border-radius: 18px;
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(255,255,255,.8);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 20px 55px rgba(0,0,0,.30);
        }

        /* HEADER */
        .login-header {
            text-align: center;
            margin-bottom: 18px;
        }

        /* LOGO */
        .logo-wrapper {
            width: 65px;
            height: 65px;
            margin: 0 auto 10px;
            border-radius: 15px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 5px 15px rgba(0,0,0,.12);
        }

        .logo-wrapper img {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }

        /* BADGE */
        .plbn-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 30px;
            background: #eff6ff;
            color: #2563eb;
            font-size: 9px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        /* TITLE */
        .login-title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 5px;
        }

        .login-title span {
            color: #2563eb;
        }

        /* SUBTITLE */
        .login-subtitle {
            color: #64748b;
            font-size: 10px;
            line-height: 1.5;
            max-width: 280px;
            margin: auto;
        }

        /* ALERT */
        .alert {
            font-size: 10px;
            padding: 8px 10px;
            border-radius: 8px;
            margin-bottom: 12px;
        }

        /* FORM GROUP */
        .form-group {
            margin-bottom: 13px;
        }

        /* LABEL */
        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 5px;
        }

        /* INPUT WRAPPER */
        .input-wrapper {
            position: relative;
        }

        /* ICON */
        .input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 12px;
            z-index: 2;
        }

        /* INPUT */
        .form-control {
            height: 43px;
            width: 100%;
            padding: 8px 38px 8px 37px;
            border-radius: 9px;
            border: 1px solid #dbe3ec;
            font-size: 11px;
        }

        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.10);
        }

        /* PASSWORD BUTTON */
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #64748b;
            cursor: pointer;
        }

        /* LOGIN BUTTON */
        .btn-login {
            width: 100%;
            height: 44px;
            border: none;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            color: white;
            font-size: 12px;
            font-weight: 700;
            background: linear-gradient(135deg, #075985, #2563eb, #4f46e5);
            box-shadow: 0 8px 20px rgba(37,99,235,.30);
            cursor: pointer;
            transition: .3s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(37,99,235,.40);
        }

        /* BACK HOME */
        .back-home {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            margin-top: 13px;
            color: #64748b;
            font-size: 10px;
            font-weight: 600;
            text-decoration: none;
        }

        .back-home:hover {
            color: #2563eb;
        }

        /* FOOTER */
        .login-footer {
            text-align: center;
            margin-top: 13px;
            padding-top: 11px;
            border-top: 1px solid #e2e8f0;
            color: #94a3b8;
            font-size: 8px;
            line-height: 1.5;
        }

        /* MOBILE */
        @media(max-width:576px) {
            .login-wrapper {
                max-width: 340px;
                padding: 12px;
            }
            .login-card {
                padding: 22px 20px 18px;
            }
        }
    </style>
</head>

<body>

<div class="login-wrapper">
    <div class="login-card">

        <!-- HEADER -->
        <div class="login-header">

            <!-- LOGO -->
            <div class="logo-wrapper">
                <?php if (file_exists('assets/images/logo-bnpp.png')): ?>
                    <img src="assets/images/logo-bnpp.png" alt="Logo BNPP">
                <?php elseif (file_exists('assets/images/logo.png')): ?>
                    <img src="assets/images/logo.png" alt="Logo PLBN Wini">
                <?php else: ?>
                    <i class="fas fa-building" style="font-size:28px; color:#2563eb;"></i>
                <?php endif; ?>
            </div>

            <div class="plbn-badge">
                <i class="fas fa-building-shield"></i> PLBN WINI
            </div>

            <h1 class="login-title">
                Selamat Datang <span>Kembali</span>
            </h1>

            <p class="login-subtitle">
                Sistem Informasi Manajemen Pengajuan dan Distribusi Perlengkapan PLBN Wini
            </p>
        </div>

        <!-- PESAN ERROR -->
        <?php showFlash(); ?>

        <!-- FORM -->
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_login, ENT_QUOTES, 'UTF-8') ?>">

            <!-- USERNAME -->
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" id="username" name="username" class="form-control"
                           placeholder="Masukkan username" autocomplete="username" required>
                </div>
            </div>

            <!-- PASSWORD -->
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" class="form-control"
                           placeholder="Masukkan password" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- LUPA PASSWORD: tambahan tanpa mengubah desain login yang sudah ada -->
            <div style="text-align:right; margin-top:-5px; margin-bottom:13px;">
                <a href="lupa_password.php"
                   style="font-size:10px; color:#2563eb; font-weight:600; text-decoration:none;">
                    Lupa Username & Password?
                </a>
            </div>

            <!-- LOGIN -->
            <button type="submit" class="btn-login">
                <i class="fas fa-right-to-bracket"></i> Login ke Sistem
            </button>
        </form>

        <!-- HOME -->
        <a href="index.php" class="back-home">
            <i class="fas fa-arrow-left"></i> Kembali ke Halaman Utama
        </a>

        <!-- FOOTER -->
        <div class="login-footer">
            <strong>SISTEM INFORMASI MANAJEMEN PLBN WINI</strong><br>
            Pengajuan & Distribusi Perlengkapan<br>
            © <?= date('Y'); ?> PLBN Wini
        </div>

    </div>
</div>

<script>
    /* =====================================================
       TOGGLE PASSWORD
    ===================================================== */
    const togglePassword = document.getElementById('togglePassword');
    const password = document.getElementById('password');

    togglePassword.addEventListener('click', function () {
        if (password.type === 'password') {
            password.type = 'text';
            this.innerHTML = '<i class="fas fa-eye-slash"></i>';
        } else {
            password.type = 'password';
            this.innerHTML = '<i class="fas fa-eye"></i>';
        }
    });
</script>

</body>
</html>