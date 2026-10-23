<?php

// =====================================================
// CONTOH SKRIP SEED PENGGUNA AWAL
// Salin berkas ini menjadi seed.php, ganti password contoh
// dengan password yang kuat, lalu jalankan dari CLI:
//
//     php seed.php
//
// Jangan mengunggah berkas seed.php yang berisi password asli.
// =====================================================

// Skrip ini hanya untuk dijalankan dari terminal/CLI.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip seed hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/config/database.php';

$users = [
    ['admin', 'GANTI_PASSWORD_ADMIN', 'admin', 'Administrator', null, null],
    ['user', 'GANTI_PASSWORD_USER', 'user', 'John Doe', 'Keuangan', 'PNS'],
];

foreach ($users as $u) {
    [$username, $password, $role, $nama, $divisi, $status] = $u;

    // Jangan menimpa akun yang sudah ada.
    $cek = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $cek->execute([$username]);

    if ($cek->fetchColumn() !== false) {
        echo "User {$username} sudah ada, dilewati." . PHP_EOL;
        continue;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO users
            (username, password, role, nama, divisi, status_kepegawaian)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$username, $hash, $role, $nama, $divisi, $status]);

    echo "User {$username} berhasil dibuat." . PHP_EOL;
}

echo "Selesai." . PHP_EOL;
