<?php
declare(strict_types=1);

/**
 * Calculate flat-rate loan breakdown.
 * 
 * @return array{principal_monthly: float, interest_monthly: float, monthly_payment: float, total_interest: float, total_payment: float}
 * @throws InvalidArgumentException
 */
function calculate_flat_loan(float $principal, float $rate_monthly, int $months): array
{
    if ($principal <= 0 || $rate_monthly < 0 || $months <= 0) {
        throw new InvalidArgumentException('Principal, rate, and months must be positive.');
    }

    $principal_monthly = round($principal / $months, 2);
    $interest_monthly = round($principal * ($rate_monthly / 100), 2);
    $monthly_payment = round($principal_monthly + $interest_monthly, 2);
    $total_interest = round($interest_monthly * $months, 2);
    $total_payment = round($principal + $total_interest, 2);

    return [
        'principal_monthly' => $principal_monthly,
        'interest_monthly' => $interest_monthly,
        'monthly_payment' => $monthly_payment,
        'total_interest' => $total_interest,
        'total_payment' => $total_payment,
    ];
}

/**
 * Validate loan simulation inputs against product constraints.
 * 
 * @param array{min_amount: string|float, max_amount: string|float, max_tenor_months: string|int} $product
 * @return string[] Array of validation error messages (empty if valid)
 */
function validate_loan_simulation(array $product, float $amount, int $tenor): array
{
    $errors = [];
    $min = (float) $product['min_amount'];
    $max = (float) $product['max_amount'];
    $max_tenor = (int) $product['max_tenor_months'];

    if ($amount < $min) {
        $errors[] = 'Jumlah pinjaman minimal Rp ' . number_format($min, 0, ',', '.') . '.';
    }
    if ($amount > $max) {
        $errors[] = 'Jumlah pinjaman maksimal Rp ' . number_format($max, 0, ',', '.') . '.';
    }
    if ($tenor <= 0 || $tenor > $max_tenor) {
        $errors[] = 'Tenor harus antara 1 sampai ' . $max_tenor . ' bulan.';
    }

    return $errors;
}
