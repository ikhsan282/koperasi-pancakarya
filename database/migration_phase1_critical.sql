-- Phase 1: Critical Features Migration
-- Run after schema.sql for existing databases
-- For fresh install: merge into schema.sql

USE koperasi_pancakarya;

-- 1. AGUNAN (Collateral) tracking
CREATE TABLE IF NOT EXISTS `loan_collaterals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `collateral_type` enum('vehicle','property','electronics','jewelry','other') NOT NULL,
  `description` text NOT NULL COMMENT 'Merk, model, tahun, kondisi',
  `estimated_value` decimal(15,2) NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `ownership_proof` varchar(255) DEFAULT NULL COMMENT 'BPKB, sertifikat, nota',
  `status` enum('held','returned') NOT NULL DEFAULT 'held',
  `return_date` date DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  CONSTRAINT `loan_collaterals_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. BIAYA ADMIN & DENDA fields
ALTER TABLE `loans` 
  ADD COLUMN `admin_fee_pct` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Biaya admin (% dari pokok)' AFTER `term_months`,
  ADD COLUMN `admin_fee_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Biaya admin (rupiah)' AFTER `admin_fee_pct`;

ALTER TABLE `loan_payments`
  ADD COLUMN `penalty_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Denda keterlambatan' AFTER `interest_amount`,
  ADD COLUMN `days_overdue` int(11) NOT NULL DEFAULT 0 COMMENT 'Jumlah hari terlambat' AFTER `penalty_amount`;

-- 3. KAS & BANK accounts
CREATE TABLE IF NOT EXISTS `cash_bank_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(100) NOT NULL,
  `account_type` enum('cash','bank') NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL COMMENT 'Nama bank (jika type=bank)',
  `account_number` varchar(50) DEFAULT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default accounts
INSERT INTO `cash_bank_accounts` (`account_name`, `account_type`, `balance`) VALUES
('Kas Koperasi', 'cash', 0.00),
('Bank Mandiri - Koperasi', 'bank', 0.00);

-- 4. KAS & BANK transactions
CREATE TABLE IF NOT EXISTS `cash_bank_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `transaction_type` enum('debit','credit') NOT NULL COMMENT 'debit=masuk, credit=keluar',
  `amount` decimal(15,2) NOT NULL,
  `balance_before` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `reference_type` enum('loan_disbursement','loan_payment','savings_deposit','savings_withdrawal','expense','other') NOT NULL,
  `reference_id` int(11) DEFAULT NULL COMMENT 'FK ke loans/loan_payments/savings_transactions',
  `description` text NOT NULL,
  `transaction_date` date NOT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`),
  KEY `processed_by` (`processed_by`),
  KEY `reference_type_id` (`reference_type`, `reference_id`),
  CONSTRAINT `cash_bank_transactions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `cash_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cash_bank_transactions_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Settings table for configurable values
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings
INSERT INTO `settings` (`key`, `value`, `description`) VALUES
('penalty_rate_per_day', '0.5', 'Denda keterlambatan per hari (% dari angsuran)'),
('default_admin_fee_pct', '2.0', 'Biaya admin default (% dari pokok pinjaman)'),
('min_collateral_loan_amount', '5000000', 'Pinjaman minimum yang wajib ada agunan (Rp)');

-- 6. Add permissions for new modules
INSERT INTO `permissions` (`name`, `description`, `module`) VALUES
('collateral.view', 'Lihat agunan', 'collateral'),
('collateral.manage', 'Kelola agunan', 'collateral'),
('cashbank.view', 'Lihat kas/bank', 'cashbank'),
('cashbank.manage', 'Kelola transaksi kas/bank', 'cashbank'),
('settings.manage', 'Kelola pengaturan sistem', 'settings');

-- Super Admin gets all new permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions` WHERE `module` IN ('collateral', 'cashbank', 'settings');

-- Admin gets view + manage (except settings)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` WHERE `module` IN ('collateral', 'cashbank') AND `name` NOT LIKE 'settings.%';

-- Bendahara gets view only
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` WHERE `name` LIKE 'collateral.view' OR `name` LIKE 'cashbank.view';

COMMIT;
