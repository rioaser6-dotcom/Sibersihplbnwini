
<?php
require_once '../includes/fungsi.php';
require_once '../config/database.php';

if (!isLoggedIn() || !isRole('admin')) {
    redirect('../login.php');
}

$uploadDir = '../uploads/bukti_pengambilan/';
$uploadUrl = '../uploads/bukti_pengambilan/';

if (empty($_SESSION['csrf_distribusi'])) {
    $_SESSION['csrf_distribusi'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tanggalTampil($tanggal): string
{
    if (empty($tanggal)) {
        return '-';
    }

    $timestamp = strtotime($tanggal);
    return $timestamp !== false ? date('d-m-Y', $timestamp) : '-';
}

$flash = getFlash();

/*
|--------------------------------------------------------------------------
| PROSES DISTRIBUSI, VERIFIKASI, DAN PERMINTAAN PERBAIKAN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $aksi = $_POST['aksi'] ?? '';
    $pengajuanId = filter_input(INPUT_POST, 'pengajuan_id', FILTER_VALIDATE_INT);

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_distribusi'], $token)
    ) {
        flash('Permintaan tidak valid. Silakan muat ulang halaman.', 'danger');
        redirect('distribusi.php');
        exit;
    }

    if (!$pengajuanId || $pengajuanId < 1) {
        flash('ID pengajuan tidak valid.', 'danger');
        redirect('distribusi.php');
        exit;
    }

    if (!in_array($aksi, ['distribusikan', 'verifikasi', 'perbaikan', 'kirim_pesan'], true)) {
        flash('Aksi yang diminta tidak valid.', 'danger');
        redirect('distribusi.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        /*
         * KIRIM PESAN DISTRIBUSI KEPADA PENGGUNA PEMILIK PENGAJUAN.
         * Penerima selalu diambil dari relasi pengajuan.user_id di database,
         * bukan dari input form, agar pesan tidak salah tujuan.
         */
        if ($aksi === 'kirim_pesan') {
            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.user_id,
                    p.status,
                    p.nama_pengaju,
                    p.divisi_pengaju,
                    u.nama AS nama_user,
                    u.username,
                    u.role
                FROM pengajuan p
                JOIN users u ON u.id = p.user_id
                WHERE p.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$pengajuanId]);
            $tujuan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tujuan) {
                throw new RuntimeException(
                    'Pengajuan tujuan pesan tidak ditemukan.'
                );
            }

            if ((string) $tujuan['role'] !== 'user') {
                throw new RuntimeException(
                    'Penerima pesan bukan akun Pengguna yang valid.'
                );
            }

            if (!in_array((string) $tujuan['status'], ['disetujui', 'didistribusi'], true)) {
                throw new RuntimeException(
                    'Pesan distribusi hanya dapat dikirim untuk pengajuan yang sudah disetujui atau sedang didistribusikan.'
                );
            }

            $isiPesan = trim((string) ($_POST['pesan'] ?? ''));

            if ($isiPesan === '') {
                throw new RuntimeException('Isi pesan wajib diisi.');
            }

            if (mb_strlen($isiPesan) > 2000) {
                throw new RuntimeException(
                    'Isi pesan maksimal 2.000 karakter.'
                );
            }

            $idempotencyKey = trim(
                (string) ($_POST['idempotency_key'] ?? '')
            );

            if (!preg_match('/^[a-f0-9]{64}$/i', $idempotencyKey)) {
                throw new RuntimeException(
                    'Permintaan pengiriman pesan tidak valid. Muat ulang halaman lalu coba kembali.'
                );
            }

            $adminId = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

            if ($adminId < 1) {
                throw new RuntimeException(
                    'Identitas Admin tidak ditemukan dalam sesi.'
                );
            }

            // Relasi distribusi diambil dari database, bukan dari input form.
            $stmtDistribusi = $pdo->prepare("
                SELECT id
                FROM distribusi
                WHERE pengajuan_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtDistribusi->execute([$pengajuanId]);
            $distribusiId = $stmtDistribusi->fetchColumn();
            $distribusiId = $distribusiId !== false
                ? (int) $distribusiId
                : null;

            // Cegah pesan ganda bila form terkirim lebih dari sekali.
            $stmtCek = $pdo->prepare("
                SELECT id, admin_id, user_id, pengajuan_id
                FROM pesan_distribusi
                WHERE idempotency_key = ?
                LIMIT 1
            ");
            $stmtCek->execute([$idempotencyKey]);
            $pesanTersimpan = $stmtCek->fetch(PDO::FETCH_ASSOC);

            if ($pesanTersimpan) {
                $samaTujuan = (
                    (int) $pesanTersimpan['admin_id'] === $adminId &&
                    (int) $pesanTersimpan['user_id'] === (int) $tujuan['user_id'] &&
                    (int) $pesanTersimpan['pengajuan_id'] === $pengajuanId
                );

                if (!$samaTujuan) {
                    throw new RuntimeException(
                        'Kunci pengiriman pesan sudah dipakai untuk tujuan lain.'
                    );
                }

                $pdo->commit();
                flash(
                    'Pesan ini sudah tersimpan sebelumnya, sehingga tidak dibuat pesan ganda.',
                    'info'
                );
                redirect('distribusi.php');
                exit;
            }

            try {
                $insertPesan = $pdo->prepare("
                    INSERT INTO pesan_distribusi
                        (pengajuan_id, distribusi_id, admin_id, user_id,
                         pesan, idempotency_key)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insertPesan->execute([
                    $pengajuanId,
                    $distribusiId,
                    $adminId,
                    (int) $tujuan['user_id'],
                    $isiPesan,
                    $idempotencyKey,
                ]);
            } catch (PDOException $insertError) {
                // Bila dua request memakai kunci yang sama, pastikan pesan
                // sudah benar-benar tersimpan sebelum menyatakan berhasil.
                if ((string) $insertError->getCode() === '23000') {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $stmtCek->execute([$idempotencyKey]);
                    $pesanTersimpan = $stmtCek->fetch(PDO::FETCH_ASSOC);

                    if (
                        $pesanTersimpan &&
                        (int) $pesanTersimpan['admin_id'] === $adminId &&
                        (int) $pesanTersimpan['user_id'] === (int) $tujuan['user_id'] &&
                        (int) $pesanTersimpan['pengajuan_id'] === $pengajuanId
                    ) {
                        flash(
                            'Pesan sudah tersimpan sebelumnya. Tidak ada pesan ganda yang dibuat.',
                            'info'
                        );
                        redirect('distribusi.php');
                        exit;
                    }
                }

                throw $insertError;
            }

            if ($insertPesan->rowCount() !== 1) {
                throw new RuntimeException(
                    'Pesan gagal disimpan ke database. Silakan coba kembali.'
                );
            }

            $pdo->commit();

            flash(
                'Pesan berhasil disimpan dan dikirim kepada '
                . (string) $tujuan['nama_user']
                . ' (' . (string) $tujuan['username']
                . ') untuk pengajuan #' . $pengajuanId . '.',
                'success'
            );
            redirect('distribusi.php');
            exit;
        }

        /*
         * ADMIN MENCATAT DISTRIBUSI BARANG.
         * Stok tidak dikurangi lagi karena sudah dikurangi
         * ketika pengajuan disetujui.
         */
        if ($aksi === 'distribusikan') {
            $stmt = $pdo->prepare("
                SELECT id, status
                FROM pengajuan
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$pengajuanId]);
            $pengajuan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pengajuan) {
                throw new RuntimeException('Pengajuan tidak ditemukan.');
            }

            if ($pengajuan['status'] !== 'disetujui') {
                throw new RuntimeException(
                    'Hanya pengajuan yang sudah disetujui yang dapat didistribusikan.'
                );
            }

            $cekDistribusi = $pdo->prepare("
                SELECT COUNT(*)
                FROM distribusi
                WHERE pengajuan_id = ?
            ");
            $cekDistribusi->execute([$pengajuanId]);

            if ((int) $cekDistribusi->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Distribusi untuk pengajuan ini sudah pernah dicatat.'
                );
            }

            $adminId = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

            if ($adminId < 1) {
                throw new RuntimeException(
                    'Identitas Admin tidak ditemukan dalam sesi.'
                );
            }

            $keterangan = trim(
                (string) ($_POST['keterangan_distribusi'] ?? '')
            );

            if (mb_strlen($keterangan) > 2000) {
                throw new RuntimeException(
                    'Keterangan distribusi maksimal 2.000 karakter.'
                );
            }

            $insert = $pdo->prepare("
                INSERT INTO distribusi
                    (pengajuan_id, admin_id, tanggal_distribusi, keterangan)
                VALUES (?, ?, NOW(), ?)
            ");
            $insert->execute([$pengajuanId, $adminId, $keterangan]);

            $update = $pdo->prepare("
                UPDATE pengajuan
                SET status = 'didistribusi'
                WHERE id = ?
                  AND status = 'disetujui'
            ");
            $update->execute([$pengajuanId]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Status pengajuan gagal diperbarui.'
                );
            }

            $pdo->commit();

            flash('Distribusi barang berhasil dicatat.', 'success');
            redirect('distribusi.php');
            exit;
        }

        /*
         * VERIFIKASI ATAU PERMINTAAN PERBAIKAN BUKTI.
         */
        $stmt = $pdo->prepare("
            SELECT id, status, bukti_pengambilan, status_bukti
            FROM pengajuan
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$pengajuanId]);
        $pengajuan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pengajuan) {
            throw new RuntimeException('Pengajuan tidak ditemukan.');
        }

        if ($pengajuan['status'] !== 'didistribusi') {
            throw new RuntimeException(
                'Pengajuan belum didistribusikan atau sudah selesai.'
            );
        }

        if ($pengajuan['status_bukti'] !== 'menunggu_verifikasi') {
            throw new RuntimeException(
                'Bukti belum dikirim atau sudah pernah diproses.'
            );
        }

        $namaFile = basename(
            (string) ($pengajuan['bukti_pengambilan'] ?? '')
        );
        $lokasiFile = $uploadDir . $namaFile;

        if (
            $namaFile === '' ||
            !is_file($lokasiFile) ||
            !is_readable($lokasiFile)
        ) {
            throw new RuntimeException(
                'File bukti tidak ditemukan atau tidak dapat dibaca.'
            );
        }

        if ($aksi === 'verifikasi') {
            $cekDistribusi = $pdo->prepare("
                SELECT COUNT(*)
                FROM distribusi
                WHERE pengajuan_id = ?
            ");
            $cekDistribusi->execute([$pengajuanId]);

            if ((int) $cekDistribusi->fetchColumn() < 1) {
                throw new RuntimeException(
                    'Catatan distribusi belum ditemukan.'
                );
            }

            $keterangan = trim((string) ($_POST['keterangan'] ?? ''));

            if (mb_strlen($keterangan) > 2000) {
                throw new RuntimeException(
                    'Keterangan maksimal 2.000 karakter.'
                );
            }

            $update = $pdo->prepare("
                UPDATE pengajuan
                SET status = 'selesai',
                    status_bukti = 'terverifikasi',
                    catatan_bukti = NULL
                WHERE id = ?
                  AND status = 'didistribusi'
                  AND status_bukti = 'menunggu_verifikasi'
            ");
            $update->execute([$pengajuanId]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Status pengajuan gagal diperbarui.'
                );
            }

            if ($keterangan !== '') {
                $cekKolom = $pdo->query("SHOW COLUMNS FROM distribusi");
                $kolomDistribusi = $cekKolom->fetchAll(PDO::FETCH_COLUMN);

                if (in_array('keterangan', $kolomDistribusi, true)) {
                    $updateDistribusi = $pdo->prepare("
                        UPDATE distribusi
                        SET keterangan = ?
                        WHERE pengajuan_id = ?
                    ");
                    $updateDistribusi->execute([$keterangan, $pengajuanId]);
                }
            }

            $pdo->commit();

            flash(
                'Bukti penerimaan berhasil diverifikasi. Status pengajuan sekarang Selesai.',
                'success'
            );
        } else {
            $catatan = trim((string) ($_POST['catatan_bukti'] ?? ''));

            if ($catatan === '') {
                throw new RuntimeException(
                    'Alasan perbaikan bukti wajib diisi.'
                );
            }

            if (mb_strlen($catatan) > 2000) {
                throw new RuntimeException(
                    'Alasan perbaikan maksimal 2.000 karakter.'
                );
            }

            $update = $pdo->prepare("
                UPDATE pengajuan
                SET status_bukti = 'perlu_perbaikan',
                    catatan_bukti = ?
                WHERE id = ?
                  AND status = 'didistribusi'
                  AND status_bukti = 'menunggu_verifikasi'
            ");
            $update->execute([$catatan, $pengajuanId]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Status bukti gagal diperbarui.');
            }

            $pdo->commit();

            flash(
                'Bukti memerlukan perbaikan. Pengguna diminta mengirim ulang bukti penerimaan.',
                'success'
            );
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Kesalahan distribusi/verifikasi pengajuan #' .
            $pengajuanId . ': ' . $ex->getMessage()
        );

        // Pesan aturan bisnis boleh ditampilkan; detail database tidak.
        $pesanError = 'Proses gagal. Silakan coba kembali.';

        if (!($ex instanceof PDOException) && $ex instanceof RuntimeException) {
            $pesanError = $ex->getMessage();
        }

        flash($pesanError, 'danger');
    }

    redirect('distribusi.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| PENGAJUAN SIAP DIDISTRIBUSIKAN
|--------------------------------------------------------------------------
*/

$queryDistribusi = $pdo->prepare("
    SELECT
        p.*,
        u.nama AS nama_user,
        u.username,
        u.divisi
    FROM pengajuan p
    JOIN users u ON p.user_id = u.id
    WHERE p.status = 'disetujui'
      AND NOT EXISTS (
          SELECT 1
          FROM distribusi d
          WHERE d.pengajuan_id = p.id
      )
    ORDER BY p.id DESC
");
$queryDistribusi->execute();
$dataDistribusi = $queryDistribusi->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DAFTAR BUKTI YANG MENUNGGU VERIFIKASI
|--------------------------------------------------------------------------
*/

$query = $pdo->prepare("
    SELECT
        p.*,
        u.nama AS nama_user,
        u.username,
        u.divisi,
        d.id AS distribusi_id,
        d.tanggal_distribusi
    FROM pengajuan p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN distribusi d ON d.pengajuan_id = p.id
    WHERE p.status = 'didistribusi'
      AND p.status_bukti = 'menunggu_verifikasi'
      AND p.bukti_pengambilan IS NOT NULL
      AND p.bukti_pengambilan <> ''
    ORDER BY p.id DESC
");
$query->execute();
$data = $query->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DISTRIBUSI BERJALAN (SUDAH DICATAT, BELUM MENUNGGU VERIFIKASI)
|--------------------------------------------------------------------------
| Panel ini memastikan Admin tetap dapat mengirim pesan setelah distribusi
| dicatat, termasuk saat Pengguna belum mengunggah bukti penerimaan.
*/

$queryBerjalan = $pdo->prepare("
    SELECT
        p.*,
        u.nama AS nama_user,
        u.username,
        u.divisi,
        d.id AS distribusi_id,
        d.tanggal_distribusi,
        d.keterangan AS keterangan_distribusi
    FROM pengajuan p
    JOIN users u ON p.user_id = u.id
    JOIN distribusi d ON d.pengajuan_id = p.id
    WHERE p.status = 'didistribusi'
      AND p.status_bukti <> 'menunggu_verifikasi'
    ORDER BY d.tanggal_distribusi DESC, p.id DESC
");
$queryBerjalan->execute();
$dataDistribusiBerjalan = $queryBerjalan->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DETAIL BARANG UNTUK TABEL VERIFIKASI
|--------------------------------------------------------------------------
*/

$detailBarang = [];

if ($data) {
    $ids = array_column($data, 'id');
    $placeholder = implode(',', array_fill(0, count($ids), '?'));

    $detailQuery = $pdo->prepare("
        SELECT
            d.pengajuan_id,
            b.nama_barang,
            d.jumlah,
            d.satuan
        FROM detail_pengajuan d
        JOIN barang b ON d.barang_id = b.id
        WHERE d.pengajuan_id IN ($placeholder)
        ORDER BY d.id ASC
    ");
    $detailQuery->execute($ids);

    foreach ($detailQuery->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $detailBarang[$item['pengajuan_id']][] = $item;
    }
}

/*
|--------------------------------------------------------------------------
| DETAIL BARANG UNTUK TABEL DISTRIBUSI
|--------------------------------------------------------------------------
*/

$detailBarangDistribusi = [];

if ($dataDistribusi) {
    $idsDistribusi = array_column($dataDistribusi, 'id');
    $placeholderDistribusi = implode(
        ',',
        array_fill(0, count($idsDistribusi), '?')
    );

    $detailQueryDistribusi = $pdo->prepare("
        SELECT
            d.pengajuan_id,
            b.nama_barang,
            d.jumlah,
            d.satuan
        FROM detail_pengajuan d
        JOIN barang b ON d.barang_id = b.id
        WHERE d.pengajuan_id IN ($placeholderDistribusi)
        ORDER BY d.id ASC
    ");
    $detailQueryDistribusi->execute($idsDistribusi);

    foreach (
        $detailQueryDistribusi->fetchAll(PDO::FETCH_ASSOC) as $item
    ) {
        $detailBarangDistribusi[$item['pengajuan_id']][] = $item;
    }
}

/*
|--------------------------------------------------------------------------
| DETAIL BARANG UNTUK TABEL DISTRIBUSI BERJALAN
|--------------------------------------------------------------------------
*/

$detailBarangBerjalan = [];

if ($dataDistribusiBerjalan) {
    $idsBerjalan = array_column($dataDistribusiBerjalan, 'id');
    $placeholderBerjalan = implode(
        ',',
        array_fill(0, count($idsBerjalan), '?')
    );

    $detailQueryBerjalan = $pdo->prepare("
        SELECT
            d.pengajuan_id,
            b.nama_barang,
            d.jumlah,
            d.satuan
        FROM detail_pengajuan d
        JOIN barang b ON d.barang_id = b.id
        WHERE d.pengajuan_id IN ($placeholderBerjalan)
        ORDER BY d.id ASC
    ");
    $detailQueryBerjalan->execute($idsBerjalan);

    foreach (
        $detailQueryBerjalan->fetchAll(PDO::FETCH_ASSOC) as $item
    ) {
        $detailBarangBerjalan[$item['pengajuan_id']][] = $item;
    }
}

/*
|--------------------------------------------------------------------------
| DATA TUJUAN PESAN DISTRIBUSI
|--------------------------------------------------------------------------
| Penerima diambil dari pengajuan.user_id yang sudah di-JOIN dengan users.
| Data ini hanya untuk tampilan modal; backend tetap memvalidasi ulang.
*/

$pesanTargets = [];

foreach (
    array_merge($dataDistribusi, $data, $dataDistribusiBerjalan) as $p
) {
    $idTarget = (int) $p['id'];

    $pesanTargets[$idTarget] = [
        'id'                 => $idTarget,
        'user_id'            => (int) $p['user_id'],
        'nama_user'          => (string) ($p['nama_user'] ?? '-'),
        'username'           => (string) ($p['username'] ?? '-'),
        'nama_pengaju'       => (string) ($p['nama_pengaju'] ?? '-'),
        'divisi'             => (string) ($p['divisi_pengaju'] ?? $p['divisi'] ?? '-'),
        'status'             => (string) ($p['status'] ?? '-'),
        'tanggal'            => $p['tanggal'] ?? null,
        'distribusi_id'      => !empty($p['distribusi_id'])
            ? (int) $p['distribusi_id']
            : null,
        'tanggal_distribusi' => $p['tanggal_distribusi'] ?? null,
    ];
}

$templatePesanDistribusi = 'Barang Anda sedang didistribusikan. '
    . 'Jangan lupa mengirimkan bukti penerimaan setelah barang diterima.';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distribusi Barang - PLBN WINI</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
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

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100%;
            background: #2c3e50;
            color: #ecf0f1;
            padding: 20px;
            overflow-y: auto;
            z-index: 1000;
            transition: transform .3s ease;
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
            font-size: 16px;
            padding: 9px 0;
            color: #ecf0f1;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            color: #ecf0f1;
            padding: 12px 15px;
            text-decoration: none;
            border-radius: 5px;
            margin-bottom: 5px;
            transition: background .2s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #34495e;
            color: #fff;
        }

        .sidebar a .icon {
            width: 24px;
            margin-right: 4px;
            text-align: center;
            flex-shrink: 0;
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
            position: absolute;
            top: 10px;
            right: 15px;
            background: transparent;
            border: none;
            color: #ecf0f1;
            font-size: 28px;
            cursor: pointer;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        .main-content {
            margin-left: 250px;
            padding: 25px;
        }

        .header {
            background: #fff;
            padding: 18px 25px;
            border-radius: 14px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .05);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 {
            font-size: 22px;
            color: #0f172a;
            font-weight: 700;
        }

        .header h1 i {
            color: #3b82f6;
        }

        .logout {
            background: #e74c3c;
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            white-space: nowrap;
        }

        .menu-toggle {
            display: none;
            background: transparent;
            border: none;
            font-size: 28px;
            color: #0f172a;
            cursor: pointer;
        }

        .flash {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            overflow-wrap: anywhere;
        }

        .flash-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .flash-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .flash-warning {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde68a;
        }

        .flash-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .panel {
            background: #fff;
            padding: 25px;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .05);
        }

        .panel h3 {
            font-size: 18px;
            color: #0f172a;
            margin-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 12px;
        }

        .info-box {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 13px 15px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            border-radius: 9px;
            margin-bottom: 18px;
            line-height: 1.5;
            font-size: 14px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 1050px;
        }

        .table th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .barang-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .barang-list li {
            margin-bottom: 5px;
            line-height: 1.5;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            display: inline-block;
            white-space: nowrap;
        }

        .badge-menunggu {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-merah {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-hijau {
            background: #dcfce7;
            color: #166534;
        }

        .badge-biru {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn {
            border: none;
            border-radius: 7px;
            padding: 9px 13px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: .2s;
        }

        .btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .btn-success {
            background: #22c55e;
            color: #fff;
        }

        .btn-success:hover:not(:disabled) {
            background: #16a34a;
        }

        .btn-danger {
            background: #ef4444;
            color: #fff;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-secondary {
            background: #94a3b8;
            color: #fff;
        }

        .btn-secondary:hover {
            background: #64748b;
        }

        .btn-primary {
            background: #3b82f6;
            color: #fff;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .aksi-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 160px;
        }

        .aksi-stack form {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .aksi-stack .btn {
            width: 100%;
        }

        .pesan-target {
            padding: 13px 15px;
            margin-bottom: 16px;
            border: 1px solid #dbeafe;
            border-radius: 9px;
            background: #f8fbff;
            font-size: 13px;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .pesan-target strong {
            color: #0f172a;
        }

        .pesan-target .target-title {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 4px;
            color: #1e40af;
            font-weight: 700;
            font-size: 14px;
        }

        .pesan-help {
            margin-top: 7px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        .modal-footer .btn-row {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            padding: 15px;
            background: rgba(15, 23, 42, .65);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
        }

        .modal.show {
            display: flex;
        }

        .modal-dialog {
            background: #fff;
            border-radius: 16px;
            max-width: 620px;
            width: 100%;
            padding: 24px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 13px;
            margin-bottom: 18px;
        }

        .modal-header h4 {
            font-size: 19px;
            color: #0f172a;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #64748b;
        }

        .modal-body p {
            margin-bottom: 9px;
            overflow-wrap: anywhere;
        }

        .modal-footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
            margin-top: 18px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 7px;
            color: #1e293b;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #e2e8f0;
            border-radius: 9px;
            font-size: 14px;
            background: #f8fafc;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .12);
            background: #fff;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 90px;
        }

        .proof-image {
            display: block;
            width: 100%;
            max-height: 380px;
            object-fit: contain;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin: 14px 0;
        }

        .empty-state {
            text-align: center;
            padding: 40px 15px;
            color: #64748b;
        }

        .empty-state i {
            display: block;
            font-size: 45px;
            margin-bottom: 15px;
            color: #22c55e;
        }

        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0) !important;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
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
                padding: 15px;
            }

            .header h1 {
                font-size: 18px;
            }
        }

        @media (max-width: 480px) {
            .panel {
                padding: 16px;
            }

            .header {
                flex-wrap: wrap;
            }

            .logout {
                padding: 8px 10px;
                font-size: 12px;
            }

            .modal-dialog {
                padding: 17px;
            }
        }
    </style>
</head>
<body>

<div class="overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <button type="button"
            class="close-sidebar"
            id="closeSidebarBtn"
            aria-label="Tutup menu">&times;</button>

    <div class="sidebar-brand">
        <img src="../assets/img/plbn-wini.png"
             alt="Logo PLBN Wini"
             class="sidebar-logo">
        <h2>PLBN WINI</h2>
    </div>

    <a href="dashboard.php">
        <span class="icon"><i class="fas fa-chart-pie"></i></span><span>Dashboard</span>
    </a>

    <a href="barang.php">
        <span class="icon"><i class="fas fa-boxes"></i></span><span>Kelola Barang</span>
    </a>

    <a href="pengajuan.php">
        <span class="icon"><i class="fas fa-file-alt"></i></span><span>Kelola Pengajuan</span>
    </a>

    <a href="distribusi.php" class="active">
        <span class="icon"><i class="fas fa-truck"></i></span><span>Distribusi</span>
    </a>

    <a href="laporan.php">
        <span class="icon"><i class="fas fa-file-lines"></i></span><span>Laporan</span>
    </a>

    <a href="anggota.php">
        <span class="icon"><i class="fas fa-users"></i></span><span>Kelola Anggota</span>
    </a>

    <a href="../logout.php" class="logout-link">
        <span class="icon"><i class="fas fa-right-from-bracket"></i></span><span>Logout</span>
    </a>
</aside>

<main class="main-content">
    <div class="header">
        <div class="header-left">
            <button class="menu-toggle"
                    id="menuToggle"
                    aria-label="Buka menu">
                <i class="fas fa-bars"></i>
            </button>

            <h1><i class="fas fa-truck"></i> Distribusi Barang</h1>
        </div>

        <a href="../logout.php" class="logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <?php if ($flash): ?>
        <?php
        $flashType = $flash['type'] ?? 'info';
        $allowedFlash = ['success', 'danger', 'warning', 'info'];

        if (!in_array($flashType, $allowedFlash, true)) {
            $flashType = 'info';
        }

        $flashIcons = [
            'success' => 'check-circle',
            'danger' => 'exclamation-circle',
            'warning' => 'exclamation-triangle',
            'info' => 'info-circle'
        ];
        ?>
        <div class="flash flash-<?= e($flashType) ?>">
            <i class="fas fa-<?= e($flashIcons[$flashType]) ?>"></i>
            <span><?= e($flash['msg'] ?? '') ?></span>
        </div>
    <?php endif; ?>

    <!-- PANEL DISTRIBUSI: menggunakan gaya panel dan tabel awal -->
    <section class="panel" style="margin-bottom:25px;">
        <h3>
            <i class="fas fa-truck" style="color:#3b82f6;"></i>
            Pengajuan Siap Didistribusikan
        </h3>

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div>
                Daftar ini berisi pengajuan yang telah disetujui dan belum
                memiliki catatan distribusi. Stok tidak akan dikurangi lagi
                ketika Admin mencatat distribusi.
            </div>
        </div>

        <?php if ($dataDistribusi): ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                    <tr>
                        <th>No.</th>
                        <th>Pengajuan</th>
                        <th>Nama Pengaju</th>
                        <th>Divisi</th>
                        <th>Daftar Barang</th>
                        <th>Tanggal Pengajuan</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($dataDistribusi as $index => $p): ?>
                        <?php
                        $idDistribusi = (int) $p['id'];
                        $itemsDistribusi = $detailBarangDistribusi[$idDistribusi] ?? [];
                        ?>

                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><strong>#<?= $idDistribusi ?></strong></td>

                            <td>
                                <?= e($p['nama_pengaju'] ?? $p['nama_user'] ?? '-') ?>
                            </td>

                            <td>
                                <?= e($p['divisi_pengaju'] ?? $p['divisi'] ?? '-') ?>
                            </td>

                            <td>
                                <?php if ($itemsDistribusi): ?>
                                    <ul class="barang-list">
                                        <?php foreach ($itemsDistribusi as $item): ?>
                                            <li>
                                                <i class="fas fa-box" style="color:#64748b;"></i>
                                                <?= e($item['nama_barang']) ?>
                                                (<?= e($item['jumlah']) ?>
                                                <?= e($item['satuan']) ?>)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td><?= e(tanggalTampil($p['tanggal'])) ?></td>

                            <td>
                                <div class="aksi-stack">
                                    <form method="POST"
                                          onsubmit="return confirm('Catat distribusi barang untuk pengajuan #<?= $idDistribusi ?>?');">
                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= e($_SESSION['csrf_distribusi']) ?>">

                                        <input type="hidden"
                                               name="pengajuan_id"
                                               value="<?= $idDistribusi ?>">

                                        <input type="hidden"
                                               name="aksi"
                                               value="distribusikan">

                                        <div class="form-group">
                                            <textarea class="form-control"
                                                      name="keterangan_distribusi"
                                                      maxlength="2000"
                                                      rows="2"
                                                      placeholder="Keterangan distribusi (opsional)"></textarea>
                                        </div>

                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-truck"></i>
                                            Catat Distribusi
                                        </button>
                                    </form>

                                    <button type="button"
                                            class="btn btn-primary"
                                            onclick="openModal('pesanModal<?= $idDistribusi ?>')">
                                        <i class="fas fa-envelope"></i>
                                        Kirim Pesan
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <p><strong>Tidak ada pengajuan yang siap didistribusikan.</strong></p>
                <p style="margin-top:8px;font-size:14px;">
                    Pengajuan yang sudah disetujui dan belum didistribusikan
                    akan muncul di sini.
                </p>
            </div>
        <?php endif; ?>
    </section>

    <!-- PANEL VERIFIKASI BUKTI: tampilan awal tetap dipertahankan -->
    <section class="panel">
        <h3>
            <i class="fas fa-clipboard-check" style="color:#3b82f6;"></i>
            Verifikasi Bukti Penerimaan Barang
        </h3>

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div>
                Halaman ini menampilkan pengajuan yang barangnya sudah
                didistribusikan dan bukti penerimaannya menunggu verifikasi.
                Status pengajuan hanya menjadi <strong>Selesai</strong>
                setelah Admin memverifikasi bukti.
            </div>
        </div>

        <?php if ($data): ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                    <tr>
                        <th>No.</th>
                        <th>Pengajuan</th>
                        <th>Nama Pengaju</th>
                        <th>Divisi</th>
                        <th>Status Kepegawaian</th>
                        <th>Daftar Barang</th>
                        <th>Tanggal Pengajuan</th>
                        <th>Status Bukti</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($data as $index => $p): ?>
                        <?php
                        $id = (int) $p['id'];
                        $namaFile = basename((string) ($p['bukti_pengambilan'] ?? ''));
                        $fileAda = $namaFile !== '' &&
                            is_file($uploadDir . $namaFile) &&
                            is_readable($uploadDir . $namaFile);
                        $urlBukti = $uploadUrl . rawurlencode($namaFile);
                        $items = $detailBarang[$id] ?? [];
                        ?>

                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><strong>#<?= $id ?></strong></td>

                            <td>
                                <?= e($p['nama_pengaju'] ?? $p['nama_user'] ?? '-') ?>
                            </td>

                            <td>
                                <?= e($p['divisi_pengaju'] ?? $p['divisi'] ?? '-') ?>
                            </td>

                            <td><?= e($p['status_kepegawaian'] ?? '-') ?></td>

                            <td>
                                <?php if ($items): ?>
                                    <ul class="barang-list">
                                        <?php foreach ($items as $item): ?>
                                            <li>
                                                <i class="fas fa-box" style="color:#64748b;"></i>
                                                <?= e($item['nama_barang']) ?>
                                                (<?= e($item['jumlah']) ?>
                                                <?= e($item['satuan']) ?>)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td><?= e(tanggalTampil($p['tanggal'])) ?></td>

                            <td>
                                <span class="badge badge-menunggu">
                                    <i class="fas fa-clock"></i>
                                    Menunggu Verifikasi
                                </span>
                            </td>

                            <td>
                                <div class="aksi-stack">
                                    <button type="button"
                                            class="btn btn-primary"
                                            onclick="openModal('buktiModal<?= $id ?>')">
                                        <i class="fas fa-eye"></i>
                                        Periksa Bukti
                                    </button>

                                    <button type="button"
                                            class="btn btn-success"
                                            onclick="openModal('pesanModal<?= $id ?>')">
                                        <i class="fas fa-envelope"></i>
                                        Kirim Pesan
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p><strong>Tidak ada bukti yang menunggu verifikasi.</strong></p>
                <p style="margin-top:8px;font-size:14px;">
                    Pengajuan akan muncul setelah barang didistribusikan
                    dan Pengguna mengirim bukti penerimaan.
                </p>
            </div>
        <?php endif; ?>
    </section>

    <!-- PANEL DISTRIBUSI BERJALAN: tetap bisa dikirimi pesan -->
    <section class="panel" style="margin-top:25px;">
        <h3>
            <i class="fas fa-truck-fast" style="color:#3b82f6;"></i>
            Distribusi Sedang Berjalan
        </h3>

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div>
                Distribusi yang sudah dicatat tetap tampil di sini agar Admin
                dapat mengirim pesan kepada Pengguna, termasuk sebelum bukti
                penerimaan diunggah. Pengiriman pesan tidak mengubah status
                distribusi maupun status bukti.
            </div>
        </div>

        <?php if ($dataDistribusiBerjalan): ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                    <tr>
                        <th>No.</th>
                        <th>Pengajuan</th>
                        <th>Nama Pengaju</th>
                        <th>Divisi</th>
                        <th>Daftar Barang</th>
                        <th>Tanggal Pengajuan</th>
                        <th>Tanggal Distribusi</th>
                        <th>Status Bukti</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($dataDistribusiBerjalan as $index => $p): ?>
                        <?php
                        $idBerjalan = (int) $p['id'];
                        $itemsBerjalan = $detailBarangBerjalan[$idBerjalan] ?? [];
                        $statusBuktiBerjalan = (string) ($p['status_bukti'] ?? 'belum_dikirim');

                        $badgeBukti = 'badge-menunggu';
                        if ($statusBuktiBerjalan === 'perlu_perbaikan') {
                            $badgeBukti = 'badge-merah';
                        } elseif ($statusBuktiBerjalan === 'terverifikasi') {
                            $badgeBukti = 'badge-hijau';
                        } elseif ($statusBuktiBerjalan === 'menunggu_verifikasi') {
                            $badgeBukti = 'badge-biru';
                        }
                        ?>

                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><strong>#<?= $idBerjalan ?></strong></td>

                            <td>
                                <?= e($p['nama_pengaju'] ?? $p['nama_user'] ?? '-') ?>
                            </td>

                            <td>
                                <?= e($p['divisi_pengaju'] ?? $p['divisi'] ?? '-') ?>
                            </td>

                            <td>
                                <?php if ($itemsBerjalan): ?>
                                    <ul class="barang-list">
                                        <?php foreach ($itemsBerjalan as $item): ?>
                                            <li>
                                                <i class="fas fa-box" style="color:#64748b;"></i>
                                                <?= e($item['nama_barang']) ?>
                                                (<?= e($item['jumlah']) ?>
                                                <?= e($item['satuan']) ?>)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td><?= e(tanggalTampil($p['tanggal'])) ?></td>

                            <td>
                                <?= e(tanggalTampil($p['tanggal_distribusi'] ?? null)) ?>
                            </td>

                            <td>
                                <span class="badge <?= e($badgeBukti) ?>">
                                    <?= e(labelStatusBukti($statusBuktiBerjalan)) ?>
                                </span>
                            </td>

                            <td>
                                <button type="button"
                                        class="btn btn-primary"
                                        onclick="openModal('pesanModal<?= $idBerjalan ?>')">
                                    <i class="fas fa-envelope"></i>
                                    Kirim Pesan
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-truck"></i>
                <p><strong>Tidak ada distribusi yang sedang berjalan.</strong></p>
                <p style="margin-top:8px;font-size:14px;">
                    Distribusi yang sudah dicatat akan tampil di sini sampai
                    bukti penerimaannya menunggu verifikasi atau proses selesai.
                </p>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php foreach ($data as $p): ?>
    <?php
    $id = (int) $p['id'];
    $namaFile = basename((string) ($p['bukti_pengambilan'] ?? ''));
    $fileAda = $namaFile !== '' &&
        is_file($uploadDir . $namaFile) &&
        is_readable($uploadDir . $namaFile);
    $urlBukti = $uploadUrl . rawurlencode($namaFile);
    ?>

    <div class="modal" id="buktiModal<?= $id ?>">
        <div class="modal-dialog">
            <div class="modal-header">
                <h4>
                    <i class="fas fa-camera" style="color:#3b82f6;"></i>
                    Bukti Penerimaan #<?= $id ?>
                </h4>

                <button type="button"
                        class="btn-close"
                        onclick="closeModal('buktiModal<?= $id ?>')"
                        aria-label="Tutup">&times;</button>
            </div>

            <div class="modal-body">
                <p>
                    <strong>Nama Pengaju:</strong>
                    <?= e($p['nama_pengaju'] ?? $p['nama_user'] ?? '-') ?>
                </p>

                <p>
                    <strong>Divisi:</strong>
                    <?= e($p['divisi_pengaju'] ?? $p['divisi'] ?? '-') ?>
                </p>

                <p>
                    <strong>Tanggal Pengajuan:</strong>
                    <?= e(tanggalTampil($p['tanggal'])) ?>
                </p>

                <?php if ($fileAda): ?>
                    <a href="<?= e($urlBukti) ?>"
                       target="_blank"
                       rel="noopener noreferrer">
                        <img src="<?= e($urlBukti) ?>"
                             alt="Bukti penerimaan pengajuan #<?= $id ?>"
                             class="proof-image">
                    </a>

                    <p style="font-size:13px;color:#64748b;">
                        Klik foto untuk melihat ukuran penuh.
                    </p>
                <?php else: ?>
                    <div class="flash flash-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        File bukti tidak ditemukan. Periksa folder penyimpanan bukti.
                    </div>
                <?php endif; ?>

                <p style="margin-top:12px;">
                    <strong>Petunjuk:</strong>
                    Pastikan foto menunjukkan bukti penerimaan barang
                    yang sesuai dengan pengajuan.
                </p>
            </div>

            <div class="modal-footer">
                <form method="POST"
                      onsubmit="return confirm('Verifikasi bukti ini dan ubah status pengajuan menjadi Selesai?');">
                    <input type="hidden"
                           name="csrf_token"
                           value="<?= e($_SESSION['csrf_distribusi']) ?>">

                    <input type="hidden"
                           name="pengajuan_id"
                           value="<?= $id ?>">

                    <input type="hidden" name="aksi" value="verifikasi">

                    <div class="form-group">
                        <label for="keterangan<?= $id ?>">
                            Keterangan tambahan (opsional)
                        </label>

                        <textarea class="form-control"
                                  name="keterangan"
                                  id="keterangan<?= $id ?>"
                                  maxlength="2000"
                                  placeholder="Catatan tambahan verifikasi..."></textarea>
                    </div>

                    <button type="submit"
                            class="btn btn-success"
                            <?= !$fileAda ? 'disabled' : '' ?>>
                        <i class="fas fa-check-circle"></i>
                        Verifikasi dan Selesaikan
                    </button>
                </form>

                <hr style="border:0;border-top:1px solid #e2e8f0;margin:18px 0;">

                <form method="POST"
                      onsubmit="return confirm('Minta Pengguna memperbaiki dan mengirim ulang bukti?');">
                    <input type="hidden"
                           name="csrf_token"
                           value="<?= e($_SESSION['csrf_distribusi']) ?>">

                    <input type="hidden"
                           name="pengajuan_id"
                           value="<?= $id ?>">

                    <input type="hidden" name="aksi" value="perbaikan">

                    <div class="form-group">
                        <label for="catatan<?= $id ?>">
                            Alasan perlu perbaikan
                            <span style="color:#dc2626;">*</span>
                        </label>

                        <textarea class="form-control"
                                  name="catatan_bukti"
                                  id="catatan<?= $id ?>"
                                  maxlength="2000"
                                  required
                                  placeholder="Contoh: Foto kurang jelas. Mohon unggah foto bukti penerimaan yang lebih jelas."></textarea>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;">
                        <button type="button"
                                class="btn btn-secondary"
                                onclick="closeModal('buktiModal<?= $id ?>')">
                            Tutup
                        </button>

                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-undo"></i>
                            Minta Perbaikan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($pesanTargets as $target): ?>
    <?php
    $idPesan = (int) $target['id'];
    $idKeyPesan = bin2hex(random_bytes(32));
    ?>
    <div class="modal" id="pesanModal<?= $idPesan ?>">
        <div class="modal-dialog">
            <div class="modal-header">
                <h4>
                    <i class="fas fa-envelope" style="color:#3b82f6;"></i>
                    Kirim Pesan Distribusi
                </h4>

                <button type="button"
                        class="btn-close"
                        onclick="closeModal('pesanModal<?= $idPesan ?>')"
                        aria-label="Tutup">&times;</button>
            </div>

            <form method="POST"
                  onsubmit="return siapkanKirimPesan(this);">
                <div class="modal-body">
                    <div class="pesan-target">
                        <div class="target-title">
                            <i class="fas fa-user-check"></i>
                            Tujuan Pesan
                        </div>
                        <div>
                            <strong>Penerima:</strong>
                            <?= e($target['nama_user']) ?>
                            (<?= e($target['username']) ?>)
                        </div>
                        <div>
                            <strong>Pengajuan:</strong>
                            #<?= $idPesan ?>
                        </div>
                        <div>
                            <strong>Divisi:</strong>
                            <?= e($target['divisi']) ?>
                        </div>
                        <div>
                            <strong>Status pengajuan:</strong>
                            <?= e(labelStatusPengajuan($target['status'])) ?>
                        </div>
                        <?php if (!empty($target['distribusi_id'])): ?>
                            <div>
                                <strong>Distribusi:</strong>
                                #<?= (int) $target['distribusi_id'] ?>
                                <?php if (!empty($target['tanggal_distribusi'])): ?>
                                    &middot;
                                    <?= e(tanggalTampil($target['tanggal_distribusi'])) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= e($_SESSION['csrf_distribusi']) ?>">

                    <input type="hidden"
                           name="aksi"
                           value="kirim_pesan">

                    <input type="hidden"
                           name="pengajuan_id"
                           value="<?= $idPesan ?>">

                    <input type="hidden"
                           name="idempotency_key"
                           value="<?= e($idKeyPesan) ?>">

                    <div class="form-group">
                        <label for="pesan<?= $idPesan ?>">
                            Isi Pesan
                            <span style="color:#dc2626;">*</span>
                        </label>

                        <textarea class="form-control"
                                  name="pesan"
                                  id="pesan<?= $idPesan ?>"
                                  rows="5"
                                  maxlength="2000"
                                  required><?= e($templatePesanDistribusi) ?></textarea>

                        <div class="pesan-help">
                            Templat pesan dapat diubah sebelum dikirim. Pesan
                            akan tampil di menu <strong>Pesan</strong> pada akun
                            Pengguna yang bersangkutan.
                        </div>
                    </div>

                    <div class="info-box" style="margin-bottom:0;">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            Mengirim pesan tidak mengubah status distribusi,
                            tidak menandai bukti sudah diterima, dan tidak
                            mengubah alur persetujuan.
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <div class="btn-row">
                        <button type="button"
                                class="btn btn-secondary"
                                onclick="closeModal('pesanModal<?= $idPesan ?>')">
                            Batal
                        </button>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i>
                            Kirim Pesan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<script>
function siapkanKirimPesan(form) {
    const textarea = form.querySelector('textarea[name="pesan"]');
    const isi = textarea ? textarea.value.trim() : '';

    if (isi === '') {
        if (textarea) {
            textarea.focus();
        }

        alert('Isi pesan wajib diisi.');
        return false;
    }

    const tombol = form.querySelector('button[type="submit"]');

    if (tombol) {
        tombol.disabled = true;
        tombol.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim...';
    }

    return true;
}

function openModal(id) {
    const modal = document.getElementById(id);

    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);

    if (modal) {
        modal.classList.remove('show');

        if (!document.querySelector('.modal.show')) {
            document.body.style.overflow = '';
        }
    }
}

document.querySelectorAll('.modal').forEach(function(modal) {
    modal.addEventListener('click', function(event) {
        if (event.target === modal) {
            modal.classList.remove('show');

            if (!document.querySelector('.modal.show')) {
                document.body.style.overflow = '';
            }
        }
    });
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.show').forEach(function(modal) {
            modal.classList.remove('show');
        });

        document.body.style.overflow = '';
    }
});

/* Sidebar responsif */
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

        if (!document.querySelector('.modal.show')) {
            document.body.style.overflow = '';
        }
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });
})();
</script>

</body>
</html>
