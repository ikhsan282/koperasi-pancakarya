-- Koperasi Pancakarya Database Schema
-- Fresh install schema - single source of truth
-- DB: koperasi_pancakarya

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+07:00";

CREATE DATABASE IF NOT EXISTS `koperasi_pancakarya` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `koperasi_pancakarya`;

START TRANSACTION;

-- Roles table
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`name`, `description`) VALUES
('Super Admin', 'Akses penuh ke semua fitur'),
('Admin', 'Manajemen anggota, simpanan, pinjaman'),
('Bendahara', 'Pengelolaan keuangan dan laporan'),
('Anggota', 'Akses terbatas, lihat data sendiri');

-- Permissions table
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `module` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`name`, `description`, `module`) VALUES
('members.view', 'Lihat daftar anggota', 'members'),
('members.create', 'Tambah anggota baru', 'members'),
('members.edit', 'Edit data anggota', 'members'),
('members.delete', 'Hapus anggota', 'members'),
('savings.view', 'Lihat simpanan', 'savings'),
('savings.create', 'Tambah simpanan', 'savings'),
('savings.edit', 'Edit simpanan', 'savings'),
('savings.delete', 'Hapus simpanan', 'savings'),
('savings.withdraw', 'Tarik simpanan', 'savings'),
('loans.view', 'Lihat pinjaman', 'loans'),
('loans.create', 'Tambah pinjaman', 'loans'),
('loans.edit', 'Edit pinjaman', 'loans'),
('loans.delete', 'Hapus pinjaman', 'loans'),
('loans.approve', 'Setujui pinjaman', 'loans'),
('loans.send_reminder', 'Kirim pengingat jatuh tempo', 'loans'),
('reports.view', 'Lihat laporan', 'reports'),
('reports.export', 'Ekspor laporan', 'reports'),
('shu.view', 'Lihat SHU', 'shu'),
('shu.manage', 'Kelola distribusi SHU', 'shu'),
('users.manage', 'Kelola pengguna', 'users');

-- Role permissions junction
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Super Admin gets all permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

-- Admin gets most permissions except users.manage
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` WHERE `name` != 'users.manage';

-- Bendahara gets view and reports
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` WHERE `name` LIKE '%.view' OR `name` LIKE 'reports.%';

-- Anggota gets only view permissions (SHU excluded: its pages list every member's data)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions` WHERE `name` LIKE '%.view' AND `module` != 'shu';

-- Users table
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`username`, `email`, `password`, `full_name`, `role_id`) VALUES
('admin', 'admin@koperasi.test', '$2y$12$oucBlvl6RGkxgjQ0iAF2IehCiJI2tn9epJCvpnW/A0BBFcRKnbTfu', 'Administrator', 1);

