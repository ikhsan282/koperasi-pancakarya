-- Add modal_koperasi setting for Financial Position Report
USE koperasi_pancakarya;

INSERT INTO `settings` (`key`, `value`, `description`) VALUES
('modal_koperasi', '0', 'Modal dasar koperasi (Rp)')
ON DUPLICATE KEY UPDATE `key` = `key`;
