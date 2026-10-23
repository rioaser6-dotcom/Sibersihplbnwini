<?php
// ==========================================
// FILE: index.php
// LANDING PAGE - PLBN WINI
// ==========================================

require_once 'includes/fungsi.php';

// Jika sudah login, arahkan hanya ke dashboard yang benar-benar tersedia.
if (isLoggedIn()) {
    $role = (string) ($_SESSION['role'] ?? '');

    if ($role === 'admin') {
        redirect('admin/dashboard.php');
    }

    if ($role === 'user') {
        redirect('user/dashboard.php');
    }

    // Role lama/tidak didukung tidak boleh diarahkan ke folder yang tidak ada.
    unset(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['nama'],
        $_SESSION['divisi'],
        $_SESSION['status_kepegawaian']
    );

    flash('Sesi sebelumnya menggunakan role yang tidak didukung. Silakan login kembali.', 'danger');
    redirect('login.php');
}
?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>
        PLBN WINI - Sistem Manajemen Pengajuan & Distribusi
    </title>

    <!-- GOOGLE FONT -->
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect"
          href="https://fonts.gstatic.com"
          crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap"
          rel="stylesheet">

    <!-- FONT AWESOME -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">


<style>

/* =====================================================
   RESET
===================================================== */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {

    font-family: 'Inter',
                 -apple-system,
                 BlinkMacSystemFont,
                 "Segoe UI",
                 sans-serif;

    background: #f1f5f9;

    color: #1e293b;

    overflow-x: hidden;
}

a {
    text-decoration: none;
}


/* =====================================================
   NAVBAR
===================================================== */

.navbar {

    position: fixed;

    top: 0;
    left: 0;
    right: 0;

    height: 74px;

    z-index: 9999;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 0 50px;

    background: rgba(255,255,255,.94);

    backdrop-filter: blur(18px);

    -webkit-backdrop-filter: blur(18px);

    border-bottom: 1px solid rgba(0,0,0,.06);

    box-shadow:
        0 8px 30px rgba(15,23,42,.08);
}


/* =====================================================
   BRAND
===================================================== */

.brand {

    display: flex;

    align-items: center;

    gap: 12px;

    color: #1e293b;
}


/* LOGO BNPP */

.logo-bnpp {

    width: 46px;

    height: 46px;

    object-fit: contain;

    border-radius: 10px;

    background: white;

    padding: 3px;

    box-shadow:
        0 4px 12px rgba(0,0,0,.10);
}


/* FALLBACK LOGO */

.logo-fallback {

    display: none;

    width: 46px;

    height: 46px;

    border-radius: 10px;

    align-items: center;

    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #075985,
            #2563eb
        );

    color: white;

    font-weight: 800;

    font-size: 13px;
}


/* BRAND TEXT */

.brand-text {
    line-height: 1.2;
}

.brand-text strong {

    display: block;

    font-size: 17px;

    font-weight: 800;

    color: #0f172a;
}

.brand-text span {

    display: block;

    font-size: 9px;

    color: #64748b;

    margin-top: 3px;

    letter-spacing: .5px;
}


/* =====================================================
   NAV RIGHT
===================================================== */

.nav-right {

    display: flex;

    align-items: center;

    gap: 10px;
}

.nav-link {

    color: #475569;

    font-size: 13px;

    font-weight: 600;

    padding: 10px 16px;

    border-radius: 10px;

    transition: .3s;
}

.nav-link:hover {

    color: #2563eb;

    background: #eff6ff;
}


/* LOGIN BUTTON */

.btn-login {

    display: flex;

    align-items: center;

    gap: 8px;

    color: white;

    padding: 11px 22px;

    border-radius: 12px;

    font-size: 13px;

    font-weight: 700;

    background:
        linear-gradient(
            135deg,
            #075985,
            #2563eb,
            #4f46e5
        );

    box-shadow:
        0 8px 20px rgba(37,99,235,.25);

    transition: .3s;
}

