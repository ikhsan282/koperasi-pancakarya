<?php
/**
 * Phase 2 Migration Validation Script
 * Run this AFTER applying migration_phase2_compliance.sql
 * Validates all tables, columns, indexes, and permissions
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$tests_passed = 0;
$tests_failed = 0;
$errors = [];

function test($name, $condition, $error_msg = '') {
    global $tests_passed, $tests_failed, $errors;
    if ($condition) {
        $tests_passed++;
        echo "✓ {$name}\n";
        return true;
    } else {
        $tests_failed++;
        echo "✗ {$name}\n";
        if ($error_msg) $errors[] = "{$name}: {$error_msg}";
        return false;
    }
}

echo "=== Phase 2 Migration Validation ===\n\n";

// 1. Check tables exist
echo "1. Checking tables...\n";
$tables = ['savings_interest_history', 'loan_approvals', 'notifications'];
foreach ($tables as $table) {
    $result = db()->query("SHOW TABLES LIKE '{$table}'");
    test("Table {$table} exists", $result && $result->num_rows > 0);
}

// 2. Check columns added
echo "\n2. Checking new columns...\n";
$columns = [
    'savings_accounts' => 'last_interest_date',
    'loan_products' => 'approval_levels',
    'loans' => 'current_approval_level',
    'members' => 'notification_preference'
];
foreach ($columns as $table => $column) {
    $result = db()->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    test("{$table}.{$column} exists", $result && $result->num_rows > 0);
}

// 3. Check table structure
echo "\n3. Checking table structures...\n";

// savings_interest_history
$result = db()->query("DESCRIBE savings_interest_history");
$cols = [];
while ($row = $result->fetch_assoc()) $cols[] = $row['Field'];
test("savings_interest_history has all columns", 
    in_array('savings_account_id', $cols) && 
    in_array('period_start', $cols) && 
    in_array('interest_amount', $cols)
);

// loan_approvals
$result = db()->query("DESCRIBE loan_approvals");
$cols = [];
while ($row = $result->fetch_assoc()) $cols[] = $row['Field'];
test("loan_approvals has all columns",
    in_array('loan_id', $cols) && 
    in_array('approver_level', $cols) && 
    in_array('status', $cols)
);

// notifications
$result = db()->query("DESCRIBE notifications");
$cols = [];
while ($row = $result->fetch_assoc()) $cols[] = $row['Field'];
test("notifications has all columns",
    in_array('notification_type', $cols) && 
    in_array('recipient_user_id', $cols) && 
    in_array('is_read', $cols)
);

// 4. Check foreign keys
echo "\n4. Checking foreign keys...\n";
$fk_query = "SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL";
$stmt = db()->prepare($fk_query);
$stmt->bind_param('s', DB_NAME);
$stmt->execute();
$result = $stmt->get_result();
$fk_count = $result->num_rows;
test("Foreign keys created", $fk_count >= 6, "Found {$fk_count} foreign keys");

// 5. Check settings inserted
echo "\n5. Checking settings...\n";
$required_settings = [
    'interest_posting_day',
    'auto_interest_enabled',
    'reminder_days_before',
    'email_from',
    'email_smtp_host'
];
foreach ($required_settings as $key) {
    $stmt = db()->prepare("SELECT COUNT(*) as c FROM settings WHERE `key` = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'];
    test("Setting '{$key}' exists", $count > 0);
}

// 6. Check permissions created
echo "\n6. Checking permissions...\n";
$required_perms = [
    'loans.approve_final',
    'savings.post_interest',
    'notifications.send',
    'notifications.view',
    'reports.income_statement',
    'reports.cashflow'
];
foreach ($required_perms as $perm) {
    $stmt = db()->prepare("SELECT COUNT(*) as c FROM permissions WHERE name = ?");
    $stmt->bind_param('s', $perm);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'];
    test("Permission '{$perm}' exists", $count > 0);
}

// 7. Check role permissions assigned
echo "\n7. Checking role permission assignments...\n";
$stmt = db()->query("
    SELECT r.name as role, p.name as permission 
    FROM role_permissions rp 
    JOIN roles r ON rp.role_id = r.id 
    JOIN permissions p ON rp.permission_id = p.id 
    WHERE p.name IN ('loans.approve_final', 'savings.post_interest', 'notifications.view')
");
$role_perms = [];
while ($row = $stmt->fetch_assoc()) {
    $role_perms[] = $row['role'] . ':' . $row['permission'];
}
test("Super Admin has approve_final", in_array('Super Admin:loans.approve_final', $role_perms));
test("Bendahara has post_interest", in_array('Bendahara:savings.post_interest', $role_perms));

// 8. Check indexes
echo "\n8. Checking indexes...\n";
$result = db()->query("SHOW INDEX FROM savings_interest_history WHERE Key_name != 'PRIMARY'");
test("savings_interest_history has indexes", $result && $result->num_rows >= 2);

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Tests Passed: {$tests_passed}\n";
echo "Tests Failed: {$tests_failed}\n";

if ($tests_failed > 0) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
} else {
    echo "\n✓ Migration validation PASSED\n";
    exit(0);
}
