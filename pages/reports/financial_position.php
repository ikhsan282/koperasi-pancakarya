<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.view');

$title = 'Laporan Posisi Keuangan';
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

$year_end = "$year-12-31";

// ASET
// 1. Kas + Bank
$stmt = db()->prepare("SELECT COALESCE(SUM(balance), 0) AS total FROM cash_bank_accounts WHERE is_active = 1");
$stmt->execute();
$cash_bank = (float) $stmt->get_result()->fetch_assoc()['total'];

// 2. Piutang Pinjaman (outstanding principal + interest)
$stmt = db()->prepare("SELECT COALESCE(SUM(balance_remaining), 0) AS total 
    FROM loan_payments 
    WHERE status IN ('pending', 'overdue') AND due_date <= ?");
$stmt->bind_param('s', $year_end);
$stmt->execute();
$loan_receivables = (float) $stmt->get_result()->fetch_assoc()['total'];

// 3. Nilai Agunan (collateral held)
$stmt = db()->prepare("SELECT COALESCE(SUM(lc.estimated_value), 0) AS total 
    FROM loan_collaterals lc
    INNER JOIN loans l ON l.id = lc.loan_id
    WHERE lc.status = 'held' AND l.status IN ('active', 'approved')");
$stmt->execute();
$collateral_value = (float) $stmt->get_result()->fetch_assoc()['total'];

$total_aset = $cash_bank + $loan_receivables + $collateral_value;

// KEWAJIBAN
// 1. Simpanan Anggota
$stmt = db()->prepare("SELECT COALESCE(SUM(balance), 0) AS total 
    FROM savings_accounts 
    WHERE status = 'active'");
$stmt->execute();
$member_savings = (float) $stmt->get_result()->fetch_assoc()['total'];

$total_kewajiban = $member_savings;

// EKUITAS
// 1. Modal Koperasi (from settings or hardcoded)
$stmt = db()->prepare("SELECT value FROM settings WHERE `key` = 'modal_koperasi'");
$stmt->execute();
$res = $stmt->get_result();
$modal_koperasi = $res->num_rows > 0 ? (float) $res->fetch_assoc()['value'] : 0.00;

// 2. SHU Tahun Berjalan
$stmt = db()->prepare("SELECT COALESCE(SUM(total_shu), 0) AS total 
    FROM shu_periods 
    WHERE fiscal_year = ? AND status = 'finalized'");
$stmt->bind_param('i', $year);
$stmt->execute();
$shu_current_year = (float) $stmt->get_result()->fetch_assoc()['total'];

$total_ekuitas = $modal_koperasi + $shu_current_year;

// Balance check
$total_passiva = $total_kewajiban + $total_ekuitas;
$balance_diff = $total_aset - $total_passiva;
$is_balanced = abs($balance_diff) < 0.01;

// PDF Export
if (($_GET['format'] ?? '') === 'pdf') {
    require_permission('reports.export');
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
}

// CSV Export
if (($_GET['format'] ?? '') === 'csv') {
    require_permission('reports.export');
    header_csv_download("Laporan_Posisi_Keuangan_{$year}.csv");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
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
    exit;
}

require __DIR__ . '/../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .card { box-shadow: none; border: none; }
    .page-actions { display: none; }
}
.balance-table { width: 100%; margin: 20px 0; }
.balance-table th { background: #f5f5f5; padding: 12px; text-align: left; font-weight: bold; }
.balance-table td { padding: 10px 12px; border-bottom: 1px solid #e0e0e0; }
.balance-table .section-header { background: #e8f4f8; font-weight: bold; font-size: 1.1em; }
.balance-table .total-row { background: #f0f0f0; font-weight: bold; border-top: 2px solid #333; }
.balance-table .amount { text-align: right; font-family: 'Courier New', monospace; }
.balance-alert { padding: 15px; margin: 20px 0; border-radius: 4px; font-weight: bold; }
.balance-alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.balance-alert.danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
</style>

<div class="page-actions no-print">
    <form method="get" class="search-form">
        <select name="year" onchange="this.form.submit()">
            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </form>
    <div>
        <button onclick="window.print()" class="btn btn-secondary">
            <span class="icon">🖨</span> Cetak
        </button>
        <a href="?year=<?= $year ?>&format=pdf" class="btn btn-danger">
            <span class="icon">📄</span> Export PDF
        </a>
        <a href="?year=<?= $year ?>&format=csv" class="btn btn-success">
            <span class="icon">📊</span> Export CSV
        </a>
    </div>
</div>

<div class="card">
    <h2 style="text-align: center; margin-bottom: 5px;">LAPORAN POSISI KEUANGAN</h2>
    <p style="text-align: center; color: #666; margin-bottom: 30px;">
        Per 31 Desember <?= $year ?>
    </p>

    <table class="balance-table">
        <thead>
            <tr>
                <th style="width: 60%;">Keterangan</th>
                <th style="width: 40%;" class="amount">Jumlah (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <!-- ASET -->
            <tr class="section-header">
                <td colspan="2">ASET</td>
            </tr>
            <tr>
                <td style="padding-left: 30px;">Kas dan Bank</td>
                <td class="amount"><?= rupiah($cash_bank) ?></td>
            </tr>
            <tr>
                <td style="padding-left: 30px;">Piutang Pinjaman</td>
                <td class="amount"><?= rupiah($loan_receivables) ?></td>
            </tr>
            <tr>
                <td style="padding-left: 30px;">Nilai Agunan</td>
                <td class="amount"><?= rupiah($collateral_value) ?></td>
            </tr>
            <tr class="total-row">
                <td>TOTAL ASET</td>
                <td class="amount"><?= rupiah($total_aset) ?></td>
            </tr>

            <!-- Spacer -->
            <tr><td colspan="2" style="height: 20px; border: none;"></td></tr>

            <!-- KEWAJIBAN -->
            <tr class="section-header">
                <td colspan="2">KEWAJIBAN</td>
            </tr>
            <tr>
                <td style="padding-left: 30px;">Simpanan Anggota</td>
                <td class="amount"><?= rupiah($member_savings) ?></td>
            </tr>
            <tr class="total-row">
                <td>TOTAL KEWAJIBAN</td>
                <td class="amount"><?= rupiah($total_kewajiban) ?></td>
            </tr>

            <!-- Spacer -->
            <tr><td colspan="2" style="height: 20px; border: none;"></td></tr>

            <!-- EKUITAS -->
            <tr class="section-header">
                <td colspan="2">EKUITAS</td>
            </tr>
            <tr>
                <td style="padding-left: 30px;">Modal Koperasi</td>
                <td class="amount"><?= rupiah($modal_koperasi) ?></td>
            </tr>
            <tr>
                <td style="padding-left: 30px;">SHU Tahun Berjalan</td>
                <td class="amount"><?= rupiah($shu_current_year) ?></td>
            </tr>
            <tr class="total-row">
                <td>TOTAL EKUITAS</td>
                <td class="amount"><?= rupiah($total_ekuitas) ?></td>
            </tr>

            <!-- Spacer -->
            <tr><td colspan="2" style="height: 20px; border: none;"></td></tr>

            <!-- TOTAL PASSIVA -->
            <tr class="total-row" style="border-top: 3px double #333;">
                <td>TOTAL KEWAJIBAN + EKUITAS</td>
                <td class="amount"><?= rupiah($total_passiva) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="balance-alert <?= $is_balanced ? 'success' : 'danger' ?> no-print">
        <?php if ($is_balanced): ?>
            ✓ Neraca BALANCE - Total Aset = Total Kewajiban + Ekuitas
        <?php else: ?>
            ⚠ PERHATIAN: Neraca TIDAK BALANCE<br>
            Selisih: <?= rupiah($balance_diff) ?> 
            (Aset <?= $balance_diff > 0 ? 'lebih besar' : 'lebih kecil' ?> dari Kewajiban + Ekuitas)
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
