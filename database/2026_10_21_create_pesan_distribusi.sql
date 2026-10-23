-- =====================================================
-- PESAN DISTRIBUSI ADMIN -> PENGGUNA
-- Dibuat: 2026-10-21
--
-- Tabel ini menyimpan pesan yang dikirim Admin dari menu
-- Distribusi kepada Pengguna pemilik pengajuan. Satu pesan
-- selalu terhubung ke pengajuan (dan distribusi bila sudah ada).
-- =====================================================

CREATE TABLE IF NOT EXISTS `pesan_distribusi` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `pengajuan_id` INT NOT NULL,
    `distribusi_id` INT DEFAULT NULL,
    `admin_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `pesan` TEXT NOT NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `dibaca_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pesan_distribusi_idempotency` (`idempotency_key`),
    KEY `idx_pesan_distribusi_user` (`user_id`, `dibaca_at`),
    KEY `idx_pesan_distribusi_pengajuan` (`pengajuan_id`),
    KEY `idx_pesan_distribusi_distribusi` (`distribusi_id`),
    KEY `idx_pesan_distribusi_created` (`created_at`),
    CONSTRAINT `fk_pesan_distribusi_pengajuan`
        FOREIGN KEY (`pengajuan_id`) REFERENCES `pengajuan` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pesan_distribusi_distribusi`
        FOREIGN KEY (`distribusi_id`) REFERENCES `distribusi` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_pesan_distribusi_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
