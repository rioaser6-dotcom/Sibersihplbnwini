<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

// ===== HANYA ADMIN YANG BOLEH AKSES =====
if (!isLoggedIn() || !isRole('admin')) {
    redirect('../login.php');
}

// ===== AMBIL FLASH MESSAGE =====
$flash = getFlash();

// ===== TOKEN CSRF =====
$csrf_barang = csrfToken('barang');

// ===== FOLDER UPLOAD GAMBAR =====
$upload_dir = '../uploads/barang/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ===== FUNGSI UPLOAD GAMBAR =====
function uploadGambar($file) {
    global $upload_dir;

    // Ekstensi ditentukan dari MIME, bukan dari nama file pengguna.
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
    ];
    $max_size = 2 * 1024 * 1024; // 2 MB

    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        flash('Gambar gagal diunggah. Silakan pilih file lain.', 'danger');
        return false;
    }

    if (
        ($file['size'] ?? 0) <= 0 ||
        $file['size'] > $max_size
    ) {
        flash('Ukuran gambar maksimal 2 MB.', 'danger');
        return false;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        flash('File yang dikirim tidak valid.', 'danger');
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!is_string($mime) || !isset($allowed[$mime])) {
        flash('Hanya file JPG, PNG, atau GIF yang diperbolehkan.', 'danger');
        return false;
    }

    $new_name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dest = $upload_dir . $new_name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        flash('Gagal menyimpan gambar. Periksa izin folder upload.', 'danger');
        return false;
    }

    return $new_name;
}

function hapusGambarBarang($namaFile) {
    global $upload_dir;

    $namaFile = basename((string) $namaFile);

    if ($namaFile !== '' && is_file($upload_dir . $namaFile)) {
        @unlink($upload_dir . $namaFile);
    }
}

function validasiBarang($nama, $satuan, $stok) {
    if ($nama === '' || $satuan === '' || $stok === '') {
        return 'Nama barang, satuan, dan stok wajib diisi.';
    }

    if (mb_strlen($nama) > 150) {
        return 'Nama barang maksimal 150 karakter.';
    }

    if (mb_strlen($satuan) > 20) {
        return 'Satuan maksimal 20 karakter.';
    }

    if (!preg_match('/^\d+$/', (string) $stok)) {
        return 'Stok harus berupa bilangan bulat 0 atau lebih.';
    }

    return null;
}

function jenisBarangAda(PDO $pdo, int $jenisId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM jenis_barang WHERE id = ?");
    $stmt->execute([$jenisId]);

    return (int) $stmt->fetchColumn() > 0;
}

// ===== TAMBAH BARANG (Admin) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah'])) {
    if (!csrfValid('barang', $_POST['csrf_token'] ?? '')) {
        flash('Permintaan tidak valid. Muat ulang halaman lalu coba kembali.', 'danger');
        redirect('barang.php');
    }

    $nama   = trim((string) ($_POST['nama_barang'] ?? ''));
    $satuan = trim((string) ($_POST['satuan'] ?? ''));
    $stok   = trim((string) ($_POST['stok'] ?? ''));

    $error = validasiBarang($nama, $satuan, $stok);

    if ($error !== null) {
        flash($error, 'danger');
        redirect('barang.php');
    }

    $jenisId = filter_var(
        $_POST['jenis_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($jenisId !== false && $jenisId !== null && !jenisBarangAda($pdo, (int) $jenisId)) {
        flash('Jenis barang yang dipilih tidak ditemukan.', 'danger');
        redirect('barang.php');
    }

    $jenisId = $jenisId === false || $jenisId === null ? null : (int) $jenisId;

    $gambar = null;

    if (isset($_FILES['gambar'])) {
        $gambar = uploadGambar($_FILES['gambar']);

        if ($gambar === false) {
            redirect('barang.php');
        }
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO barang (nama_barang, jenis_id, satuan, stok, gambar)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $nama,
            $jenisId,
            $satuan,
            (int) $stok,
            $gambar,
        ]);
        $barangId = (int) $pdo->lastInsertId();

        if ((int) $stok > 0) {
            $trx = $pdo->prepare(
                "INSERT INTO transaksi_stok
                    (barang_id, jenis_transaksi, jumlah, stok_sebelum, stok_sesudah,
                     tanggal, keterangan)
                 VALUES (?, 'masuk', ?, 0, ?, CURDATE(), 'Stok awal saat barang ditambahkan')"
            );
            $trx->execute([$barangId, (int) $stok, (int) $stok]);
        }

        $pdo->commit();
        flash('Barang berhasil ditambahkan.', 'success');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (is_string($gambar) && $gambar !== '') {
            hapusGambarBarang($gambar);
        }

        error_log('Tambah barang gagal: ' . $e->getMessage());
        flash('Barang gagal ditambahkan. Silakan coba kembali.', 'danger');
    }

    redirect('barang.php');
}

