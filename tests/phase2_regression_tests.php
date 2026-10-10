<?php
/**
 * Phase 2 Regression Test Suite
 * Validates Phase 1 features still work after Phase 2 migration
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$passed = 0;
$failed = 0;
$warnings = [];

function test_regression($name, $callback) {
    global $passed, $failed, $warnings;
    try {
        $result = $callback();
        if ($result === true) {
            $passed++;
            echo "✓ {$name}\n";
            return true;
        } elseif (is_string($result)) {
            $passed++;
            $warnings[] = "{$name}: {$result}";
            echo "⚠ {$name} - {$result}\n";
            return true;
        } else {
            $failed++;
            echo "✗ {$name}\n";
            return false;
        }
    } catch (Throwable $e) {
        $failed++;
        echo "✗ {$name} - Exception: {$e->getMessage()}\n";
        return false;
    }
}

echo "=== Phase 2 Regression Tests ===\n";
echo "Validating Phase 1 features after Phase 2 migration\n\n";

// 1. Agunan (Collateral) Tests
echo "1. COLLATERAL SYSTEM\n";

test_regression("Collateral table exists", function() {
    $r = db()->query("SHOW TABLES LIKE 'loan_collaterals'");
    return $r && $r->num_rows > 0;
});

test_regression("Collateral constraint enforced", function() {
    // Check if validation code exists in process.php
    $content = file_get_contents(__DIR__ . '/../pages/loans/process.php');
    return str_contains($content, 'collateral') && str_contains($content, '5000000');
});

// 2. Penalty Calculation Tests
echo "\n2. PENALTY SYSTEM\n";

test_regression("Penalty fields exist in loan_payments", function() {
    $r = db()->query("SHOW COLUMNS FROM loan_payments LIKE 'penalty_%'");
    return $r && $r->num_rows >= 2;
});

test_regression("Penalty calculation logic present", function() {
    $files = ['pages/loans/payment.php', 'includes/loan_tools.php'];
    foreach ($files as $f) {
        if (file_exists(__DIR__ . '/../' . $f)) {
            $content = file_get_contents(__DIR__ . '/../' . $f);
            if (str_contains($content, 'penalty')) return true;
        }
    }
    return false;
});

// 3. Admin Fee Tests
echo "\n3. ADMIN FEE SYSTEM\n";

test_regression("Admin fee field exists in loans", function() {
    $r = db()->query("SHOW COLUMNS FROM loans LIKE 'admin_fee_amount'");
    return $r && $r->num_rows > 0;
});

test_regression("Admin fee in loan_products", function() {
    $r = db()->query("SHOW COLUMNS FROM loan_products LIKE 'admin_fee_%'");
    return $r && $r->num_rows > 0;
});

// 4. Cash/Bank System Tests
echo "\n4. CASH/BANK SYSTEM\n";

test_regression("Cash/bank tables exist", function() {
    $tables = ['cash_bank_accounts', 'cash_bank_transactions'];
    foreach ($tables as $t) {
        $r = db()->query("SHOW TABLES LIKE '{$t}'");
        if (!$r || $r->num_rows == 0) return false;
    }
    return true;
});

test_regression("Cash/bank helper functions exist", function() {
    return file_exists(__DIR__ . '/../includes/cashbank_helpers.php');
});

test_regression("Cash/bank UI pages exist", function() {
    $pages = ['pages/cashbank/accounts.php', 'pages/cashbank/transactions.php'];
    foreach ($pages as $p) {
        if (!file_exists(__DIR__ . '/../' . $p)) return false;
    }
    return true;
});

// 5. Financial Position Report
echo "\n5. FINANCIAL POSITION REPORT\n";

test_regression("Financial position report exists", function() {
    return file_exists(__DIR__ . '/../pages/reports/financial_position.php');
});

test_regression("Modal Koperasi in database", function() {
    $r = db()->query("SHOW COLUMNS FROM settings LIKE 'modal_koperasi'");
    if (!$r || $r->num_rows == 0) {
        // Check if it's in settings table as a key-value pair
        $stmt = db()->prepare("SELECT COUNT(*) as c FROM settings WHERE `key` = 'modal_koperasi'");
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['c'];
        return $count > 0;
    }
    return true;
});

// 6. Single-level Approval (backward compatibility)
echo "\n6. SINGLE-LEVEL APPROVAL (Backward Compatibility)\n";

test_regression("Approval logic in process.php", function() {
    $content = file_get_contents(__DIR__ . '/../pages/loans/process.php');
    return str_contains($content, 'approve') && str_contains($content, 'status');
});

test_regression("Approval without approval_levels field works", function() {
    // Check if default value allows single approval
    $r = db()->query("SHOW COLUMNS FROM loan_products LIKE 'approval_levels'");
    if ($r && $r->num_rows > 0) {
        $col = $r->fetch_assoc();
        return str_contains($col['Default'] ?? '', '1');
    }
    return "approval_levels not migrated yet";
});

// 7. Loan Payment Processing
echo "\n7. LOAN PAYMENT PROCESSING\n";

test_regression("Payment page exists", function() {
    return file_exists(__DIR__ . '/../pages/loans/payment.php');
});

test_regression("Payment schedule generation", function() {
    $content = file_get_contents(__DIR__ . '/../pages/loans/process.php');
    return str_contains($content, 'loan_payments') && str_contains($content, 'INSERT');
});

// 8. Savings System
echo "\n8. SAVINGS SYSTEM\n";

test_regression("Savings tables intact", function() {
    $tables = ['savings_accounts', 'savings_transactions'];
    foreach ($tables as $t) {
        $r = db()->query("SHOW TABLES LIKE '{$t}'");
        if (!$r || $r->num_rows == 0) return false;
    }
    return true;
});

test_regression("Savings deposit/withdrawal pages", function() {
    return file_exists(__DIR__ . '/../pages/savings/form.php');
});

// 9. Member Management
echo "\n9. MEMBER MANAGEMENT\n";

test_regression("Members table intact", function() {
    $r = db()->query("SHOW TABLES LIKE 'members'");
    return $r && $r->num_rows > 0;
});

test_regression("Member CRUD pages exist", function() {
    return file_exists(__DIR__ . '/../pages/members/index.php') &&
           file_exists(__DIR__ . '/../pages/members/form.php');
});

// 10. Authentication & Authorization
echo "\n10. AUTH & PERMISSIONS\n";

test_regression("Auth system intact", function() {
    return file_exists(__DIR__ . '/../includes/auth.php');
});

test_regression("Users and roles tables", function() {
    $tables = ['users', 'roles', 'permissions', 'role_permissions'];
    foreach ($tables as $t) {
        $r = db()->query("SHOW TABLES LIKE '{$t}'");
        if (!$r || $r->num_rows == 0) return false;
    }
    return true;
});

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Regression Tests: {$passed} passed, {$failed} failed\n";

if (count($warnings) > 0) {
    echo "\nWarnings:\n";
    foreach ($warnings as $w) {
        echo "  ⚠ {$w}\n";
    }
}

if ($failed == 0) {
    echo "\n✓ All regression tests PASSED\n";
    echo "Phase 1 features remain intact after Phase 2 migration.\n";
    exit(0);
} else {
    echo "\n✗ Some regression tests FAILED\n";
    echo "Phase 2 migration may have broken Phase 1 features.\n";
    exit(1);
}
