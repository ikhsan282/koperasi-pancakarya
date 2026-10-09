<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.export');

$months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) $month = (int) date('n');
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

$stmt = db()->prepare("SELECT m.member_number, m.full_name,
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

header_csv_download("Laporan Simpanan {$months[$month]} {$year}.csv");
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Laporan Simpanan', $months[$month] . ' ' . $year]);
fputcsv($out, []);
fputcsv($out, ['No. Anggota', 'Nama', 'Setoran', 'Penarikan', 'Setoran Bersih', 'Saldo Saat Ini']);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['member_number'],
        $row['full_name'],
        number_format((float) $row['deposit_total'], 2, '.', ''),
        number_format((float) $row['withdrawal_total'], 2, '.', ''),
        number_format((float) $row['net_total'], 2, '.', ''),
        number_format((float) $row['current_balance'], 2, '.', ''),
    ]);
}

fputcsv($out, [
    'TOTAL',
    '',
    number_format(array_sum(array_column($rows, 'deposit_total')), 2, '.', ''),
    number_format(array_sum(array_column($rows, 'withdrawal_total')), 2, '.', ''),
    number_format(array_sum(array_column($rows, 'net_total')), 2, '.', ''),
    number_format(array_sum(array_column($rows, 'current_balance')), 2, '.', ''),
]);

fclose($out);
