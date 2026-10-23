<?php

// =====================================================
// MULAI SESSION (hanya jika belum aktif)
// =====================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================================================
// FUNGSI-FUNGSI LAINNYA
// =====================================================

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function flash($msg, $type = 'success') {
    $allowed = ['success', 'danger', 'warning', 'info'];

    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    $_SESSION['flash'] = [
        'msg'  => (string) $msg,
        'type' => $type,
    ];
}

/**
 * Ambil pesan flash lalu hapus dari session.
 * Mendukung key lama "message" agar halaman lama tetap kompatibel.
 */
function getFlash() {
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    $allowed = ['success', 'danger', 'warning', 'info'];
    $type = $flash['type'] ?? 'info';

    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    $msg = $flash['msg'] ?? $flash['message'] ?? '';

    if ((string) $msg === '') {
        return null;
    }

    return [
        'msg'  => (string) $msg,
        'type' => $type,
    ];
}

function showFlash() {
    $flash = getFlash();

    if (!$flash) {
        return;
    }

    $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
    $msg  = htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8');

    echo "<div class='alert alert-$type alert-dismissible fade show' role='alert'>
            $msg
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
          </div>";
}

/**
 * Token CSRF per form/aksi.
 */
function csrfToken($key = 'default') {
    if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }

    if (
        empty($_SESSION['csrf_tokens'][$key]) ||
        !is_string($_SESSION['csrf_tokens'][$key])
    ) {
        $_SESSION['csrf_tokens'][$key] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_tokens'][$key];
}

function csrfValid($key, $token) {
    return is_string($token)
        && !empty($_SESSION['csrf_tokens'][$key])
        && is_string($_SESSION['csrf_tokens'][$key])
        && hash_equals($_SESSION['csrf_tokens'][$key], $token);
}

function csrfRotate($key = 'default') {
    unset($_SESSION['csrf_tokens'][$key]);
}

/**
 * Jumlah pesan distribusi yang belum dibaca oleh seorang Pengguna.
 */