.btn-login:hover {

    transform: translateY(-2px);

    box-shadow:
        0 12px 28px rgba(37,99,235,.35);

    color: white;
}


/* =====================================================
   HERO
===================================================== */

.hero {

    position: relative;

    min-height: 100vh;

    display: flex;

    align-items: center;

    padding:
        115px
        50px
        60px;

    overflow: hidden;

    color: white;

    /*
    =====================================================
    BACKGROUND FOTO GEDUNG PLBN WINI
    =====================================================
    */

    background-image:

        linear-gradient(
            90deg,
            rgba(2,15,30,0.55) 0%,
            rgba(2,15,30,0.35) 35%,
            rgba(2,15,30,0.15) 65%,
            rgba(2,15,30,0.25) 100%
        ),

        url("assets/images/plbn-wini.jpg");

    background-size: cover;

    background-position: center center;

    background-repeat: no-repeat;

    /* background-attachment: fixed;  dihapus agar tidak bermasalah */
}


/* LAPISAN WARNA */

.hero::before {

    content: "";

    position: absolute;

    inset: 0;

    background:
        radial-gradient(
            circle at 80% 20%,
            rgba(37,99,235,.25),
            transparent 35%
        );

    pointer-events: none;
}


/* ORNAMEN */

.hero::after {

    content: "";

    position: absolute;

    width: 550px;

    height: 550px;

    border-radius: 50%;

    border:
        1px solid rgba(255,255,255,.10);

    right: -200px;

    bottom: -220px;

    box-shadow:

        0 0 0 70px rgba(255,255,255,.025),

        0 0 0 140px rgba(255,255,255,.015);

    pointer-events: none;
}


/* =====================================================
   HERO CONTAINER
===================================================== */

.hero-container {

    width: 100%;

    max-width: 1250px;

    margin: auto;

    display: grid;

    grid-template-columns:
        1fr
        .85fr;

    align-items: center;

    gap: 55px;

    position: relative;

    z-index: 2;
}


/* =====================================================
   HERO TEXT
===================================================== */

.hero-text {

    animation:
        fadeLeft .9s ease forwards;
}


@keyframes fadeLeft {

    from {

        opacity: 0;

        transform:
            translateX(-40px);
    }

    to {

        opacity: 1;

        transform:
            translateX(0);
    }
}


/* BADGE */

.hero-badge {

    display: inline-flex;

    align-items: center;

    gap: 8px;

    padding: 8px 15px;

    border-radius: 50px;

    background:
        rgba(255,255,255,.12);

    border:
        1px solid rgba(255,255,255,.20);

    backdrop-filter: blur(10px);

    font-size: 11px;

    font-weight: 700;

    color: #dbeafe;

    margin-bottom: 18px;
}

.hero-badge i {

    color: #60a5fa;
}


/* =====================================================
   TITLE
===================================================== */

.hero-text h1 {

    font-size: 43px;

    line-height: 1.15;

    font-weight: 800;

    letter-spacing: -1.5px;

    margin-bottom: 18px;
}

.hero-text h1 span {

    background:
        linear-gradient(
            90deg,
            #60a5fa,
            #93c5fd,
            #ffffff
        );

    -webkit-background-clip: text;

    -webkit-text-fill-color: transparent;

    background-clip: text;
}


/* =====================================================
   DESCRIPTION
===================================================== */

.hero-description {

    max-width: 600px;

    color: #dbeafe;

    font-size: 14px;

    line-height: 1.8;

    margin-bottom: 22px;
}


/* =====================================================
   FEATURES
===================================================== */

.features {

    display: grid;

    grid-template-columns:
        repeat(2, max-content);

    gap: 10px 25px;

    margin-bottom: 26px;
}

.feature {

    display: flex;

    align-items: center;

    gap: 8px;

    color: #e2e8f0;

    font-size: 12px;

    font-weight: 500;
}

