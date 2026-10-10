#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Daily Backup Cron Job
 * 
 * Run daily via crontab:
 * 0 2 * * * php /path/to/cron/daily_backup.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/backup_helpers.php';

// Check if auto backup is enabled
$stmt = db()->prepare("SELECT value FROM settings WHERE `key` = 'auto_backup_enabled'");
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$auto_backup_enabled = ($result['value'] ?? '0') === '1';

if (!$auto_backup_enabled) {
    echo "[INFO] Auto backup is disabled. Exiting.\n";
    exit(0);
}

echo "[INFO] Starting daily backup...\n";

// Create backup
$backup_result = create_backup(null, 'auto');

if ($backup_result['success']) {
    echo "[OK] Backup created: " . basename($backup_result['file']) . "\n";
    echo "[INFO] Size: " . number_format($backup_result['size'] / (1024 * 1024), 2) . " MB\n";
} else {
    echo "[ERROR] Backup failed: " . $backup_result['error'] . "\n";
    exit(1);
}

// Cleanup old backups
echo "[INFO] Cleaning up old backups...\n";
$deleted_count = cleanup_old_backups();
echo "[OK] Deleted {$deleted_count} old backup(s)\n";

echo "[INFO] Daily backup completed successfully.\n";
