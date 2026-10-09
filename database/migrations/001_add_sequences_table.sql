-- Migration: Add sequences table for atomic number generation
-- Fixes race conditions in member, loan, and savings account number generation

CREATE TABLE IF NOT EXISTS `sequences` (
  `name` varchar(50) NOT NULL,
  `current_value` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize sequences for existing data
INSERT INTO `sequences` (`name`, `current_value`)
SELECT 'member_number_' || DATE_FORMAT(CURDATE(), '%Y%m'), 
       COALESCE(MAX(CAST(SUBSTRING(member_number, -4) AS UNSIGNED)), 0)
FROM members 
WHERE member_number LIKE CONCAT('KP-', DATE_FORMAT(CURDATE(), '%Y%m'), '-%')
ON DUPLICATE KEY UPDATE current_value = VALUES(current_value);

INSERT INTO `sequences` (`name`, `current_value`)
SELECT 'loan_number_' || DATE_FORMAT(CURDATE(), '%Y%m'),
       COALESCE(MAX(CAST(SUBSTRING(loan_number, -4) AS UNSIGNED)), 0)
FROM loans
WHERE loan_number LIKE CONCAT('L-', DATE_FORMAT(CURDATE(), '%Y%m'), '-%')
ON DUPLICATE KEY UPDATE current_value = VALUES(current_value);