.feature i {

    width: 21px;

    height: 21px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background:
        rgba(34,197,94,.18);

    color: #4ade80;

    font-size: 9px;
}


/* =====================================================
   BUTTON
===================================================== */

.hero-buttons {

    display: flex;

    align-items: center;

    gap: 12px;

    flex-wrap: wrap;
}


.btn-primary {

    display: inline-flex;

    align-items: center;

    gap: 9px;

    padding: 13px 23px;

    border-radius: 11px;

    color: white;

    background:
        linear-gradient(
            135deg,
            #2563eb,
            #4f46e5
        );

    font-size: 12px;

    font-weight: 700;

    box-shadow:
        0 10px 25px rgba(37,99,235,.35);

    transition: .3s;
}

.btn-primary:hover {

    transform:
        translateY(-3px);

    color: white;

    box-shadow:
        0 15px 30px rgba(37,99,235,.45);
}


.btn-secondary {

    display: inline-flex;

    align-items: center;

    gap: 9px;

    padding: 13px 21px;

    border-radius: 11px;

    color: white;

    background:
        rgba(255,255,255,.10);

    border:
        1px solid rgba(255,255,255,.25);

    backdrop-filter: blur(10px);

    font-size: 12px;

    font-weight: 600;

    transition: .3s;
}

.btn-secondary:hover {

    background:
        rgba(255,255,255,.18);

    transform:
        translateY(-3px);

    color: white;
}


/* =====================================================
   FOTO GEDUNG PLBN WINI
===================================================== */

.hero-image {

    animation:
        fadeRight .9s ease forwards;

    width: 100%;

    max-width: 500px;

    margin-left: auto;
}


@keyframes fadeRight {

    from {

        opacity: 0;

        transform:
            translateX(40px);
    }

    to {

        opacity: 1;

        transform:
            translateX(0);
    }
}


/* CARD FOTO */

.image-card {

    position: relative;

    border-radius: 22px;

    padding: 7px;

    background:
        linear-gradient(
            135deg,
            rgba(255,255,255,.40),
            rgba(255,255,255,.08)
        );

    border:
        1px solid rgba(255,255,255,.30);

    backdrop-filter: blur(10px);

    box-shadow:
        0 25px 60px rgba(0,0,0,.35);
}


/* =====================================================
   BAGIAN FOTO
===================================================== */

.image-inner {

    position: relative;

    overflow: hidden;

    border-radius: 17px;

    background: #0f172a;

    display: flex;

    align-items: center;

    justify-content: center;
}


/* FOTO PLBN - PERBAIKAN AGAR TIDAK TERPOTONG */

.image-inner img {

    width: 100%;

    height: auto;          /* proporsional */

    display: block;

    object-fit: contain;   /* tampil semua */

    /* max-height: 350px;  dihapus */

    transition:
        transform .7s ease;
}


/*
Saat mouse diarahkan ke foto,
zoom hanya sedikit
*/

.image-inner:hover img {

    transform:
        scale(1.02);
}


/* =====================================================
   OVERLAY FOTO
===================================================== */

.image-inner::after {

    content: "";

    position: absolute;

    inset: 0;

    background:
        linear-gradient(
            180deg,
            transparent 55%,
            rgba(0,0,0,.75)
        );

    pointer-events: none;
}


/* =====================================================
   STATUS SISTEM
===================================================== */

.available {

    position: absolute;

    z-index: 5;

    top: 15px;

    right: 15px;

    display: flex;

    align-items: center;

    gap: 8px;

    padding: 7px 13px;

    border-radius: 50px;

    color: white;

    background:
        rgba(22,163,74,.90);

    backdrop-filter: blur(10px);

    font-size: 10px;

    font-weight: 700;

    box-shadow:
        0 7px 20px rgba(0,0,0,.20);
}


.available-dot {

    width: 7px;

    height: 7px;

    border-radius: 50%;

    background: #bbf7d0;

    box-shadow:
        0 0 0 4px rgba(187,247,208,.18);
}


