<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.export');

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
    $contract_interest = (float) $row['amount'] * ((float) $row['interest_rate'] / 100) * (int) $row['term_months'];
    $row['principal_remaining'] = max(0, (float) $row['amount'] - (float) $row['principal_paid']);
    $row['interest_remaining'] = max(0, $contract_interest - (float) $row['interest_paid']);
    $row['outstanding'] = $row['principal_remaining'] + $row['interest_remaining'];
    $rows[] = $row;
}

header_csv_download('Laporan Outstanding Pinjaman ' . date('d_m_Y') . '.csv');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Laporan Outstanding Pinjaman', 'Per ' . date('d/m/Y')]);
fputcsv($out, []);
fputcsv($out, ['No. Anggota', 'Nama', 'No. Pinjaman', 'Tgl Pengajuan', 'Status', 'Sisa Pokok', 'Sisa Bunga', 'Total Outstanding']);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['member_number'],
        $row['full_name'],
        $row['loan_number'],
        $row['application_date'] ? date('d/m/Y', strtotime($row['application_date'])) : '-',
        $row['status'] === 'active' ? 'Aktif' : 'Disetujui',
        number_format((float) $row['principal_remaining'], 2, '.', ''),
        number_format((float) $row['interest_remaining'], 2, '.', ''),
        number_format((float) $row['outstanding'], 2, '.', ''),
    ]);
}

fputcsv($out, [
    'TOTAL',
    '',
    '',
    '',
    '',
    number_format(array_sum(array_column($rows, 'principal_remaining')), 2, '.', ''),
    number_format(array_sum(array_column($rows, 'interest_remaining')), 2, '.', ''),
    number_format(array_sum(array_column($rows, 'outstanding')), 2, '.', ''),
]);

fclose($out);
