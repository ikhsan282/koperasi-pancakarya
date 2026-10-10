<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.kap');
$title = 'Laporan KAP (Kualitas Aktiva Produktif)';

// Get threshold settings from database
$thresholds = [
    'kl' => 90,
    'diragukan' => 120,
    'macet' => 180
];
$settings_res = db()->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'kap_threshold_%'");
while ($row = $settings_res->fetch_assoc()) {
    if ($row['setting_key'] === 'kap_threshold_kl') $thresholds['kl'] = (int) $row['setting_value'];
    if ($row['setting_key'] === 'kap_threshold_diragukan') $thresholds['diragukan'] = (int) $row['setting_value'];
    if ($row['setting_key'] === 'kap_threshold_macet') $thresholds['macet'] = (int) $row['setting_value'];
}

// Filter by kolektibilitas status
$filter_status = $_GET['status'] ?? 'all';

// Query loans with days overdue calculation
$query = "SELECT 
    l.id,
    l.loan_number,
    m.member_number,
    m.full_name,
    l.amount,
    l.interest_rate,
    l.term_months,
    l.status AS loan_status,
    l.application_date,
    COALESCE(SUM(lp.principal_amount), 0) AS principal_paid,
    COALESCE(SUM(lp.interest_amount), 0) AS interest_paid,
    (SELECT MAX(DATEDIFF(CURDATE(), lp2.due_date))
     FROM loan_payments lp2
     WHERE lp2.loan_id = l.id 
       AND lp2.status IN ('pending', 'overdue')
       AND lp2.due_date < CURDATE()
    ) AS days_overdue
FROM loans l
JOIN members m ON m.id = l.member_id
LEFT JOIN loan_payments lp ON lp.loan_id = l.id AND lp.status = 'paid'
WHERE l.status IN ('approved', 'active')
GROUP BY l.id, l.loan_number, m.member_number, m.full_name, l.amount, 
         l.interest_rate, l.term_months, l.status, l.application_date
ORDER BY m.full_name, l.id";

$result = db()->query($query);
$rows = [];

while ($row = $result->fetch_assoc()) {
    // Calculate outstanding balance
    $contract_interest = (float) $row['amount'] * ((float) $row['interest_rate'] / 100) * (int) $row['term_months'];
    $row['principal_remaining'] = max(0, (float) $row['amount'] - (float) $row['principal_paid']);
    $row['interest_remaining'] = max(0, $contract_interest - (float) $row['interest_paid']);
    $row['outstanding'] = $row['principal_remaining'] + $row['interest_remaining'];
    
    // Determine kolektibilitas status based on days overdue
    $days = (int) ($row['days_overdue'] ?? 0);
    if ($days < $thresholds['kl']) {
        $row['kolektibilitas'] = 'LANCAR';
        $row['kol_class'] = 'success';
    } elseif ($days < $thresholds['diragukan']) {
        $row['kolektibilitas'] = 'KURANG LANCAR';
        $row['kol_class'] = 'warning';
    } elseif ($days < $thresholds['macet']) {
        $row['kolektibilitas'] = 'DIRAGUKAN';
        $row['kol_class'] = 'orange';
    } else {
        $row['kolektibilitas'] = 'MACET';
        $row['kol_class'] = 'danger';
    }
    
    // Apply filter
    if ($filter_status === 'all' || strtolower($row['kolektibilitas']) === strtolower($filter_status)) {
        $rows[] = $row;
    }
}

// Calculate summary statistics
$summary = [
    'LANCAR' => ['count' => 0, 'outstanding' => 0],
    'KURANG LANCAR' => ['count' => 0, 'outstanding' => 0],
    'DIRAGUKAN' => ['count' => 0, 'outstanding' => 0],
    'MACET' => ['count' => 0, 'outstanding' => 0],
];

foreach ($rows as $row) {
    $summary[$row['kolektibilitas']]['count']++;
    $summary[$row['kolektibilitas']]['outstanding'] += $row['outstanding'];
}

$total_outstanding = array_sum(array_column($summary, 'outstanding'));
$npl_amount = $summary['KURANG LANCAR']['outstanding'] + $summary['DIRAGUKAN']['outstanding'] + $summary['MACET']['outstanding'];
$npl_ratio = $total_outstanding > 0 ? ($npl_amount / $total_outstanding) * 100 : 0;

