<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('savings.create');

$account_id = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;

if ($account_id > 0) {
    $stmt = db()->prepare('SELECT sa.*, m.member_number, m.full_name, st.name AS type_name
                           FROM savings_accounts sa
                           JOIN members m ON sa.member_id = m.id
                           JOIN savings_types st ON sa.savings_type_id = st.id
                           WHERE sa.id = ?');
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    if (!$account) {
        flash('error', 'Rekening tidak ditemukan.');
        redirect('pages/savings/index.php');
    }
    $title = 'Transaksi Simpanan - ' . $account['member_number'];
} else {
    flash('error', 'Pilih rekening terlebih dahulu.');
    redirect('pages/savings/index.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $transaction_type = trim($_POST['transaction_type'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $transaction_date = trim($_POST['transaction_date'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($transaction_type) || !in_array($transaction_type, ['deposit', 'withdrawal'])) {
        $errors[] = 'Jenis transaksi tidak valid.';
    }
    if (empty($amount) || !is_numeric($amount) || (float)$amount <= 0) {
        $errors[] = 'Jumlah harus lebih dari 0.';
    }
    if (empty($transaction_date)) {
        $errors[] = 'Tanggal transaksi wajib diisi.';
    }

    if (empty($errors)) {
        $amount = (float) $amount;
        $balance_before = (float) $account['balance'];

        if ($transaction_type === 'withdrawal') {
            if (!can('savings.withdraw')) {
                $errors[] = 'Anda tidak memiliki izin untuk menarik simpanan.';
            } elseif ($amount > $balance_before) {
                $errors[] = 'Saldo tidak mencukupi untuk penarikan.';
            }
        }

        if (empty($errors)) {
            db()->begin_transaction();
            try {
                $balance_after = $transaction_type === 'deposit' 
                    ? $balance_before + $amount 
                    : $balance_before - $amount;

                $stmt = db()->prepare('INSERT INTO savings_transactions (savings_account_id, transaction_type, amount, balance_before, balance_after, transaction_date, description, processed_by) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $user_id = current_user()['id'];
                $stmt->bind_param('isdddssi', $account_id, $transaction_type, $amount, $balance_before, $balance_after, $transaction_date, $description, $user_id);
                $stmt->execute();

                $stmt = db()->prepare('UPDATE savings_accounts SET balance = ? WHERE id = ?');
                $stmt->bind_param('di', $balance_after, $account_id);
                $stmt->execute();

                db()->commit();
                log_activity('savings_transaction', "Transaksi {$transaction_type} {$amount} pada rekening {$account['account_number']}");
                flash('success', 'Transaksi berhasil dicatat.');
                redirect('pages/savings/index.php');
            } catch (Exception $e) {
                db()->rollback();
                $errors[] = 'Gagal menyimpan transaksi: ' . $e->getMessage();
            }
        }
    }
}

// Load recent transactions
$stmt = db()->prepare('SELECT * FROM savings_transactions WHERE savings_account_id = ? ORDER BY id DESC LIMIT 10');
$stmt->bind_param('i', $account_id);
$stmt->execute();
$transactions = $stmt->get_result();

require __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <h3>Rekening Simpanan</h3>
    <table class="table-info">
        <tr><th>No. Rekening</th><td><?= e($account['account_number']) ?></td></tr>
        <tr><th>Anggota</th><td><?= e($account['member_number']) ?> - <?= e($account['full_name']) ?></td></tr>
        <tr><th>Jenis</th><td><?= e($account['type_name']) ?></td></tr>
        <tr><th>Saldo Saat Ini</th><td><strong><?= rupiah($account['balance']) ?></strong></td></tr>
    </table>
</div>

<div class="card">
    <h3>Transaksi Baru</h3>
    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="form-row">
            <div class="form-group">
                <label for="transaction_type">Jenis Transaksi *</label>
                <select id="transaction_type" name="transaction_type" required>
                    <option value="">-- Pilih --</option>
                    <option value="deposit">Setoran</option>
                    <?php if (can('savings.withdraw')): ?>
                        <option value="withdrawal">Penarikan</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="amount">Jumlah (Rp) *</label>
                <input type="number" id="amount" name="amount" min="1" step="0.01" required>
            </div>
        </div>

        <div class="form-group">
            <label for="transaction_date">Tanggal Transaksi *</label>
            <input type="date" id="transaction_date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
            <label for="description">Keterangan</label>
            <textarea id="description" name="description" rows="2"></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Simpan Transaksi</button>
            <a href="<?= url('pages/savings/index.php') ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<div class="card">
    <h3>Riwayat Transaksi (10 Terakhir)</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Jenis</th>
                <th>Jumlah</th>
                <th>Saldo Sebelum</th>
                <th>Saldo Sesudah</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($transactions->num_rows === 0): ?>
                <tr><td colspan="5" class="text-center">Belum ada transaksi.</td></tr>
            <?php else: ?>
                <?php while ($trx = $transactions->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($trx['transaction_date'])) ?></td>
                        <td>
                            <span class="badge badge-<?= $trx['transaction_type'] === 'deposit' ? 'success' : 'warning' ?>">
                                <?= $trx['transaction_type'] === 'deposit' ? 'Setoran' : 'Penarikan' ?>
                            </span>
                        </td>
                        <td><?= rupiah($trx['amount']) ?></td>
                        <td><?= rupiah($trx['balance_before']) ?></td>
                        <td><strong><?= rupiah($trx['balance_after']) ?></strong></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
