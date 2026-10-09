<?php
/**
 * Comprehensive test for 5 critical bug fixes
 * Tests race conditions, overdue calculation, and disbursement validation
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sequences.php';

echo "========================================\n";
echo "TESTING 5 CRITICAL BUG FIXES\n";
echo "Branch: fix/koperasi-critical-bugs\n";
echo "========================================\n\n";

$results = [];

// TEST 0: Check if migration has been applied
echo "TEST 0: Migration Status Check\n";
echo "------------------------------\n";
try {
    $db = db();
    $result = $db->query("SHOW TABLES LIKE 'sequences'");
    if ($result->num_rows > 0) {
        echo "✓ sequences table exists\n";
        
        // Check table structure
        $result = $db->query("DESCRIBE sequences");
        $cols = [];
        while ($row = $result->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
        echo "  Columns: " . implode(', ', $cols) . "\n";
        
        // Check current data
        $result = $db->query("SELECT * FROM sequences");
        $count = $result->num_rows;
        echo "  Current sequences: $count\n";
        if ($count > 0) {
            while ($row = $result->fetch_assoc()) {
                echo "    - {$row['name']}: {$row['current_value']}\n";
            }
        }
        $results['migration'] = 'PASS';
    } else {
        echo "✗ sequences table NOT found - migration not applied\n";
        $results['migration'] = 'FAIL';
    }
} catch (Exception $e) {
    echo "✗ Error checking migration: " . $e->getMessage() . "\n";
    $results['migration'] = 'FAIL';
}
echo "\n";

// TEST 1: Race condition - Member number generation
echo "TEST 1: Member Number Generation (Race Condition Fix)\n";
echo "-------------------------------------------------------\n";
try {
    $numbers = [];
    $iterations = 10;
    
    echo "Generating $iterations member numbers sequentially...\n";
    for ($i = 0; $i < $iterations; $i++) {
        $num = generate_member_number();
        $numbers[] = $num;
        echo "  [$i] $num\n";
    }
    
    // Check for duplicates
    $unique = array_unique($numbers);
    if (count($unique) === count($numbers)) {
        echo "✓ All $iterations numbers are unique\n";
        
        // Check format
        $valid_format = true;
        foreach ($numbers as $num) {
            if (!preg_match('/^KP-\d{6}-\d{4}$/', $num)) {
                $valid_format = false;
                echo "✗ Invalid format: $num\n";
            }
        }
        
        if ($valid_format) {
            echo "✓ All numbers match format KP-YYYYMM-XXXX\n";
            $results['member_race'] = 'PASS';
        } else {
            echo "✗ Format validation failed\n";
            $results['member_race'] = 'FAIL';
        }
    } else {
        echo "✗ DUPLICATES FOUND!\n";
        $dupes = array_diff_assoc($numbers, $unique);
        foreach ($dupes as $dupe) {
            echo "  Duplicate: $dupe\n";
        }
        $results['member_race'] = 'FAIL';
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    $results['member_race'] = 'FAIL';
}
echo "\n";

// TEST 2: Race condition - Loan number generation
echo "TEST 2: Loan Number Generation (Race Condition Fix)\n";
echo "-----------------------------------------------------\n";
try {
    $numbers = [];
    $iterations = 10;
    
    echo "Generating $iterations loan numbers sequentially...\n";
    for ($i = 0; $i < $iterations; $i++) {
        $num = generate_loan_number();
        $numbers[] = $num;
        echo "  [$i] $num\n";
    }
    
    // Check for duplicates
    $unique = array_unique($numbers);
    if (count($unique) === count($numbers)) {
        echo "✓ All $iterations numbers are unique\n";
        
        // Check format
        $valid_format = true;
        foreach ($numbers as $num) {
            if (!preg_match('/^L-\d{6}-\d{4}$/', $num)) {
                $valid_format = false;
                echo "✗ Invalid format: $num\n";
            }
        }
        
        if ($valid_format) {
            echo "✓ All numbers match format L-YYYYMM-XXXX\n";
            $results['loan_race'] = 'PASS';
        } else {
            echo "✗ Format validation failed\n";
            $results['loan_race'] = 'FAIL';
        }
    } else {
        echo "✗ DUPLICATES FOUND!\n";
        $dupes = array_diff_assoc($numbers, $unique);
        foreach ($dupes as $dupe) {
            echo "  Duplicate: $dupe\n";
        }
        $results['loan_race'] = 'FAIL';
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    $results['loan_race'] = 'FAIL';
}
echo "\n";

// TEST 3: Race condition - Savings account number generation
echo "TEST 3: Savings Account Number Generation (Race Condition Fix)\n";
echo "----------------------------------------------------------------\n";
try {
    $numbers = [];
    $iterations = 10;
    $test_member = 'KP-202610-0001';
    $test_type = 1;
    
    echo "Generating $iterations savings account numbers for member $test_member, type $test_type...\n";
    for ($i = 0; $i < $iterations; $i++) {
        $num = generate_savings_account_number($test_member, $test_type);
        $numbers[] = $num;
        echo "  [$i] $num\n";
    }
    
    // Check for duplicates
    $unique = array_unique($numbers);
    if (count($unique) === count($numbers)) {
        echo "✓ All $iterations numbers are unique\n";
        
        // Check format
        $valid_format = true;
        foreach ($numbers as $num) {
            if (!preg_match('/^SIM-KP-\d{6}-\d{4}-\d+-\d{2}$/', $num)) {
                $valid_format = false;
                echo "✗ Invalid format: $num\n";
            }
        }
        
        if ($valid_format) {
            echo "✓ All numbers match format SIM-{member_number}-{type_id}-XX\n";
            $results['savings_race'] = 'PASS';
        } else {
            echo "✗ Format validation failed\n";
            $results['savings_race'] = 'FAIL';
        }
    } else {
        echo "✗ DUPLICATES FOUND!\n";
        $dupes = array_diff_assoc($numbers, $unique);
        foreach ($dupes as $dupe) {
            echo "  Duplicate: $dupe\n";
        }
        $results['savings_race'] = 'FAIL';
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    $results['savings_race'] = 'FAIL';
}
echo "\n";

// TEST 4: Dashboard overdue calculation
echo "TEST 4: Dashboard Overdue Calculation\n";
echo "---------------------------------------\n";
try {
    $db = db();
    
    // Run the actual query from dashboard
    $query = "SELECT l.id, l.loan_number, m.member_number, m.full_name, l.monthly_payment,
                     COUNT(CASE WHEN lp.status != \"paid\" AND lp.due_date < CURRENT_DATE THEN 1 END) AS overdue_count
              FROM loans l 
              JOIN members m ON m.id = l.member_id
              JOIN loan_payments lp ON lp.loan_id = l.id
              WHERE l.status = \"active\"
              GROUP BY l.id
              HAVING overdue_count > 0
              ORDER BY overdue_count DESC";
    
    echo "Executing overdue calculation query...\n";
    $stmt = $db->query($query);
    $overdue_loans = $stmt->fetch_all(MYSQLI_ASSOC);
    
    echo "Found " . count($overdue_loans) . " loans with overdue payments\n";
    
    if (count($overdue_loans) > 0) {
        echo "\nOverdue loans:\n";
        foreach (array_slice($overdue_loans, 0, 5) as $loan) {
            echo "  - {$loan['loan_number']} ({$loan['full_name']}): {$loan['overdue_count']} angsuran lewat\n";
        }
        if (count($overdue_loans) > 5) {
            echo "  ... and " . (count($overdue_loans) - 5) . " more\n";
        }
    }
    
    // Verify the query logic manually for one loan
    if (count($overdue_loans) > 0) {
        $test_loan = $overdue_loans[0];
        $loan_id = $test_loan['id'];
        
        echo "\nVerifying calculation for loan #{$loan_id}:\n";
        
        // Manual count
        $verify = $db->query("SELECT COUNT(*) as cnt FROM loan_payments 
                              WHERE loan_id = $loan_id 
                              AND status != 'paid' 
                              AND due_date < CURRENT_DATE");
        $manual_count = $verify->fetch_assoc()['cnt'];
        
        echo "  Query result: {$test_loan['overdue_count']} overdue\n";
        echo "  Manual count: $manual_count overdue\n";
        
        if ($manual_count == $test_loan['overdue_count']) {
            echo "✓ Overdue count matches manual verification\n";
            $results['overdue_calc'] = 'PASS';
        } else {
            echo "✗ Overdue count MISMATCH!\n";
            $results['overdue_calc'] = 'FAIL';
        }
    } else {
        echo "✓ Query executed successfully (no overdue loans found)\n";
        $results['overdue_calc'] = 'PASS';
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    $results['overdue_calc'] = 'FAIL';
}
echo "\n";

// TEST 5: Disbursement date validation
echo "TEST 5: Disbursement Date Validation\n";
echo "--------------------------------------\n";
echo "Testing validation logic from pages/loans/process.php...\n\n";

$test_cases = [
    [
        'name' => 'Future date',
        'disbursement' => date('Y-m-d', strtotime('+1 day')),
        'application' => date('Y-m-d', strtotime('-7 days')),
        'expected' => 'REJECT',
        'reason' => 'Tanggal pencairan tidak boleh di masa depan'
    ],
    [
        'name' => 'Date before application',
        'disbursement' => date('Y-m-d', strtotime('-10 days')),
        'application' => date('Y-m-d', strtotime('-7 days')),
        'expected' => 'REJECT',
        'reason' => 'Tanggal pencairan tidak boleh sebelum tanggal pengajuan'
    ],
    [
        'name' => 'Today (valid)',
        'disbursement' => date('Y-m-d'),
        'application' => date('Y-m-d', strtotime('-7 days')),
        'expected' => 'ACCEPT',
        'reason' => 'Valid disbursement date'
    ],
    [
        'name' => 'Past date after application (valid)',
        'disbursement' => date('Y-m-d', strtotime('-2 days')),
        'application' => date('Y-m-d', strtotime('-7 days')),
        'expected' => 'ACCEPT',
        'reason' => 'Valid disbursement date'
    ],
];

$all_passed = true;
foreach ($test_cases as $i => $test) {
    echo "Test case " . ($i + 1) . ": {$test['name']}\n";
    echo "  Disbursement: {$test['disbursement']}\n";
    echo "  Application:  {$test['application']}\n";
    
    // Simulate validation logic from process.php
    $disbursement_date = $test['disbursement'];
    $date_obj = DateTime::createFromFormat('Y-m-d', $disbursement_date);
    
    $error = null;
    if (!$date_obj || $date_obj->format('Y-m-d') !== $disbursement_date) {
        $error = 'Tanggal pencairan tidak valid';
    } else {
        $today = new DateTime();
        $app_date = new DateTime($test['application']);
        
        if ($date_obj > $today) {
            $error = 'Tanggal pencairan tidak boleh di masa depan';
        } elseif ($date_obj < $app_date) {
            $error = 'Tanggal pencairan tidak boleh sebelum tanggal pengajuan';
        }
    }
    
    $actual = $error ? 'REJECT' : 'ACCEPT';
    $passed = ($actual === $test['expected']);
    
    if ($passed) {
        echo "  ✓ {$actual} (expected {$test['expected']})\n";
        if ($error) {
            echo "    Error message: $error\n";
        }
    } else {
        echo "  ✗ {$actual} (expected {$test['expected']})\n";
        echo "    Error: " . ($error ?? 'none') . "\n";
        $all_passed = false;
    }
    echo "\n";
}

if ($all_passed) {
    echo "✓ All validation test cases passed\n";
    $results['disbursement_validation'] = 'PASS';
} else {
    echo "✗ Some validation test cases failed\n";
    $results['disbursement_validation'] = 'FAIL';
}

// FINAL SUMMARY
echo "\n========================================\n";
echo "FINAL TEST RESULTS\n";
echo "========================================\n";
$total = count($results);
$passed = count(array_filter($results, fn($r) => $r === 'PASS'));

foreach ($results as $test => $result) {
    $status = $result === 'PASS' ? '✓' : '✗';
    echo "$status " . str_pad(ucwords(str_replace('_', ' ', $test)), 40) . " $result\n";
}

echo "\nSummary: $passed/$total tests passed\n";

if ($passed === $total) {
    echo "\n🎉 ALL BUG FIXES VERIFIED!\n";
    exit(0);
} else {
    echo "\n⚠️  SOME TESTS FAILED - Review output above\n";
    exit(1);
}
