<?php
/**
 * Phase 2 Feature Implementation Check
 * Validates that all 5 features are implemented
 * Run this to check development progress
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "=== Phase 2 Feature Implementation Status ===\n\n";

$status = [
    'implemented' => 0,
    'partial' => 0,
    'missing' => 0
];

function check_feature($name, $files, $functions = []) {
    global $status;
    
    echo "\n{$name}:\n";
    
    $files_exist = 0;
    $files_total = count($files);
    
    foreach ($files as $file) {
        $exists = file_exists(__DIR__ . '/../' . $file);
        echo ($exists ? '  ✓' : '  ✗') . " {$file}\n";
        if ($exists) $files_exist++;
    }
    
    // Check for required functions/code
    $funcs_found = 0;
    $funcs_total = count($functions);
    
    foreach ($functions as $func) {
        $found = false;
        foreach ($files as $file) {
            $path = __DIR__ . '/../' . $file;
            if (file_exists($path) && str_contains(file_get_contents($path), $func)) {
                $found = true;
                break;
            }
        }
        echo ($found ? '  ✓' : '  ✗') . " Function/code: {$func}\n";
        if ($found) $funcs_found++;
    }
    
    // Determine status
    $total_checks = $files_total + $funcs_total;
    $total_found = $files_exist + $funcs_found;
    
    if ($total_found == 0) {
        echo "  Status: ❌ NOT IMPLEMENTED\n";
        $status['missing']++;
    } elseif ($total_found == $total_checks) {
        echo "  Status: ✅ IMPLEMENTED\n";
        $status['implemented']++;
    } else {
        echo "  Status: ⚠️  PARTIAL ({$total_found}/{$total_checks})\n";
        $status['partial']++;
    }
}

// Feature 1: Income Statement
check_feature('Task 1: Income Statement', [
    'pages/reports/income_statement.php',
    'pages/reports/income_statement_export.php'
], [
    'interest_amount',
    'admin_fee_amount',
    'penalty_amount'
]);

// Feature 2: Cashflow Statement (enhanced)
check_feature('Task 2: Cashflow Statement', [
    'pages/reports/cashflow_statement.php'
], [
    'saldo_awal',
    'saldo_akhir',
    'cash_bank_transactions'
]);

// Feature 3: Savings Interest
check_feature('Task 3: Savings Interest', [
    'includes/savings_interest.php',
    'pages/savings/interest_posting.php',
    'cron/monthly_interest.php'
], [
    'calculate_monthly_interest',
    'post_interest'
]);

// Feature 4: Approval Workflow
check_feature('Task 4: Multi-level Approval', [
    'pages/loans/process.php'
], [
    'approval_levels',
    'current_approval_level',
    'loan_approvals'
]);

// Feature 5: Notifications
check_feature('Task 5: Notifications', [
    'includes/notification_helpers.php',
    'pages/notifications/index.php',
    'pages/notifications/send.php',
    'cron/loan_due_reminders.php'
], [
    'send_notification',
    'send_email_notification'
]);

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Implementation Summary:\n";
echo "  Implemented: {$status['implemented']}/5\n";
echo "  Partial: {$status['partial']}/5\n";
echo "  Missing: {$status['missing']}/5\n";

$completion = round(($status['implemented'] / 5) * 100);
echo "\nCompletion: {$completion}%\n";

if ($status['implemented'] == 5) {
    echo "\n✓ All Phase 2 features implemented!\n";
    exit(0);
} else {
    echo "\n⚠️  Phase 2 implementation incomplete\n";
    exit(1);
}
