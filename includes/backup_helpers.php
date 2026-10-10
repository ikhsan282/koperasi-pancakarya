<?php
declare(strict_types=1);

/**
 * Create database backup
 * 
 * @param int|null $user_id User ID yang membuat backup
 * @param string $type 'manual' or 'auto'
 * @return array ['success' => bool, 'file' => string, 'error' => string|null]
 */
function create_backup(?int $user_id = null, string $type = 'manual'): array
{
    $db = db();
    
    // Load settings
    $stmt = $db->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('backup_path')");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->get_result()->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
    
    $backup_path = $settings['backup_path'] ?? '/opt/data/backups';
    
    // Ensure backup directory exists
    if (!is_dir($backup_path)) {
        mkdir($backup_path, 0755, true);
    }
    
    // Generate backup filename
    $timestamp = date('Y-m-d_His');
    $backup_file = $backup_path . '/backup_' . $timestamp . '.sql';
    $backup_file_gz = $backup_file . '.gz';
    
    // Find mysqldump binary
    $mysqldump = find_mysql_binary('mysqldump');
    if (!$mysqldump) {
        return ['success' => false, 'file' => '', 'error' => 'mysqldump tidak ditemukan'];
    }
    
    // Build mysqldump command
    $cmd = sprintf(
        '%s --user=%s --host=%s %s %s > %s 2>&1',
        escapeshellarg($mysqldump),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_HOST),
        DB_PASS ? '--password=' . escapeshellarg(DB_PASS) : '',
        escapeshellarg(DB_NAME),
        escapeshellarg($backup_file)
    );
    
    // Execute backup
    exec($cmd, $output, $return_code);
    
    if ($return_code !== 0 || !file_exists($backup_file)) {
        $error = 'Backup gagal: ' . implode("\n", $output);
        log_backup($type, basename($backup_file), 0, 'failed', $error, $user_id);
        return ['success' => false, 'file' => '', 'error' => $error];
    }
    
    // Compress with gzip
    $gzip_cmd = sprintf('gzip -f %s 2>&1', escapeshellarg($backup_file));
    exec($gzip_cmd, $gz_output, $gz_code);
    
    $final_file = file_exists($backup_file_gz) ? $backup_file_gz : $backup_file;
    $file_size = filesize($final_file);
    
    // Log success
    log_backup($type, basename($final_file), $file_size, 'success', null, $user_id);
    
    return [
        'success' => true,
        'file' => $final_file,
        'error' => null,
        'size' => $file_size
    ];
}

/**
 * Restore database from backup file
 * 
 * @param string $backup_file Full path to .sql or .sql.gz file
 * @param int|null $user_id User performing restore
 * @return array ['success' => bool, 'error' => string|null]
 */
function restore_backup(string $backup_file, ?int $user_id = null): array
{
    if (!file_exists($backup_file)) {
        return ['success' => false, 'error' => 'File backup tidak ditemukan'];
    }
    
    // Find mysql binary
    $mysql = find_mysql_binary('mysql');
    if (!$mysql) {
        return ['success' => false, 'error' => 'mysql tidak ditemukan'];
    }
    
    // Check if file is gzipped
    $is_gzipped = substr($backup_file, -3) === '.gz';
    
    if ($is_gzipped) {
        // Decompress and pipe to mysql
        $cmd = sprintf(
            'gunzip -c %s | %s --user=%s --host=%s %s %s 2>&1',
            escapeshellarg($backup_file),
            escapeshellarg($mysql),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_HOST),
            DB_PASS ? '--password=' . escapeshellarg(DB_PASS) : '',
            escapeshellarg(DB_NAME)
        );
    } else {
        // Direct restore
        $cmd = sprintf(
            '%s --user=%s --host=%s %s %s < %s 2>&1',
            escapeshellarg($mysql),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_HOST),
            DB_PASS ? '--password=' . escapeshellarg(DB_PASS) : '',
            escapeshellarg(DB_NAME),
            escapeshellarg($backup_file)
        );
    }
    
    exec($cmd, $output, $return_code);
    
    if ($return_code !== 0) {
        $error = 'Restore gagal: ' . implode("\n", $output);
        return ['success' => false, 'error' => $error];
    }
    
    // Log activity
    if ($user_id) {
        log_activity('restore_backup', 'Restore database dari ' . basename($backup_file));
    }
    
    return ['success' => true, 'error' => null];
}

/**
 * Get preview of backup file (first N lines)
 * 
 * @param string $backup_file Full path to .sql or .sql.gz file
 * @param int $lines Number of lines to preview
 * @return array ['success' => bool, 'preview' => string, 'error' => string|null]
 */
function preview_backup(string $backup_file, int $lines = 50): array
{
    if (!file_exists($backup_file)) {
        return ['success' => false, 'preview' => '', 'error' => 'File tidak ditemukan'];
    }
    
    $is_gzipped = substr($backup_file, -3) === '.gz';
    
    if ($is_gzipped) {
        $cmd = sprintf('gunzip -c %s | head -n %d', escapeshellarg($backup_file), $lines);
    } else {
        $cmd = sprintf('head -n %d %s', $lines, escapeshellarg($backup_file));
    }
    
    exec($cmd, $output, $return_code);
    
    if ($return_code !== 0) {
        return ['success' => false, 'preview' => '', 'error' => 'Gagal membaca file'];
    }
    
    return ['success' => true, 'preview' => implode("\n", $output), 'error' => null];
}

/**
 * Delete old backups based on retention policy
 * 
 * @return int Number of deleted backups
 */
function cleanup_old_backups(): int
{
    $db = db();
    
    // Get retention days setting
    $stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'backup_retention_days'");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $retention_days = (int) ($result['value'] ?? 30);
    
    // Get old backups from database
    $stmt = $db->prepare("
        SELECT id, backup_file 
        FROM backup_logs 
        WHERE status = 'success' 
          AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->bind_param('i', $retention_days);
    $stmt->execute();
    $old_backups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $deleted_count = 0;
    
    // Get backup path
    $stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'backup_path'");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $backup_path = $result['value'] ?? '/opt/data/backups';
    
    foreach ($old_backups as $backup) {
        $full_path = $backup_path . '/' . $backup['backup_file'];
        
        // Delete physical file
        if (file_exists($full_path)) {
            unlink($full_path);
        }
        
        // Delete log entry
        $stmt = $db->prepare("DELETE FROM backup_logs WHERE id = ?");
        $stmt->bind_param('i', $backup['id']);
        $stmt->execute();
        
        $deleted_count++;
    }
    
    return $deleted_count;
}

/**
 * Log backup operation
 */
function log_backup(string $type, string $filename, int $size, string $status, ?string $error, ?int $user_id): void
{
    $stmt = db()->prepare("
        INSERT INTO backup_logs (backup_type, backup_file, file_size, status, error_message, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('ssissi', $type, $filename, $size, $status, $error, $user_id);
    $stmt->execute();
}

/**
 * Find mysql binary (mysqldump or mysql)
 * 
 * @param string $binary 'mysql' or 'mysqldump'
 * @return string|null Full path to binary or null if not found
 */
function find_mysql_binary(string $binary): ?string
{
    // Common paths
    $paths = [
        '/usr/bin/' . $binary,
        '/usr/local/bin/' . $binary,
        '/usr/local/mysql/bin/' . $binary,
        '/opt/homebrew/bin/' . $binary,
    ];
    
    // Check common paths first
    foreach ($paths as $path) {
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }
    
    // Try which command
    exec('which ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $code);
    if ($code === 0 && !empty($output[0])) {
        return $output[0];
    }
    
    // Just return binary name and hope it's in PATH
    return $binary;
}
