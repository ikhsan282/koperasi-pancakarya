<?php
declare(strict_types=1);

/**
 * Split SHU into jasa modal (by savings) and jasa anggota (by loan interest paid).
 * Largest-remainder rounding so the members always sum exactly to $total_shu.
 *
 * @param array<int, array{member_id:int, savings_base:int, loan_base:int}> $members
 * @return array<int, array{member_id:int, jasa_modal:int, jasa_anggota:int, total_shu:int}>
 * @throws InvalidArgumentException
 */
function calculate_shu_distribution(int $total_shu, int $pct_modal, array $members): array
{
    if ($total_shu < 0 || $pct_modal < 0 || $pct_modal > 100 || empty($members)) {
        throw new InvalidArgumentException('Parameter SHU tidak valid.');
    }

    $pool_modal = intdiv($total_shu * $pct_modal + 50, 100);
    $pool_anggota = $total_shu - $pool_modal;
    $sum_savings = array_sum(array_column($members, 'savings_base'));
    $sum_loan = array_sum(array_column($members, 'loan_base'));

    if (($pool_modal > 0 && $sum_savings <= 0) || ($pool_anggota > 0 && $sum_loan <= 0)) {
        throw new InvalidArgumentException('Dasar pembagian kosong untuk pool yang bernilai > 0.');
    }

    $modal = allocate_largest_remainder($pool_modal, array_column($members, 'savings_base'));
    $anggota = allocate_largest_remainder($pool_anggota, array_column($members, 'loan_base'));

    $result = [];
    foreach ($members as $i => $m) {
        $result[] = [
            'member_id' => (int) $m['member_id'],
            'jasa_modal' => $modal[$i],
            'jasa_anggota' => $anggota[$i],
            'total_shu' => $modal[$i] + $anggota[$i],
        ];
    }
    return $result;
}

/**
 * Apply manual adjustments (keyed by member_id). Adjustments must net to zero so the
 * distribution still sums to the total SHU, and no member may end below zero.
 *
 * @param array<int, array{member_id:int, jasa_modal:int, jasa_anggota:int, total_shu:int}> $rows
 * @param array<int, int> $adjustments member_id => int
 * @return array<int, array{member_id:int, jasa_modal:int, jasa_anggota:int, adjustment:int, total_shu:int}>
 * @throws InvalidArgumentException
 */
function apply_shu_adjustments(array $rows, array $adjustments): array
{
    $known = array_column($rows, 'member_id');
    $adjustments = array_intersect_key($adjustments, array_flip($known));
    if (array_sum($adjustments) !== 0) {
        throw new InvalidArgumentException('Total penyesuaian harus nol agar jumlah SHU tetap.');
    }
    foreach ($rows as $i => $r) {
        $adj = (int) ($adjustments[$r['member_id']] ?? 0);
        $rows[$i]['adjustment'] = $adj;
        $rows[$i]['total_shu'] = $r['total_shu'] + $adj;
        if ($rows[$i]['total_shu'] < 0) {
            throw new InvalidArgumentException("Penyesuaian membuat SHU anggota ID {$r['member_id']} negatif.");
        }
    }
    return $rows;
}

/** @param int[] $bases */
function allocate_largest_remainder(int $pool, array $bases): array
{
    $sum = array_sum($bases);
    $shares = [];
    $remainders = [];
    foreach ($bases as $i => $base) {
        $exact = $sum > 0 ? $pool * ((float) $base / (float) $sum) : 0.0;
        $shares[$i] = (int) floor($exact);
        $remainders[$i] = $exact - $shares[$i];
    }
    $deficit = $pool - array_sum($shares);
    arsort($remainders);
    foreach (array_slice(array_keys($remainders), 0, max(0, $deficit)) as $i) {
        $shares[$i]++;
    }
    return $shares;
}

/**
 * Member bases for one fiscal year: savings balance (active accounts) and loan interest paid in that year.
 * ponytail: savings use the current balance, not a year-end snapshot (no snapshot table exists).
 * Upgrade path: snapshot balances at period close.
 *
 * @return array<int, array{member_id:int, member_number:string, full_name:string, savings_base:int, loan_base:int}>
 */
function shu_member_bases(mysqli $db, int $fiscal_year): array
{
    $stmt = $db->prepare("SELECT m.id AS member_id, m.member_number, m.full_name,
            ROUND(COALESCE((SELECT SUM(sa.balance) FROM savings_accounts sa
                WHERE sa.member_id = m.id AND sa.status = 'active'), 0)) AS savings_base,
            ROUND(COALESCE((SELECT SUM(lp.interest_amount) FROM loan_payments lp
                JOIN loans l ON l.id = lp.loan_id
                WHERE l.member_id = m.id AND lp.status = 'paid' AND YEAR(lp.payment_date) = ?), 0)) AS loan_base
        FROM members m
        WHERE m.status = 'active'
        ORDER BY m.full_name");
    $stmt->bind_param('i', $fiscal_year);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['member_id'] = (int) $r['member_id'];
        $r['savings_base'] = (int) $r['savings_base'];
        $r['loan_base'] = (int) $r['loan_base'];
    }
    return $rows;
}
