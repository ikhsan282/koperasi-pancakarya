<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.export');

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

$filter_status = $_GET['status'] ?? 'all';

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
    $contract_interest = (float) $row['amount'] * ((float) $row['interest_rate'] / 100) * (int) $row['term_months'];
    $row['principal_remaining'] = max(0, (float) $row['amount'] - (float) $row['principal_paid']);
    $row['interest_remaining'] = max(0, $contract_interest - (float) $row['interest_paid']);
    $row['outstanding'] = $row['principal_remaining'] + $row['interest_remaining'];
    
    $days = (int) ($row['days_overdue'] ?? 0);
    if ($days < $thresholds['kl']) {
        $row['kolektibilitas'] = 'LANCAR';
    } elseif ($days < $thresholds['diragukan']) {
        $row['kolektibilitas'] = 'KURANG LANCAR';
    } elseif ($days < $thresholds['macet']) {
        $row['kolektibilitas'] = 'DIRAGUKAN';
    } else {
        $row['kolektibilitas'] = 'MACET';
    }
    
    if ($filter_status === 'all' || strtolower($row['kolektibilitas']) === strtolower($filter_status)) {
        $rows[] = $row;
    }
}

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
        rupiah($row['outstanding'])
    ];
}

$pdf->table(
    ['No. Anggota', 'Nama', 'No. Pinjaman', 'Hari Telat', 'Kolektibilitas', 'Outstanding'],
    $tbl
);

$pdf->ln(4);
$pdf->heading('RINGKASAN', 2);
$pdf->table(
    ['Kategori', 'Jumlah Pinjaman', 'Total Outstanding'],
    [
        ['LANCAR', $summary['LANCAR']['count'], rupiah($summary['LANCAR']['outstanding'])],
        ['KURANG LANCAR', $summary['KURANG LANCAR']['count'], rupiah($summary['KURANG LANCAR']['outstanding'])],
        ['DIRAGUKAN', $summary['DIRAGUKAN']['count'], rupiah($summary['DIRAGUKAN']['outstanding'])],
        ['MACET', $summary['MACET']['count'], rupiah($summary['MACET']['outstanding'])],
    ]
);

$pdf->download('Laporan_KAP_' . date('Ymd') . '.pdf');
