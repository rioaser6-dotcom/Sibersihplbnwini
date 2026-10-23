<?php
/**
 * Komponen sidebar bersama untuk seluruh halaman Pengguna.
 *
 * Set variabel $activeMenu sebelum include:
 * dashboard | buat_pengajuan | lihat_barang | riwayat | pesan
 */
$activeMenu = isset($activeMenu) ? (string) $activeMenu : '';

$jumlahPesanBelumDibaca = 0;

if (
    isset($pdo) &&
    $pdo instanceof PDO &&
    isset($_SESSION['user_id'])
) {
    $jumlahPesanBelumDibaca = hitungPesanBelumDibaca(
        $pdo,
        (int) $_SESSION['user_id']
    );
}

$menuPengguna = [
    [
        'key'   => 'dashboard',
        'label' => 'Dashboard',
        'href'  => 'dashboard.php',
        'icon'  => 'fa-chart-pie',
    ],
    [
        'key'   => 'buat_pengajuan',
        'label' => 'Buat Pengajuan',
        'href'  => 'buat_pengajuan.php',
        'icon'  => 'fa-file-signature',
    ],
    [
        'key'   => 'lihat_barang',
        'label' => 'Daftar Barang',
        'href'  => 'lihat_barang.php',
        'icon'  => 'fa-boxes',
    ],
    [
        'key'   => 'riwayat',
        'label' => 'Riwayat Pengajuan',
        'href'  => 'riwayat.php',
        'icon'  => 'fa-history',
    ],
    [
        'key'   => 'pesan',
        'label' => 'Pesan',
        'href'  => 'pesan.php',
        'icon'  => 'fa-envelope',
    ],
];
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <button
        type="button"
        class="sidebar-close"
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

    <?php foreach ($menuPengguna as $menu): ?>
        <a
            href="<?= htmlspecialchars($menu['href'], ENT_QUOTES, 'UTF-8') ?>"
            class="<?= $activeMenu === $menu['key'] ? 'active' : '' ?>"
        >
            <span class="icon"><i class="fas <?= htmlspecialchars($menu['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
            <span><?= htmlspecialchars($menu['label'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php if (
                $menu['key'] === 'pesan' &&
                $jumlahPesanBelumDibaca > 0
            ): ?>
                <span class="menu-badge" aria-label="<?= (int) $jumlahPesanBelumDibaca ?> pesan belum dibaca">
                    <?= $jumlahPesanBelumDibaca > 99 ? '99+' : (int) $jumlahPesanBelumDibaca ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>

    <a href="../logout.php" class="logout-link">
        <span class="icon"><i class="fas fa-right-from-bracket"></i></span>
        <span>Logout</span>
    </a>
</aside>

<script src="../includes/user_sidebar.js" defer></script>