// ===== EDIT BARANG (Admin) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit'])) {
    if (!csrfValid('barang', $_POST['csrf_token'] ?? '')) {
        flash('Permintaan tidak valid. Muat ulang halaman lalu coba kembali.', 'danger');
        redirect('barang.php');
    }

    $id = filter_var(
        $_POST['id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$id) {
        flash('Data barang tidak ditemukan.', 'danger');
        redirect('barang.php');
    }

    $nama   = trim((string) ($_POST['nama_barang'] ?? ''));
    $satuan = trim((string) ($_POST['satuan'] ?? ''));
    $stok   = trim((string) ($_POST['stok'] ?? ''));

    $error = validasiBarang($nama, $satuan, $stok);

    if ($error !== null) {
        flash($error, 'danger');
        redirect('barang.php');
    }

    $jenisId = filter_var(
        $_POST['jenis_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($jenisId !== false && $jenisId !== null && !jenisBarangAda($pdo, (int) $jenisId)) {
        flash('Jenis barang yang dipilih tidak ditemukan.', 'danger');
        redirect('barang.php');
    }

    $jenisId = $jenisId === false || $jenisId === null ? null : (int) $jenisId;

    $stmt = $pdo->prepare("SELECT gambar, stok FROM barang WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $barangLama = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($barangLama === false) {
        flash('Data barang tidak ditemukan.', 'danger');
        redirect('barang.php');
    }

    $old_image = $barangLama['gambar'];
    $old_stok = (int) $barangLama['stok'];
    $stok_baru = (int) $stok;

    $gambar = $old_image;

    if (isset($_FILES['gambar'])) {
        $new_image = uploadGambar($_FILES['gambar']);

        if ($new_image === false) {
            redirect('barang.php');
        }

        if (is_string($new_image) && $new_image !== '') {
            $gambar = $new_image;
        }
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "UPDATE barang
             SET nama_barang = ?, jenis_id = ?, satuan = ?, stok = ?, gambar = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $nama,
            $jenisId,
            $satuan,
            $stok_baru,
            $gambar,
            $id,
        ]);

        if ($stok_baru !== $old_stok) {
            $trx = $pdo->prepare(
                "INSERT INTO transaksi_stok
                    (barang_id, jenis_transaksi, jumlah, stok_sebelum, stok_sesudah,
                     tanggal, keterangan)
                 VALUES (?, 'penyesuaian_saldo', ?, ?, ?, CURDATE(), 'Penyesuaian stok manual oleh admin')"
            );
            $trx->execute([$id, $stok_baru - $old_stok, $old_stok, $stok_baru]);
        }

        $pdo->commit();

        // Hapus gambar lama hanya setelah data baru tersimpan.
        if (
            is_string($gambar) &&
            $gambar !== '' &&
            $old_image !== false &&
            $old_image !== '' &&
            $gambar !== $old_image
        ) {
            hapusGambarBarang($old_image);
        }

        flash('Barang berhasil diperbarui.', 'success');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (
            isset($new_image) &&
            is_string($new_image) &&
            $new_image !== '' &&
            $new_image !== $old_image
        ) {
            hapusGambarBarang($new_image);
        }

        error_log('Edit barang gagal: ' . $e->getMessage());
        flash('Barang gagal diperbarui. Silakan coba kembali.', 'danger');
    }

    redirect('barang.php');
}

