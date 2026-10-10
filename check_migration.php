<?php
// Check if migration is needed
require_once __DIR__ . '/config/config.php';

// Create mysqli connection directly
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

$db->set_charset('utf8mb4');

// Check if admin_fee_pct column exists in loans table
$result = $db->query("SHOW COLUMNS FROM loans LIKE 'admin_fee_pct'");
$admin_fee_exists = $result->num_rows > 0;

// Check if penalty_amount column exists in loan_payments table
$result = $db->query("SHOW COLUMNS FROM loan_payments LIKE 'penalty_amount'");
$penalty_exists = $result->num_rows > 0;

// Check if settings table exists
$result = $db->query("SHOW TABLES LIKE 'settings'");
$settings_exists = $result->num_rows > 0;

echo "Migration Status:\n";
echo "- Admin fee columns in loans: " . ($admin_fee_exists ? "EXISTS" : "MISSING") . "\n";
echo "- Penalty columns in loan_payments: " . ($penalty_exists ? "EXISTS" : "MISSING") . "\n";
echo "- Settings table: " . ($settings_exists ? "EXISTS" : "MISSING") . "\n";

if (!$admin_fee_exists || !$penalty_exists || !$settings_exists) {
    echo "\nMigration needed. Running now...\n\n";
    
    $sql = file_get_contents(__DIR__ . '/database/migration_phase1_critical.sql');
    
    // Execute multi-query
    if ($db->multi_query($sql)) {
        do {
            if ($result = $db->store_result()) {
                $result->free();
            }
        } while ($db->next_result());
    }
    
    if ($db->errno) {
        echo "ERROR: " . $db->error . "\n";
    } else {
        echo "✓ Migration completed successfully!\n";
    }
} else {
    echo "\n✓ All migrations already applied!\n";
}

$db->close();
