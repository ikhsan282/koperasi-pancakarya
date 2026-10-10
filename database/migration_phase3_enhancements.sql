-- Phase 3: Enhancements & Operational Features Migration
-- Run after migration_phase2_compliance.sql
-- For existing databases with Phase 1 & 2 applied

USE koperasi_pancakarya;

START TRANSACTION;

-- 1. LOAN RESTRUCTURING (Restrukturisasi Pinjaman)
CREATE TABLE IF NOT EXISTS `loan_restructures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `restructure_type` enum('reschedule','extend_tenor','reduce_rate') NOT NULL,
  `reason` text NOT NULL,
  `old_term_months` int(11) NOT NULL,
  `new_term_months` int(11) NOT NULL,
  `old_interest_rate` decimal(5,2) NOT NULL,
  `new_interest_rate` decimal(5,2) NOT NULL,
  `old_monthly_payment` decimal(15,2) NOT NULL,
  `new_monthly_payment` decimal(15,2) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `loan_restructures_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_restructures_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. WRITE-OFF (Hapus Buku)
CREATE TABLE IF NOT EXISTS `loan_writeoffs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `writeoff_amount` decimal(15,2) NOT NULL COMMENT 'Amount written off',
  `remaining_balance` decimal(15,2) NOT NULL COMMENT 'Balance before writeoff',
  `reason` text NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `writeoff_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `loan_writeoffs_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loan_writeoffs_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `loans`
  ADD COLUMN IF NOT EXISTS `writeoff_status` enum('none','partial','full') NOT NULL DEFAULT 'none',
  ADD COLUMN IF NOT EXISTS `writeoff_amount` decimal(15,2) NOT NULL DEFAULT 0.00;

-- 3. BACKUP LOG tracking
CREATE TABLE IF NOT EXISTS `backup_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `backup_type` enum('manual','auto') NOT NULL,
  `backup_file` varchar(255) NOT NULL,
  `file_size` bigint(20) NOT NULL COMMENT 'Size in bytes',
  `status` enum('success','failed') NOT NULL,
  `error_message` text,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `backup_logs_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. WHATSAPP notification log (dedup tracking)
CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `reference_type` enum('loan_payment','loan_approval','savings_deposit','general') NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `status` enum('sent','failed','queued') NOT NULL,
  `response` text COMMENT 'API response',
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `phone` (`phone`),
  KEY `reference` (`reference_type`, `reference_id`),
  KEY `sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. SETTINGS for Phase 3
INSERT INTO `settings` (`key`, `value`, `description`) VALUES
('whatsapp_api_url', '', 'WhatsApp Gateway API URL (Fonnte/Wablas)'),
('whatsapp_api_key', '', 'WhatsApp Gateway API Key'),
('whatsapp_enabled', '0', 'Enable WhatsApp notifications (0=disabled, 1=enabled)'),
('backup_path', '/opt/data/backups', 'Path untuk simpan backup files'),
('backup_retention_days', '30', 'Hapus backup lebih lama dari N hari'),
('auto_backup_enabled', '0', 'Enable auto backup harian (0=disabled, 1=enabled)'),
('kap_threshold_kl', '90', 'Hari keterlambatan untuk status Kurang Lancar'),
('kap_threshold_diragukan', '120', 'Hari keterlambatan untuk status Diragukan'),
('kap_threshold_macet', '180', 'Hari keterlambatan untuk status Macet')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- 6. NEW PERMISSIONS for Phase 3
INSERT INTO `permissions` (`name`, `description`, `module`) VALUES
('loans.restructure', 'Restrukturisasi pinjaman', 'loans'),
('loans.writeoff', 'Hapus buku kredit macet', 'loans'),
('reports.kap', 'Lihat laporan KAP (kolektibilitas)', 'reports'),
('shu.distribute', 'Distribute SHU ke anggota', 'shu'),
('backup.create', 'Buat backup database', 'backup'),
('backup.restore', 'Restore database dari backup', 'backup')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Super Admin gets all Phase 3 permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions` 
WHERE `name` IN (
  'loans.restructure',
  'loans.writeoff',
  'reports.kap',
  'shu.distribute',
  'backup.create',
  'backup.restore'
)
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

-- Admin gets restructure + KAP report
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` 
WHERE `name` IN ('loans.restructure', 'reports.kap')
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

-- Bendahara gets KAP report + backup
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` 
WHERE `name` IN ('reports.kap', 'backup.create')
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

COMMIT;
