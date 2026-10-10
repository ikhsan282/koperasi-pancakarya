<?php
require_once __DIR__ . '/../config/database.php';

$sql = file_get_contents(__DIR__ . '/migration_shu_distribution.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));

$db = db();
$errors = [];

foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    try {
        $db->query($stmt);
        echo "✓ " . substr($stmt, 0, 60) . "...\n";
    } catch (mysqli_sql_exception $e) {
        // Ignore duplicate column errors (migration already applied)
        if ($e->getCode() === 1060) {
            echo "⊘ Already exists: " . substr($stmt, 0, 50) . "...\n";
        } else {
            $errors[] = $e->getMessage() . " in: " . $stmt;
            echo "✗ " . $e->getMessage() . "\n";
        }
    }
}

if ($errors) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $err) echo "  - $err\n";
    exit(1);
}

echo "\n✓ Migration completed successfully\n";
