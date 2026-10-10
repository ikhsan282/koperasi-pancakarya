<?php
declare(strict_types=1);

/**
 * Create database backup using PHP native approach
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
        if (!mkdir($backup_path, 0755, true)) {
            return ['success' => false, 'file' => '', 'error' => 'Gagal membuat direktori backup'];
        }
    }
    
    // Generate backup filename
    $timestamp = date('Y-m-d_His');
    $backup_file = $backup_path . '/backup_' . $timestamp . '.sql';
    
    try {
        // Open file for writing
        $handle = fopen($backup_file, 'w');
        if (!$handle) {
            throw new Exception('Gagal membuka file untuk ditulis');
        }
        
        // Write header
        fwrite($handle, "-- Koperasi Pancakarya Database Backup\n");
        fwrite($handle, "-- Created: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database: " . DB_NAME . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        
        // Get all tables
        $tables_result = $db->query("SHOW TABLES");
        $tables = [];
        while ($row = $tables_result->fetch_array()) {
            $tables[] = $row[0];
        }
        
        // Backup each table
        foreach ($tables as $table) {
            // Table structure
            fwrite($handle, "-- Table: {$table}\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            
            $create_result = $db->query("SHOW CREATE TABLE `{$table}`");
            $create_row = $create_result->fetch_assoc();
            fwrite($handle, $create_row['Create Table'] . ";\n\n");
            
            // Table data
            $data_result = $db->query("SELECT * FROM `{$table}`");
            if ($data_result->num_rows > 0) {
                fwrite($handle, "-- Data for table {$table}\n");
                
                while ($row = $data_result->fetch_assoc()) {
                    $columns = array_keys($row);
                    $values = array_map(function($val) use ($db) {
                        if ($val === null) return 'NULL';
                        return "'" . $db->real_escape_string($val) . "'";
                    }, array_values($row));
                    
                    $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                    fwrite($handle, $sql);
                }
                fwrite($handle, "\n");
            }
        }
        
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
        
        // Compress with gzip if available
        $final_file = $backup_file;
        if (function_exists('gzopen')) {
            $gz_file = $backup_file . '.gz';
            $gz = gzopen($gz_file, 'w9');
            $fp = fopen($backup_file, 'r');
            
            if ($gz && $fp) {
                while (!feof($fp)) {
                    gzwrite($gz, fread($fp, 8192));
                }
                fclose($fp);
                gzclose($gz);
                
                // Remove uncompressed file
                unlink($backup_file);
                $final_file = $gz_file;
            }
        }
        
        $file_size = filesize($final_file);
        
        // Log success
        log_backup($type, basename($final_file), $file_size, 'success', null, $user_id);
        
        return [
            'success' => true,
            'file' => $final_file,
            'error' => null,
            'size' => $file_size
        ];
        
    } catch (Throwable $e) {
        if (isset($handle) && $handle) {
            fclose($handle);
        }
        if (file_exists($backup_file)) {
            unlink($backup_file);
        }
        
        $error = 'Backup gagal: ' . $e->getMessage();
        log_backup($type, basename($backup_file), 0, 'failed', $error, $user_id);
        return ['success' => false, 'file' => '', 'error' => $error];
    }
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
    
    try {
        $db = db();
        
        // Read file content
        $is_gzipped = substr($backup_file, -3) === '.gz';
        
        if ($is_gzipped) {
            $content = file_get_contents('compress.zlib://' . $backup_file);
        } else {
            $content = file_get_contents($backup_file);
        }
        
        if ($content === false) {
            return ['success' => false, 'error' => 'Gagal membaca file backup'];
        }
        
        // Split into statements
        $statements = array_filter(
            array_map('trim', explode(';', $content)),
            function($stmt) {
                return !empty($stmt) && !preg_match('/^--/', $stmt);
            }
        );
        
        // Execute each statement
        $db->begin_transaction();
        
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $db->query($statement . ';');
            }
        }
        
        $db->commit();
        
        // Log activity
        if ($user_id) {
            log_activity('restore_backup', 'Restore database dari ' . basename($backup_file));
        }
        
        return ['success' => true, 'error' => null];
        
    } catch (Throwable $e) {
        if (isset($db)) {
            $db->rollback();
        }
        return ['success' => false, 'error' => 'Restore gagal: ' . $e->getMessage()];
    }
}

/**
 * Get preview of backup file (first N lines)
 */
function preview_backup(string $backup_file, int $lines = 50): array
{
    if (!file_exists($backup_file)) {
        return ['success' => false, 'preview' => '', 'error' => 'File tidak ditemukan'];
    }
    
    try {
        $is_gzipped = substr($backup_file, -3) === '.gz';
        
        if ($is_gzipped) {
            $handle = gzopen($backup_file, 'r');
        } else {
            $handle = fopen($backup_file, 'r');
        }
        
        if (!$handle) {
            return ['success' => false, 'preview' => '', 'error' => 'Gagal membuka file'];
        }
        
        $preview = '';
        $line_count = 0;
        
        while ($line_count < $lines && !feof($handle)) {
            if ($is_gzipped) {
                $line = gzgets($handle);
            } else {
                $line = fgets($handle);
            }
            
            if ($line !== false) {
                $preview .= $line;
                $line_count++;
            }
        }
        
        if ($is_gzipped) {
            gzclose($handle);
        } else {
            fclose($handle);
        }
        
        return ['success' => true, 'preview' => $preview, 'error' => null];
        
    } catch (Throwable $e) {
        return ['success' => false, 'preview' => '', 'error' => $e->getMessage()];
    }
}

/**
 * Delete old backups based on retention policy
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