/* =====================================================
   INFO DI ATAS FOTO
===================================================== */

.image-info {

    position: absolute;

    left: 22px;

    right: 22px;

    bottom: 18px;

    z-index: 5;

    color: white;
}


.image-info h3 {

    font-size: 17px;

    font-weight: 700;

    margin-bottom: 4px;
}


.image-info p {

    font-size: 10px;

    color: #cbd5e1;

    line-height: 1.5;
}


/* =====================================================
   FLOATING CARD
===================================================== */

.floating-card {

    position: absolute;

    left: -25px;

    bottom: 25px;

    z-index: 10;

    display: flex;

    align-items: center;

    gap: 10px;

    padding: 11px 15px;

    border-radius: 13px;

    background:
        rgba(255,255,255,.96);

    color: #1e293b;

    box-shadow:
        0 15px 40px rgba(0,0,0,.22);

    animation:
        floating 3s ease-in-out infinite;
}


@keyframes floating {

    0%,100% {

        transform:
            translateY(0);
    }

    50% {

        transform:
            translateY(-7px);
    }
}


.floating-icon {

    width: 36px;

    height: 36px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 10px;

    color: white;

    background:
        linear-gradient(
            135deg,
            #2563eb,
            #4f46e5
        );
}


.floating-text strong {

    display: block;

    font-size: 11px;
}

.floating-text span {

    font-size: 9px;

    color: #64748b;
}


/* =====================================================
   INFORMASI SISTEM
===================================================== */

.quick-section {

    background: #f8fafc;

    padding: 60px 50px;
}


.quick-container {

    max-width: 1150px;

    margin: auto;

    display: grid;

    grid-template-columns:
        repeat(4,1fr);

    gap: 18px;
}


.quick-card {

    background: white;

    border-radius: 17px;

    padding: 22px;

    border:
        1px solid #e2e8f0;

    box-shadow:
        0 8px 25px rgba(15,23,42,.05);

    transition: .3s;
}


.quick-card:hover {

    transform:
        translateY(-5px);

    box-shadow:
        0 15px 35px rgba(15,23,42,.09);
}


.quick-icon {

    width: 43px;

    height: 43px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 11px;

    background: #eff6ff;

    color: #2563eb;

    margin-bottom: 14px;

    font-size: 17px;
}


.quick-card h3 {

    font-size: 13px;

    color: #1e293b;

    margin-bottom: 6px;
}


.quick-card p {

    color: #64748b;

    font-size: 10px;

    line-height: 1.6;
}


/* =====================================================
   FOOTER
===================================================== */

.footer {

    background: #0f172a;

    color: #94a3b8;

    padding: 28px 20px;

    text-align: center;

    font-size: 10px;
}


