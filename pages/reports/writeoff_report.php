<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.view');

$title = 'Laporan Hapus Buku Kredit Macet';

$period_from = trim($_GET['period_from'] ?? '');
$period_to = trim($_GET['period_to'] ?? '');
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

if ($period_from === '') $period_from = date('Y-m-01');
if ($period_to === '') $period_to = date('Y-m-d');

$sql = 'SELECT w.*, l.loan_number, l.amount AS loan_amount, l.disbursement_date,
               m.member_number, m.full_name, u.full_name AS approver_name
        FROM loan_writeoffs w
        JOIN loans l ON w.loan_id = l.id
        JOIN members m ON l.member_id = m.id
        LEFT JOIN users u ON w.approved_by = u.id
        WHERE w.approved_by IS NOT NULL
        AND w.writeoff_date BETWEEN ? AND ?
        ORDER BY w.writeoff_date DESC, w.id DESC';

$stmt = db()->prepare($sql);
$stmt->bind_param('ss', $period_from, $period_to);
$stmt->execute();
$writeoffs = $stmt->get_result();

$data = [];
$total_writeoff = 0;
$total_loan_amount = 0;
$count_full = 0;
$count_partial = 0;

while ($row = $writeoffs->fetch_assoc()) {
    $data[] = $row;
    $total_writeoff += (float) $row['writeoff_amount'];
    $total_loan_amount += (float) $row['loan_amount'];
    
    $is_full = ($row['writeoff_amount'] >= $row['remaining_balance'] - 0.01);
    if ($is_full) {
        $count_full++;
    } else {
        $count_partial++;
    }
}

