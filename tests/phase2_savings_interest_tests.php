<?php
/**
 * Phase 2: Savings Interest Functional Tests
 * Tests calculation logic, posting, and edge cases
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/savings_interest.php';

$passed = 0;
$failed = 0;
$errors = [];

function test_interest($name, $callback) {
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

echo "=== Phase 2: Savings Interest Tests ===\n\n";

// TEST 1: Calculation accuracy
echo "1. CALCULATION TESTS\n";

test_interest("Monthly interest calculation formula", function() {
    // Create test account
    db()->begin_transaction();
    try {
        // Mock data: 10,000,000 balance, 6% annual rate
        $stmt = db()->query("SELECT id FROM savings_types LIMIT 1");
        $type = $stmt->fetch_assoc();
        if (!$type) return "No savings types found";
        
        $stmt = db()->query("SELECT id FROM members LIMIT 1");
        $member = $stmt->fetch_assoc();
        if (!$member) return "No members found";
        
        $account_num = 'TEST-INT-' . time();
        $stmt = db()->prepare("INSERT INTO savings_accounts 
            (account_number, member_id, savings_type_id, balance, status) 
            VALUES (?, ?, ?, 10000000, 'active')");
        $stmt->bind_param('sii', $account_num, $member['id'], $type['id']);
        $stmt->execute();
        $account_id = db()->insert_id();
        
        // Update interest rate to 6%
        $stmt = db()->prepare("UPDATE savings_types SET interest_rate = 6.0 WHERE id = ?");
        $stmt->bind_param('i', $type['id']);
        $stmt->execute();
        
        $interest = calculate_monthly_interest($account_id, 1, 2026);
        
        // Expected: 10,000,000 * (6/100) / 12 = 50,000
        $expected = 50000.00;
        $actual = $interest['interest_amount'];
        
        db()->rollback();
        
        if (abs($actual - $expected) < 0.01) {
            return true;
        }
        return "Expected {$expected}, got {$actual}";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

test_interest("Zero balance account returns 0 interest", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT sa.id FROM savings_accounts sa 
                            JOIN savings_types st ON sa.savings_type_id = st.id 
                            WHERE sa.balance = 0 AND st.interest_rate > 0 LIMIT 1");
        $account = $stmt->fetch_assoc();
        
        if (!$account) {
            db()->rollback();
            return "No zero-balance account found to test";
        }
        
        $interest = calculate_monthly_interest((int)$account['id'], 1, 2026);
        db()->rollback();
        
        if ($interest && $interest['interest_amount'] == 0) {
            return true;
        }
        return "Zero balance should return 0 interest";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

test_interest("Inactive account is skipped", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id FROM savings_types LIMIT 1");
        $type = $stmt->fetch_assoc();
        $stmt = db()->query("SELECT id FROM members LIMIT 1");
        $member = $stmt->fetch_assoc();
        
        $account_num = 'TEST-CLOSED-' . time();
        $stmt = db()->prepare("INSERT INTO savings_accounts 
            (account_number, member_id, savings_type_id, balance, status) 
            VALUES (?, ?, ?, 5000000, 'closed')");
        $stmt->bind_param('sii', $account_num, $member['id'], $type['id']);
        $stmt->execute();
        $account_id = db()->insert_id();
        
        $interest = calculate_monthly_interest($account_id, 1, 2026);
        
        db()->rollback();
        
        if ($interest === null) {
            return true;
        }
        return "Inactive account should return null";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

// TEST 2: Posting logic
echo "\n2. POSTING LOGIC TESTS\n";

test_interest("Double-posting prevention", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id FROM savings_accounts WHERE balance > 0 AND status = 'active' LIMIT 1");
        $account = $stmt->fetch_assoc();
        if (!$account) {
            db()->rollback();
            return "No active account found";
        }
        
        $stmt = db()->query("SELECT id FROM users LIMIT 1");
        $user = $stmt->fetch_assoc();
        if (!$user) {
            db()->rollback();
            return "No user found";
        }
        
        // First post
        $success1 = post_interest_for_account((int)$account['id'], 1, 2026, (int)$user['id']);
        
        // Try to post again for same period
        try {
            $success2 = post_interest_for_account((int)$account['id'], 1, 2026, (int)$user['id']);
            db()->rollback();
            return "Should throw exception on double post";
        } catch (Exception $e) {
            db()->rollback();
            if (str_contains($e->getMessage(), 'sudah diposting')) {
                return true;
            }
            return "Wrong exception: " . $e->getMessage();
        }
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

test_interest("Interest posts to savings_transactions", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id FROM savings_accounts WHERE balance > 0 AND status = 'active' LIMIT 1");
        $account = $stmt->fetch_assoc();
        $stmt = db()->query("SELECT id FROM users LIMIT 1");
        $user = $stmt->fetch_assoc();
        
        if (!$account || !$user) {
            db()->rollback();
            return "Test data missing";
        }
        
        $before_count = db()->query("SELECT COUNT(*) as c FROM savings_transactions WHERE savings_account_id = {$account['id']}")->fetch_assoc()['c'];
        
        post_interest_for_account((int)$account['id'], 2, 2026, (int)$user['id']);
        
        $after_count = db()->query("SELECT COUNT(*) as c FROM savings_transactions WHERE savings_account_id = {$account['id']}")->fetch_assoc()['c'];
        
        db()->rollback();
        
        if ($after_count == $before_count + 1) {
            return true;
        }
        return "Transaction not created";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

test_interest("Interest updates account balance", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id, balance FROM savings_accounts WHERE balance > 0 AND status = 'active' LIMIT 1");
        $account = $stmt->fetch_assoc();
        $stmt = db()->query("SELECT id FROM users LIMIT 1");
        $user = $stmt->fetch_assoc();
        
        if (!$account || !$user) {
            db()->rollback();
            return "Test data missing";
        }
        
        $before_balance = (float)$account['balance'];
        $interest_data = calculate_monthly_interest((int)$account['id'], 3, 2026);
        $expected_interest = $interest_data['interest_amount'];
        
        post_interest_for_account((int)$account['id'], 3, 2026, (int)$user['id']);
        
        $stmt = db()->prepare("SELECT balance FROM savings_accounts WHERE id = ?");
        $stmt->bind_param('i', $account['id']);
        $stmt->execute();
        $after_balance = (float)$stmt->get_result()->fetch_assoc()['balance'];
        
        db()->rollback();
        
        $expected_after = $before_balance + $expected_interest;
        if (abs($after_balance - $expected_after) < 0.01) {
            return true;
        }
        return "Balance not updated correctly: expected {$expected_after}, got {$after_balance}";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

test_interest("Interest creates history record", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id FROM savings_accounts WHERE balance > 0 AND status = 'active' LIMIT 1");
        $account = $stmt->fetch_assoc();
        $stmt = db()->query("SELECT id FROM users LIMIT 1");
        $user = $stmt->fetch_assoc();
        
        if (!$account || !$user) {
            db()->rollback();
            return "Test data missing";
        }
        
        post_interest_for_account((int)$account['id'], 4, 2026, (int)$user['id']);
        
        $stmt = db()->prepare("SELECT COUNT(*) as c FROM savings_interest_history 
                              WHERE savings_account_id = ? AND period_start = '2026-04-01'");
        $stmt->bind_param('i', $account['id']);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['c'];
        
        db()->rollback();
        
        if ($count == 1) {
            return true;
        }
        return "History record not created";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

// TEST 3: Edge cases
echo "\n3. EDGE CASE TESTS\n";

test_interest("Invalid month throws exception", function() {
    try {
        $stmt = db()->query("SELECT id FROM savings_accounts LIMIT 1");
        $account = $stmt->fetch_assoc();
        calculate_monthly_interest((int)$account['id'], 13, 2026);
        return "Should throw exception for month 13";
    } catch (InvalidArgumentException $e) {
        return true;
    } catch (Throwable $e) {
        return "Wrong exception type: " . get_class($e);
    }
});

test_interest("Invalid year throws exception", function() {
    try {
        $stmt = db()->query("SELECT id FROM savings_accounts LIMIT 1");
        $account = $stmt->fetch_assoc();
        calculate_monthly_interest((int)$account['id'], 1, 1999);
        return "Should throw exception for year 1999";
    } catch (InvalidArgumentException $e) {
        return true;
    } catch (Throwable $e) {
        return "Wrong exception type: " . get_class($e);
    }
});

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Savings Interest Tests: {$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
} else {
    echo "\n✓ All savings interest tests PASSED\n";
    exit(0);
}
