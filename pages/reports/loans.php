<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.view');
$title = 'Laporan Pinjaman';

$rows = [];
$res = db()->query('SELECT l.id, l.loan_number, m.member_number, m.full_name, l.amount, l.interest_rate,
                    l.term_months, l.status, l.application_date,
                    COALESCE(SUM(lp.principal_amount),0) AS principal_paid,
                    COALESCE(SUM(lp.interest_amount),0) AS interest_paid
                    FROM loans l JOIN members m ON m.id = l.member_id
                    LEFT JOIN loan_payments lp ON lp.loan_id = l.id
                    WHERE l.status IN ("approved", "active")
                    GROUP BY l.id, l.loan_number, m.member_number, m.full_name, l.amount, l.interest_rate,
                             l.term_months, l.status, l.application_date
                    ORDER BY m.full_name, l.id');
while ($row = $res->fetch_assoc()) {
    // Total bunga kontrak mengikuti formula pinjaman/form.php: pokok x bunga bulanan x tenor.
    $contract_interest = (float) $row['amount'] * ((float) $row['interest_rate'] / 100) * (int) $row['term_months'];
    $row['principal_remaining'] = max(0, (float) $row['amount'] - (float) $row['principal_paid']);
    $row['interest_remaining'] = max(0, $contract_interest - (float) $row['interest_paid']);
    $row['outstanding'] = $row['principal_remaining'] + $row['interest_remaining'];
    $rows[] = $row;
}

$principal_total = array_sum(array_column($rows, 'principal_remaining'));
$interest_total = array_sum(array_column($rows, 'interest_remaining'));

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <div><strong><?= count($rows) ?></strong> pinjaman belum lunas</div>
    <?php if (can('reports.export')): ?>
        <a href="<?= url('pages/reports/loans_export.php') ?>" class="btn btn-success">Export CSV</a>
    <?php endif; ?>
</div>

<div class="report-summary">
    <div class="report-card"><h4>Sisa Pokok</h4><div class="report-value"><?= rupiah($principal_total) ?></div><small>Outstanding principal</small></div>
    <div class="report-card"><h4>Sisa Bunga</h4><div class="report-value"><?= rupiah($interest_total) ?></div><small>Outstanding interest</small></div>
    <div class="report-card"><h4>Total Outstanding</h4><div class="report-value"><?= rupiah($principal_total + $interest_total) ?></div><small>Pokok + bunga</small></div>
</div>

<div class="card">
    <h3>Outstanding Pinjaman per Anggota</h3>
    <table class="table">
        <thead><tr><th>No. Anggota</th><th>Nama</th><th>No. Pinjaman</th><th>Status</th><th>Sisa Pokok</th><th>Sisa Bunga</th><th>Total Outstanding</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="text-center">Tidak ada pinjaman outstanding.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['member_number']) ?></td>
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= e($row['loan_number']) ?></td>
                        <td><span class="badge badge-<?= $row['status'] === 'active' ? 'success' : 'warning' ?>"><?= $row['status'] === 'active' ? 'Aktif' : 'Disetujui' ?></span></td>
                        <td><?= rupiah($row['principal_remaining']) ?></td>
                        <td><?= rupiah($row['interest_remaining']) ?></td>
                        <td><strong><?= rupiah($row['outstanding']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot><tr><th colspan="4">Total</th><th><?= rupiah($principal_total) ?></th><th><?= rupiah($interest_total) ?></th><th><?= rupiah($principal_total + $interest_total) ?></th></tr></tfoot>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