function hitungPesanBelumDibaca(PDO $pdo, $userId)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);

    if ($userId === false || (int) $userId < 1) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM pesan_distribusi
         WHERE user_id = ?
           AND dibaca_at IS NULL"
    );
    $stmt->execute([(int) $userId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Label status pengajuan yang konsisten untuk halaman Pengguna.
 */
function labelStatusPengajuan($status)
{
    $status = (string) $status;

    $label = [
        'pending'           => 'Menunggu Persetujuan',
        'diajukan'          => 'Menunggu Persetujuan',
        'diteruskan'        => 'Diteruskan',
        'disetujui'         => 'Disetujui',
        'disetujui_vendor'  => 'Disetujui',
        'ditolak'           => 'Ditolak',
        'ditolak_vendor'    => 'Ditolak',
        'didistribusi'      => 'Didistribusikan',
        'selesai'           => 'Selesai',
    ];

    return $label[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Label status bukti penerimaan barang.
 */
function labelStatusBukti($status)
{
    $status = (string) $status;

    $label = [
        'belum_dikirim'       => 'Belum dikirim',
        'menunggu_verifikasi' => 'Menunggu verifikasi',
        'perlu_perbaikan'     => 'Perlu dikirim ulang',
        'terverifikasi'       => 'Terverifikasi',
    ];

    return $label[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Progres tampilan pengajuan untuk halaman Pengguna.
 *
 * Dipakai bersama oleh halaman Riwayat Pengajuan dan Detail Riwayat
 * Pengajuan agar keterangan status tetap konsisten.
 */
function progresPengajuan($status, $statusBukti = 'belum_dikirim')
{
    $status = (string) $status;
    $statusBukti = (string) $statusBukti;

    if (in_array($status, ['ditolak', 'ditolak_vendor'], true)) {
        return [
            'step' => 0,
            'ditolak' => true,
            'keterangan' => 'Pengajuan ditolak. Hubungi Admin untuk informasi lebih lanjut.'
        ];
    }

    if ($status === 'selesai') {
        return [
            'step' => 5,
            'ditolak' => false,
            'keterangan' => 'Bukti penerimaan telah disetujui Admin. Pengajuan selesai.'
        ];
    }

    if ($statusBukti === 'menunggu_verifikasi') {
        return [
            'step' => 4,
            'ditolak' => false,
            'keterangan' => 'Bukti penerimaan sudah dikirim dan sedang diperiksa Admin.'
        ];
    }

    if ($statusBukti === 'perlu_perbaikan') {
        return [
            'step' => 3,
            'ditolak' => false,
            'keterangan' => 'Bukti penerimaan perlu diperbaiki dan dikirim ulang.'
        ];
    }

    if ($status === 'didistribusi') {
        return [
            'step' => 3,
            'ditolak' => false,
            'keterangan' => 'Distribusi sedang berlangsung. Setelah menerima barang, kirim foto bukti penerimaan.'
        ];
    }

    if (in_array($status, ['disetujui', 'disetujui_vendor'], true)) {
        return [
            'step' => 3,
            'ditolak' => false,
            'keterangan' => 'Pengajuan disetujui. Tunggu Admin memulai distribusi barang.'
        ];
    }

    if ($status === 'diteruskan') {
        return [
            'step' => 2,
            'ditolak' => false,
            'keterangan' => 'Pengajuan sedang diproses.'
        ];
    }

    return [
        'step' => 1,
        'ditolak' => false,
        'keterangan' => 'Pengajuan berhasil dikirim dan menunggu pemeriksaan Admin.'
    ];
}

/**
 * Status pengajuan yang masih menahan (mereservasi) stok.
 *
 * Stok fisik baru dikurangi ketika Admin menyetujui pengajuan. Selama
 * pengajuan masih menunggu, jumlahnya tetap dihitung sebagai reservasi agar
 * pengajuan lain tidak dapat mengambil stok yang sama.
 */
function statusPengajuanMenahanStok()
{
    return ['pending', 'diajukan', 'diteruskan'];
}

/**
 * Ambil stok fisik dan stok tersedia (stok fisik - jumlah yang masih
 * menunggu persetujuan) untuk sekumpulan barang.
 *
 * @param PDO      $pdo
 * @param int[]    $barangIds
 * @param int|null $kecualiPengajuanId Abaikan reservasi dari pengajuan ini
 *                                     (dipakai saat Admin mengedit pengajuan).
 * @param bool     $kunciBaris         Gunakan SELECT ... FOR UPDATE. Wajib
 *                                     dipanggil di dalam transaksi.
 * @return array<int, array{id:int,nama_barang:string,satuan:string,stok:int,terpesan:int,tersedia:int}>
 */
function ambilStokTersediaBarang(PDO $pdo, array $barangIds, $kecualiPengajuanId = null, $kunciBaris = false)
{
    $ids = [];

    foreach ($barangIds as $barangId) {
        $barangId = filter_var($barangId, FILTER_VALIDATE_INT);

        if ($barangId !== false && (int) $barangId > 0) {
            $ids[(int) $barangId] = (int) $barangId;
        }
    }

    $ids = array_values($ids);
    sort($ids);

    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Kunci baris barang dalam urutan id yang sama agar transaksi yang
    // berjalan bersamaan tidak saling mengunci dalam urutan berbeda.
    $sql = "SELECT id, nama_barang, satuan, COALESCE(stok, 0) AS stok
            FROM barang
            WHERE id IN ($placeholders)
            ORDER BY id";

    if ($kunciBaris) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);

    $hasil = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $stok = (int) $row['stok'];

        $hasil[$id] = [
            'id'          => $id,
            'nama_barang' => (string) $row['nama_barang'],
            'satuan'      => (string) $row['satuan'],
            'stok'        => $stok,
            'terpesan'    => 0,
            'tersedia'    => max(0, $stok),
        ];
    }

    $status = statusPengajuanMenahanStok();
    $statusPlaceholders = implode(',', array_fill(0, count($status), '?'));
    $params = array_merge($ids, $status);
    $excludeSql = '';

    if ($kecualiPengajuanId !== null) {
        $kecualiPengajuanId = (int) $kecualiPengajuanId;

        if ($kecualiPengajuanId > 0) {
            $excludeSql = " AND p.id <> ?";
            $params[] = $kecualiPengajuanId;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT dp.barang_id, COALESCE(SUM(dp.jumlah), 0) AS terpesan
         FROM detail_pengajuan dp
         JOIN pengajuan p ON p.id = dp.pengajuan_id
         WHERE dp.barang_id IN ($placeholders)
           AND p.status IN ($statusPlaceholders)
           $excludeSql
         GROUP BY dp.barang_id"
    );
    $stmt->execute($params);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['barang_id'];

        if (!isset($hasil[$id])) {
            continue;
        }

        $terpesan = max(0, (int) $row['terpesan']);
        $hasil[$id]['terpesan'] = $terpesan;
        $hasil[$id]['tersedia'] = max(0, $hasil[$id]['stok'] - $terpesan);
    }

    return $hasil;
}

/**
 * Pesan kesalahan stok yang konsisten untuk frontend dan backend.
 */
function pesanStokTidakCukup(array $barang, $jumlah)
{
    $nama = trim((string) ($barang['nama_barang'] ?? 'barang'));
    $satuan = trim((string) ($barang['satuan'] ?? ''));
    $tersedia = max(0, (int) ($barang['tersedia'] ?? 0));

    if ($satuan === '') {
        $satuan = 'unit';
    }

    return 'Jumlah pengajuan melebihi stok yang tersedia untuk "'
        . $nama . '". Stok saat ini hanya ' . $tersedia . ' ' . $satuan . '.';
}
