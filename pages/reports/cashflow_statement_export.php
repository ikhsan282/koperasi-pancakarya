<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.export');

$period_type = $_GET['period'] ?? 'monthly';
$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
$quarter = (int) ($_GET['quarter'] ?? ceil(date('n') / 3));
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$breakdown = isset($_GET['breakdown']);

if ($year < 2000 || $year > 2100) $year = (int) date('Y');

switch ($period_type) {
    case 'monthly':
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        $period_label = date('F Y', strtotime($start_date));
        break;
    case 'quarterly':
        $q_start_month = ($quarter - 1) * 3 + 1;
        $start_date = sprintf('%04d-%02d-01', $year, $q_start_month);
        $end_date = date('Y-m-t', strtotime($start_date . ' +2 months'));
        $period_label = "Q$quarter $year";
        break;
    case 'yearly':
        $start_date = "$year-01-01";
        $end_date = "$year-12-31";
        $period_label = "Tahun $year";
        break;
    case 'custom':
        if (!$start_date || !$end_date) {
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
        }
        $period_label = date('d M Y', strtotime($start_date)) . ' - ' . date('d M Y', strtotime($end_date));
        break;
    default:
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $period_label = date('F Y');
}

$stmt = db()->prepare("SELECT COALESCE(SUM(balance), 0) AS total FROM cash_bank_accounts WHERE is_active = 1");
$stmt->execute();
$current_total = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt = db()->prepare("
    SELECT 
        reference_type,
        transaction_type,
        COALESCE(SUM(amount), 0) AS total
    FROM cash_bank_transactions
    WHERE transaction_date BETWEEN ? AND ?
    GROUP BY reference_type, transaction_type
");
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$period_txns = [];
while ($row = $stmt->get_result()->fetch_assoc()) {
    $key = $row['reference_type'] . '_' . $row['transaction_type'];
    $period_txns[$key] = (float) $row['total'];
}

$stmt = db()->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END), 0) AS total_debit,
        COALESCE(SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END), 0) AS total_credit
    FROM cash_bank_transactions
    WHERE transaction_date BETWEEN ? AND ?
");
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$period_summary = $stmt->get_result()->fetch_assoc();
$period_debit = (float) $period_summary['total_debit'];
$period_credit = (float) $period_summary['total_credit'];
$net_change = $period_debit - $period_credit;

$opening_balance = $current_total - $net_change;
$closing_balance = $current_total;

$receipts = [
    'loan_payment' => $period_txns['loan_payment_debit'] ?? 0,
    'savings_deposit' => $period_txns['savings_deposit_debit'] ?? 0,
];
$total_receipts = array_sum($receipts);

$disbursements = [
    'loan_disbursement' => $period_txns['loan_disbursement_credit'] ?? 0,
    'savings_withdrawal' => $period_txns['savings_withdrawal_credit'] ?? 0,
    'expense' => $period_txns['expense_credit'] ?? 0,
    'other' => $period_txns['other_credit'] ?? 0,
];
$total_disbursements = array_sum($disbursements);

$net_operational = $total_receipts - $total_disbursements;

$account_breakdown = [];
if ($breakdown) {
    $stmt = db()->prepare("
        SELECT 
            a.id, a.account_name, a.account_type,
            COALESCE(SUM(CASE WHEN t.transaction_type = 'debit' THEN t.amount ELSE 0 END), 0) AS debit,
            COALESCE(SUM(CASE WHEN t.transaction_type = 'credit' THEN t.amount ELSE 0 END), 0) AS credit
        FROM cash_bank_accounts a
        LEFT JOIN cash_bank_transactions t ON a.id = t.account_id 
            AND t.transaction_date BETWEEN ? AND ?
        WHERE a.is_active = 1
        GROUP BY a.id, a.account_name, a.account_type
        ORDER BY a.account_name
    ");
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $account_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

header_csv_download("Laporan_Arus_Kas_{$start_date}_{$end_date}.csv");
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['LAPORAN ARUS KAS']);
fputcsv($out, ['Periode', $period_label]);
fputcsv($out, []);

fputcsv($out, ['ARUS KAS OPERASIONAL']);
fputcsv($out, ['Penerimaan Kas']);
fputcsv($out, ['  Angsuran Pinjaman', number_format($receipts['loan_payment'], 2, '.', '')]);
fputcsv($out, ['  Setoran Simpanan', number_format($receipts['savings_deposit'], 2, '.', '')]);
fputcsv($out, ['Total Penerimaan', number_format($total_receipts, 2, '.', '')]);
fputcsv($out, []);
fputcsv($out, ['Pengeluaran Kas']);
fputcsv($out, ['  Pencairan Pinjaman', number_format($disbursements['loan_disbursement'], 2, '.', '')]);
fputcsv($out, ['  Penarikan Simpanan', number_format($disbursements['savings_withdrawal'], 2, '.', '')]);
fputcsv($out, ['  Beban Operasional', number_format($disbursements['expense'], 2, '.', '')]);
fputcsv($out, ['  Lain-lain', number_format($disbursements['other'], 2, '.', '')]);
fputcsv($out, ['Total Pengeluaran', number_format($total_disbursements, 2, '.', '')]);
fputcsv($out, []);
fputcsv($out, ['Arus Kas Bersih Operasional', number_format($net_operational, 2, '.', '')]);
fputcsv($out, []);
fputcsv($out, ['SALDO KAS']);
fputcsv($out, ['Saldo Awal Periode', number_format($opening_balance, 2, '.', '')]);
fputcsv($out, ['Arus Kas Bersih', number_format($net_change, 2, '.', '')]);
fputcsv($out, ['Saldo Akhir Periode', number_format($closing_balance, 2, '.', '')]);

if ($breakdown && count($account_breakdown) > 0) {
    fputcsv($out, []);
    fputcsv($out, ['BREAKDOWN PER AKUN']);
    fputcsv($out, ['Nama Akun', 'Tipe', 'Debit', 'Kredit', 'Net']);
    foreach ($account_breakdown as $acc) {
        $net = (float)$acc['debit'] - (float)$acc['credit'];
        fputcsv($out, [
            $acc['account_name'],
            $acc['account_type'],
            number_format($acc['debit'], 2, '.', ''),
            number_format($acc['credit'], 2, '.', ''),
            number_format($net, 2, '.', ''),
        ]);
    }
}

fclose($out);
