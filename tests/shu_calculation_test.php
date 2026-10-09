<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/shu_tools.php';

// Two members, 40% jasa modal pool (40M) split by savings 10M:5M, 60% jasa anggota pool (60M) split by interest 500k:250k
$members = [
    ['member_id' => 1, 'savings_base' => 10000000, 'loan_base' => 500000],
    ['member_id' => 2, 'savings_base' => 5000000, 'loan_base' => 250000],
];
$r = calculate_shu_distribution(100000000, 40, $members);
assert($r[0]['jasa_modal'] === 26666667 && $r[1]['jasa_modal'] === 13333333, 'jasa modal split with largest remainder');
assert($r[0]['jasa_anggota'] === 40000000 && $r[1]['jasa_anggota'] === 20000000, 'jasa anggota split');
assert($r[0]['total_shu'] === 66666667 && $r[1]['total_shu'] === 33333333, 'total per member');
assert(array_sum(array_column($r, 'total_shu')) === 100000000, 'sum equals total SHU exactly');

// Odd total: rounding remainder must still reconcile exactly
$r = calculate_shu_distribution(1000001, 33, [
    ['member_id' => 1, 'savings_base' => 1, 'loan_base' => 1],
    ['member_id' => 2, 'savings_base' => 1, 'loan_base' => 1],
    ['member_id' => 3, 'savings_base' => 1, 'loan_base' => 1],
]);
assert(array_sum(array_column($r, 'total_shu')) === 1000001, 'odd total reconciles');

// 0% jasa modal: whole SHU goes to jasa anggota, modal base may be empty
$r = calculate_shu_distribution(5000, 0, [
    ['member_id' => 1, 'savings_base' => 0, 'loan_base' => 3],
    ['member_id' => 2, 'savings_base' => 0, 'loan_base' => 1],
]);
assert($r[0]['total_shu'] === 3750 && $r[1]['total_shu'] === 1250, 'pct 0 sends everything to jasa anggota');

// Manual adjustments are editable but must keep the total unchanged
$r = apply_shu_adjustments($r, [1 => -250, 2 => 250]);
assert($r[0]['total_shu'] === 3500 && $r[1]['total_shu'] === 1500, 'manual adjustment applied');
// Adjustments for members outside the distribution are ignored, not counted
assert(array_sum(array_column(apply_shu_adjustments($r, [1 => -250, 2 => 250, 99 => 7]), 'total_shu')) === 5000, 'unknown member id ignored');
try {
    apply_shu_adjustments($r, [1 => 1]);
    assert(false, 'non-zero adjustment sum must fail');
} catch (InvalidArgumentException) {
}

// Empty pool with positive amount must be rejected, not silently dropped
foreach ([
    [100, 50, [['member_id' => 1, 'savings_base' => 0, 'loan_base' => 5]]],
    [100, 50, [['member_id' => 1, 'savings_base' => 5, 'loan_base' => 0]]],
    [-1, 50, [['member_id' => 1, 'savings_base' => 5, 'loan_base' => 5]]],
    [100, 101, [['member_id' => 1, 'savings_base' => 5, 'loan_base' => 5]]],
    [100, 50, []],
] as [$total, $pct, $m]) {
    try {
        calculate_shu_distribution($total, $pct, $m);
        assert(false, 'expected InvalidArgumentException');
    } catch (InvalidArgumentException) {
    }
}

fwrite(STDOUT, "shu_calculation: OK\n");
