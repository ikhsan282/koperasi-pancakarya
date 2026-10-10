<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.view');
$title = 'Laporan Arus Kas (Cash Flow Statement)';

// Period filter
$period_type = $_GET['period'] ?? 'monthly';
$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
$quarter = (int) ($_GET['quarter'] ?? ceil(date('n') / 3));
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$breakdown = isset($_GET['breakdown']); // Per-account breakdown

if ($year < 2000 || $year > 2100) $year = (int) date('Y');

// Calculate period dates
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

// Get current total balance across all active accounts
$stmt = db()->prepare("SELECT COALESCE(SUM(balance), 0) AS total FROM cash_bank_accounts WHERE is_active = 1");
$stmt->execute();
$current_total = (float) $stmt->get_result()->fetch_assoc()['total'];

// Calculate transactions within period
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

// Calculate net change during period
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

// Calculate opening balance: current balance minus net change
$opening_balance = $current_total - $net_change;
$closing_balance = $current_total;

// Operational Cash Flows
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

// Per-account breakdown (optional)
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

// CSV Export
if (($_GET['format'] ?? '') === 'csv') {
    require_permission('reports.export');
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
    exit;
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form" style="flex-wrap: wrap; gap: 10px;">
        <div>
            <label>Periode</label>
            <select name="period" id="period_type" onchange="updatePeriodInputs()">
                <option value="monthly" <?= $period_type === 'monthly' ? 'selected' : '' ?>>Bulanan</option>
                <option value="quarterly" <?= $period_type === 'quarterly' ? 'selected' : '' ?>>Triwulanan</option>
                <option value="yearly" <?= $period_type === 'yearly' ? 'selected' : '' ?>>Tahunan</option>
                <option value="custom" <?= $period_type === 'custom' ? 'selected' : '' ?>>Custom</option>
            </select>
        </div>
        
        <div id="monthly_inputs" style="display: <?= $period_type === 'monthly' ? 'flex' : 'none' ?>; gap: 10px;">
            <input type="number" name="year" value="<?= $year ?>" min="2000" max="2100" style="width:80px;" placeholder="Tahun">
            <select name="month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        
        <div id="quarterly_inputs" style="display: <?= $period_type === 'quarterly' ? 'flex' : 'none' ?>; gap: 10px;">
            <input type="number" name="year" value="<?= $year ?>" min="2000" max="2100" style="width:80px;" placeholder="Tahun">
            <select name="quarter">
                <option value="1" <?= $quarter === 1 ? 'selected' : '' ?>>Q1 (Jan-Mar)</option>
                <option value="2" <?= $quarter === 2 ? 'selected' : '' ?>>Q2 (Apr-Jun)</option>
                <option value="3" <?= $quarter === 3 ? 'selected' : '' ?>>Q3 (Jul-Sep)</option>
                <option value="4" <?= $quarter === 4 ? 'selected' : '' ?>>Q4 (Oct-Dec)</option>
            </select>
        </div>
        
        <div id="yearly_inputs" style="display: <?= $period_type === 'yearly' ? 'flex' : 'none' ?>;">
            <input type="number" name="year" value="<?= $year ?>" min="2000" max="2100" style="width:100px;" placeholder="Tahun">
        </div>
        
        <div id="custom_inputs" style="display: <?= $period_type === 'custom' ? 'flex' : 'none' ?>; gap: 10px;">
            <input type="date" name="start_date" value="<?= $start_date ?>">
            <input type="date" name="end_date" value="<?= $end_date ?>">
        </div>
        
        <label style="display: flex; align-items: center; gap: 5px;">
            <input type="checkbox" name="breakdown" <?= $breakdown ? 'checked' : '' ?>>
            Per Akun
        </label>
        
        <button type="submit" class="btn btn-secondary">Filter</button>
    </form>
    
    <?php if (can('reports.export')): ?>
        <button onclick="window.print()" class="btn btn-success">Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['format' => 'csv'])) ?>" class="btn btn-success">Export CSV</a>
    <?php endif; ?>
</div>

<script>
function updatePeriodInputs() {
    const type = document.getElementById('period_type').value;
    document.getElementById('monthly_inputs').style.display = type === 'monthly' ? 'flex' : 'none';
    document.getElementById('quarterly_inputs').style.display = type === 'quarterly' ? 'flex' : 'none';
    document.getElementById('yearly_inputs').style.display = type === 'yearly' ? 'flex' : 'none';
    document.getElementById('custom_inputs').style.display = type === 'custom' ? 'flex' : 'none';
}
</script>

