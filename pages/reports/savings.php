<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.view');

$months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) $month = (int) date('n');
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

$title = 'Laporan Simpanan - ' . $months[$month] . ' ' . $year;

// Setoran & penarikan per anggota dalam periode
$stmt = db()->prepare("SELECT m.id, m.member_number, m.full_name,
    COALESCE(SUM(CASE WHEN st.transaction_type = 'deposit' THEN st.amount END), 0) AS deposit_total,
    COALESCE(SUM(CASE WHEN st.transaction_type = 'withdrawal' THEN st.amount END), 0) AS withdrawal_total,
    COALESCE(SUM(CASE WHEN st.transaction_type = 'deposit' THEN st.amount ELSE -st.amount END), 0) AS net_total,
    (SELECT COALESCE(SUM(sa.balance),0) FROM savings_accounts sa WHERE sa.member_id = m.id AND sa.status = 'active') AS current_balance
    FROM members m
    LEFT JOIN savings_accounts sa ON sa.member_id = m.id
    LEFT JOIN savings_transactions st ON st.savings_account_id = sa.id
        AND MONTH(st.transaction_date) = ? AND YEAR(st.transaction_date) = ?
    GROUP BY m.id, m.member_number, m.full_name
    HAVING deposit_total > 0 OR withdrawal_total > 0 OR current_balance > 0
    ORDER BY m.full_name");
$stmt->bind_param('ii', $month, $year);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totals = [
    'deposit' => array_sum(array_column($rows, 'deposit_total')),
    'withdrawal' => array_sum(array_column($rows, 'withdrawal_total')),
    'net' => array_sum(array_column($rows, 'net_total')),
    'balance' => array_sum(array_column($rows, 'current_balance')),
];

if (($_GET['format'] ?? '') === 'pdf') {
    require_permission('reports.export');
    require_once __DIR__ . '/../../includes/pdf.php';
    $pdf = new SimplePDF("Laporan Simpanan {$months[$month]} {$year}", APP_NAME, true);
    $pdf->heading("Laporan Simpanan {$months[$month]} {$year}", 1);
    $pdf->ln(4);
    $tbl = [];
    foreach ($rows as $row) {
        $tbl[] = [
            $row['member_number'],
            $row['full_name'],
            rupiah($row['deposit_total']),
            rupiah($row['withdrawal_total']),
            rupiah($row['net_total']),
            rupiah($row['current_balance']),
        ];
    }
    $tbl[] = ['Total', '', rupiah($totals['deposit']), rupiah($totals['withdrawal']), rupiah($totals['net']), rupiah($totals['balance'])];
    $pdf->table(
        ['No. Anggota', 'Nama', 'Setoran', 'Penarikan', 'Setoran Bersih', 'Saldo Saat Ini'],
        $tbl
    );
    $pdf->download("Laporan Simpanan {$months[$month]} {$year}.pdf");
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form">
        <select name="month">
            <?php foreach ($months as $i => $name): ?>
                <option value="<?= $i ?>" <?= $i === $month ? 'selected' : '' ?>><?= $name ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="year" value="<?= $year ?>" min="2000" max="2100" style="max-width:120px;">
        <button type="submit" class="btn btn-secondary">Filter</button>
    </form>
    <?php if (can('reports.export')): ?>
        <a href="<?= url('pages/reports/savings_export.php?month=' . $month . '&year=' . $year) ?>" class="btn btn-success">Export CSV</a>
        <a href="<?= url('pages/reports/savings.php?month=' . $month . '&year=' . $year . '&format=pdf') ?>" class="btn btn-success">Export PDF</a>
    <?php endif; ?>
</div>

<div class="report-summary">
    <div class="report-card">
        <h4>Total Setoran</h4>
        <div class="report-value"><?= rupiah($totals['deposit']) ?></div>
        <small><?= $months[$month] . ' ' . $year ?></small>
    </div>
    <div class="report-card">
        <h4>Total Penarikan</h4>
        <div class="report-value"><?= rupiah($totals['withdrawal']) ?></div>
        <small><?= $months[$month] . ' ' . $year ?></small>
    </div>
    <div class="report-card">
        <h4>Setoran Bersih</h4>
        <div class="report-value"><?= rupiah($totals['net']) ?></div>
        <small><?= $months[$month] . ' ' . $year ?></small>
    </div>
    <div class="report-card">
        <h4>Total Saldo Simpanan</h4>
        <div class="report-value"><?= rupiah($totals['balance']) ?></div>
        <small>Semua rekening aktif</small>
    </div>
</div>

<div class="card">
    <h3>Rincian Simpanan per Anggota</h3>
    <table class="table">
        <thead>
            <tr>
                <th>No. Anggota</th>
                <th>Nama</th>
                <th>Setoran</th>
                <th>Penarikan</th>
                <th>Setoran Bersih</th>
                <th>Saldo Saat Ini</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" class="text-center">Tidak ada data.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['member_number']) ?></td>
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= rupiah($row['deposit_total']) ?></td>
                        <td><?= rupiah($row['withdrawal_total']) ?></td>
                        <td><strong><?= rupiah($row['net_total']) ?></strong></td>
                        <td><?= rupiah($row['current_balance']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2">Total</th>
                <th><?= rupiah($totals['deposit']) ?></th>
                <th><?= rupiah($totals['withdrawal']) ?></th>
                <th><?= rupiah($totals['net']) ?></th>
                <th><?= rupiah($totals['balance']) ?></th>
            </tr>
        </tfoot>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
