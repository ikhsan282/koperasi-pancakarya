<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/loan_tools.php';

$simulation = calculate_flat_loan(12000000, 1.5, 12);
assert($simulation['principal_monthly'] === 1000000.0);
assert($simulation['interest_monthly'] === 180000.0);
assert($simulation['monthly_payment'] === 1180000.0);
assert($simulation['total_interest'] === 2160000.0);
assert($simulation['total_payment'] === 14160000.0);

$product = ['min_amount' => '1000000', 'max_amount' => '20000000', 'max_tenor_months' => '24'];
assert(validate_loan_simulation($product, 500000, 12) === ['Jumlah pinjaman minimal Rp 1.000.000.']);
assert(validate_loan_simulation($product, 12000000, 25) === ['Tenor harus antara 1 sampai 24 bulan.']);
assert(validate_loan_simulation($product, 12000000, 12) === []);

try {
    calculate_flat_loan(0, 1.5, 12);
    assert(false, 'Nilai pokok nol harus ditolak.');
} catch (InvalidArgumentException) {
}

fwrite(STDOUT, "loan_tools: OK\n");
