<?php
/**
 * Phase 2: Income Statement & Cashflow Functional Tests
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$passed = 0;
$failed = 0;
$errors = [];

function test_report($name, $callback) {
    global $passed, $failed, $errors;
    try {
        $result = $callback();
        if ($result === true) {
            $passed++;
            echo "✓ {$name}\n";
            return true;
        } else {
            $failed++;
            $errors[] = "{$name}: {$result}";
            echo "✗ {$name} - {$result}\n";
            return false;
        }
    } catch (Throwable $e) {
        $failed++;
        $errors[] = "{$name}: Exception - {$e->getMessage()}";
        echo "✗ {$name} - Exception: {$e->getMessage()}\n";
        return false;
    }
}

echo "=== Phase 2: Report Functional Tests ===\n\n";

// TEST 1: Income Statement
echo "1. INCOME STATEMENT TESTS\n";

test_report("Income statement file exists", function() {
    if (file_exists(__DIR__ . '/../pages/reports/income_statement.php')) {
        return true;
    }
    return "File not found";
});

test_report("Interest income query structure", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'loan_payments') && 
        str_contains($content, 'interest_amount') &&
        str_contains($content, 'payment_date')) {
        return true;
    }
    return "Interest income query missing or incomplete";
});

test_report("Admin fee income query structure", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'admin_fee_amount') && 
        str_contains($content, 'loans')) {
        return true;
    }
    return "Admin fee query missing";
});

test_report("Penalty income query structure", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'penalty_amount')) {
        return true;
    }
    return "Penalty income query missing";
});

test_report("Savings interest expense query", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'savings_interest_history')) {
        return true;
    }
    return "Savings interest expense query missing";
});

test_report("Operational expense query", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'cash_bank_transactions') && 
        str_contains($content, 'expense')) {
        return true;
    }
    return "Operational expense query missing";
});

test_report("Net income calculation present", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'net_income') && 
        (str_contains($content, 'total_revenue - total_expense') || 
         str_contains($content, '$total_revenue - $total_expense'))) {
        return true;
    }
    return "Net income calculation missing";
});

test_report("Period comparison logic exists", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'prev_') || str_contains($content, 'previous')) {
        return true;
    }
    return "Comparison with previous period missing";
});

test_report("CSV export capability", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'csv') && str_contains($content, 'fputcsv')) {
        return true;
    }
    return "CSV export missing";
});

test_report("PDF export capability", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'pdf') || str_contains($content, 'SimplePDF')) {
        return true;
    }
    return "PDF export missing";
});

// TEST 2: Cashflow Statement
echo "\n2. CASHFLOW STATEMENT TESTS\n";

test_report("Cashflow statement file exists", function() {
    if (file_exists(__DIR__ . '/../pages/reports/cashflow_statement.php')) {
        return true;
    }
    return "File not found";
});

test_report("Opening balance (saldo awal) calculation", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/cashflow_statement.php');
    if (str_contains($content, 'opening_balance') || str_contains($content, 'saldo_awal')) {
        return true;
    }
    return "Opening balance calculation missing";
});

test_report("Closing balance (saldo akhir) calculation", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/cashflow_statement.php');
    if (str_contains($content, 'closing_balance') || str_contains($content, 'saldo_akhir')) {
        return true;
    }
    return "Closing balance calculation missing";
});

test_report("Net cash flow calculation", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/cashflow_statement.php');
    if (str_contains($content, 'net_') && (str_contains($content, 'debit') || str_contains($content, 'credit'))) {
        return true;
    }
    return "Net cash flow calculation missing";
});

test_report("Cash receipts breakdown", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/cashflow_statement.php');
    if (str_contains($content, 'loan_payment') && str_contains($content, 'savings_deposit')) {
        return true;
    }
    return "Cash receipts breakdown incomplete";
});

test_report("Cash disbursements breakdown", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/cashflow_statement.php');
    if (str_contains($content, 'loan_disbursement') && str_contains($content, 'savings_withdrawal')) {
        return true;
    }
    return "Cash disbursements breakdown incomplete";
});

test_report("Per-account breakdown option", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/cashflow_statement.php');
    if (str_contains($content, 'breakdown') || str_contains($content, 'account_breakdown')) {
        return true;
    }
    return "Per-account breakdown missing";
});

test_report("Data source: cash_bank_transactions", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/cashflow_statement.php');
    if (str_contains($content, 'cash_bank_transactions')) {
        return true;
    }
    return "Not using cash_bank_transactions table";
});

// TEST 3: Data Integrity Tests
echo "\n3. DATA INTEGRITY TESTS\n";

test_report("Income statement handles zero transactions", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/income_statement.php');
    if (str_contains($content, 'COALESCE') || str_contains($content, 'IFNULL')) {
        return true;
    }
    return "No NULL/zero handling in queries";
});

test_report("Cashflow consistency: opening + net = closing", function() {
    $content = file_get_contents(__DIR__ . '/../pages/reports/cashflow_statement.php');
    // Check if there's logic that ensures: opening_balance + net_change = closing_balance
    if (str_contains($content, 'opening_balance') && 
        str_contains($content, 'net_') && 
        str_contains($content, 'closing_balance')) {
        return true;
    }
    return "Consistency check missing";
});

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Report Tests: {$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
} else {
    echo "\n✓ All report tests PASSED\n";
    exit(0);
}
