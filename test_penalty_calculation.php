<?php
// Standalone test for penalty calculation (no DB needed)

function calculate_penalty_test(string $due_date, string $payment_date, float $amount_due, float $penalty_rate = 0.5): array
{
    $due = new DateTime($due_date);
    $paid = new DateTime($payment_date);
    $days_overdue = max(0, $paid->diff($due)->days * ($paid > $due ? 1 : 0));
    
    if ($days_overdue === 0) {
        return ['penalty_amount' => 0.0, 'days_overdue' => 0];
    }
    
    $penalty_amount = round($days_overdue * ($penalty_rate / 100) * $amount_due, 2);
    
    return ['penalty_amount' => $penalty_amount, 'days_overdue' => $days_overdue];
}

echo "=== Test Penalty Calculation ===\n\n";

// Test 1: On time payment (no penalty)
$test1 = calculate_penalty_test('2024-01-15', '2024-01-15', 1000000);
echo "Test 1 - On time:\n";
echo "  Days overdue: {$test1['days_overdue']}\n";
echo "  Penalty: Rp" . number_format($test1['penalty_amount'], 0, ',', '.') . "\n";
echo "  Expected: 0 days, Rp0\n";
echo "  Status: " . ($test1['days_overdue'] === 0 ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 2: 10 days late, Rp1,000,000, 0.5% per day
$test2 = calculate_penalty_test('2024-01-15', '2024-01-25', 1000000, 0.5);
echo "Test 2 - 10 days late, 0.5% rate:\n";
echo "  Days overdue: {$test2['days_overdue']}\n";
echo "  Penalty: Rp" . number_format($test2['penalty_amount'], 0, ',', '.') . "\n";
echo "  Expected: 10 days, Rp50,000\n";
echo "  Status: " . ($test2['penalty_amount'] == 50000 ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 3: 5 days late, Rp2,000,000, 1% per day
$test3 = calculate_penalty_test('2024-02-01', '2024-02-06', 2000000, 1.0);
echo "Test 3 - 5 days late, 1% rate:\n";
echo "  Days overdue: {$test3['days_overdue']}\n";
echo "  Penalty: Rp" . number_format($test3['penalty_amount'], 0, ',', '.') . "\n";
echo "  Expected: 5 days, Rp100,000\n";
echo "  Status: " . ($test3['penalty_amount'] == 100000 ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 4: Early payment (no penalty)
$test4 = calculate_penalty_test('2024-03-15', '2024-03-10', 1500000);
echo "Test 4 - Early payment:\n";
echo "  Days overdue: {$test4['days_overdue']}\n";
echo "  Penalty: Rp" . number_format($test4['penalty_amount'], 0, ',', '.') . "\n";
echo "  Expected: 0 days, Rp0\n";
echo "  Status: " . ($test4['days_overdue'] === 0 ? "✓ PASS" : "✗ FAIL") . "\n\n";

echo "=== Test Admin Fee Calculation ===\n\n";

// Test admin fee calculation
function calculate_admin_fee_test(float $principal, float $pct): float
{
    return round($principal * ($pct / 100), 2);
}

$fee1 = calculate_admin_fee_test(10000000, 2.0);
echo "Test 1 - Rp10,000,000 × 2%:\n";
echo "  Admin Fee: Rp" . number_format($fee1, 0, ',', '.') . "\n";
echo "  Expected: Rp200,000\n";
echo "  Status: " . ($fee1 == 200000 ? "✓ PASS" : "✗ FAIL") . "\n\n";

$fee2 = calculate_admin_fee_test(5000000, 3.5);
echo "Test 2 - Rp5,000,000 × 3.5%:\n";
echo "  Admin Fee: Rp" . number_format($fee2, 0, ',', '.') . "\n";
echo "  Expected: Rp175,000\n";
echo "  Status: " . ($fee2 == 175000 ? "✓ PASS" : "✗ FAIL") . "\n\n";

echo "=== All Tests Complete ===\n";