// Export to PDF
if (($_GET['format'] ?? '') === 'pdf') {
    require_permission('reports.export');
    require_once __DIR__ . '/../../includes/pdf.php';
    $pdf = new SimplePDF('Laporan KAP (Kualitas Aktiva Produktif)', APP_NAME, true);
    $pdf->heading('Laporan KAP (Kualitas Aktiva Produktif)', 1);
    $pdf->text('Per ' . date('d/m/Y'), 10);
    $pdf->ln(2);
    $pdf->text("NPL Ratio: " . number_format($npl_ratio, 2) . '%', 10);
    $pdf->ln(4);
    
    $tbl = [];
    foreach ($rows as $row) {
        $tbl[] = [
            $row['member_number'],
            $row['full_name'],
            $row['loan_number'],
            $row['days_overdue'] ?? 0,
            $row['kolektibilitas'],
            rupiah($row['outstanding']),
        ];
    }
    
    $pdf->table(
        ['No. Anggota', 'Nama', 'No. Pinjaman', 'Hari Telat', 'Kolektibilitas', 'Outstanding'],
        $tbl
    );
    
    $pdf->ln(4);
    $pdf->heading('Ringkasan per Kategori', 2);
    $summary_tbl = [];
    foreach ($summary as $status => $data) {
        $summary_tbl[] = [
            $status,
            $data['count'],
            rupiah($data['outstanding'])
        ];
    }
    $pdf->table(
        ['Kategori', 'Jumlah', 'Total Outstanding'],
        $summary_tbl
    );
    
    $pdf->download('Laporan_KAP_' . date('d_m_Y') . '.pdf');
}

// Export to XLSX
if (($_GET['format'] ?? '') === 'xlsx') {
    require_permission('reports.export');
    require_once __DIR__ . '/../../includes/xlsx.php';
    
    $headers = ['No. Anggota', 'Nama', 'No. Pinjaman', 'Tgl Pengajuan', 'Hari Telat', 'Kolektibilitas', 'Outstanding'];
    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            $row['member_number'],
            $row['full_name'],
            $row['loan_number'],
            $row['application_date'] ? date('d/m/Y', strtotime($row['application_date'])) : '-',
            (int) ($row['days_overdue'] ?? 0),
            $row['kolektibilitas'],
            (float) $row['outstanding'],
        ];
    }
    
    // Add summary
    $data[] = ['', '', '', '', '', '', ''];
    $data[] = ['RINGKASAN', '', '', '', '', '', ''];
    foreach ($summary as $status => $stats) {
        $data[] = [
            $status,
            '',
            '',
            '',
            '',
            "Jumlah: {$stats['count']}",
            (float) $stats['outstanding'],
        ];
    }
    $data[] = ['', '', '', '', '', 'NPL RATIO', number_format($npl_ratio, 2) . '%'];
    
    xlsx_export('Laporan_KAP_' . date('Y-m-d') . '.xlsx', $headers, $data, 'Laporan KAP');
}

