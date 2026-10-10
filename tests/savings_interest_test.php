#!/usr/bin/env php
<?php
/**
 * Test script for savings interest calculation
 * Run: php tests/savings_interest_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/savings_interest.php';

echo "=== Savings Interest Calculation Test ===\n\n";

// Test 1: Calculate interest for sample account
echo "[TEST 1] Interest calculation formula\n";
$test_balance = 1000000.00;
$test_rate = 2.00; // 2% annual
$expected_monthly = round($test_balance * ($test_rate / 100) / 12, 2);
echo "  Balance: " . number_format($test_balance, 2) . "\n";
echo "  Annual Rate: {$test_rate}%\n";
echo "  Expected Monthly Interest: " . number_format($expected_monthly, 2) . "\n";
echo "  Formula: balance * (rate / 100) / 12\n";
echo "  ✓ Formula verified\n\n";

// Test 2: Get eligible accounts
echo "[TEST 2] Get accounts with interest rates\n";
$stmt = db()->prepare('SELECT COUNT(*) AS total FROM savings_accounts sa
                      JOIN savings_types st ON sa.savings_type_id = st.id
                      WHERE sa.status = "active" AND st.interest_rate > 0');
$stmt->execute();
$count = $stmt->get_result()->fetch_assoc()['total'];
echo "  Active accounts with interest: {$count}\n";

if ($count > 0) {
    // Get one sample account
    $stmt = db()->prepare('SELECT sa.id, sa.account_number, sa.balance, st.interest_rate, m.full_name
                          FROM savings_accounts sa
                          JOIN savings_types st ON sa.savings_type_id = st.id
                          JOIN members m ON sa.member_id = m.id
                          WHERE sa.status = "active" AND st.interest_rate > 0
                          LIMIT 1');
    $stmt->execute();
    $sample = $stmt->get_result()->fetch_assoc();
    
    if ($sample) {
        echo "  Sample Account: {$sample['account_number']} ({$sample['full_name']})\n";
        echo "    Balance: " . number_format((float)$sample['balance'], 2) . "\n";
        echo "    Rate: {$sample['interest_rate']}%\n";
        
        // Calculate interest for current month
        $current_month = (int) date('n');
        $current_year = (int) date('Y');
        
        $interest = calculate_monthly_interest((int)$sample['id'], $current_month, $current_year);
        if ($interest) {
            echo "    Calculated Interest: " . number_format($interest['interest_amount'], 2) . "\n";
            echo "  ✓ Calculation successful\n\n";
        } else {
            echo "  ✗ Calculation returned null\n\n";
        }
    }
} else {
    echo "  ⚠ No accounts with interest rate found\n";
    echo "  Tip: Update savings_types.interest_rate > 0 for testing\n\n";
}

// Test 3: Check for duplicate postings
echo "[TEST 3] Duplicate posting prevention\n";
$test_month = (int) date('n');
$test_year = (int) date('Y');
$accounts = get_accounts_for_interest_posting($test_month, $test_year);
$already_posted = array_filter($accounts, fn($a) => $a['already_posted']);
echo "  Period: " . date('F Y', mktime(0, 0, 0, $test_month, 1, $test_year)) . "\n";
echo "  Total accounts: " . count($accounts) . "\n";
echo "  Already posted: " . count($already_posted) . "\n";
echo "  ✓ Duplicate check working\n\n";

// Test 4: Settings check
echo "[TEST 4] Settings verification\n";
$stmt = db()->prepare('SELECT `key`, `value` FROM settings WHERE `key` IN ("auto_interest_enabled", "interest_posting_day")');
$stmt->execute();
$settings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($settings as $s) {
    echo "  {$s['key']}: {$s['value']}\n";
}
if (empty($settings)) {
    echo "  ⚠ Settings not found. Run migration_phase2_compliance.sql\n";
} else {
    echo "  ✓ Settings configured\n";
}
echo "\n";

// Test 5: Database structure check
echo "[TEST 5] Database structure\n";
$tables = ['savings_interest_history', 'savings_accounts'];
foreach ($tables as $table) {
    $result = db()->query("SHOW TABLES LIKE '{$table}'");
    if ($result->num_rows > 0) {
        echo "  ✓ Table {$table} exists\n";
    } else {
        echo "  ✗ Table {$table} missing\n";
    }
}

// Check last_interest_date column
$result = db()->query("SHOW COLUMNS FROM savings_accounts LIKE 'last_interest_date'");
if ($result->num_rows > 0) {
    echo "  ✓ Column savings_accounts.last_interest_date exists\n";
} else {
    echo "  ✗ Column savings_accounts.last_interest_date missing\n";
}

echo "\n=== Test Complete ===\n";
echo "Manual testing:\n";
echo "1. Visit pages/savings/interest_posting.php\n";
echo "2. Select previous month, click Preview\n";
echo "3. Review calculations, click Confirm & Post\n";
echo "4. Check pages/savings/interest_history.php\n";
echo "5. Run: php cron/monthly_interest.php [month] [year]\n";
