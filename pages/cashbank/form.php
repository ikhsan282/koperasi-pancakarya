<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cashbank_helpers.php';

require_permission('cashbank.manage');

$title = 'Transaksi Manual Kas/Bank';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $account_id = (int) ($_POST['account_id'] ?? 0);
    $transaction_type = trim($_POST['transaction_type'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $transaction_date = trim($_POST['transaction_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if ($account_id <= 0) $errors[] = 'Pilih akun kas/bank.';
    if (!in_array($transaction_type, ['debit', 'credit'])) $errors[] = 'Jenis transaksi tidak valid.';
    if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) $errors[] = 'Jumlah harus lebih dari 0.';
    if ($transaction_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transaction_date)) {
        $errors[] = 'Tanggal transaksi tidak valid.';
    }
    if ($description === '') $errors[] = 'Deskripsi wajib diisi.';
    
    if (empty($errors)) {
        $amount = round((float) $amount, 2);
        
        try {
            post_cashbank_transaction(
                $account_id,
                $transaction_type,
                $amount,
                'other',
                null,
                $description,
                $transaction_date
            );
            
            log_activity('manual_cashbank_transaction', "Transaksi manual {$transaction_type} " . rupiah($amount) . ": {$description}");
            flash('success', 'Transaksi berhasil dicatat.');
            redirect('pages/cashbank/transactions.php?account_id=' . $account_id);
        } catch (Exception $e) {
            $errors[] = 'Gagal menyimpan transaksi: ' . $e->getMessage();
        }
    }
}

// Get active accounts
$accounts = db()->query('SELECT * FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name');

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 700px; margin: 0 auto;">
    <h3>Input Transaksi Manual</h3>
    <p style="color: #666; margin-bottom: 1.5rem;">
        Untuk mencatat pengeluaran operasional, penerimaan non-anggota, atau transaksi lain yang tidak terkait dengan simpanan/pinjaman.
    </p>
    
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        
        <div class="form-group">
            <label for="account_id">Akun Kas/Bank *</label>
            <select id="account_id" name="account_id" required onchange="updateBalanceDisplay()">
                <option value="">-- Pilih Akun --</option>
                <?php while ($acc = $accounts->fetch_assoc()): ?>
                    <option value="<?= $acc['id'] ?>" 
                            data-balance="<?= $acc['balance'] ?>"
                            <?= old('account_id') == $acc['id'] ? 'selected' : '' ?>>
                        <?= e($acc['account_name']) ?> - Saldo: <?= rupiah($acc['balance']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <div id="balance-display" style="margin-top: 0.5rem; font-size: 0.9rem; color: #666;"></div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="transaction_type">Jenis Transaksi *</label>
                <select id="transaction_type" name="transaction_type" required>
                    <option value="">-- Pilih --</option>
                    <option value="debit" <?= old('transaction_type') === 'debit' ? 'selected' : '' ?>>Debit (Uang Masuk)</option>
                    <option value="credit" <?= old('transaction_type') === 'credit' ? 'selected' : '' ?>>Credit (Uang Keluar)</option>
                </select>
                <small style="display: block; margin-top: 0.25rem; color: #666;">
                    Debit menambah saldo, Credit mengurangi saldo
                </small>
            </div>
            <div class="form-group">
                <label for="amount">Jumlah (Rp) *</label>
                <input type="number" id="amount" name="amount" min="1" step="0.01" value="<?= old('amount') ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label for="transaction_date">Tanggal Transaksi *</label>
            <input type="date" id="transaction_date" name="transaction_date" value="<?= old('transaction_date', date('Y-m-d')) ?>" required>
        </div>
        
        <div class="form-group">
            <label for="description">Deskripsi *</label>
            <textarea id="description" name="description" rows="3" required placeholder="Misal: Biaya listrik bulan Oktober, Sumbangan dari sponsor, dll"><?= old('description') ?></textarea>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Simpan transaksi ini?')">Simpan Transaksi</button>
            <a href="<?= url('pages/cashbank/transactions.php') ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<script>
function updateBalanceDisplay() {
    const select = document.getElementById('account_id');
    const display = document.getElementById('balance-display');
    const option = select.options[select.selectedIndex];
    
    if (option.value) {
        const balance = parseFloat(option.dataset.balance || 0);
        display.textContent = 'Saldo saat ini: Rp ' + balance.toLocaleString('id-ID');
    } else {
        display.textContent = '';
    }
}

document.addEventListener('DOMContentLoaded', updateBalanceDisplay);
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