-- Members table
CREATE TABLE `members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `id_number` varchar(20) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text,
  `join_date` date NOT NULL,
  `status` enum('active','inactive','resigned') NOT NULL DEFAULT 'active',
  `photo` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_number` (`member_number`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Savings types
CREATE TABLE `savings_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `interest_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `min_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `savings_types` (`name`, `description`, `interest_rate`, `min_balance`) VALUES
('Simpanan Pokok', 'Simpanan pokok wajib anggota', 0.00, 100000.00),
('Simpanan Wajib', 'Simpanan wajib bulanan', 0.00, 50000.00),
('Simpanan Sukarela', 'Simpanan sukarela anggota', 2.00, 0.00);

-- Savings accounts
CREATE TABLE `savings_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `savings_type_id` int(11) NOT NULL,
  `account_number` varchar(20) NOT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','closed') NOT NULL DEFAULT 'active',
  `opened_date` date NOT NULL,
  `closed_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_number` (`account_number`),
  KEY `member_id` (`member_id`),
  KEY `savings_type_id` (`savings_type_id`),
  CONSTRAINT `savings_accounts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `savings_accounts_ibfk_2` FOREIGN KEY (`savings_type_id`) REFERENCES `savings_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Savings transactions
CREATE TABLE `savings_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `savings_account_id` int(11) NOT NULL,
  `transaction_type` enum('deposit','withdrawal') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `balance_before` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `transaction_date` date NOT NULL,
  `description` text,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `savings_account_id` (`savings_account_id`),
  KEY `processed_by` (`processed_by`),
  CONSTRAINT `savings_transactions_ibfk_1` FOREIGN KEY (`savings_account_id`) REFERENCES `savings_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `savings_transactions_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Loan products
CREATE TABLE `loan_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL COMMENT 'Bunga per bulan FLAT (%)',
  `max_tenor_months` int(11) NOT NULL,
  `min_amount` decimal(15,2) NOT NULL,
  `max_amount` decimal(15,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `loan_products` (`name`, `interest_rate`, `max_tenor_months`, `min_amount`, `max_amount`, `is_active`) VALUES
('Pinjaman Konsumtif', 1.50, 24, 1000000.00, 20000000.00, 1),
('Pinjaman Produktif', 1.25, 36, 5000000.00, 50000000.00, 1),
('Pinjaman Darurat', 2.00, 12, 500000.00, 5000000.00, 1),
('Pinjaman Modal Usaha', 1.00, 48, 10000000.00, 100000000.00, 1);

-- Loans table
CREATE TABLE `loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `loan_product_id` int(11) DEFAULT NULL,
  `loan_number` varchar(20) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `term_months` int(11) NOT NULL,
  `monthly_payment` decimal(15,2) NOT NULL,
  `purpose` text,
  `rejection_notes` text DEFAULT NULL,
  `status` enum('pending','approved','active','completed','rejected','defaulted') NOT NULL DEFAULT 'pending',
  `application_date` date NOT NULL,
  `approval_date` date DEFAULT NULL,
  `disbursement_date` date DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_number` (`loan_number`),
  KEY `member_id` (`member_id`),
  KEY `loan_product_id` (`loan_product_id`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `loans_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loans_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_ibfk_3` FOREIGN KEY (`loan_product_id`) REFERENCES `loan_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Loan payments (schedule angsuran)
CREATE TABLE `loan_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `due_date` date NOT NULL COMMENT 'Jatuh tempo angsuran',
  `payment_number` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Total pembayaran yang diterima',
  `principal_amount` decimal(15,2) NOT NULL,
  `interest_amount` decimal(15,2) NOT NULL,
  `amount_due` decimal(15,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `balance_remaining` decimal(15,2) NOT NULL,
  `payment_date` date DEFAULT NULL COMMENT 'NULL = belum dibayar',
  `status` enum('pending','paid','overdue') NOT NULL DEFAULT 'pending',
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `processed_by` (`processed_by`),
  CONSTRAINT `loan_payments_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loan_payments_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atomic sequences for race-free number generation
CREATE TABLE `sequences` (
  `name` varchar(50) NOT NULL,
  `current_value` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity logs
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email logs for deduplication
CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email_type` varchar(50) NOT NULL,
  `reference_id` int(11) NOT NULL COMMENT 'FK to payment/loan/member depending on type',
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('sent','failed') NOT NULL,
  `error_message` text,
  `sent_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_type_reference_date` (`email_type`, `reference_id`, `sent_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SHU periods
CREATE TABLE `shu_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fiscal_year` int(11) NOT NULL,
  `total_shu` decimal(15,2) NOT NULL,
  `pct_jasa_modal` int(11) NOT NULL COMMENT 'Percentage to jasa modal (0-100)',
  `status` enum('draft','finalized') NOT NULL DEFAULT 'draft',
  `finalized_at` timestamp NULL DEFAULT NULL,
  `finalized_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fiscal_year` (`fiscal_year`),
  KEY `finalized_by` (`finalized_by`),
  CONSTRAINT `shu_periods_ibfk_1` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SHU distributions
CREATE TABLE `shu_distributions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `savings_base` decimal(15,2) NOT NULL COMMENT 'Savings balance used for calculation',
  `loan_base` decimal(15,2) NOT NULL COMMENT 'Loan interest paid used for calculation',
  `jasa_modal` decimal(15,2) NOT NULL,
  `jasa_anggota` decimal(15,2) NOT NULL,
  `adjustment` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Penyesuaian manual admin (total nol per periode)',
  `total_shu` decimal(15,2) NOT NULL COMMENT 'jasa_modal + jasa_anggota + adjustment',
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `period_member` (`period_id`, `member_id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `shu_distributions_ibfk_1` FOREIGN KEY (`period_id`) REFERENCES `shu_periods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shu_distributions_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