<div class="report-summary">
    <div class="report-card">
        <h4>Saldo Awal</h4>
        <div class="report-value"><?= rupiah($opening_balance) ?></div>
        <small><?= date('d M Y', strtotime($start_date)) ?></small>
    </div>
    <div class="report-card">
        <h4>Arus Kas Bersih</h4>
        <div class="report-value" style="color: <?= $net_change >= 0 ? '#059669' : '#dc2626' ?>;"><?= rupiah($net_change) ?></div>
        <small><?= $period_label ?></small>
    </div>
    <div class="report-card">
        <h4>Saldo Akhir</h4>
        <div class="report-value"><?= rupiah($closing_balance) ?></div>
        <small><?= date('d M Y', strtotime($end_date)) ?></small>
    </div>
</div>

<div class="card">
    <h3>Laporan Arus Kas - <?= $period_label ?></h3>
    <table class="table">
        <tbody>
            <tr class="section-header">
                <td colspan="2"><strong>ARUS KAS OPERASIONAL</strong></td>
            </tr>
            <tr>
                <td colspan="2"><strong>Penerimaan Kas:</strong></td>
            </tr>
            <tr>
                <td style="padding-left: 2em;">Angsuran Pinjaman</td>
                <td style="text-align: right;"><?= rupiah($receipts['loan_payment']) ?></td>
            </tr>
            <tr>
                <td style="padding-left: 2em;">Setoran Simpanan</td>
                <td style="text-align: right;"><?= rupiah($receipts['savings_deposit']) ?></td>
            </tr>
            <tr style="background-color: #f9fafb;">
                <td><strong>Total Penerimaan</strong></td>
                <td style="text-align: right;"><strong><?= rupiah($total_receipts) ?></strong></td>
            </tr>
            <tr>
                <td colspan="2"><strong>Pengeluaran Kas:</strong></td>
            </tr>
            <tr>
                <td style="padding-left: 2em;">Pencairan Pinjaman</td>
                <td style="text-align: right;"><?= rupiah($disbursements['loan_disbursement']) ?></td>
            </tr>
            <tr>
                <td style="padding-left: 2em;">Penarikan Simpanan</td>
                <td style="text-align: right;"><?= rupiah($disbursements['savings_withdrawal']) ?></td>
            </tr>
            <tr>
                <td style="padding-left: 2em;">Beban Operasional</td>
                <td style="text-align: right;"><?= rupiah($disbursements['expense']) ?></td>
            </tr>
            <tr>
                <td style="padding-left: 2em;">Lain-lain</td>
                <td style="text-align: right;"><?= rupiah($disbursements['other']) ?></td>
            </tr>
            <tr style="background-color: #f9fafb;">
                <td><strong>Total Pengeluaran</strong></td>
                <td style="text-align: right;"><strong><?= rupiah($total_disbursements) ?></strong></td>
            </tr>
            <tr style="background-color: #e5e7eb;">
                <td><strong>Arus Kas Bersih Operasional</strong></td>
                <td style="text-align: right; color: <?= $net_operational >= 0 ? '#059669' : '#dc2626' ?>;"><strong><?= rupiah($net_operational) ?></strong></td>
            </tr>
            <tr>
                <td colspan="2">&nbsp;</td>
            </tr>
            <tr class="section-header">
                <td colspan="2"><strong>SALDO KAS</strong></td>
            </tr>
            <tr>
                <td>Saldo Awal Periode</td>
                <td style="text-align: right;"><?= rupiah($opening_balance) ?></td>
            </tr>
            <tr>
                <td>Arus Kas Bersih</td>
                <td style="text-align: right; color: <?= $net_change >= 0 ? '#059669' : '#dc2626' ?>;"><?= rupiah($net_change) ?></td>
            </tr>
            <tr style="background-color: #e5e7eb;">
                <td><strong>Saldo Akhir Periode</strong></td>
                <td style="text-align: right;"><strong><?= rupiah($closing_balance) ?></strong></td>
            </tr>
        </tbody>
    </table>
</div>

<?php if ($breakdown && count($account_breakdown) > 0): ?>
<div class="card">
    <h3>Breakdown per Akun</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Nama Akun</th>
                <th>Tipe</th>
                <th style="text-align: right;">Debit (Masuk)</th>
                <th style="text-align: right;">Kredit (Keluar)</th>
                <th style="text-align: right;">Net</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($account_breakdown as $acc): 
                $net = (float)$acc['debit'] - (float)$acc['credit'];
            ?>
            <tr>
                <td><?= htmlspecialchars($acc['account_name']) ?></td>
                <td><?= htmlspecialchars($acc['account_type']) ?></td>
                <td style="text-align: right;"><?= rupiah($acc['debit']) ?></td>
                <td style="text-align: right;"><?= rupiah($acc['credit']) ?></td>
                <td style="text-align: right; color: <?= $net >= 0 ? '#059669' : '#dc2626' ?>;"><?= rupiah($net) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<style>
@media print {
    .page-actions, .sidebar, .header, .nav { display: none !important; }
    .card { page-break-inside: avoid; }
    .section-header { font-weight: bold; background-color: #f3f4f6 !important; }
}
.section-header td { background-color: #f3f4f6; padding: 10px 15px !important; }
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
