-- Add backup permissions
INSERT INTO `permissions` (`name`, `description`, `module`) VALUES
('backup.create', 'Membuat backup database manual', 'backup'),
('backup.restore', 'Restore database dari backup', 'backup'),
('backup.delete', 'Menghapus file backup', 'backup')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Grant to Super Admin role
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.id FROM permissions p 
WHERE p.name IN ('backup.create', 'backup.restore', 'backup.delete')
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp 
    WHERE rp.role_id = 1 AND rp.permission_id = p.id
);

-- Grant backup.create to Admin role (but NOT restore)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, p.id FROM permissions p 
WHERE p.name IN ('backup.create')
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp 
    WHERE rp.role_id = 2 AND rp.permission_id = p.id
);
