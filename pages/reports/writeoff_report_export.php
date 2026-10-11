<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.export');

$period_from = trim($_GET['period_from'] ?? '');
$period_to = trim($_GET['period_to'] ?? '');

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
