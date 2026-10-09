<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/loan_tools.php';

// Edge case: zero principal
try {
    calculate_flat_loan(0, 1.5, 12);
    assert(false, 'Zero principal should throw InvalidArgumentException');
} catch (InvalidArgumentException) {
    // Expected
}

// Edge case: negative principal
try {
    calculate_flat_loan(-1000000, 1.5, 12);
    assert(false, 'Negative principal should throw InvalidArgumentException');
} catch (InvalidArgumentException) {
    // Expected
}

// Edge case: negative interest rate
try {
    calculate_flat_loan(12000000, -1.5, 12);
    assert(false, 'Negative interest rate should throw InvalidArgumentException');
} catch (InvalidArgumentException) {
    // Expected
}

// Edge case: zero interest rate (valid - interest-free loan)
$result = calculate_flat_loan(12000000, 0, 12);
assert($result['interest_monthly'] === 0.0);
assert($result['total_interest'] === 0.0);
assert($result['monthly_payment'] === 1000000.0);
assert($result['total_payment'] === 12000000.0);

// Edge case: zero months
try {
    calculate_flat_loan(12000000, 1.5, 0);
    assert(false, 'Zero months should throw InvalidArgumentException');
} catch (InvalidArgumentException) {
    // Expected
}

// Edge case: negative months
try {
    calculate_flat_loan(12000000, 1.5, -12);
    assert(false, 'Negative months should throw InvalidArgumentException');
} catch (InvalidArgumentException) {
    // Expected
}

// Edge case: fractional interest rates
$result = calculate_flat_loan(10000000, 1.75, 12);
assert($result['interest_monthly'] === 175000.0);
assert($result['monthly_payment'] === 1008333.33);
assert($result['total_interest'] === 2100000.0);

$result = calculate_flat_loan(10000000, 0.5, 12);
assert($result['interest_monthly'] === 50000.0);
assert($result['monthly_payment'] === 883333.33);

// Edge case: very high interest rate
$result = calculate_flat_loan(1000000, 10.0, 12);
assert($result['interest_monthly'] === 100000.0);
assert($result['monthly_payment'] === 183333.33);
assert($result['total_interest'] === 1200000.0);

// Edge case: single month loan
$result = calculate_flat_loan(12000000, 1.5, 1);
assert($result['principal_monthly'] === 12000000.0);
assert($result['interest_monthly'] === 180000.0);
assert($result['monthly_payment'] === 12180000.0);
assert($result['total_payment'] === 12180000.0);

// Edge case: very long tenor (24 months = max per product constraints)
$result = calculate_flat_loan(20000000, 1.5, 24);
assert($result['principal_monthly'] === 833333.33);
assert($result['interest_monthly'] === 300000.0);
assert($result['monthly_payment'] === 1133333.33);
assert($result['total_interest'] === 7200000.0);
assert($result['total_payment'] === 27200000.0);

// Edge case: small principal amount (min = 1,000,000 per product)
$result = calculate_flat_loan(1000000, 1.5, 12);
assert($result['principal_monthly'] === 83333.33);
assert($result['interest_monthly'] === 15000.0);
assert($result['monthly_payment'] === 98333.33);

// Edge case: max principal amount (20,000,000 per product)
$result = calculate_flat_loan(20000000, 1.5, 12);
assert($result['principal_monthly'] === 1666666.67);
assert($result['interest_monthly'] === 300000.0);
assert($result['monthly_payment'] === 1966666.67);
assert($result['total_interest'] === 3600000.0);
assert($result['total_payment'] === 23600000.0);

// Validation edge cases
$product = ['min_amount' => '1000000', 'max_amount' => '20000000', 'max_tenor_months' => '24'];

// Below minimum
$errors = validate_loan_simulation($product, 999999, 12);
assert(count($errors) === 1);
assert(str_contains($errors[0], 'minimal'));

// Above maximum
$errors = validate_loan_simulation($product, 20000001, 12);
assert(count($errors) === 1);
assert(str_contains($errors[0], 'maksimal'));

// Tenor too long
$errors = validate_loan_simulation($product, 10000000, 25);
assert(count($errors) === 1);
assert(str_contains($errors[0], 'Tenor harus'));

// Multiple validation errors
$errors = validate_loan_simulation($product, 100000, 30);
assert(count($errors) === 2);

// Exact boundaries (should pass)
$errors = validate_loan_simulation($product, 1000000, 1);
assert($errors === []);

$errors = validate_loan_simulation($product, 20000000, 24);
assert($errors === []);

fwrite(STDOUT, "loan_calculator_edge_cases: OK (all " . __LINE__ . " assertions passed)\n");
