-- Phase 2: Compliance Features Migration
-- Run after migration_phase1_critical.sql
-- For existing databases with Phase 1 applied

USE koperasi_pancakarya;

START TRANSACTION;

-- 1. SAVINGS INTEREST tracking
CREATE TABLE IF NOT EXISTS `savings_interest_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `savings_account_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `balance_base` decimal(15,2) NOT NULL COMMENT 'Balance used for calculation',
  `interest_rate` decimal(5,2) NOT NULL COMMENT 'Annual rate (%)',
  `interest_amount` decimal(15,2) NOT NULL COMMENT 'Calculated interest',
  `posted_date` date NOT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `savings_account_id` (`savings_account_id`),
  KEY `posted_by` (`posted_by`),
  KEY `period` (`period_start`, `period_end`),
  CONSTRAINT `savings_interest_history_ibfk_1` FOREIGN KEY (`savings_account_id`) REFERENCES `savings_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `savings_interest_history_ibfk_2` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `savings_accounts` 
  ADD COLUMN IF NOT EXISTS `last_interest_date` date DEFAULT NULL COMMENT 'Last interest calculation date';

-- 2. APPROVAL WORKFLOW multi-level
CREATE TABLE IF NOT EXISTS `loan_approvals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `approver_level` int(11) NOT NULL COMMENT '1=first approval, 2=final approval',
  `approver_id` int(11) NOT NULL,
  `status` enum('approved','rejected') NOT NULL,
  `notes` text,
  `approved_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `approver_id` (`approver_id`),
  CONSTRAINT `loan_approvals_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_approvals_ibfk_2` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `loan_products`
  ADD COLUMN IF NOT EXISTS `approval_levels` int(11) NOT NULL DEFAULT 1 COMMENT '1=single, 2=dual approval';

ALTER TABLE `loans`
  ADD COLUMN IF NOT EXISTS `current_approval_level` int(11) NOT NULL DEFAULT 0 COMMENT '0=pending, 1=first approved, 2=final approved';

-- 3. NOTIFICATIONS system
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_type` enum('loan_due_reminder','loan_approved','loan_rejected','general') NOT NULL,
  `reference_type` enum('loan','loan_payment','savings','member','other') NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `recipient_user_id` int(11) DEFAULT NULL COMMENT 'FK to users (for staff)',
  `recipient_member_id` int(11) DEFAULT NULL COMMENT 'FK to members (for anggota)',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `recipient_user_id` (`recipient_user_id`),
  KEY `recipient_member_id` (`recipient_member_id`),
  KEY `is_read` (`is_read`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`recipient_member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `members`
  ADD COLUMN IF NOT EXISTS `notification_preference` enum('email','sms','both','none') NOT NULL DEFAULT 'email';

-- 4. SETTINGS for Phase 2 features
INSERT INTO `settings` (`key`, `value`, `description`) VALUES
('interest_posting_day', '1', 'Tanggal posting bunga simpanan setiap bulan (1-28)'),
('auto_interest_enabled', '0', 'Enable auto-posting bunga simpanan (0=disabled, 1=enabled)'),
('reminder_days_before', '3', 'Kirim reminder N hari sebelum jatuh tempo'),
('email_from', 'noreply@koperasi.test', 'Email pengirim untuk notifikasi'),
('email_smtp_host', '', 'SMTP host (kosongkan untuk mail() PHP)'),
('email_smtp_port', '587', 'SMTP port (587 untuk TLS, 465 untuk SSL)'),
('email_smtp_user', '', 'SMTP username'),
('email_smtp_pass', '', 'SMTP password'),
('sms_gateway_url', '', 'SMS gateway API URL (optional)'),
('sms_api_key', '', 'SMS gateway API key (optional)')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- 5. NEW PERMISSIONS for Phase 2
INSERT INTO `permissions` (`name`, `description`, `module`) VALUES
('loans.approve_final', 'Approval final pinjaman (level 2)', 'loans'),
('savings.post_interest', 'Posting bunga simpanan', 'savings'),
('notifications.send', 'Kirim notifikasi manual', 'notifications'),
('notifications.view', 'Lihat notifikasi', 'notifications'),
('reports.income_statement', 'Lihat laporan laba rugi', 'reports'),
('reports.cashflow', 'Lihat laporan arus kas', 'reports')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Super Admin gets all Phase 2 permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions` 
WHERE `name` IN (
  'loans.approve_final', 
  'savings.post_interest', 
  'notifications.send', 
  'notifications.view',
  'reports.income_statement',
  'reports.cashflow'
)
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

-- Admin gets approve (level 1) + view notifications + reports
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` 
WHERE `name` IN (
  'notifications.view',
  'reports.income_statement',
  'reports.cashflow'
)
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

-- Bendahara gets post interest + view reports
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` 
WHERE `name` IN (
  'savings.post_interest',
  'notifications.view',
  'reports.income_statement',
  'reports.cashflow'
)
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

-- Anggota gets view notifications only
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions` 
WHERE `name` = 'notifications.view'
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

COMMIT;
