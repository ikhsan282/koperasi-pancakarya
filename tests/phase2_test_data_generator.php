<?php
/**
 * Phase 2 Test Data Generator
 * Creates realistic test data for Phase 2 feature testing
 * Run AFTER migration and BEFORE manual testing
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== Phase 2 Test Data Generator ===\n\n";

$db = db();
$db->begin_transaction();

try {
    // 1. Create test members with different notification preferences
    echo "1. Creating test members...\n";
    
    $test_members = [
        ['Budi Email', 'budi@test.com', '081234567890', 'email'],
        ['Ani SMS', 'ani@test.com', '081234567891', 'sms'],
        ['Citra Both', 'citra@test.com', '081234567892', 'both'],
        ['Dedi None', 'dedi@test.com', '081234567893', 'none']
    ];
    
    $member_ids = [];
    foreach ($test_members as $m) {
        $stmt = $db->prepare("
            INSERT INTO members (member_number, name, email, phone, address, join_date, status, notification_preference) 
            VALUES (?, ?, ?, ?, 'Test Address', CURDATE(), 'active', ?)
        ");
        $member_num = 'TEST' . str_pad(count($member_ids) + 1, 4, '0', STR_PAD_LEFT);
        $stmt->bind_param('sssss', $member_num, $m[0], $m[1], $m[2], $m[3]);
        $stmt->execute();
        $member_ids[] = $db->insert_id;
        echo "  ✓ Created member: {$m[0]} (ID: {$db->insert_id})\n";
    }
    
    // 2. Create loan products with different approval levels
    echo "\n2. Creating loan products...\n";
    
    $products = [
        ['Pinjaman Express (Single)', 1.5, 12, 1],
        ['Pinjaman Premium (Dual)', 1.2, 24, 2]
    ];
    
    $product_ids = [];
    foreach ($products as $p) {
        $stmt = $db->prepare("
            INSERT INTO loan_products (name, interest_rate, max_tenor_months, min_amount, max_amount, approval_levels, is_active)
            VALUES (?, ?, ?, 1000000, 20000000, ?, 1)
        ");
        $stmt->bind_param('sdii', $p[0], $p[1], $p[2], $p[3]);
        $stmt->execute();
        $product_ids[] = $db->insert_id;
        echo "  ✓ Created product: {$p[0]} (approval_levels={$p[3]})\n";
    }
    
    // 3. Create test loans with different statuses
    echo "\n3. Creating test loans...\n";
    
    $user_id = 1; // Assume Super Admin exists
    
    // Loan 1: Single-level (for backward compatibility test)
    $loan_number = 'LOAN-TEST-001';
    $stmt = $db->prepare("
        INSERT INTO loans (loan_number, loan_product_id, member_id, member_name, amount, interest_rate, term_months, 
                          admin_fee_amount, application_date, status, current_approval_level)
        VALUES (?, ?, ?, ?, 5000000, 1.5, 12, 100000, CURDATE(), 'pending', 0)
    ");
    $stmt->bind_param('siis', $loan_number, $product_ids[0], $member_ids[0], $test_members[0][0]);
    $stmt->execute();
    $loan1_id = $db->insert_id;
    echo "  ✓ Created loan: {$loan_number} (single-level approval)\n";
    
    // Loan 2: Dual-level (for new workflow test)
    $loan_number = 'LOAN-TEST-002';
    $stmt = $db->prepare("
        INSERT INTO loans (loan_number, loan_product_id, member_id, member_name, amount, interest_rate, term_months,
                          admin_fee_amount, application_date, status, current_approval_level)
        VALUES (?, ?, ?, ?, 10000000, 1.2, 24, 200000, CURDATE(), 'pending', 0)
    ");
    $stmt->bind_param('siis', $loan_number, $product_ids[1], $member_ids[1], $test_members[1][0]);
    $stmt->execute();
    $loan2_id = $db->insert_id;
    echo "  ✓ Created loan: {$loan_number} (dual-level approval)\n";
    
    // 4. Create savings accounts with balances
    echo "\n4. Creating savings accounts...\n";
    
    $savings_ids = [];
    foreach ($member_ids as $idx => $mid) {
        $account_num = 'SAV-TEST-' . str_pad($idx + 1, 4, '0', STR_PAD_LEFT);
        $balance = 5000000 + ($idx * 1000000);
        
        $stmt = $db->prepare("
            INSERT INTO savings_accounts (account_number, member_id, member_name, account_type, balance, interest_rate, 
                                         opening_date, status, last_interest_date)
            VALUES (?, ?, ?, 'simpanan_pokok', ?, 6.0, CURDATE(), 'active', NULL)
        ");
        $stmt->bind_param('sisd', $account_num, $mid, $test_members[$idx][0], $balance);
        $stmt->execute();
        $savings_ids[] = $db->insert_id;
        echo "  ✓ Created savings: {$account_num} (balance: Rp " . number_format($balance) . ")\n";
    }
    
    // 5. Create cash/bank accounts
    echo "\n5. Creating cash/bank accounts...\n";
    
    $accounts = [
        ['Kas Kecil Test', 'cash', 1000000],
        ['Bank BCA Test', 'bank', 50000000]
    ];
    
    $cashbank_ids = [];
    foreach ($accounts as $a) {
        $stmt = $db->prepare("
            INSERT INTO cash_bank_accounts (account_name, account_type, account_number, initial_balance, current_balance, is_active)
            VALUES (?, ?, 'TEST-ACC', ?, ?, 1)
        ");
        $stmt->bind_param('ssdd', $a[0], $a[1], $a[2], $a[2]);
        $stmt->execute();
        $cashbank_ids[] = $db->insert_id;
        echo "  ✓ Created account: {$a[0]} (balance: Rp " . number_format($a[2]) . ")\n";
    }
    
    // 6. Create historical transactions for report testing
    echo "\n6. Creating historical transactions (6 months)...\n";
    
    for ($month = 1; $month <= 6; $month++) {
        $date = date('Y-m-d', strtotime("-{$month} months"));
        
        // Loan payment (interest income)
        $stmt = $db->prepare("
            INSERT INTO loan_payments (loan_id, due_date, payment_date, amount, principal_amount, interest_amount, 
                                      amount_due, amount_paid, status, balance_remaining, payment_number)
            VALUES (?, ?, ?, 500000, 425000, 75000, 500000, 500000, 'paid', 0, ?)
        ");
        $stmt->bind_param('issi', $loan1_id, $date, $date, $month);
        $stmt->execute();
        
        // Savings transaction
        $stmt = $db->prepare("
            INSERT INTO savings_transactions (savings_account_id, transaction_type, amount, balance_after, transaction_date, notes)
            VALUES (?, 'deposit', 500000, ?, ?, 'Test deposit')
        ");
        $balance = 5000000 + ($month * 500000);
        $stmt->bind_param('ids', $savings_ids[0], $balance, $date);
        $stmt->execute();
        
        // Cash/bank expense
        $stmt = $db->prepare("
            INSERT INTO cash_bank_transactions (account_id, transaction_type, amount, balance_after, reference_type,
                                               transaction_date, description)
            VALUES (?, 'credit', 200000, ?, 'expense', ?, 'Test operational expense')
        ");
        $cb_balance = 1000000 - ($month * 200000);
        $stmt->bind_param('ids', $cashbank_ids[0], $cb_balance, $date);
        $stmt->execute();
    }
    echo "  ✓ Created 18 transactions (6 months × 3 types)\n";
    
    // 7. Create test notifications
    echo "\n7. Creating test notifications...\n";
    
    $stmt = $db->prepare("
        INSERT INTO notifications (notification_type, reference_type, reference_id, recipient_member_id, title, message, is_read, sent_at)
        VALUES ('loan_due_reminder', 'loan_payment', ?, ?, 'Reminder: Angsuran Jatuh Tempo', 'Angsuran Anda jatuh tempo 3 hari lagi', 0, NOW())
    ");
    $stmt->bind_param('ii', $loan1_id, $member_ids[0]);
    $stmt->execute();
    echo "  ✓ Created test notification\n";
    
    $db->commit();
    
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "✓ Test data generation COMPLETE\n\n";
    echo "Summary:\n";
    echo "  Members: " . count($member_ids) . " (with varied notification preferences)\n";
    echo "  Loan Products: " . count($product_ids) . " (single & dual approval)\n";
    echo "  Loans: 2 (for approval workflow testing)\n";
    echo "  Savings Accounts: " . count($savings_ids) . " (ready for interest posting)\n";
    echo "  Cash/Bank Accounts: " . count($cashbank_ids) . "\n";
    echo "  Historical Transactions: 18 (6 months of data)\n";
    echo "  Notifications: 1 test notification\n";
    echo "\nYou can now test Phase 2 features with realistic data!\n";
    
} catch (Throwable $e) {
    $db->rollback();
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
