<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.export');

$format = $_GET['format'] ?? 'pdf';
$period_type = $_GET['period_type'] ?? 'yearly';
$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
$quarter = (int) ($_GET['quarter'] ?? ceil(date('n') / 3));
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if ($year < 2000 || $year > 2100) $year = (int) date('Y');
if ($month < 1 || $month > 12) $month = (int) date('n');
if ($quarter < 1 || $quarter > 4) $quarter = 1;

// Calculate date range
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

// PENDAPATAN
$stmt = db()->prepare("SELECT COALESCE(SUM(interest_amount), 0) AS total 
    FROM loan_payments 
    WHERE status = 'paid' AND payment_date >= ? AND payment_date <= ?");
$stmt->bind_param('ss', $period_start, $period_end);
$stmt->execute();
$interest_income = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt->bind_param('ss', $prev_start, $prev_end);
$stmt->execute();
$prev_interest_income = (float) $stmt->get_result()->fetch_assoc()['total'];

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

// BEBAN
$stmt = db()->prepare("SELECT COALESCE(SUM(interest_amount), 0) AS total 
    FROM savings_interest_history 
    WHERE posted_date >= ? AND posted_date <= ?");
$stmt->bind_param('ss', $period_start, $period_end);
$stmt->execute();
$interest_expense = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt->bind_param('ss', $prev_start, $prev_end);
$stmt->execute();
$prev_interest_expense = (float) $stmt->get_result()->fetch_assoc()['total'];

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

$net_income = $total_revenue - $total_expense;
$prev_net_income = $prev_total_revenue - $prev_total_expense;

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

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Laporan_Laba_Rugi_' . str_replace(' ', '_', $period_label) . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
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
} else {
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
