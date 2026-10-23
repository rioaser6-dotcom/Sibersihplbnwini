<?php

// =====================================================
// CONTOH KONFIGURASI DATABASE
// Salin berkas ini menjadi config/database.php, lalu sesuaikan
// nilai host, nama database, username, dan password.
// =====================================================

// Zona waktu aplikasi: PLBN Wini berada di wilayah WITA (UTC+8).
date_default_timezone_set('Asia/Makassar');

$host = 'localhost';
$dbname = 'db_plbn_wini';
$username = 'root';
$password = ''; // Isi dengan password database Anda.

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    // Detail kesalahan hanya dicatat di log server, tidak ditampilkan ke pengguna.
    error_log('Koneksi database gagal: ' . $e->getMessage());
    http_response_code(500);
    die('Koneksi database gagal. Silakan hubungi administrator.');
}
