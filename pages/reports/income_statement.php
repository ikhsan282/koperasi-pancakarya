<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.view');
$title = 'Laporan Laba Rugi';

// Period filter
$period_type = $_GET['period_type'] ?? 'yearly';
$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
$quarter = (int) ($_GET['quarter'] ?? ceil(date('n') / 3));
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if ($year < 2000 || $year > 2100) $year = (int) date('Y');
if ($month < 1 || $month > 12) $month = (int) date('n');
if ($quarter < 1 || $quarter > 4) $quarter = 1;

// Calculate date range based on period type
switch ($period_type) {
    case 'monthly':
        $period_start = date('Y-m-01', strtotime("$year-$month-01"));
        $period_end = date('Y-m-t', strtotime("$year-$month-01"));
        $period_label = date('F Y', strtotime($period_start));
        $prev_start = date('Y-m-01', strtotime($period_start . ' -1 month'));
        $prev_end = date('Y-m-t', strtotime($period_start . ' -1 month'));
        break;
    case 'quarterly':
        $q_start_month = ($quarter - 1) * 3 + 1;
        $period_start = date('Y-m-01', strtotime("$year-$q_start_month-01"));
        $period_end = date('Y-m-t', strtotime("$year-$q_start_month-01 +2 months"));
        $period_label = "Q$quarter $year";
        $prev_start = date('Y-m-01', strtotime($period_start . ' -3 months'));
        $prev_end = date('Y-m-t', strtotime($period_end . ' -3 months'));
        break;
    case 'custom':
        if ($start_date && $end_date) {
            $period_start = $start_date;
            $period_end = $end_date;
            $period_label = date('d M Y', strtotime($start_date)) . ' - ' . date('d M Y', strtotime($end_date));
            $days = (strtotime($end_date) - strtotime($start_date)) / 86400;
            $prev_start = date('Y-m-d', strtotime($start_date . " -$days days"));
            $prev_end = date('Y-m-d', strtotime($start_date . ' -1 day'));
        } else {
            $period_start = date('Y-01-01', strtotime("$year-01-01"));
            $period_end = date('Y-12-31', strtotime("$year-12-31"));
            $period_label = "Tahun $year";
            $prev_start = date('Y-01-01', strtotime("$year-01-01 -1 year"));
            $prev_end = date('Y-12-31', strtotime("$year-12-31 -1 year"));
        }
        break;
    case 'yearly':
    default:
        $period_start = date('Y-01-01', strtotime("$year-01-01"));
        $period_end = date('Y-12-31', strtotime("$year-12-31"));
        $period_label = "Tahun $year";
        $prev_start = date('Y-01-01', strtotime("$year-01-01 -1 year"));
        $prev_end = date('Y-12-31', strtotime("$year-12-31 -1 year"));
}

