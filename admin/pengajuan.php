<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';
if (!isLoggedIn() || !isRole('admin')) redirect('../login.php');

// ===== AMBIL FLASH MESSAGE =====
$flash = getFlash();

// ===== TOKEN CSRF =====
$csrf_pengajuan = csrfToken('pengajuan');

// ========== PROSES SETUJUI / TOLAK (POST) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi_pengajuan'])) {
    if (!csrfValid('pengajuan', $_POST['csrf_token'] ?? '')) {
        flash('Permintaan tidak valid. Muat ulang halaman lalu coba kembali.', 'danger');
        redirect('pengajuan.php');
    }

    $id = filter_var(
        $_POST['pengajuan_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $aksi = $_POST['aksi_pengajuan'] ?? '';

    if (!$id || !in_array($aksi, ['approve', 'reject'], true)) {
        flash('Data pengajuan tidak valid.', 'danger');
        redirect('pengajuan.php');
    }

    $pesan = null;
    $tipePesan = 'success';

    try {
        $pdo->beginTransaction();

        // Kunci baris agar dua admin tidak memproses pengajuan yang sama.
        $cek = $pdo->prepare("SELECT status FROM pengajuan WHERE id = ? FOR UPDATE");
        $cek->execute([$id]);
        $status = $cek->fetchColumn();

        if ($status === false) {
            throw new RuntimeException('Pengajuan tidak ditemukan.');
        }

        if ($status !== 'pending') {
            throw new RuntimeException('Pengajuan sudah diproses.');
        }

        if ($aksi === 'approve') {
            // Gabungkan barang yang sama agar stok tidak berkurang dua kali.
            $detail = $pdo->prepare(
                "SELECT barang_id, SUM(jumlah) AS jumlah
                 FROM detail_pengajuan
                 WHERE pengajuan_id = ?
                 GROUP BY barang_id
                 ORDER BY barang_id"
            );
            $detail->execute([$id]);
            $items = $detail->fetchAll(PDO::FETCH_ASSOC);

            if (!$items) {
                throw new RuntimeException('Pengajuan tidak memiliki rincian barang.');
            }

            foreach ($items as $item) {
                $jumlah = (int) $item['jumlah'];
                $barangId = (int) $item['barang_id'];

                if ($jumlah < 1) {
                    throw new RuntimeException('Jumlah barang pada pengajuan tidak valid.');
                }

                $update = $pdo->prepare(
                    "UPDATE barang
                     SET stok = stok - ?
                     WHERE id = ? AND stok >= ?"
                );
                $update->execute([$jumlah, $barangId, $jumlah]);

                if ($update->rowCount() === 0) {
                    $infoBarang = $pdo->prepare(
                        "SELECT nama_barang, satuan, COALESCE(stok, 0) AS stok
                         FROM barang
                         WHERE id = ?
                         LIMIT 1"
                    );
                    $infoBarang->execute([$barangId]);
                    $barangKurang = $infoBarang->fetch(PDO::FETCH_ASSOC);

                    if ($barangKurang) {
                        throw new RuntimeException(
                            pesanStokTidakCukup([
                                'nama_barang' => $barangKurang['nama_barang'],
                                'satuan'      => $barangKurang['satuan'],
                                'tersedia'    => (int) $barangKurang['stok'],
                            ], $jumlah)
                        );
                    }

                    throw new RuntimeException('Stok tidak mencukupi untuk salah satu barang.');
                }
            }

            $stmt = $pdo->prepare(
                "UPDATE pengajuan
                 SET status = 'disetujui'
                 WHERE id = ? AND status = 'pending'"
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Status pengajuan gagal diperbarui.');
            }

            $stmt = $pdo->prepare(
                "UPDATE detail_pengajuan
                 SET status_detail = 'disetujui'
                 WHERE pengajuan_id = ?"
            );
            $stmt->execute([$id]);

            $pesan = 'Pengajuan berhasil disetujui dan stok dikurangi.';
            $tipePesan = 'success';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE pengajuan
                 SET status = 'ditolak'
                 WHERE id = ? AND status = 'pending'"
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Status pengajuan gagal diperbarui.');
            }

            $stmt = $pdo->prepare(
                "UPDATE detail_pengajuan
                 SET status_detail = 'ditolak'
                 WHERE pengajuan_id = ?"
            );
            $stmt->execute([$id]);

            $pesan = 'Pengajuan ditolak.';
            $tipePesan = 'warning';
        }

        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException) {
            error_log('Proses pengajuan #' . $id . ' gagal: ' . $e->getMessage());
            $pesan = 'Pengajuan gagal diproses. Silakan coba kembali.';
        } else {
            $pesan = $e->getMessage();
        }

        $tipePesan = 'danger';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Proses pengajuan #' . $id . ' gagal: ' . $e->getMessage());
        $pesan = 'Pengajuan gagal diproses. Silakan coba kembali.';
        $tipePesan = 'danger';
    }

    if ($pesan !== null) {
        flash($pesan, $tipePesan);
    }

    redirect('pengajuan.php');
}

