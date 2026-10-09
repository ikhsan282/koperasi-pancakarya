<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.view');
$title = 'Laporan Arus Kas';

$months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

// Pemasukan: setoran simpanan + angsuran pinjaman; Pengeluaran: penarikan + pencairan pinjaman
$stmt = db()->prepare("SELECT ym,
    COALESCE(SUM(deposit), 0) AS deposit,
    COALESCE(SUM(withdrawal), 0) AS withdrawal,
    COALESCE(SUM(installment), 0) AS installment,
    COALESCE(SUM(disbursement), 0) AS disbursement
    FROM (
        SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym,
               CASE WHEN transaction_type = 'deposit' THEN amount END AS deposit,
               CASE WHEN transaction_type = 'withdrawal' THEN amount END AS withdrawal,
               NULL AS installment, NULL AS disbursement
        FROM savings_transactions WHERE YEAR(transaction_date) = ?
        UNION ALL
        SELECT DATE_FORMAT(payment_date, '%Y-%m'),
               NULL, NULL, amount, NULL
        FROM loan_payments WHERE YEAR(payment_date) = ?
        UNION ALL
        SELECT DATE_FORMAT(disbursement_date, '%Y-%m'),
               NULL, NULL, NULL, amount
        FROM loans WHERE disbursement_date IS NOT NULL AND YEAR(disbursement_date) = ?
    ) t
    GROUP BY ym ORDER BY ym");
$stmt->bind_param('iii', $year, $year, $year);
$stmt->execute();
$by_month = [];
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $by_month[(int) substr($row['ym'], 5, 2)] = $row;
}

$rows = [];
$cumulative = 0.0;
for ($m = 1; $m <= 12; $m++) {
    $r = $by_month[$m] ?? ['deposit' => 0, 'withdrawal' => 0, 'installment' => 0, 'disbursement' => 0];
    $income = (float) $r['deposit'] + (float) $r['installment'];
    $expense = (float) $r['withdrawal'] + (float) $r['disbursement'];
    $net = $income - $expense;
    $cumulative += $net;
    $rows[] = [
        'month' => $months[$m],
        'deposit' => (float) $r['deposit'],
        'installment' => (float) $r['installment'],
        'income' => $income,
        'withdrawal' => (float) $r['withdrawal'],
        'disbursement' => (float) $r['disbursement'],
        'expense' => $expense,
        'net' => $net,
        'cumulative' => $cumulative,
    ];
}

$totals = [
    'deposit' => array_sum(array_column($rows, 'deposit')),
    'installment' => array_sum(array_column($rows, 'installment')),
    'income' => array_sum(array_column($rows, 'income')),
    'withdrawal' => array_sum(array_column($rows, 'withdrawal')),
    'disbursement' => array_sum(array_column($rows, 'disbursement')),
    'expense' => array_sum(array_column($rows, 'expense')),
];

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form">
        <input type="number" name="year" value="<?= $year ?>" min="2000" max="2100" style="max-width:120px;">
        <button type="submit" class="btn btn-secondary">Filter Tahun</button>
    </form>
    <?php if (can('reports.export')): ?>
        <a href="<?= url('pages/reports/cashflow_export.php?year=' . $year) ?>" class="btn btn-success">Export CSV</a>
    <?php endif; ?>
</div>

<div class="report-summary">
    <div class="report-card"><h4>Total Pemasukan</h4><div class="report-value"><?= rupiah($totals['income']) ?></div><small>Setoran + angsuran</small></div>
    <div class="report-card"><h4>Total Pengeluaran</h4><div class="report-value"><?= rupiah($totals['expense']) ?></div><small>Penarikan + pencairan</small></div>
    <div class="report-card"><h4>Surplus/Defisit</h4><div class="report-value"><?= rupiah($totals['income'] - $totals['expense']) ?></div><small>Pemasukan - pengeluaran</small></div>
</div>

<div class="card">
    <h3>Arus Kas <?= $year ?></h3>
    <table class="table">
        <thead>
            <tr>
                <th>Bulan</th>
                <th>Setoran Simpanan</th>
                <th>Angsuran Diterima</th>
                <th>Total Pemasukan</th>
                <th>Penarikan Simpanan</th>
                <th>Pencairan Pinjaman</th>
                <th>Total Pengeluaran</th>
                <th>Surplus/Defisit</th>
                <th>Saldo Kumulatif</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= $row['month'] ?></td>
                    <td><?= rupiah($row['deposit']) ?></td>
                    <td><?= rupiah($row['installment']) ?></td>
                    <td><strong><?= rupiah($row['income']) ?></strong></td>
                    <td><?= rupiah($row['withdrawal']) ?></td>
                    <td><?= rupiah($row['disbursement']) ?></td>
                    <td><strong><?= rupiah($row['expense']) ?></strong></td>
                    <td style="color: <?= $row['net'] < 0 ? '#dc2626' : '#059669' ?>;"><strong><?= rupiah($row['net']) ?></strong></td>
                    <td><?= rupiah($row['cumulative']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th>Total</th>
                <th><?= rupiah($totals['deposit']) ?></th>
                <th><?= rupiah($totals['installment']) ?></th>
                <th><?= rupiah($totals['income']) ?></th>
                <th><?= rupiah($totals['withdrawal']) ?></th>
                <th><?= rupiah($totals['disbursement']) ?></th>
                <th><?= rupiah($totals['expense']) ?></th>
                <th><?= rupiah($totals['income'] - $totals['expense']) ?></th>
                <th><?= rupiah($cumulative) ?></th>
            </tr>
        </tfoot>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