// Export to CSV
if (($_GET['format'] ?? '') === 'csv') {
    require_permission('reports.export');
    header_csv_download('Laporan_KAP_' . date('Y-m-d') . '.csv');
    
    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    
    fputcsv($fp, ['No. Anggota', 'Nama', 'No. Pinjaman', 'Tgl Pengajuan', 'Hari Telat', 'Kolektibilitas', 'Outstanding']);
    foreach ($rows as $row) {
        fputcsv($fp, [
            $row['member_number'],
            $row['full_name'],
            $row['loan_number'],
            $row['application_date'] ? date('d/m/Y', strtotime($row['application_date'])) : '-',
            $row['days_overdue'] ?? 0,
            $row['kolektibilitas'],
            $row['outstanding'],
        ]);
    }
    
    // Add summary
    fputcsv($fp, []);
    fputcsv($fp, ['RINGKASAN']);
    foreach ($summary as $status => $stats) {
        fputcsv($fp, [$status, '', '', '', '', "Jumlah: {$stats['count']}", $stats['outstanding']]);
    }
    fputcsv($fp, ['', '', '', '', '', 'NPL RATIO', number_format($npl_ratio, 2) . '%']);
    
    fclose($fp);
    exit;
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form">
        <select name="status">
            <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>Semua Kategori</option>
            <option value="lancar" <?= $filter_status === 'lancar' ? 'selected' : '' ?>>Lancar</option>
            <option value="kurang lancar" <?= $filter_status === 'kurang lancar' ? 'selected' : '' ?>>Kurang Lancar</option>
            <option value="diragukan" <?= $filter_status === 'diragukan' ? 'selected' : '' ?>>Diragukan</option>
            <option value="macet" <?= $filter_status === 'macet' ? 'selected' : '' ?>>Macet</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
    </form>
    <?php if (can('reports.export')): ?>
        <a href="<?= url('pages/reports/kap_report.php?status=' . urlencode($filter_status) . '&format=csv') ?>" class="btn btn-success">Export CSV</a>
        <a href="<?= url('pages/reports/kap_report.php?status=' . urlencode($filter_status) . '&format=xlsx') ?>" class="btn btn-success">Export Excel</a>
        <a href="<?= url('pages/reports/kap_report.php?status=' . urlencode($filter_status) . '&format=pdf') ?>" class="btn btn-success">Export PDF</a>
    <?php endif; ?>
</div>

<div class="report-summary">
    <div class="report-card">
        <h4>LANCAR</h4>
        <div class="report-value"><?= $summary['LANCAR']['count'] ?> pinjaman</div>
        <small><?= rupiah($summary['LANCAR']['outstanding']) ?></small>
    </div>
    <div class="report-card" style="border-left: 4px solid #f39c12;">
        <h4>KURANG LANCAR</h4>
        <div class="report-value"><?= $summary['KURANG LANCAR']['count'] ?> pinjaman</div>
        <small><?= rupiah($summary['KURANG LANCAR']['outstanding']) ?></small>
    </div>
    <div class="report-card" style="border-left: 4px solid #e67e22;">
        <h4>DIRAGUKAN</h4>
        <div class="report-value"><?= $summary['DIRAGUKAN']['count'] ?> pinjaman</div>
        <small><?= rupiah($summary['DIRAGUKAN']['outstanding']) ?></small>
    </div>
    <div class="report-card" style="border-left: 4px solid #e74c3c;">
        <h4>MACET</h4>
        <div class="report-value"><?= $summary['MACET']['count'] ?> pinjaman</div>
        <small><?= rupiah($summary['MACET']['outstanding']) ?></small>
    </div>
</div>

<div class="card" style="margin-top: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h3>NPL Ratio (Non Performing Loan)</h3>
        <div style="text-align: right;">
            <div style="font-size: 32px; font-weight: bold; color: <?= $npl_ratio > 5 ? '#e74c3c' : ($npl_ratio > 2 ? '#f39c12' : '#27ae60') ?>;">
                <?= number_format($npl_ratio, 2) ?>%
            </div>
            <small>NPL = (KL + Diragukan + Macet) / Total Outstanding</small>
        </div>
    </div>
</div>

<div class="card">
    <h3>Rincian Kolektibilitas per Pinjaman</h3>
    <p><strong><?= count($rows) ?></strong> pinjaman ditampilkan</p>
    <table class="table">
        <thead>
            <tr>
                <th>No. Anggota</th>
                <th>Nama</th>
                <th>No. Pinjaman</th>
                <th>Hari Telat</th>
                <th>Kolektibilitas</th>
                <th>Outstanding</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" class="text-center">Tidak ada data pinjaman.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['member_number']) ?></td>
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= e($row['loan_number']) ?></td>
                        <td><?= $row['days_overdue'] ?? 0 ?> hari</td>
                        <td><span class="badge badge-<?= $row['kol_class'] ?>"><?= e($row['kolektibilitas']) ?></span></td>
                        <td><strong><?= rupiah($row['outstanding']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>Keterangan Klasifikasi</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Kategori</th>
                <th>Kriteria (Hari Keterlambatan)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><span class="badge badge-success">LANCAR</span></td>
                <td>0 - <?= $thresholds['kl'] - 1 ?> hari</td>
            </tr>
            <tr>
                <td><span class="badge badge-warning">KURANG LANCAR</span></td>
                <td><?= $thresholds['kl'] ?> - <?= $thresholds['diragukan'] - 1 ?> hari</td>
            </tr>
            <tr>
                <td><span class="badge badge-orange">DIRAGUKAN</span></td>
                <td><?= $thresholds['diragukan'] ?> - <?= $thresholds['macet'] - 1 ?> hari</td>
            </tr>
            <tr>
                <td><span class="badge badge-danger">MACET</span></td>
                <td>≥ <?= $thresholds['macet'] ?> hari</td>
            </tr>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