// ========== PROSES EDIT ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_pengajuan'])) {
    if (!csrfValid('pengajuan', $_POST['csrf_token'] ?? '')) {
        flash('Permintaan tidak valid. Muat ulang halaman lalu coba kembali.', 'danger');
        redirect('pengajuan.php');
    }

    $pengajuan_id = filter_var(
        $_POST['pengajuan_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$pengajuan_id) {
        flash('Data pengajuan tidak valid.', 'danger');
        redirect('pengajuan.php');
    }

    $nama_pengaju = trim((string) ($_POST['nama_pengaju'] ?? ''));
    $divisi = trim((string) ($_POST['divisi'] ?? ''));
    $status_kepegawaian = trim((string) ($_POST['status_kepegawaian'] ?? ''));
    $catatan = trim((string) ($_POST['catatan'] ?? ''));

    $error = null;

    if ($nama_pengaju === '' || $divisi === '' || $status_kepegawaian === '') {
        $error = 'Nama pengaju, divisi, dan status kepegawaian wajib diisi.';
    } elseif (
        mb_strlen($nama_pengaju) > 100 ||
        mb_strlen($divisi) > 100 ||
        mb_strlen($status_kepegawaian) > 50
    ) {
        $error = 'Panjang nama, divisi, atau status kepegawaian melebihi batas.';
    } elseif (mb_strlen($catatan) > 5000) {
        $error = 'Catatan maksimal 5.000 karakter.';
    }

    $barang_ids = $_POST['barang_id'] ?? [];
    $jumlahs = $_POST['jumlah'] ?? [];
    $items = [];

    if ($error === null) {
        if (!is_array($barang_ids) || !is_array($jumlahs)) {
            $error = 'Rincian barang tidak valid.';
        } else {
            foreach ($barang_ids as $key => $barang_id) {
                $jumlah_nilai = $jumlahs[$key] ?? null;

                if (!is_scalar($barang_id) || !is_scalar($jumlah_nilai)) {
                    $error = 'Rincian barang tidak valid.';
                    break;
                }

                $barang_id = filter_var($barang_id, FILTER_VALIDATE_INT);
                $jumlah = filter_var($jumlah_nilai, FILTER_VALIDATE_INT);
                $jumlah_raw = trim((string) $jumlah_nilai);

                // Lewati baris yang benar-benar kosong.
                if ($barang_id === false && $jumlah_raw === '') {
                    continue;
                }

                if (!$barang_id || !$jumlah || $jumlah < 1) {
                    $error = 'Setiap barang yang dipilih harus memiliki jumlah minimal 1.';
                    break;
                }

                // Gabungkan baris dengan barang yang sama.
                $items[$barang_id] = ($items[$barang_id] ?? 0) + $jumlah;
            }

            if ($error === null && !$items) {
                $error = 'Minimal satu barang harus diisi dengan jumlah yang valid.';
            }
        }
    }

    if ($error !== null) {
        flash($error, 'danger');
        redirect('pengajuan.php');
    }

    try {
        $pdo->beginTransaction();

        $cek = $pdo->prepare("SELECT status FROM pengajuan WHERE id = ? FOR UPDATE");
        $cek->execute([$pengajuan_id]);
        $status = $cek->fetchColumn();

        if ($status === false) {
            throw new RuntimeException('Pengajuan tidak ditemukan.');
        }

        if ($status !== 'pending') {
            throw new RuntimeException('Hanya pengajuan dengan status pending yang dapat diedit.');
        }

        // Validasi ulang stok memakai data terbaru. Reservasi milik
        // pengajuan ini sendiri tidak dihitung karena akan diganti.
        $stokBarang = ambilStokTersediaBarang(
            $pdo,
            array_keys($items),
            $pengajuan_id,
            true
        );

        if (count($stokBarang) !== count($items)) {
            throw new RuntimeException('Salah satu barang yang dipilih tidak ditemukan.');
        }

        foreach ($items as $barang_id => $jumlah) {
            $barang_id = (int) $barang_id;
            $jumlah = (int) $jumlah;
            $tersedia = (int) ($stokBarang[$barang_id]['tersedia'] ?? 0);

            if ($jumlah < 1) {
                throw new RuntimeException('Jumlah pengajuan harus berupa bilangan bulat minimal 1.');
            }

            if ($jumlah > $tersedia) {
                throw new RuntimeException(pesanStokTidakCukup($stokBarang[$barang_id], $jumlah));
            }
        }

        $stmt = $pdo->prepare(
            "UPDATE pengajuan
             SET nama_pengaju = ?, divisi_pengaju = ?, status_kepegawaian = ?, catatan = ?
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([
            $nama_pengaju,
            $divisi,
            $status_kepegawaian,
            $catatan,
            $pengajuan_id
        ]);

        $del = $pdo->prepare("DELETE FROM detail_pengajuan WHERE pengajuan_id = ?");
        $del->execute([$pengajuan_id]);

        $stmt2 = $pdo->prepare(
            "INSERT INTO detail_pengajuan
                (pengajuan_id, barang_id, jumlah, satuan)
             VALUES (?, ?, ?, ?)"
        );

        foreach ($items as $barang_id => $jumlah) {
            $stmt2->execute([
                $pengajuan_id,
                (int) $barang_id,
                (int) $jumlah,
                $stokBarang[(int) $barang_id]['satuan']
            ]);
        }

        $pdo->commit();
        flash('Pengajuan berhasil diperbarui.', 'success');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException) {
            error_log('Edit pengajuan #' . $pengajuan_id . ' gagal: ' . $e->getMessage());
            flash('Pengajuan gagal diperbarui. Silakan coba kembali.', 'danger');
        } else {
            flash($e->getMessage(), 'danger');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Edit pengajuan #' . $pengajuan_id . ' gagal: ' . $e->getMessage());
        flash('Pengajuan gagal diperbarui. Silakan coba kembali.', 'danger');
    }

    redirect('pengajuan.php');
}

// ========== DETAIL PENGAJUAN ==========
$detailPengajuan = null;
$detailItems = [];

if (isset($_GET['detail'])) {
    $detailId = filter_var(
        $_GET['detail'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$detailId) {
        flash('ID pengajuan tidak valid.', 'danger');
        redirect('pengajuan.php');
    }

    $stmt = $pdo->prepare(
        "SELECT p.*, u.nama AS user_nama, u.username
         FROM pengajuan p
         JOIN users u ON p.user_id = u.id
         WHERE p.id = ?
         LIMIT 1"
    );
    $stmt->execute([$detailId]);
    $detailPengajuan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$detailPengajuan) {
        flash('Pengajuan tidak ditemukan.', 'danger');
        redirect('pengajuan.php');
    }

    $stmt = $pdo->prepare(
        "SELECT b.nama_barang, d.jumlah, d.satuan, d.status_detail
         FROM detail_pengajuan d
         JOIN barang b ON d.barang_id = b.id
         WHERE d.pengajuan_id = ?
         ORDER BY b.nama_barang"
    );
    $stmt->execute([$detailId]);
    $detailItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ========== AMBIL SEMUA PENGAJUAN ==========
$query = $pdo->query("
    SELECT p.*, u.nama as user_nama 
    FROM pengajuan p
    JOIN users u ON p.user_id = u.id
    ORDER BY p.id DESC
");
$pengajuan = $query->fetchAll();

// Ambil semua barang untuk dropdown di modal edit
$allBarang = $pdo->query("SELECT * FROM barang ORDER BY nama_barang")->fetchAll();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengajuan - PLBN WINI</title>
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
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        .overlay.active {
            display: block;
        }

        /* ===== MAIN CONTENT ===== */
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
            gap: 15px;
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
        .flash-info { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }

        /* ===== PANEL ===== */
        .panel {
            background: white;
            padding: 25px;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .panel h3 {
            font-size: 18px;
            color: #0f172a;
            margin-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 12px;
        }

        /* ===== TABLE ===== */
        .table-wrapper {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 1000px;
        }
        .table th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            padding: 12px 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        .table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .table tbody tr:hover { background: #f8fafc; }

        .table .barang-list {
            margin: 0;
            padding-left: 0;
            list-style: none;
        }
        .table .barang-list li { margin-bottom: 3px; font-size: 13px; }

        /* ===== BADGE ===== */
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-disetujui { background: #d1fae5; color: #065f46; }
        .badge-ditolak { background: #fee2e2; color: #991b1b; }
        .badge-didistribusi { background: #ede9fe; color: #5b21b6; }
        .badge-selesai { background: #dbeafe; color: #1e40af; }

        /* ===== BUTTONS ===== */
        .btn {
            display: inline-block;
            padding: 5px 14px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            font-size: 12px;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-success { background: #22c55e; color: white; }
        .btn-success:hover { background: #16a34a; transform: translateY(-1px); }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-1px); }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-warning:hover { background: #d97706; transform: translateY(-1px); }
        .btn-secondary { background: #94a3b8; color: white; }
        .btn-secondary:hover { background: #64748b; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-info { background: #0ea5e9; color: white; }
        .btn-info:hover { background: #0284c7; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }

        .text-muted { color: #94a3b8; }

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
            max-width: 750px;
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
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }
        textarea.form-control { resize: vertical; min-height: 80px; }

        .row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; margin-bottom: 15px; }
        .col-md-4 { flex: 1 1 calc(33.333% - 15px); min-width: 180px; }
        .col-md-5 { flex: 1 1 calc(41.666% - 15px); min-width: 180px; }
        .col-md-3 { flex: 1 1 calc(25% - 15px); min-width: 120px; }
        .col-md-2 { flex: 0 0 auto; min-width: 70px; }

        .baris-barang {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px;
            background: #f8fafc;
            padding: 12px;
            border-radius: 10px;
            border-left: 4px solid #3b82f6;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 480px) {
            .header {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 10px;
            }
            .header .user-info { margin-left: auto; }
            .table th, .table td { padding: 6px; font-size: 13px; }
            .col-md-4, .col-md-5, .col-md-3 { flex: 1 1 100%; }
            .baris-barang .col-md-5,
            .baris-barang .col-md-3,
            .baris-barang .col-md-2 {
                flex: 1 1 100%;
            }
        }
        @media (max-width: 768px) {
            .col-md-4, .col-md-5, .col-md-3 { flex: 1 1 100%; }
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

    <a href="pengajuan.php" class="active">
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
    <div class="header">
        <div style="display: flex; align-items: center; gap: 12px;">
            <button class="menu-toggle" id="menuToggle" aria-label="Buka menu">
                <i class="fas fa-bars"></i>
            </button>
            <h1><i class="fas fa-file-alt"></i> Kelola Pengajuan</h1>
        </div>
        <div class="user-info">
            <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Flash Message -->
    <?php if ($flash): ?>
        <div class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'danger' ? 'exclamation-circle' : 'info-circle') ?>"></i>
            <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($detailPengajuan): ?>
        <?php
        $statusDetail = (string) $detailPengajuan['status'];
        $kelasStatus = preg_replace('/[^a-z_]/', '', $statusDetail);
        $buktiNama = basename((string) ($detailPengajuan['bukti_pengambilan'] ?? ''));
        $buktiPath = '../uploads/bukti_pengambilan/' . $buktiNama;
        $buktiAda = $buktiNama !== '' && is_file($buktiPath);
        ?>
        <div class="panel" style="margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
                <h3 style="margin:0;"><i class="fas fa-file-signature" style="color:#3b82f6;"></i> Detail Pengajuan #<?= (int) $detailPengajuan['id'] ?></h3>
                <a href="pengajuan.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Kembali ke Daftar</a>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin-bottom:16px;">
                <div><strong>Nama Pengaju</strong><br><?= htmlspecialchars($detailPengajuan['nama_pengaju'], ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Akun Pengguna</strong><br><?= htmlspecialchars($detailPengajuan['user_nama'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($detailPengajuan['username'], ENT_QUOTES, 'UTF-8') ?>)</div>
                <div><strong>Divisi</strong><br><?= htmlspecialchars($detailPengajuan['divisi_pengaju'], ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Status Kepegawaian</strong><br><?= htmlspecialchars($detailPengajuan['status_kepegawaian'], ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Tanggal Pengajuan</strong><br><?= htmlspecialchars(date('d-m-Y', strtotime($detailPengajuan['tanggal'])), ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Status</strong><br><span class="badge badge-<?= htmlspecialchars($kelasStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $statusDetail)), ENT_QUOTES, 'UTF-8') ?></span></div>
                <div><strong>Status Bukti</strong><br><?= htmlspecialchars(str_replace('_', ' ', (string) $detailPengajuan['status_bukti']), ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Catatan</strong><br><?= htmlspecialchars($detailPengajuan['catatan'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <?php if (!empty($detailPengajuan['catatan_bukti'])): ?>
                <div class="flash flash-warning" style="margin-bottom:16px;">
                    <i class="fas fa-info-circle"></i>
                    Catatan bukti: <?= htmlspecialchars($detailPengajuan['catatan_bukti'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Barang</th>
                            <th>Jumlah</th>
                            <th>Satuan</th>
                            <th>Status Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($detailItems): ?>
                            <?php foreach ($detailItems as $index => $item): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($item['nama_barang'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) $item['jumlah'] ?></td>
                                    <td><?= htmlspecialchars($item['satuan'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(ucfirst((string) $item['status_detail']), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">Tidak ada rincian barang.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:16px;">
                <strong>Bukti Pengambilan:</strong>
                <?php if ($buktiAda): ?>
                    <a href="<?= htmlspecialchars($buktiPath, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Lihat bukti</a>
                <?php else: ?>
                    <span class="text-muted">Belum ada bukti.</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h3><i class="fas fa-list-ul" style="color:#3b82f6;"></i> Daftar Semua Pengajuan</h3>
        <?php if (count($pengajuan) > 0): ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Pengaju</th>
                            <th>Divisi</th>
                            <th>Status Kepeg.</th>
                            <th>Daftar Barang</th>
                            <th>Catatan</th>
                            <th>Tanggal</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($pengajuan as $p): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= htmlspecialchars($p['nama_pengaju']) ?></td>
                            <td><?= htmlspecialchars($p['divisi_pengaju']) ?></td>
                            <td><?= htmlspecialchars($p['status_kepegawaian']) ?></td>
                            <td>
                                <?php
                                $detailStmt = $pdo->prepare("
                                    SELECT b.nama_barang, d.jumlah, d.satuan 
                                    FROM detail_pengajuan d
                                    JOIN barang b ON d.barang_id = b.id
                                    WHERE d.pengajuan_id = ?
                                ");
                                $detailStmt->execute([$p['id']]);
                                $items = $detailStmt->fetchAll();
                                if ($items) {
                                    echo '<ul class="barang-list">';
                                    foreach ($items as $item) {
                                        echo '<li>• ' . htmlspecialchars($item['nama_barang']) 
                                             . ' (' . $item['jumlah'] . ' ' . htmlspecialchars($item['satuan']) . ')</li>';
                                    }
                                    echo '</ul>';
                                } else {
                                    echo '<span class="text-muted">-</span>';
                                }
                                ?>
                            </td>
                            <td><?= htmlspecialchars($p['catatan'] ?? '-') ?></td>
                            <td><?= date('d-m-Y', strtotime($p['tanggal'])) ?></td>
                            <td>
                                <span class="badge badge-<?= $p['status'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', $p['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex; gap:4px; flex-wrap:wrap;">
                                    <a href="?detail=<?= (int) $p['id'] ?>" class="btn btn-info btn-sm">
                                        <i class="fas fa-eye"></i> Detail
                                    </a>
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Setujui pengajuan ini? Stok akan dikurangi.')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_pengajuan, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="aksi_pengajuan" value="approve">
                                            <input type="hidden" name="pengajuan_id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Setujui</button>
                                        </form>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Tolak pengajuan ini?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_pengajuan, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="aksi_pengajuan" value="reject">
                                            <input type="hidden" name="pengajuan_id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Tolak</button>
                                        </form>
                                        <button type="button" class="btn btn-warning btn-sm" onclick="openEditModal(<?= (int) $p['id'] ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:40px 0; color:#94a3b8;">
                <i class="fas fa-inbox" style="font-size:48px; margin-bottom:15px; display:block;"></i>
                <p style="font-size:16px;">Belum ada pengajuan.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== MODAL EDIT (di luar tabel agar HTML valid) ===== -->
<?php foreach ($pengajuan as $p): ?>
    <?php if ($p['status'] === 'pending'): ?>
        <?php
        $detailEdit = $pdo->prepare(
            "SELECT d.id, d.barang_id, d.jumlah, d.satuan, b.nama_barang
             FROM detail_pengajuan d
             JOIN barang b ON d.barang_id = b.id
             WHERE d.pengajuan_id = ?
             ORDER BY b.nama_barang"
        );
        $detailEdit->execute([$p['id']]);
        $itemsEdit = $detailEdit->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="modal" id="editModal<?= (int) $p['id'] ?>">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_pengajuan, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-edit" style="color:#f59e0b;"></i> Edit Pengajuan #<?= (int) $p['id'] ?></h5>
                            <button type="button" class="btn-close" onclick="closeEditModal(<?= (int) $p['id'] ?>)">&times;</button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="pengajuan_id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="edit_pengajuan" value="1">

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Nama Lengkap</label>
                                        <input name="nama_pengaju" class="form-control" maxlength="100" value="<?= htmlspecialchars($p['nama_pengaju'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Divisi</label>
                                        <input name="divisi" class="form-control" maxlength="100" value="<?= htmlspecialchars($p['divisi_pengaju'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Status Kepegawaian</label>
                                        <input name="status_kepegawaian" class="form-control" maxlength="50" value="<?= htmlspecialchars($p['status_kepegawaian'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Catatan</label>
                                <textarea name="catatan" class="form-control" rows="2" maxlength="5000"><?= htmlspecialchars($p['catatan'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>

                            <h5 style="margin: 20px 0 10px 0; color:#1e293b; font-size:16px;"><i class="fas fa-list-ul" style="color:#3b82f6;"></i> Daftar Barang</h5>
                            <div id="editDetailContainer<?= (int) $p['id'] ?>">
                                <?php if ($itemsEdit): ?>
                                    <?php foreach ($itemsEdit as $item): ?>
                                        <div class="baris-barang">
                                            <div class="col-md-5">
                                                <select name="barang_id[]" class="form-control" required>
                                                    <option value="">-- Pilih Barang --</option>
                                                    <?php foreach ($allBarang as $b): ?>
                                                        <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $item['barang_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($b['nama_barang'], ENT_QUOTES, 'UTF-8') ?> (Stok: <?= (int) $b['stok'] ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <input name="jumlah[]" type="number" class="form-control" placeholder="Jumlah" min="1" step="1" value="<?= (int) $item['jumlah'] ?>" required>
                                            </div>
                                            <div class="col-md-2">
                                                <button type="button" class="btn btn-success btn-sm" onclick="tambahBarisEdit(<?= (int) $p['id'] ?>)"><i class="fas fa-plus"></i></button>
                                                <button type="button" class="btn btn-danger btn-sm" onclick="hapusBarisEdit(this)"><i class="fas fa-minus"></i></button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="baris-barang">
                                        <div class="col-md-5">
                                            <select name="barang_id[]" class="form-control" required>
                                                <option value="">-- Pilih Barang --</option>
                                                <?php foreach ($allBarang as $b): ?>
                                                    <option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars($b['nama_barang'], ENT_QUOTES, 'UTF-8') ?> (Stok: <?= (int) $b['stok'] ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <input name="jumlah[]" type="number" class="form-control" placeholder="Jumlah" min="1" step="1" required>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="button" class="btn btn-success btn-sm" onclick="tambahBarisEdit(<?= (int) $p['id'] ?>)"><i class="fas fa-plus"></i></button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="hapusBarisEdit(this)"><i class="fas fa-minus"></i></button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeEditModal(<?= (int) $p['id'] ?>)">Batal</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<script>
function openEditModal(id) {
    document.getElementById('editModal' + id).classList.add('show');
}
function closeEditModal(id) {
    document.getElementById('editModal' + id).classList.remove('show');
}
// Tutup modal jika klik di luar area modal
window.addEventListener('click', function(e) {
    document.querySelectorAll('.modal.show').forEach(modal => {
        if (e.target === modal) {
            modal.classList.remove('show');
        }
    });
});

function tambahBarisEdit(id) {
    const container = document.getElementById('editDetailContainer' + id);
    const template = container.querySelector('.baris-barang');
    const newRow = template.cloneNode(true);
    newRow.querySelectorAll('input, select').forEach(el => el.value = '');
    container.appendChild(newRow);
}

function hapusBarisEdit(btn) {
    const row = btn.closest('.baris-barang');
    const container = row.parentNode;
    if (container.querySelectorAll('.baris-barang').length <= 1) {
        alert('Minimal satu baris barang harus ada.');
        return;
    }
    row.remove();
}

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