// PENDAPATAN (Revenue)
// 1. Pendapatan Bunga Pinjaman
$stmt = db()->prepare("SELECT COALESCE(SUM(interest_amount), 0) AS total 
    FROM loan_payments 
    WHERE status = 'paid' AND payment_date >= ? AND payment_date <= ?");
$stmt->bind_param('ss', $period_start, $period_end);
$stmt->execute();
$interest_income = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt->bind_param('ss', $prev_start, $prev_end);
$stmt->execute();
$prev_interest_income = (float) $stmt->get_result()->fetch_assoc()['total'];

// 2. Pendapatan Biaya Admin
$stmt = db()->prepare("SELECT COALESCE(SUM(admin_fee_amount), 0) AS total 
    FROM loans 
    WHERE status IN ('approved', 'active', 'completed') 
    AND approval_date >= ? AND approval_date <= ?");
$stmt->bind_param('ss', $period_start, $period_end);
$stmt->execute();
$admin_fee_income = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt->bind_param('ss', $prev_start, $prev_end);
$stmt->execute();
$prev_admin_fee_income = (float) $stmt->get_result()->fetch_assoc()['total'];

// 3. Pendapatan Denda
$stmt = db()->prepare("SELECT COALESCE(SUM(penalty_amount), 0) AS total 
    FROM loan_payments 
    WHERE status = 'paid' AND payment_date >= ? AND payment_date <= ?");
$stmt->bind_param('ss', $period_start, $period_end);
$stmt->execute();
$penalty_income = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt->bind_param('ss', $prev_start, $prev_end);
$stmt->execute();
$prev_penalty_income = (float) $stmt->get_result()->fetch_assoc()['total'];

$total_revenue = $interest_income + $admin_fee_income + $penalty_income;
$prev_total_revenue = $prev_interest_income + $prev_admin_fee_income + $prev_penalty_income;

// BEBAN (Expenses)
// 1. Beban Bunga Simpanan
$stmt = db()->prepare("SELECT COALESCE(SUM(interest_amount), 0) AS total 
    FROM savings_interest_history 
    WHERE posted_date >= ? AND posted_date <= ?");
$stmt->bind_param('ss', $period_start, $period_end);
$stmt->execute();
$interest_expense = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt->bind_param('ss', $prev_start, $prev_end);
$stmt->execute();
$prev_interest_expense = (float) $stmt->get_result()->fetch_assoc()['total'];

// 2. Beban Operasional
$stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) AS total 
    FROM cash_bank_transactions 
    WHERE reference_type = 'expense' AND transaction_date >= ? AND transaction_date <= ?");
$stmt->bind_param('ss', $period_start, $period_end);
$stmt->execute();
$operational_expense = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt->bind_param('ss', $prev_start, $prev_end);
$stmt->execute();
$prev_operational_expense = (float) $stmt->get_result()->fetch_assoc()['total'];

$total_expense = $interest_expense + $operational_expense;
$prev_total_expense = $prev_interest_expense + $prev_operational_expense;

// LABA BERSIH (Net Income)
$net_income = $total_revenue - $total_expense;
$prev_net_income = $prev_total_revenue - $prev_total_expense;

// Calculate percentage changes
function calc_change($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return round((($current - $previous) / abs($previous)) * 100, 2);
}

$changes = [
    'interest_income' => calc_change($interest_income, $prev_interest_income),
    'admin_fee_income' => calc_change($admin_fee_income, $prev_admin_fee_income),
    'penalty_income' => calc_change($penalty_income, $prev_penalty_income),
    'total_revenue' => calc_change($total_revenue, $prev_total_revenue),
    'interest_expense' => calc_change($interest_expense, $prev_interest_expense),
    'operational_expense' => calc_change($operational_expense, $prev_operational_expense),
    'total_expense' => calc_change($total_expense, $prev_total_expense),
    'net_income' => calc_change($net_income, $prev_net_income),
];

// CSV Export
if (($_GET['format'] ?? '') === 'csv') {
    require_permission('reports.export');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Laporan_Laba_Rugi_' . str_replace(' ', '_', $period_label) . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['LAPORAN LABA RUGI']);
    fputcsv($out, [APP_NAME]);
    fputcsv($out, ['Periode: ' . $period_label]);
    fputcsv($out, []);
    fputcsv($out, ['Keterangan', 'Jumlah (Rp)', 'Perubahan (%)']);
    fputcsv($out, []);
    fputcsv($out, ['PENDAPATAN']);
    fputcsv($out, ['Pendapatan Bunga Pinjaman', $interest_income, $changes['interest_income']]);
    fputcsv($out, ['Pendapatan Biaya Admin', $admin_fee_income, $changes['admin_fee_income']]);
    fputcsv($out, ['Pendapatan Denda', $penalty_income, $changes['penalty_income']]);
    fputcsv($out, ['TOTAL PENDAPATAN', $total_revenue, $changes['total_revenue']]);
    fputcsv($out, []);
    fputcsv($out, ['BEBAN']);
    fputcsv($out, ['Beban Bunga Simpanan', $interest_expense, $changes['interest_expense']]);
    fputcsv($out, ['Beban Operasional', $operational_expense, $changes['operational_expense']]);
    fputcsv($out, ['TOTAL BEBAN', $total_expense, $changes['total_expense']]);
    fputcsv($out, []);
    fputcsv($out, ['LABA BERSIH', $net_income, $changes['net_income']]);
    fclose($out);
    exit;
}

// PDF Export
if (($_GET['format'] ?? '') === 'pdf') {
    require_permission('reports.export');
    require_once __DIR__ . '/../../includes/pdf.php';
    $pdf = new SimplePDF('Laporan Laba Rugi', APP_NAME, false);
    $pdf->heading('LAPORAN LABA RUGI', 1);
    $pdf->text('Periode: ' . $period_label, 11.0, false);
    $pdf->ln(8);
    
    $pdf->heading('PENDAPATAN', 2);
    $pdf->table(
        ['Keterangan', 'Jumlah (Rp)', 'Perubahan (%)'],
        [
            ['Pendapatan Bunga Pinjaman', rupiah($interest_income), $changes['interest_income'] . '%'],
            ['Pendapatan Biaya Admin', rupiah($admin_fee_income), $changes['admin_fee_income'] . '%'],
            ['Pendapatan Denda', rupiah($penalty_income), $changes['penalty_income'] . '%'],
            ['TOTAL PENDAPATAN', rupiah($total_revenue), $changes['total_revenue'] . '%'],
        ]
    );
    
    $pdf->heading('BEBAN', 2);
    $pdf->table(
        ['Keterangan', 'Jumlah (Rp)', 'Perubahan (%)'],
        [
            ['Beban Bunga Simpanan', rupiah($interest_expense), $changes['interest_expense'] . '%'],
            ['Beban Operasional', rupiah($operational_expense), $changes['operational_expense'] . '%'],
            ['TOTAL BEBAN', rupiah($total_expense), $changes['total_expense'] . '%'],
        ]
    );
    
    $pdf->heading('LABA BERSIH', 2);
    $pdf->table(
        ['Keterangan', 'Jumlah (Rp)', 'Perubahan (%)'],
        [
            ['LABA BERSIH', rupiah($net_income), $changes['net_income'] . '%'],
        ]
    );
    
    $pdf->download("Laporan_Laba_Rugi_{$period_label}.pdf");
}

require __DIR__ . '/../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .page-actions { display: none !important; }
}
.change-positive { color: #059669; font-weight: 600; }
.change-negative { color: #dc2626; font-weight: 600; }
.change-neutral { color: #6b7280; }
</style>

<div class="page-actions no-print">
    <form method="get" class="search-form" style="gap: 0.5rem; flex-wrap: wrap;">
        <select name="period_type" id="period_type" style="max-width:150px;" onchange="togglePeriodInputs()">
            <option value="yearly" <?= $period_type === 'yearly' ? 'selected' : '' ?>>Tahunan</option>
            <option value="quarterly" <?= $period_type === 'quarterly' ? 'selected' : '' ?>>Triwulanan</option>
            <option value="monthly" <?= $period_type === 'monthly' ? 'selected' : '' ?>>Bulanan</option>
            <option value="custom" <?= $period_type === 'custom' ? 'selected' : '' ?>>Custom</option>
        </select>
        
        <input type="number" name="year" id="year_input" value="<?= $year ?>" min="2000" max="2100" 
            style="max-width:100px;" <?= $period_type === 'custom' ? 'style="display:none;"' : '' ?>>
        
        <select name="month" id="month_input" style="max-width:120px; <?= $period_type !== 'monthly' ? 'display:none;' : '' ?>">
            <?php for($m=1; $m<=12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
        </select>
        
        <select name="quarter" id="quarter_input" style="max-width:100px; <?= $period_type !== 'quarterly' ? 'display:none;' : '' ?>">
            <option value="1" <?= $quarter === 1 ? 'selected' : '' ?>>Q1</option>
            <option value="2" <?= $quarter === 2 ? 'selected' : '' ?>>Q2</option>
            <option value="3" <?= $quarter === 3 ? 'selected' : '' ?>>Q3</option>
            <option value="4" <?= $quarter === 4 ? 'selected' : '' ?>>Q4</option>
        </select>
        
        <input type="date" name="start_date" id="start_date" value="<?= $start_date ?>" 
            style="max-width:160px; <?= $period_type !== 'custom' ? 'display:none;' : '' ?>">
        <input type="date" name="end_date" id="end_date" value="<?= $end_date ?>" 
            style="max-width:160px; <?= $period_type !== 'custom' ? 'display:none;' : '' ?>">
        
        <button type="submit" class="btn btn-secondary">Tampilkan</button>
    </form>
    
    <?php if (can('reports.export')): ?>
        <button onclick="window.print()" class="btn btn-success">Print</button>
        <a href="<?= url('pages/reports/income_statement.php?' . http_build_query(array_merge($_GET, ['format' => 'csv']))) ?>" class="btn btn-success">Export CSV</a>
        <a href="<?= url('pages/reports/income_statement.php?' . http_build_query(array_merge($_GET, ['format' => 'pdf']))) ?>" class="btn btn-success">Export PDF</a>
    <?php endif; ?>
</div>

<script>
function togglePeriodInputs() {
    const type = document.getElementById('period_type').value;
    document.getElementById('year_input').style.display = type === 'custom' ? 'none' : 'block';
    document.getElementById('month_input').style.display = type === 'monthly' ? 'block' : 'none';
    document.getElementById('quarter_input').style.display = type === 'quarterly' ? 'block' : 'none';
    document.getElementById('start_date').style.display = type === 'custom' ? 'block' : 'none';
    document.getElementById('end_date').style.display = type === 'custom' ? 'block' : 'none';
}
</script>

<div class="card">
    <div style="text-align: center; margin-bottom: 2rem;">
        <h2 style="margin: 0; font-weight: 700;">LAPORAN LABA RUGI</h2>
        <h3 style="margin: 0.5rem 0; font-weight: 600;"><?= APP_NAME ?></h3>
        <p style="margin: 0.25rem 0; font-size: 1.1rem;">Periode: <?= $period_label ?></p>
        <p style="margin: 0; font-size: 0.9rem; color: #6b7280;">Sesuai SAK EP (Standar Akuntansi Keuangan Entitas Privat)</p>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th style="width: 60%;">Keterangan</th>
                <th style="width: 25%; text-align: right;">Jumlah (Rp)</th>
                <th style="width: 15%; text-align: right;" class="no-print">Perubahan</th>
            </tr>
        </thead>
        <tbody>
            <!-- PENDAPATAN -->
            <tr style="background-color: #f9fafb;">
                <td colspan="3"><strong>PENDAPATAN</strong></td>
            </tr>
            <tr>
                <td style="padding-left: 2rem;">Pendapatan Bunga Pinjaman</td>
                <td style="text-align: right;"><?= rupiah($interest_income) ?></td>
                <td style="text-align: right;" class="no-print">
                    <span class="<?= $changes['interest_income'] >= 0 ? 'change-positive' : 'change-negative' ?>">
                        <?= $changes['interest_income'] >= 0 ? '+' : '' ?><?= $changes['interest_income'] ?>%
                    </span>
                </td>
            </tr>
            <tr>
                <td style="padding-left: 2rem;">Pendapatan Biaya Admin</td>
                <td style="text-align: right;"><?= rupiah($admin_fee_income) ?></td>
                <td style="text-align: right;" class="no-print">
                    <span class="<?= $changes['admin_fee_income'] >= 0 ? 'change-positive' : 'change-negative' ?>">
                        <?= $changes['admin_fee_income'] >= 0 ? '+' : '' ?><?= $changes['admin_fee_income'] ?>%
                    </span>
                </td>
            </tr>
            <tr>
                <td style="padding-left: 2rem;">Pendapatan Denda</td>
                <td style="text-align: right;"><?= rupiah($penalty_income) ?></td>
                <td style="text-align: right;" class="no-print">
                    <span class="<?= $changes['penalty_income'] >= 0 ? 'change-positive' : 'change-negative' ?>">
                        <?= $changes['penalty_income'] >= 0 ? '+' : '' ?><?= $changes['penalty_income'] ?>%
                    </span>
                </td>
            </tr>
            <tr style="background-color: #f3f4f6; font-weight: 600;">
                <td>TOTAL PENDAPATAN</td>
                <td style="text-align: right;"><?= rupiah($total_revenue) ?></td>
                <td style="text-align: right;" class="no-print">
                    <span class="<?= $changes['total_revenue'] >= 0 ? 'change-positive' : 'change-negative' ?>">
                        <?= $changes['total_revenue'] >= 0 ? '+' : '' ?><?= $changes['total_revenue'] ?>%
                    </span>
                </td>
            </tr>
            
            <!-- BEBAN -->
            <tr style="background-color: #f9fafb;">
                <td colspan="3"><strong>BEBAN</strong></td>
            </tr>
            <tr>
                <td style="padding-left: 2rem;">Beban Bunga Simpanan</td>
                <td style="text-align: right;"><?= rupiah($interest_expense) ?></td>
                <td style="text-align: right;" class="no-print">
                    <span class="<?= $changes['interest_expense'] >= 0 ? 'change-negative' : 'change-positive' ?>">
                        <?= $changes['interest_expense'] >= 0 ? '+' : '' ?><?= $changes['interest_expense'] ?>%
                    </span>
                </td>
            </tr>
            <tr>
                <td style="padding-left: 2rem;">Beban Operasional</td>
                <td style="text-align: right;"><?= rupiah($operational_expense) ?></td>
                <td style="text-align: right;" class="no-print">
                    <span class="<?= $changes['operational_expense'] >= 0 ? 'change-negative' : 'change-positive' ?>">
                        <?= $changes['operational_expense'] >= 0 ? '+' : '' ?><?= $changes['operational_expense'] ?>%
                    </span>
                </td>
            </tr>
            <tr style="background-color: #f3f4f6; font-weight: 600;">
                <td>TOTAL BEBAN</td>
                <td style="text-align: right;"><?= rupiah($total_expense) ?></td>
                <td style="text-align: right;" class="no-print">
                    <span class="<?= $changes['total_expense'] >= 0 ? 'change-negative' : 'change-positive' ?>">
                        <?= $changes['total_expense'] >= 0 ? '+' : '' ?><?= $changes['total_expense'] ?>%
                    </span>
                </td>
            </tr>
            
            <!-- LABA BERSIH -->
            <tr style="background-color: <?= $net_income >= 0 ? '#d1fae5' : '#fee2e2' ?>; font-weight: 700; font-size: 1.1rem;">
                <td>LABA BERSIH</td>
                <td style="text-align: right; color: <?= $net_income >= 0 ? '#059669' : '#dc2626' ?>;"><?= rupiah($net_income) ?></td>
                <td style="text-align: right;" class="no-print">
                    <span class="<?= $changes['net_income'] >= 0 ? 'change-positive' : 'change-negative' ?>">
                        <?= $changes['net_income'] >= 0 ? '+' : '' ?><?= $changes['net_income'] ?>%
                    </span>
                </td>
            </tr>
        </tbody>
    </table>
    
    <div style="margin-top: 1.5rem; padding: 1rem; background-color: #f9fafb; border-radius: 0.5rem;" class="no-print">
        <h4 style="margin: 0 0 0.5rem 0; font-size: 1rem;">Catatan:</h4>
        <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.9rem; color: #4b5563;">
            <li>Persentase perubahan dibandingkan dengan periode sebelumnya</li>
            <li>Pendapatan Bunga Pinjaman: bunga yang diterima dari angsuran pinjaman</li>
            <li>Pendapatan Biaya Admin: biaya admin yang dikenakan saat pinjaman disetujui</li>
            <li>Pendapatan Denda: denda keterlambatan pembayaran angsuran</li>
            <li>Beban Bunga Simpanan: bunga yang dibayarkan kepada anggota atas simpanan</li>
            <li>Beban Operasional: biaya operasional koperasi (dari transaksi kas/bank)</li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
