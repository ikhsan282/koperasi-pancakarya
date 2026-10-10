#!/usr/bin/env php
<?php
// Standalone migration runner - no auth required
// Run: php run_migration.php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_NAME', 'koperasi_pancakarya');

$sql_file = __DIR__ . '/database/migration_phase1_critical.sql';

if (!file_exists($sql_file)) {
    die("ERROR: Migration file not found at {$sql_file}\n");
}

echo "Connecting to database...\n";
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    die("ERROR: Connection failed: " . $db->connect_error . "\n");
}

$db->set_charset('utf8mb4');
echo "✓ Connected\n\n";

// Check if already migrated
$check = $db->query("SHOW COLUMNS FROM loans LIKE 'admin_fee_pct'");
if ($check->num_rows > 0) {
    echo "✓ Migration already applied!\n\n";
    echo "Verification:\n";
} else {
    echo "Running migration...\n";
    $sql = file_get_contents($sql_file);
    
    if ($db->multi_query($sql)) {
        do {
            if ($result = $db->store_result()) {
                $result->free();
            }
        } while ($db->next_result());
    }
    
    if ($db->errno) {
        die("ERROR: " . $db->error . "\n");
    }
    
    echo "✓ Migration executed\n\n";
    echo "Verification:\n";
}

// Verify all changes
$checks = [
    "SHOW COLUMNS FROM loans LIKE 'admin_fee_pct'" => "loans.admin_fee_pct",
    "SHOW COLUMNS FROM loans LIKE 'admin_fee_amount'" => "loans.admin_fee_amount",
    "SHOW COLUMNS FROM loan_payments LIKE 'penalty_amount'" => "loan_payments.penalty_amount",
    "SHOW COLUMNS FROM loan_payments LIKE 'days_overdue'" => "loan_payments.days_overdue",
    "SHOW TABLES LIKE 'settings'" => "settings table",
    "SHOW TABLES LIKE 'cash_bank_accounts'" => "cash_bank_accounts table",
    "SHOW TABLES LIKE 'loan_collaterals'" => "loan_collaterals table"
];

foreach ($checks as $query => $label) {
    $result = $db->query($query);
    $status = $result->num_rows > 0 ? '✓' : '✗';
    echo "  {$status} {$label}\n";
}

// Check settings values
echo "\nSettings:\n";
$result = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('penalty_rate_per_day', 'default_admin_fee_pct')");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "  • {$row['key']} = {$row['value']}\n";
    }
}

$db->close();
echo "\n✓ Done!\n";