// ===== HAPUS BARANG (Admin) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus'])) {
    if (!csrfValid('barang', $_POST['csrf_token'] ?? '')) {
        flash('Permintaan tidak valid. Muat ulang halaman lalu coba kembali.', 'danger');
        redirect('barang.php');
    }

    $id = filter_var(
        $_POST['id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$id) {
        flash('Data barang tidak ditemukan.', 'danger');
        redirect('barang.php');
    }

    // Cek apakah barang masih direferensikan di detail_pengajuan.
    $cek = $pdo->prepare("SELECT COUNT(*) FROM detail_pengajuan WHERE barang_id = ?");
    $cek->execute([$id]);

    if ((int) $cek->fetchColumn() > 0) {
        flash('Barang tidak bisa dihapus karena sudah pernah digunakan dalam pengajuan.', 'danger');
        redirect('barang.php');
    }

    $stmt = $pdo->prepare("SELECT gambar FROM barang WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $gambar = $stmt->fetchColumn();

    if ($gambar === false) {
        flash('Data barang tidak ditemukan.', 'danger');
        redirect('barang.php');
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM barang WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 1 && $gambar !== null && $gambar !== '') {
            hapusGambarBarang($gambar);
        }

        flash('Barang berhasil dihapus.', 'success');
    } catch (PDOException $e) {
        error_log('Hapus barang gagal: ' . $e->getMessage());
        flash('Barang gagal dihapus. Silakan coba kembali.', 'danger');
    }

    redirect('barang.php');
}

// ===== AMBIL DATA UNTUK TAMPILAN =====
$q = trim((string) ($_GET['q'] ?? ''));
$filter_jenis = filter_var(
    $_GET['jenis_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$filter_jenis = $filter_jenis === false || $filter_jenis === null ? null : (int) $filter_jenis;

$sql = "SELECT b.*, j.nama_jenis
        FROM barang b
        LEFT JOIN jenis_barang j ON j.id = b.jenis_id";
$where = [];
$params = [];

if ($q !== '') {
    $where[] = "b.nama_barang LIKE ?";
    $params[] = '%' . $q . '%';
}

if ($filter_jenis !== null) {
    $where[] = "b.jenis_id = ?";
    $params[] = $filter_jenis;
}

if (count($where) > 0) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY b.id DESC";

$stmtBarang = $pdo->prepare($sql);
$stmtBarang->execute($params);
$data = $stmtBarang->fetchAll(PDO::FETCH_ASSOC);

$total_barang = (int) $pdo->query("SELECT COUNT(*) FROM barang")->fetchColumn();
$total_stok_habis = (int) $pdo->query(
    "SELECT COUNT(*) FROM barang WHERE COALESCE(stok, 0) <= 0"
)->fetchColumn();
$jumlah_tampil = count($data);

$jenis_list = $pdo->query(
    "SELECT id, nama_jenis FROM jenis_barang ORDER BY nama_jenis ASC"
)->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Barang - Admin PLBN WINI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* ===== RESET & GLOBAL ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; }

        
/* ===== SIDEBAR ===== */
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

/* ===== BRAND / LOGO ===== */
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
    
}

/* ===== MENU SIDEBAR ===== */
.sidebar a {
    display: block;
    color: #ecf0f1;
    padding: 12px 15px;
    text-decoration: none;
    border-radius: 5px;
    margin-bottom: 5px;
    transition: background 0.2s;
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

/* ===== TOMBOL TUTUP SIDEBAR ===== */
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
.main-content {
    margin-left: 250px;
    padding: 25px;
    transition: margin-left 0.3s;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
}
/* ===== RESPONSIVE SIDEBAR ===== */
@media (min-width: 769px) {
    .sidebar {
        transform: translateX(0) !important;
    }
}

@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        width: 250px;
    }

    .sidebar.open {
        transform: translateX(0);
    }

    .close-sidebar {
        display: block;
    }
}


        
/* ===== OVERLAY ===== */
.overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
}

