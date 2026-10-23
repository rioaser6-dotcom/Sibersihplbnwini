<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

// Hanya administrator yang boleh menghapus anggota.
if (!isLoggedIn() || !isRole('admin')) {
    redirect('../login.php');
}

// Operasi hapus wajib menggunakan POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('anggota.php?pesan=permintaan_tidak_valid');
}

$token = $_POST['csrf_token'] ?? '';

if (
    !is_string($token) ||
    empty($_SESSION['csrf_hapus_anggota']) ||
    !is_string($_SESSION['csrf_hapus_anggota']) ||
    !hash_equals($_SESSION['csrf_hapus_anggota'], $token)
) {
    redirect('anggota.php?pesan=permintaan_tidak_valid');
}

$id = filter_var(
    $_POST['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$id) {
    redirect('anggota.php?pesan=anggota_tidak_ditemukan');
}

try {
    $pdo->beginTransaction();

    // Kunci baris agar tidak ada proses lain yang menghapus bersamaan.
    $stmt = $pdo->prepare(
        "SELECT id
         FROM users
         WHERE id = ? AND role = 'user'
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([$id]);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        redirect('anggota.php?pesan=anggota_tidak_ditemukan');
    }

    // Jangan hapus anggota yang memiliki riwayat pengajuan.
    $cek = $pdo->prepare("SELECT COUNT(*) FROM pengajuan WHERE user_id = ?");
    $cek->execute([$id]);

    if ((int) $cek->fetchColumn() > 0) {
        $pdo->rollBack();
        redirect('anggota.php?pesan=hapus_gagal_relasi');
    }

    $hapus = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
    $hapus->execute([$id]);

    if ($hapus->rowCount() !== 1) {
        $pdo->rollBack();
        redirect('anggota.php?pesan=hapus_gagal');
    }

    $pdo->commit();

    // Token sekali pakai setelah operasi berhasil.
    unset($_SESSION['csrf_hapus_anggota']);

    redirect('anggota.php?pesan=hapus_berhasil');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Gagal menghapus anggota #' . $id . ': ' . $e->getMessage());
    redirect('anggota.php?pesan=hapus_gagal');
}
?>