.footer strong {

    color: white;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media(max-width:1100px) {

    .hero-container {

        gap: 35px;
    }

    .hero-text h1 {

        font-size: 37px;
    }

    .image-inner img {

        /* max-height: 320px;  dihapus */
    }
}


@media(max-width:900px) {

    .navbar {

        padding: 0 25px;
    }

    .hero {

        padding:
            110px
            25px
            55px;

        /* background-attachment: scroll;  tidak perlu */
    }

    .hero-container {

        grid-template-columns: 1fr;

        text-align: center;

        gap: 40px;
    }

    .hero-description {

        margin-left: auto;

        margin-right: auto;
    }

    .features {

        justify-content: center;
    }

    .hero-buttons {

        justify-content: center;
    }

    .hero-image {

        max-width: 600px;

        margin: auto;
    }

    .image-inner img {

        /* max-height: 350px;  dihapus */
    }

    .floating-card {

        left: 15px;
    }

    .quick-section {

        padding: 50px 25px;
    }

    .quick-container {

        grid-template-columns:
            repeat(2,1fr);
    }
}


@media(max-width:600px) {

    .navbar {

        height: 68px;

        padding: 0 15px;
    }

    .logo-bnpp,
    .logo-fallback {

        width: 40px;

        height: 40px;
    }

    .brand-text strong {

        font-size: 14px;
    }

    .brand-text span {

        font-size: 8px;
    }

    .nav-link {

        display: none;
    }

    .btn-login {

        padding: 9px 14px;

        font-size: 11px;
    }

    .hero {

        padding:
            100px
            18px
            45px;
    }

    .hero-text h1 {

        font-size: 28px;

        letter-spacing: -.8px;
    }

    .hero-description {

        font-size: 12px;

        line-height: 1.7;
    }

    .features {

        grid-template-columns: 1fr;

        justify-items: start;

        text-align: left;

        width: fit-content;

        margin-left: auto;

        margin-right: auto;
    }

    .hero-buttons {

        flex-direction: column;

        width: 100%;
    }

    .btn-primary,
    .btn-secondary {

        width: 100%;

        justify-content: center;
    }

    .image-inner img {

        /* max-height: 280px;  dihapus */
        width: 100%;

        object-fit: contain;
    }

    .floating-card {

        display: none;
    }

    .image-info {

        left: 15px;

        right: 15px;

        bottom: 15px;
    }

    .image-info h3 {

        font-size: 14px;
    }

    .image-info p {

        font-size: 8px;
    }

    .quick-container {

        grid-template-columns: 1fr;
    }
}


@media(max-width:380px) {

    .brand-text {

        display: none;
    }

    .hero-text h1 {

        font-size: 24px;
    }

    .image-inner img {

        /* max-height: 230px;  dihapus */
    }
}

</style>

</head>


<body>


<!-- =====================================================
     NAVBAR
===================================================== -->

<nav class="navbar">

    <a href="index.php" class="brand">

        <!-- LOGO BNPP -->

        <img
            src="assets/images/logo-bnpp.png"
            alt="Logo BNPP"
            class="logo-bnpp"

            onerror="
                this.style.display='none';
                document.getElementById('logoFallback').style.display='flex';
            "
        >

        <div
            id="logoFallback"
            class="logo-fallback"
        >
            BNPP
        </div>


        <div class="brand-text">

            <strong>
                BADAN NASIONAL PENGELOLA PERBATASAN BNPP-RI
            </strong>

            <span>
                POS LINTAS BATAS NEGARA (PLBN) WINI
            </span>

        </div>

    </a>


    <div class="nav-right">

        <a
            href="#informasi"
            class="nav-link"
        >
            Tentang Sistem
        </a>


        <a
            href="login.php"
            class="btn-login"
        >

            <i class="fas fa-right-to-bracket"></i>

            Login

        </a>

    </div>

</nav>


<!-- =====================================================
     HERO
===================================================== -->

<section class="hero">

    <div class="hero-container">


        <!-- =================================================
             TEXT
        ================================================== -->

        <div class="hero-text">


            <div class="hero-badge">

                <i class="fas fa-building-shield"></i>

                POS LINTAS BATAS NEGARA WINI

            </div>


            <h1>

                Sistem Informasi

                <span>
                    Pengajuan &amp; Distribusi
                </span>

                Barang Kebersihan

                <span>
                    (SIBERSIH)
                </span>

                PLBN WINI

            </h1>
            
            
            <p class="hero-description">
                Mendukung operasional yang lebih efisien
                dan terorganisir melalui sistem informasi
                terintegrasi untuk mengelola pengajuan,
                persediaan, distribusi, serta pemantauan
                perlengkapan di lingkungan PLBN Wini.
            </p>


            <!-- FEATURES -->

            <div class="features">

                <div class="feature">

                    <i class="fas fa-check"></i>

                    Pengajuan Online

                </div>


                <div class="feature">

                    <i class="fas fa-check"></i>

                    Manajemen Perlengkapan

                </div>


                <div class="feature">

                    <i class="fas fa-check"></i>

                    Tracking Distribusi

                </div>


                <div class="feature">

                    <i class="fas fa-check"></i>

                    Laporan Terintegrasi

                </div>

            </div>


            <!-- BUTTON - PERBAIKAN -->
            <div class="hero-buttons">
                <a href="login.php" class="btn-primary">
                    <i class="fas fa-right-to-bracket"></i>
                    Masuk ke Sistem
                </a>
                <a
                    href="#informasi"
                    class="btn-secondary"
                >

                    <i class="fas fa-circle-info"></i>

                    Pelajari Sistem

                </a>

            </div>


        </div>


        <!-- =================================================
             FOTO PLBN WINI
        ================================================== -->

        <div class="hero-image">


            <div class="image-card">


                <div class="image-inner">


                    <!-- STATUS -->

                    <div class="available">

                        <span class="available-dot"></span>

                        Sistem Aktif

                    </div>


                    <!-- FOTO GEDUNG -->

                    <img
                        src="assets/images/plbn-wini.jpg"
                        alt="Gedung PLBN Wini"

                        onerror="
                            this.onerror=null;
                            this.src='assets/img/plbn-wini.png';
                        "
                    >


                    <!-- INFORMASI FOTO -->

                    <div class="image-info">

                        <h3>

                            <i class="fas fa-location-dot"></i>

                            PLBN Wini

                        </h3>

                        <p>

                            Pos Lintas Batas Negara Wini,
                            Kabupaten Timor Tengah Utara,
                            Nusa Tenggara Timur.

                        </p>

                    </div>


                </div>

            </div>

        </div>

    </div>

</section>


<!-- =====================================================
     INFORMASI SISTEM
===================================================== -->

<section
    class="quick-section"
    id="informasi"
>

    <div class="quick-container">


        <!-- CARD 1 -->

        <div class="quick-card">

            <div class="quick-icon">

                <i class="fas fa-file-signature"></i>

            </div>

            <h3>
                Pengajuan Online
            </h3>

            <p>

                Pengguna dapat melakukan pengajuan
                perlengkapan secara mudah dan
                terstruktur melalui sistem.

            </p>

        </div>


        <!-- CARD 2 -->

        <div class="quick-card">

            <div class="quick-icon">

                <i class="fas fa-boxes-stacked"></i>

            </div>

            <h3>
                Manajemen Barang
            </h3>

            <p>

                Membantu pengelolaan data perlengkapan
                dan informasi ketersediaan barang.

            </p>

        </div>


        <!-- CARD 3 -->

        <div class="quick-card">

            <div class="quick-icon">

                <i class="fas fa-truck-fast"></i>

            </div>

            <h3>
                Distribusi
            </h3>

            <p>

                Mempermudah pencatatan dan pemantauan
                proses distribusi perlengkapan kepada
                pengguna.

            </p>

        </div>


        <!-- CARD 4 -->

        <div class="quick-card">

            <div class="quick-icon">

                <i class="fas fa-chart-column"></i>

            </div>

            <h3>
                Laporan
            </h3>

            <p>

                Menyediakan informasi laporan pengajuan
                dan distribusi untuk mendukung proses
                pengawasan dan evaluasi.

            </p>

        </div>


    </div>

</section>


<!-- =====================================================
     FOOTER
===================================================== -->

<footer class="footer">

    <strong>
        PLBN WINI
    </strong>

    <br>

    Sistem Informasi Manajemen Pengajuan &
    Distribusi Barang Kebersihan PLBN Wini

    <br><br>

    © <?= date('Y') ?> PLBN Wini.

</footer>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

document.querySelectorAll(
    'a[href^="#"]'
).forEach(function(anchor) {

    anchor.addEventListener(
        'click',
        function(e) {

            const target =
                document.querySelector(
                    this.getAttribute('href')
                );

            if (target) {

                e.preventDefault();

                target.scrollIntoView({
                    behavior: 'smooth'
                });

            }

        }
    );

});

</script>


</body>

</html>