.overlay.active {
    display: block;
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
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
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
            gap: 10px;
            flex-wrap: wrap;
        }
        .header .user-info .logout {
            background: #e74c3c;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: 0.2s;
        }
        .header .user-info .logout:hover { background: #c0392b; }
        .header .btn-tambah {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
            font-size: 14px;
            white-space: nowrap;
        }
        .header .btn-tambah:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34,197,94,0.3);
        }

        .menu-toggle {
            display: none;
            background: transparent;
            border: none;
            font-size: 28px;
            color: #0f172a;
            cursor: pointer;
            padding: 0 5px;
        }
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
        }

        /* ===== STATISTIK ===== */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stats .card {
            background: white;
            border-radius: 16px;
            padding: 20px 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 16px;
            border-left: 6px solid #3b82f6;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stats .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 28px rgba(0,0,0,0.08);
        }
        .stats .card .card-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            flex-shrink: 0;
        }
        .stats .card .number { font-size: 28px; font-weight: 800; color: #0f172a; }
        .stats .card .label { font-size: 14px; color: #64748b; font-weight: 500; margin-top: 4px; }

        /* ===== PANEL ===== */
        .panel {
            background: white;
            padding: 25px;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        .panel h3 {
            font-size: 18px;
            color: #0f172a;
            margin-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ===== FORM ===== */
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 6px; color: #1e293b; font-size: 14px; }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: 0.2s;
            background: #f8fafc;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
            background: white;
        }
        .row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .col-md-3 { flex: 1 1 calc(25% - 15px); min-width: 160px; }
        .col-md-4 { flex: 1 1 calc(33.333% - 15px); min-width: 200px; }
        .col-md-2 { flex: 0 0 auto; min-width: 120px; }

        /* ===== BUTTONS ===== */
        .btn {
            display: inline-block;
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            transition: 0.2s;
        }
        .btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(59,130,246,0.3); }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-warning:hover { background: #d97706; transform: translateY(-2px); }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-2px); }
        .btn-sm { padding: 5px 12px; font-size: 13px; border-radius: 6px; }
        .btn-secondary { background: #94a3b8; color: white; }
        .btn-secondary:hover { background: #64748b; }
        .btn-kembali {
            background: #94a3b8;
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 15px;
            font-weight: 600;
            transition: 0.2s;
        }
        .btn-kembali:hover { background: #64748b; }

        /* ===== TABLE ===== */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .table th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            padding: 12px 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        .table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .table tbody tr:hover { background: #f8fafc; }
        .table img.thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .table .no-image { color: #94a3b8; font-size: 12px; }

        /* ===== MODAL ===== */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15,23,42,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex; }
        .modal-dialog {
            background: white;
            border-radius: 16px;
            max-width: 500px;
            width: 90%;
            padding: 25px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .modal-header h5 { font-size: 20px; color: #0f172a; font-weight: 700; }
        .modal-header .btn-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #64748b;
            transition: 0.2s;
        }
        .modal-header .btn-close:hover { color: #0f172a; transform: rotate(90deg); }
        .modal-footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
            margin-top: 20px;
            text-align: right;
        }
        .preview-img {
            max-width: 100px;
            max-height: 100px;
            border-radius: 8px;
            margin-top: 8px;
            border: 1px solid #e2e8f0;
        }

        /* ===== FILTER & BADGE ===== */
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        .filter-bar .form-control { max-width: 260px; }
        .filter-bar select.form-control { max-width: 220px; }
        .badge-soft {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        .badge-new { background: #dbeafe; color: #1e40af; }
        .badge-muted { background: #f1f5f9; color: #475569; }

        /* ===== FLASH ===== */
        .flash {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .flash-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .flash-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .flash-warning { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 480px) {
            .header {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 10px;
            }
            .header .user-info { margin-left: auto; }
            .stats { grid-template-columns: 1fr; }
            .table { font-size: 12px; }
            .table th, .table td { padding: 8px; }
            .col-md-3, .col-md-4, .col-md-2 {
                flex: 1 1 100%;
            }
        }
    </style>
</head>
<body>

<!-- OVERLAY -->
<div class="overlay" id="sidebarOverlay"></div>


<!-- SIDEBAR ADMIN -->
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

    <a href="barang.php" class="active">
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

    <a href="anggota.php">
        <span class="icon"><i class="fas fa-users"></i></span> <span>Kelola Anggota</span>
    </a>

    <a href="../logout.php" class="logout-link">
        <span class="icon"><i class="fas fa-right-from-bracket"></i></span> <span>Logout</span>
    </a>
</div>


<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- HEADER -->
    <div class="header">
        <div style="display: flex; align-items: center; gap: 12px;">
            <button class="menu-toggle" id="menuToggle" aria-label="Buka menu">
                <i class="fas fa-bars"></i>
            </button>
            <h1><i class="fas fa-boxes"></i> Kelola Barang</h1>
        </div>
        <div class="user-info">
            <button id="btnTampilkanForm" class="btn-tambah"><i class="fas fa-plus-circle"></i> Tambah Barang</button>
            <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Flash Message -->
    <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] ?>">
            <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'danger' ? 'exclamation-circle' : 'info-circle') ?>"></i>
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- STATISTIK -->
    <div class="stats" id="statistikBarang">
        <div class="card">
            <div class="card-icon"><i class="fas fa-boxes"></i></div>
            <div>
                <div class="number"><?= $total_barang ?></div>
                <div class="label">Total Barang</div>
            </div>
        </div>
        <div class="card" style="border-left-color: #ef4444;">
            <div class="card-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="number"><?= $total_stok_habis ?></div>
                <div class="label">Stok Habis</div>
            </div>
        </div>
    </div>

    <!-- FORM TAMBAH (tersembunyi) -->
    <div id="formTambah" style="display:none;">
        <div class="panel">
            <button type="button" class="btn-kembali" onclick="kembaliKeDaftar()"><i class="fas fa-arrow-left"></i> Kembali ke Daftar</button>
            <h3><i class="fas fa-plus-circle" style="color:#22c55e;"></i> Tambah Barang</h3>
            <form method="POST" enctype="multipart/form-data" class="row">
                <input type="hidden" name="tambah" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_barang, ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Nama Barang</label>
                        <input name="nama_barang" class="form-control" placeholder="Masukkan nama barang" maxlength="150" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Jenis/Kategori <span style="color:#94a3b8;font-weight:400;">(opsional)</span></label>
                        <select name="jenis_id" class="form-control">
                            <option value="">— Belum ditentukan —</option>
                            <?php foreach ($jenis_list as $jenis): ?>
                                <option value="<?= (int) $jenis['id'] ?>"><?= htmlspecialchars($jenis['nama_jenis'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Stok</label>
                        <input name="stok" type="number" class="form-control" placeholder="Jumlah stok" min="0" step="1" required>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Satuan</label>
                        <input name="satuan" class="form-control" placeholder="pcs, box, kg" maxlength="20" required>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Gambar</label>
                        <input name="gambar" type="file" class="form-control" accept="image/jpeg,image/png,image/gif">
                    </div>
                </div>
                <div class="col-md-3 offset-md-9" style="display: flex; align-items: flex-end; margin-top: 10px;">
                    <button type="submit" class="btn btn-primary" style="width:100%;"><i class="fas fa-save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DAFTAR BARANG -->
    <div id="daftarBarang">
        <div class="panel">
            <h3><i class="fas fa-list-ul" style="color:#3b82f6;"></i> Daftar Barang</h3>

            <form method="GET" action="barang.php" class="filter-bar">
                <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Cari nama barang...">
                <select name="jenis_id" class="form-control">
                    <option value="">Semua jenis barang</option>
                    <?php foreach ($jenis_list as $jenis): ?>
                        <option value="<?= (int) $jenis['id'] ?>" <?= $filter_jenis === (int) $jenis['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($jenis['nama_jenis'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Cari</button>
                <?php if ($q !== '' || $filter_jenis !== null): ?>
                    <a href="barang.php" class="btn btn-secondary"><i class="fas fa-rotate-left"></i> Reset</a>
                <?php endif; ?>
                <span style="color:#64748b; font-size:13px; margin-left:auto;">
                    Menampilkan <?= $jumlah_tampil ?> dari <?= $total_barang ?> barang
                </span>
            </form>

            <?php if (count($data) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Gambar</th>
                                <th>Nama Barang</th>
                                <th>Jenis</th>
                                <th>Stok</th>
                                <th>Satuan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($data as $i => $b): ?>
                            <?php
                            $gambar_barang = basename((string) ($b['gambar'] ?? ''));
                            $gambar_tersedia = $gambar_barang !== '' && is_file($upload_dir . $gambar_barang);
                            ?>
                            <tr>
                                <td><?= $i+1 ?></td>
                                <td>
                                    <?php if ($gambar_tersedia): ?>
                                        <img src="../uploads/barang/<?= htmlspecialchars(rawurlencode($gambar_barang), ENT_QUOTES, 'UTF-8') ?>" class="thumbnail" alt="Gambar <?= htmlspecialchars($b['nama_barang'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php else: ?>
                                        <span class="no-image"><i class="fas fa-image"></i> Tidak ada</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars($b['nama_barang'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td>
                                    <?php if (!empty($b['nama_jenis'])): ?>
                                        <span class="badge-soft badge-new"><?= htmlspecialchars($b['nama_jenis'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span class="badge-soft badge-muted">Belum ditentukan</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $b['stok'] ?></td>
                                <td><?= htmlspecialchars($b['satuan'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="openEditModal(<?= (int) $b['id'] ?>)"><i class="fas fa-edit"></i> Edit</button>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Yakin hapus barang ini? Data tidak dapat dikembalikan.')">
                                        <input type="hidden" name="hapus" value="1">
                                        <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_barang, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash-alt"></i> Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px 0; color: #94a3b8;">
                    <i class="fas fa-box-open" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                    <p style="font-size: 16px;">
                        <?= ($q !== '' || $filter_jenis !== null)
                            ? 'Tidak ada barang yang cocok dengan pencarian atau filter jenis.'
                            : 'Belum ada barang. Tambahkan barang pertama Anda!' ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
<!-- MODAL EDIT (dibuat per barang) -->
<?php foreach ($data as $b): ?>
<?php
$gambar_edit = basename((string) ($b['gambar'] ?? ''));
$gambar_edit_tersedia = $gambar_edit !== '' && is_file($upload_dir . $gambar_edit);
?>
<div class="modal" id="editModal<?= (int) $b['id'] ?>">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_barang, ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-header">
                    <h5><i class="fas fa-edit" style="color:#f59e0b;"></i> Edit Barang</h5>
                    <button type="button" class="btn-close" onclick="closeEditModal(<?= (int) $b['id'] ?>)">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <input type="hidden" name="edit" value="1">
                    <div class="form-group">
                        <label>Nama Barang</label>
                        <input name="nama_barang" value="<?= htmlspecialchars($b['nama_barang'], ENT_QUOTES, 'UTF-8') ?>" class="form-control" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label>Jenis/Kategori</label>
                        <select name="jenis_id" class="form-control">
                            <option value="">— Belum ditentukan —</option>
                            <?php foreach ($jenis_list as $jenis): ?>
                                <option value="<?= (int) $jenis['id'] ?>" <?= (int) ($b['jenis_id'] ?? 0) === (int) $jenis['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($jenis['nama_jenis'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Stok</label>
                        <input name="stok" type="number" value="<?= (int) $b['stok'] ?>" class="form-control" min="0" step="1" required>
                    </div>
                    <div class="form-group">
                        <label>Satuan</label>
                        <input name="satuan" value="<?= htmlspecialchars($b['satuan'], ENT_QUOTES, 'UTF-8') ?>" class="form-control" maxlength="20" required>
                    </div>
                    <div class="form-group">
                        <label>Gambar (kosongkan jika tidak ingin mengubah)</label>
                        <?php if ($gambar_edit_tersedia): ?>
                            <div>
                                <img src="../uploads/barang/<?= htmlspecialchars(rawurlencode($gambar_edit), ENT_QUOTES, 'UTF-8') ?>" class="preview-img" alt="Gambar saat ini">
                            </div>
                        <?php endif; ?>
                        <input name="gambar" type="file" class="form-control" accept="image/jpeg,image/png,image/gif">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal(<?= (int) $b['id'] ?>)">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
// Toggle form tambah
document.getElementById('btnTampilkanForm').addEventListener('click', function() {
    document.getElementById('daftarBarang').style.display = 'none';
    document.getElementById('formTambah').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

function kembaliKeDaftar() {
    document.getElementById('daftarBarang').style.display = 'block';
    document.getElementById('formTambah').style.display = 'none';
    const statistik = document.getElementById('statistikBarang');
    if (statistik) statistik.style.display = 'flex';
}

function openEditModal(id) {
    const modal = document.getElementById('editModal' + id);
    if (modal) modal.classList.add('show');
}

function closeEditModal(id) {
    const modal = document.getElementById('editModal' + id);
    if (modal) modal.classList.remove('show');
}

// Tutup modal klik di luar
window.addEventListener('click', function(e) {
    document.querySelectorAll('.modal.show').forEach(modal => {
        if (e.target === modal) modal.classList.remove('show');
    });
});

// ===== SIDEBAR TOGGLE =====
(function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuToggle = document.getElementById('menuToggle');
    const closeBtn = document.getElementById('closeSidebarBtn');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    menuToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        if (sidebar.classList.contains('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    closeBtn.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
            closeSidebar();
        }
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
})();
</script>

</body>
</html>