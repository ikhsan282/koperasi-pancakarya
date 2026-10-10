<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('cashbank.view');

$title = 'Transaksi Kas & Bank';

// Filters
$account_id = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$ref_type = trim($_GET['ref_type'] ?? '');

// Build query
$where = [];
$params = [];
$types = '';

if ($account_id > 0) {
    $where[] = 't.account_id = ?';
    $params[] = $account_id;
    $types .= 'i';
}

if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $where[] = 't.transaction_date >= ?';
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $where[] = 't.transaction_date <= ?';
    $params[] = $date_to;
    $types .= 's';
}

if ($ref_type !== '' && in_array($ref_type, ['loan_disbursement', 'loan_payment', 'savings_deposit', 'savings_withdrawal', 'expense', 'other'])) {
    $where[] = 't.reference_type = ?';
    $params[] = $ref_type;
    $types .= 's';
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get transactions
$sql = "SELECT t.*, a.account_name, a.account_type, u.name AS processed_by_name
        FROM cash_bank_transactions t
        JOIN cash_bank_accounts a ON t.account_id = a.id
        LEFT JOIN users u ON t.processed_by = u.id
        {$where_clause}
        ORDER BY t.transaction_date DESC, t.id DESC
        LIMIT 200";

$stmt = db()->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$transactions = $stmt->get_result();

// Get accounts for filter
$accounts = db()->query('SELECT id, account_name FROM cash_bank_accounts ORDER BY account_name');

// Calculate totals
$sql_totals = "SELECT 
                COALESCE(SUM(CASE WHEN t.transaction_type = 'debit' THEN t.amount ELSE 0 END), 0) AS total_in,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'credit' THEN t.amount ELSE 0 END), 0) AS total_out
               FROM cash_bank_transactions t
               {$where_clause}";
$stmt = db()->prepare($sql_totals);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();

require __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <h3>Filter Transaksi</h3>
    <form method="get" class="form">
        <div class="form-row">
            <div class="form-group">
                <label for="account_id">Akun</label>
                <select id="account_id" name="account_id">
                    <option value="">Semua Akun</option>
                    <?php while ($acc = $accounts->fetch_assoc()): ?>
                        <option value="<?= $acc['id'] ?>" <?= $account_id == $acc['id'] ? 'selected' : '' ?>>
                            <?= e($acc['account_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="date_from">Dari Tanggal</label>
                <input type="date" id="date_from" name="date_from" value="<?= e($date_from) ?>">
            </div>
            <div class="form-group">
                <label for="date_to">Sampai Tanggal</label>
                <input type="date" id="date_to" name="date_to" value="<?= e($date_to) ?>">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="ref_type">Jenis Referensi</label>
                <select id="ref_type" name="ref_type">
                    <option value="">Semua Jenis</option>
                    <option value="loan_disbursement" <?= $ref_type === 'loan_disbursement' ? 'selected' : '' ?>>Pencairan Pinjaman</option>
                    <option value="loan_payment" <?= $ref_type === 'loan_payment' ? 'selected' : '' ?>>Angsuran Pinjaman</option>
                    <option value="savings_deposit" <?= $ref_type === 'savings_deposit' ? 'selected' : '' ?>>Setoran Simpanan</option>
                    <option value="savings_withdrawal" <?= $ref_type === 'savings_withdrawal' ? 'selected' : '' ?>>Penarikan Simpanan</option>
                    <option value="expense" <?= $ref_type === 'expense' ? 'selected' : '' ?>>Pengeluaran</option>
                    <option value="other" <?= $ref_type === 'other' ? 'selected' : '' ?>>Lainnya</option>
                </select>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Terapkan Filter</button>
            <a href="<?= url('pages/cashbank/transactions.php') ?>" class="btn btn-secondary">Reset</a>
            <a href="<?= url('pages/cashbank/accounts.php') ?>" class="btn btn-secondary">Kelola Akun</a>
        </div>
    </form>
</div>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h3>Daftar Transaksi</h3>
        <?php if (can('cashbank.manage')): ?>
            <a href="<?= url('pages/cashbank/form.php') ?>" class="btn btn-primary">+ Transaksi Manual</a>
        <?php endif; ?>
    </div>
    
    <div class="dashboard-stats" style="margin-bottom: 1.5rem;">
        <div class="stat-card">
            <div class="stat-icon">↓</div>
            <div class="stat-info">
                <div class="stat-label">Total Masuk (Debit)</div>
                <div class="stat-value" style="color: #10b981;"><?= rupiah($totals['total_in']) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">↑</div>
            <div class="stat-info">
                <div class="stat-label">Total Keluar (Credit)</div>
                <div class="stat-value" style="color: #c53030;"><?= rupiah($totals['total_out']) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">═</div>
            <div class="stat-info">
                <div class="stat-label">Selisih (Net)</div>
                <div class="stat-value"><?= rupiah($totals['total_in'] - $totals['total_out']) ?></div>
            </div>
        </div>
    </div>
    
    <table class="table">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Akun</th>
                <th>Jenis</th>
                <th>Debit (Masuk)</th>
                <th>Credit (Keluar)</th>
                <th>Saldo Sesudah</th>
                <th>Deskripsi</th>
                <th>Ref</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($transactions->num_rows === 0): ?>
                <tr><td colspan="8" class="text-center">Tidak ada transaksi.</td></tr>
            <?php else: ?>
                <?php while ($trx = $transactions->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($trx['transaction_date'])) ?></td>
                        <td><?= e($trx['account_name']) ?></td>
                        <td>
                            <span class="badge badge-<?= $trx['transaction_type'] === 'debit' ? 'success' : 'warning' ?>">
                                <?= $trx['transaction_type'] === 'debit' ? 'Debit' : 'Credit' ?>
                            </span>
                        </td>
                        <td><?= $trx['transaction_type'] === 'debit' ? rupiah($trx['amount']) : '-' ?></td>
                        <td><?= $trx['transaction_type'] === 'credit' ? rupiah($trx['amount']) : '-' ?></td>
                        <td><strong><?= rupiah($trx['balance_after']) ?></strong></td>
                        <td>
                            <?= e($trx['description']) ?>
                            <br><small style="color: #666;">oleh <?= e($trx['processed_by_name'] ?? 'Sistem') ?></small>
                        </td>
                        <td>
                            <?php
                            $ref_labels = [
                                'loan_disbursement' => 'Pencairan',
                                'loan_payment' => 'Angsuran',
                                'savings_deposit' => 'Setoran',
                                'savings_withdrawal' => 'Penarikan',
                                'expense' => 'Pengeluaran',
                                'other' => 'Lainnya'
                            ];
                            ?>
                            <small><?= $ref_labels[$trx['reference_type']] ?? $trx['reference_type'] ?></small>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php if ($transactions->num_rows >= 200): ?>
        <p class="text-center" style="margin-top: 1rem; color: #666;">
            Menampilkan 200 transaksi terakhir. Gunakan filter untuk mempersempit hasil.
        </p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
