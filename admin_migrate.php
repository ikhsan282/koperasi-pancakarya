<?php
// Admin-only migration runner - access via browser
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_permission('settings.manage');

$status = [];
$sql_file = __DIR__ . '/database/migration_phase1_critical.sql';

if (!file_exists($sql_file)) {
    die('Migration file not found');
}

$sql = file_get_contents($sql_file);
$db = db();

// Check current state
$check = $db->query("SHOW COLUMNS FROM loans LIKE 'admin_fee_pct'");
$already_migrated = $check->num_rows > 0;

if ($already_migrated) {
    $status[] = '✓ Migration already applied';
} else {
    try {
        $db->multi_query($sql);
        do {
            if ($result = $db->store_result()) {
                $result->free();
            }
        } while ($db->next_result());
        
        $status[] = '✓ Migration executed successfully';
    } catch (Exception $e) {
        $status[] = '✗ Error: ' . $e->getMessage();
    }
}

// Verify
$verify = [];
$verify[] = $db->query("SHOW COLUMNS FROM loans LIKE 'admin_fee_pct'")->num_rows > 0 ? '✓ loans.admin_fee_pct' : '✗ loans.admin_fee_pct';
$verify[] = $db->query("SHOW COLUMNS FROM loan_payments LIKE 'penalty_amount'")->num_rows > 0 ? '✓ loan_payments.penalty_amount' : '✗ loan_payments.penalty_amount';
$verify[] = $db->query("SHOW TABLES LIKE 'settings'")->num_rows > 0 ? '✓ settings table' : '✗ settings table';

echo '<h2>Migration Status</h2><pre>';
echo implode("\n", $status);
echo "\n\n<strong>Verification:</strong>\n";
echo implode("\n", $verify);
echo '</pre>';
?>
