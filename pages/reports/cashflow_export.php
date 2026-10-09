<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.export');

$months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

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

header_csv_download("Laporan Arus Kas {$year}.csv");
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Laporan Arus Kas', 'Tahun ' . $year]);
fputcsv($out, []);
fputcsv($out, ['Bulan', 'Setoran Simpanan', 'Angsuran Diterima', 'Total Pemasukan', 'Penarikan Simpanan', 'Pencairan Pinjaman', 'Total Pengeluaran', 'Surplus/Defisit', 'Saldo Kumulatif']);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['month'],
        number_format($row['deposit'], 2, '.', ''),
        number_format($row['installment'], 2, '.', ''),
        number_format($row['income'], 2, '.', ''),
        number_format($row['withdrawal'], 2, '.', ''),
        number_format($row['disbursement'], 2, '.', ''),
        number_format($row['expense'], 2, '.', ''),
        number_format($row['net'], 2, '.', ''),
        number_format($row['cumulative'], 2, '.', ''),
    ]);
}

fclose($out);