if ($export) {
    header_csv_download('laporan_hapus_buku_' . $period_from . '_' . $period_to . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    
    fputcsv($out, ['Laporan Hapus Buku Kredit Macet']);
    fputcsv($out, ['Periode', $period_from . ' s/d ' . $period_to]);
    fputcsv($out, []);
    
    fputcsv($out, [
        'Tanggal Writeoff',
        'No. Pinjaman',
        'No. Anggota',
        'Nama Anggota',
        'Pokok Pinjaman',
        'Sisa Tagihan',
        'Jumlah Writeoff',
        'Jenis',
        'Disetujui Oleh',
        'Tgl Persetujuan'
    ]);
    
    foreach ($data as $row) {
        $is_full = ($row['writeoff_amount'] >= $row['remaining_balance'] - 0.01);
        fputcsv($out, [
            date('d/m/Y', strtotime($row['writeoff_date'])),
            $row['loan_number'],
            $row['member_number'],
            $row['full_name'],
            $row['loan_amount'],
            $row['remaining_balance'],
            $row['writeoff_amount'],
            $is_full ? 'Full' : 'Partial',
            $row['approver_name'],
            date('d/m/Y H:i', strtotime($row['approved_at']))
        ]);
    }
    
    fputcsv($out, []);
    fputcsv($out, ['RINGKASAN']);
    fputcsv($out, ['Total Pinjaman yang Dihapus Buku', count($data)]);
    fputcsv($out, ['Full Writeoff', $count_full]);
    fputcsv($out, ['Partial Writeoff', $count_partial]);
    fputcsv($out, ['Total Pokok Pinjaman', $total_loan_amount]);
    fputcsv($out, ['Total Jumlah Writeoff', $total_writeoff]);
    
    fclose($out);
    exit;
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form" style="gap: 0.5rem;">
        <input type="date" name="period_from" value="<?= e($period_from) ?>" required>
        <span style="align-self: center;">s/d</span>
        <input type="date" name="period_to" value="<?= e($period_to) ?>" required>
        <button type="submit" class="btn btn-primary">Tampilkan</button>
        <a href="<?= url('pages/reports/writeoff_report.php') ?>" class="btn btn-text">Reset</a>
    </form>
    <div style="display: flex; gap: 0.5rem;">
        <a href="<?= url('pages/loans/writeoff_list.php') ?>" class="btn btn-secondary">Kelola Writeoff</a>
        <?php if (count($data) > 0): ?>
            <button onclick="window.print()" class="btn btn-secondary">🖨 Cetak</button>
            <a href="<?= url('pages/reports/writeoff_report.php?period_from=' . urlencode($period_from) . '&period_to=' . urlencode($period_to) . '&export=csv') ?>" class="btn btn-success">📥 Export CSV</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3 style="margin-top: 0;">Laporan Hapus Buku Kredit Macet</h3>
    <p style="margin-bottom: 1.5rem; color: #666;">
        Periode: <strong><?= date('d/m/Y', strtotime($period_from)) ?></strong> s/d <strong><?= date('d/m/Y', strtotime($period_to)) ?></strong>
    </p>
    
    <?php if (count($data) === 0): ?>
        <div class="alert info">
            Tidak ada data hapus buku untuk periode ini.
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div style="background: #fff5f5; border: 1px solid #feb2b2; border-radius: 6px; padding: 1rem;">
                <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.25rem;">Total Pinjaman</div>
                <div style="font-size: 1.5rem; font-weight: bold; color: #c53030;"><?= count($data) ?></div>
            </div>
            <div style="background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 1rem;">
                <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.25rem;">Full Writeoff</div>
                <div style="font-size: 1.5rem; font-weight: bold; color: #f59e0b;"><?= $count_full ?></div>
            </div>
            <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 1rem;">
                <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.25rem;">Partial Writeoff</div>
                <div style="font-size: 1.5rem; font-weight: bold; color: #d97706;"><?= $count_partial ?></div>
            </div>
            <div style="background: #f0f9ff; border: 1px solid #93c5fd; border-radius: 6px; padding: 1rem;">
                <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.25rem;">Total Writeoff</div>
                <div style="font-size: 1.25rem; font-weight: bold; color: #1e40af;"><?= rupiah($total_writeoff) ?></div>
            </div>
        </div>
        
        <table class="table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>No. Pinjaman</th>
                    <th>Anggota</th>
                    <th>Pokok Pinjaman</th>
                    <th>Sisa Tagihan</th>
                    <th>Jumlah Writeoff</th>
                    <th>Jenis</th>
                    <th>Disetujui Oleh</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): ?>
                    <?php $is_full = ($row['writeoff_amount'] >= $row['remaining_balance'] - 0.01); ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($row['writeoff_date'])) ?></td>
                        <td>
                            <a href="<?= url('pages/loans/detail.php?id=' . $row['loan_id']) ?>">
                                <?= e($row['loan_number']) ?>
                            </a>
                        </td>
                        <td>
                            <?= e($row['member_number']) ?><br>
                            <small><?= e($row['full_name']) ?></small>
                        </td>
                        <td><?= rupiah($row['loan_amount']) ?></td>
                        <td><?= rupiah($row['remaining_balance']) ?></td>
                        <td><strong style="color: #c53030;"><?= rupiah($row['writeoff_amount']) ?></strong></td>
                        <td>
                            <span class="badge badge-<?= $is_full ? 'danger' : 'warning' ?>">
                                <?= $is_full ? 'Full' : 'Partial' ?>
                            </span>
                        </td>
                        <td>
                            <?= e($row['approver_name']) ?><br>
                            <small><?= date('d/m/Y', strtotime($row['approved_at'])) ?></small>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot style="font-weight: bold; background: #f7fafc;">
                <tr>
                    <td colspan="3">TOTAL</td>
                    <td><?= rupiah($total_loan_amount) ?></td>
                    <td colspan="1"></td>
                    <td style="color: #c53030;"><?= rupiah($total_writeoff) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</div>

<style media="print">
    .page-actions, .btn, nav, header, footer { display: none !important; }
    body { background: white; }
    .card { box-shadow: none; border: 1px solid #ddd; }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; page-break-after: auto; }
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
