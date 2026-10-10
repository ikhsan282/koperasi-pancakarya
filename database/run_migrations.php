#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Migration Runner
 * Run SQL migration files
 */

if ($argc < 2) {
    echo "Usage: php run_migrations.php <migration_file.sql>\n";
    exit(1);
}

$migration_file = $argv[1];

if (!file_exists($migration_file)) {
    echo "Error: File not found: {$migration_file}\n";
    exit(1);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "Running migration: {$migration_file}\n";

$sql = file_get_contents($migration_file);
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Execute multi-query
if ($db->multi_query($sql)) {
    do {
        // Consume results
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

if ($db->error) {
    echo "Error: " . $db->error . "\n";
    exit(1);
}

echo "Migration completed successfully!\n";
$db->close();
