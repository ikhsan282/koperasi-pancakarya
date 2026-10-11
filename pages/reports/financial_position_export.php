<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.export');

$format = $_GET['format'] ?? 'pdf';
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

$year_end = "$year-12-31";

// ASET
$stmt = db()->prepare("SELECT COALESCE(SUM(balance), 0) AS total FROM cash_bank_accounts WHERE is_active = 1");
$stmt->execute();
$cash_bank = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt = db()->prepare("SELECT COALESCE(SUM(balance_remaining), 0) AS total 
    FROM loan_payments 
    WHERE status IN ('pending', 'overdue') AND due_date <= ?");
$stmt->bind_param('s', $year_end);
$stmt->execute();
$loan_receivables = (float) $stmt->get_result()->fetch_assoc()['total'];

$stmt = db()->prepare("SELECT COALESCE(SUM(lc.estimated_value), 0) AS total 
    FROM loan_collaterals lc
    INNER JOIN loans l ON l.id = lc.loan_id
    WHERE lc.status = 'held' AND l.status IN ('active', 'approved')");
$stmt->execute();
$collateral_value = (float) $stmt->get_result()->fetch_assoc()['total'];

$total_aset = $cash_bank + $loan_receivables + $collateral_value;

// KEWAJIBAN
$stmt = db()->prepare("SELECT COALESCE(SUM(balance), 0) AS total 
    FROM savings_accounts 
    WHERE status = 'active'");
$stmt->execute();
$member_savings = (float) $stmt->get_result()->fetch_assoc()['total'];

$total_kewajiban = $member_savings;

// EKUITAS
$stmt = db()->prepare("SELECT value FROM settings WHERE `key` = 'modal_koperasi'");
$stmt->execute();
$res = $stmt->get_result();
$modal_koperasi = $res->num_rows > 0 ? (float) $res->fetch_assoc()['value'] : 0.00;

$stmt = db()->prepare("SELECT COALESCE(SUM(total_shu), 0) AS total 
    FROM shu_periods 
    WHERE fiscal_year = ? AND status = 'finalized'");
$stmt->bind_param('i', $year);
$stmt->execute();
$shu_current_year = (float) $stmt->get_result()->fetch_assoc()['total'];

$total_ekuitas = $modal_koperasi + $shu_current_year;

$total_passiva = $total_kewajiban + $total_ekuitas;
$balance_diff = $total_aset - $total_passiva;
$is_balanced = abs($balance_diff) < 0.01;

if ($format === 'pdf') {
    require_once __DIR__ . '/../../includes/pdf.php';
    $pdf = new SimplePDF("Laporan Posisi Keuangan {$year}", APP_NAME, false);
    $pdf->heading("LAPORAN POSISI KEUANGAN", 1);
    $pdf->text("Per 31 Desember {$year}", 11.0, false);
    $pdf->ln(8);
    
    $pdf->heading("ASET", 2);
    $pdf->table(
        ['Keterangan', 'Jumlah (Rp)'],
        [
            ['Kas dan Bank', rupiah($cash_bank)],
            ['Piutang Pinjaman', rupiah($loan_receivables)],
            ['Nilai Agunan', rupiah($collateral_value)],
            ['TOTAL ASET', rupiah($total_aset)],
        ],
        [3, 2]
    );
    
    $pdf->heading("KEWAJIBAN", 2);
    $pdf->table(
        ['Keterangan', 'Jumlah (Rp)'],
        [
            ['Simpanan Anggota', rupiah($member_savings)],
            ['TOTAL KEWAJIBAN', rupiah($total_kewajiban)],
        ],
        [3, 2]
    );
    
    $pdf->heading("EKUITAS", 2);
    $pdf->table(
        ['Keterangan', 'Jumlah (Rp)'],
        [
            ['Modal Koperasi', rupiah($modal_koperasi)],
            ['SHU Tahun Berjalan', rupiah($shu_current_year)],
            ['TOTAL EKUITAS', rupiah($total_ekuitas)],
        ],
        [3, 2]
    );
    
    $pdf->ln(5);
    $pdf->text("Total Kewajiban + Ekuitas: " . rupiah($total_passiva), 11.0, true);
    $pdf->text("Status: " . ($is_balanced ? "BALANCE" : "TIDAK BALANCE (Selisih: " . rupiah($balance_diff) . ")"), 11.0, false);
    
    $pdf->download("Laporan_Posisi_Keuangan_{$year}.pdf");
} else {
    header_csv_download("Laporan_Posisi_Keuangan_{$year}.csv");
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    
    fputcsv($out, ['LAPORAN POSISI KEUANGAN']);
    fputcsv($out, ['Tahun', $year]);
    fputcsv($out, []);
    
    fputcsv($out, ['ASET']);
    fputcsv($out, ['Kas dan Bank', $cash_bank]);
    fputcsv($out, ['Piutang Pinjaman', $loan_receivables]);
    fputcsv($out, ['Nilai Agunan', $collateral_value]);
    fputcsv($out, ['Total Aset', $total_aset]);
    fputcsv($out, []);
    
    fputcsv($out, ['KEWAJIBAN']);
    fputcsv($out, ['Simpanan Anggota', $member_savings]);
    fputcsv($out, ['Total Kewajiban', $total_kewajiban]);
    fputcsv($out, []);
    
    fputcsv($out, ['EKUITAS']);
    fputcsv($out, ['Modal Koperasi', $modal_koperasi]);
    fputcsv($out, ['SHU Tahun Berjalan', $shu_current_year]);
    fputcsv($out, ['Total Ekuitas', $total_ekuitas]);
    fputcsv($out, []);
    
    fputcsv($out, ['Total Kewajiban + Ekuitas', $total_passiva]);
    fputcsv($out, ['Selisih', $balance_diff]);
    fputcsv($out, ['Status', $is_balanced ? 'BALANCE' : 'TIDAK BALANCE']);
    
    fclose($out);
}
