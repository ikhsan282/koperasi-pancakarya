<?php
// Quick migration runner
require_once __DIR__ . '/../config/database.php';

$sql = file_get_contents(__DIR__ . '/migrations/001_add_sequences_table.sql');

// Split by semicolon and execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));

foreach ($statements as $stmt) {
    if (empty($stmt) || strpos($stmt, '--') === 0) continue;
    
    echo "Executing: " . substr($stmt, 0, 80) . "...\n";
    if (db()->query($stmt)) {
        echo "✓ Success\n";
    } else {
        echo "✗ Error: " . db()->error . "\n";
    }
}

echo "\nMigration complete.\n";
