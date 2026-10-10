<?php
// Run migration_phase1_critical.sql
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$sql = file_get_contents(__DIR__ . '/database/migration_phase1_critical.sql');

// Split by semicolon and execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));

$db = db();
$db->begin_transaction();

try {
    foreach ($statements as $stmt) {
        if (empty($stmt) || strpos($stmt, '--') === 0 || strtoupper(trim($stmt)) === 'USE KOPERASI_PANCAKARYA') {
            continue;
        }
        echo "Executing: " . substr($stmt, 0, 80) . "...\n";
        $db->query($stmt);
    }
    $db->commit();
    echo "\n✓ Migration completed successfully!\n";
} catch (Exception $e) {
    $db->rollback();
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
