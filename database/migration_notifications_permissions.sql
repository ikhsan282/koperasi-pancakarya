-- Notification System Permissions
-- Run this migration to add notification permissions

INSERT IGNORE INTO `permissions` (`name`, `description`, `module`) VALUES
('notifications.view', 'Lihat notifikasi', 'notifications'),
('notifications.send', 'Kirim notifikasi manual', 'notifications');

-- Grant to Super Admin and Admin roles
INSERT IGNORE INTO `role_permissions` (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name IN ('Super Admin', 'Admin')
  AND p.name IN ('notifications.view', 'notifications.send');

-- Grant view permission to Bendahara and Anggota
INSERT IGNORE INTO `role_permissions` (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name IN ('Bendahara', 'Anggota')
  AND p.name = 'notifications.view';
