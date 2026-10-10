<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sequences.php';

require_permission('savings.create');

$account_id = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;
$preselect_member = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;
$account = null;

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
    $title = 'Setoran / Penarikan Simpanan';
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $member_id = (int) ($_POST['member_id'] ?? 0);
    $savings_type_id = (int) ($_POST['savings_type_id'] ?? 0);
    $transaction_type = trim($_POST['transaction_type'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $transaction_date = trim($_POST['transaction_date'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($account) {
        $member_id = (int) $account['member_id'];
        $savings_type_id = (int) $account['savings_type_id'];
    }

    if (!in_array($transaction_type, ['deposit', 'withdrawal'], true)) {
        $errors[] = 'Jenis transaksi tidak valid.';
    }
    if ($transaction_type === 'withdrawal' && !can('savings.withdraw')) {
        $errors[] = 'Anda tidak memiliki izin untuk menarik simpanan.';
    }
    if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        $errors[] = 'Jumlah harus lebih dari 0.';
    }
    if ($transaction_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transaction_date)) {
        $errors[] = 'Tanggal transaksi wajib diisi.';
    }
    if ($member_id <= 0 || $savings_type_id <= 0) {
        $errors[] = 'Pilih anggota dan jenis simpanan.';
    }

    if (empty($errors)) {
        $amount = round((float) $amount, 2);
        $cashbank_account_id = (int) ($_POST['cashbank_account_id'] ?? 0);
        
        require_once __DIR__ . '/../../includes/cashbank_helpers.php';

        db()->begin_transaction();
        try {
            // Find existing account, or create one for this member+type
            if ($account) {
                $acc_id = (int) $account['id'];
            } else {
                $stmt = db()->prepare('SELECT id FROM savings_accounts WHERE member_id = ? AND savings_type_id = ? FOR UPDATE');
                $stmt->bind_param('ii', $member_id, $savings_type_id);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                if ($existing) {
                    $acc_id = (int) $existing['id'];
                } else {
                    $stmt = db()->prepare('SELECT m.member_number FROM members m WHERE m.id = ?');
                    $stmt->bind_param('i', $member_id);
                    $stmt->execute();
                    $meta = $stmt->get_result()->fetch_assoc();
                    if (!$meta) {
                        throw new Exception('Anggota tidak ditemukan.');
                    }
                    // Generate account number atomically
                    $account_number = generate_savings_account_number($meta['member_number'], $savings_type_id);

                    $stmt = db()->prepare('INSERT INTO savings_accounts (member_id, savings_type_id, account_number, balance, status, opened_date) VALUES (?, ?, ?, 0, "active", ?)');
                    $stmt->bind_param('iiss', $member_id, $savings_type_id, $account_number, $transaction_date);
                    $stmt->execute();
                    $acc_id = db()->insert_id;
                }
            }

            // Lock the account row and read balance inside the transaction
            $stmt = db()->prepare('SELECT sa.balance, sa.status, m.member_number, m.full_name, sa.account_number
                                   FROM savings_accounts sa
                                   JOIN members m ON sa.member_id = m.id
                                   WHERE sa.id = ? FOR UPDATE');
            $stmt->bind_param('i', $acc_id);
            $stmt->execute();
            $locked = $stmt->get_result()->fetch_assoc();
            if (!$locked) {
                throw new Exception('Rekening tidak ditemukan.');
            }
            if ($locked['status'] !== 'active') {
                throw new Exception('Rekening sudah ditutup, tidak bisa bertransaksi.');
            }

            $balance_before = (float) $locked['balance'];
            if ($transaction_type === 'withdrawal' && $amount > $balance_before) {
                throw new Exception('Jumlah penarikan melebihi saldo rekening (' . rupiah($balance_before) . ').');
            }
            $balance_after = $transaction_type === 'deposit' ? $balance_before + $amount : $balance_before - $amount;

            $stmt = db()->prepare('INSERT INTO savings_transactions (savings_account_id, transaction_type, amount, balance_before, balance_after, transaction_date, description, processed_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $user_id = current_user()['id'];
            $stmt->bind_param('isdddssi', $acc_id, $transaction_type, $amount, $balance_before, $balance_after, $transaction_date, $description, $user_id);
            $stmt->execute();
            $trx_id = db()->insert_id;

            $stmt = db()->prepare('UPDATE savings_accounts SET balance = ? WHERE id = ?');
            $stmt->bind_param('di', $balance_after, $acc_id);
            $stmt->execute();
            
            // Auto-post to cash/bank
            if ($cashbank_account_id > 0) {
                $cb_type = $transaction_type === 'deposit' ? 'debit' : 'credit';
                $cb_ref = $transaction_type === 'deposit' ? 'savings_deposit' : 'savings_withdrawal';
                post_cashbank_transaction(
                    $cashbank_account_id,
                    $cb_type,
                    $amount,
                    $cb_ref,
                    $trx_id,
                    ucfirst($transaction_type) . " simpanan {$locked['account_number']} - {$locked['full_name']}",
                    $transaction_date
                );
            }

            db()->commit();
            log_activity('savings_transaction', ucfirst($transaction_type) . ' ' . rupiah($amount) . ' pada rekening ' . $locked['account_number']);
            flash('success', 'Transaksi berhasil dicatat.');
            redirect('pages/savings/detail.php?id=' . $acc_id);
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Gagal menyimpan transaksi: ' . $e->getMessage();
        }
    }
}

$members = db()->query('SELECT id, member_number, full_name FROM members WHERE status = "active" ORDER BY full_name ASC');
$savings_types = db()->query('SELECT * FROM savings_types WHERE is_active = 1 ORDER BY id');

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($account): ?>
<div class="card">
    <h3>Rekening Simpanan</h3>
    <table class="table-info">
        <tr><th>No. Rekening</th><td><?= e($account['account_number']) ?></td></tr>
        <tr><th>Anggota</th><td><?= e($account['member_number']) ?> - <?= e($account['full_name']) ?></td></tr>
        <tr><th>Jenis</th><td><?= e($account['type_name']) ?></td></tr>
        <tr><th>Saldo Saat Ini</th><td><strong><?= rupiah($account['balance']) ?></strong></td></tr>
    </table>
</div>
<?php endif; ?>

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

        <?php if (!$account): ?>
        <div class="form-row">
            <div class="form-group">
                <label for="member_id">Anggota *</label>
                <select id="member_id" name="member_id" class="ts-select" required>
                    <option value="">-- Pilih Anggota --</option>
                    <?php while ($m = $members->fetch_assoc()): ?>
                        <option value="<?= $m['id'] ?>" <?= (old('member_id') ?: $preselect_member) == $m['id'] ? 'selected' : '' ?>>
                            <?= e($m['member_number']) ?> - <?= e($m['full_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="savings_type_id">Jenis Simpanan *</label>
                <select id="savings_type_id" name="savings_type_id" class="ts-select" required>
                    <option value="">-- Pilih Jenis --</option>
                    <?php while ($t = $savings_types->fetch_assoc()): ?>
                        <option value="<?= $t['id'] ?>" <?= old('savings_type_id') == $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>
        <p><small style="color: #666;">Rekening akan otomatis dibuat jika anggota belum memilikinya untuk jenis simpanan ini.</small></p>
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label for="transaction_type">Jenis Transaksi *</label>
                <select id="transaction_type" name="transaction_type" required>
                    <option value="">-- Pilih --</option>
                    <option value="deposit" <?= old('transaction_type') === 'deposit' ? 'selected' : '' ?>>Setoran</option>
                    <?php if (can('savings.withdraw')): ?>
                        <option value="withdrawal" <?= old('transaction_type') === 'withdrawal' ? 'selected' : '' ?>>Penarikan</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="amount">Jumlah (Rp) *</label>
                <input type="number" id="amount" name="amount" min="1" step="0.01" value="<?= old('amount') ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="transaction_date">Tanggal Transaksi *</label>
                <input type="date" id="transaction_date" name="transaction_date" value="<?= old('transaction_date', date('Y-m-d')) ?>" required>
            </div>
            <div class="form-group">
                <label for="description">Keterangan</label>
                <input type="text" id="description" name="description" value="<?= old('description') ?>">
            </div>
        </div>
        
        <?php if (can('cashbank.manage')): ?>
        <div class="form-group">
            <label for="cashbank_account_id">Catat ke Akun Kas/Bank</label>
            <select id="cashbank_account_id" name="cashbank_account_id">
                <option value="">-- Tidak dicatat --</option>
                <?php
                $cb_accounts = db()->query('SELECT id, account_name, balance FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name');
                while ($cba = $cb_accounts->fetch_assoc()): ?>
                    <option value="<?= $cba['id'] ?>"><?= e($cba['account_name']) ?> - Saldo: <?= rupiah($cba['balance']) ?></option>
                <?php endwhile; ?>
            </select>
            <small style="display: block; margin-top: 0.25rem; color: #666;">Opsional: otomatis catat penerimaan/pengeluaran ke akun kas/bank</small>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Simpan Transaksi</button>
            <a href="<?= url($account ? 'pages/savings/detail.php?id=' . $account['id'] : 'pages/savings/index.php') ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<?php if ($account): ?>
<div class="card">
    <h3>Riwayat Transaksi (10 Terakhir)</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Jenis</th>
                <th>Jumlah</th>
                <th>Saldo Sesudah</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $stmt = db()->prepare('SELECT * FROM savings_transactions WHERE savings_account_id = ? ORDER BY id DESC LIMIT 10');
            $stmt->bind_param('i', $account_id);
            $stmt->execute();
            $transactions = $stmt->get_result();
            if ($transactions->num_rows === 0): ?>
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
                        <td><strong><?= rupiah($trx['balance_after']) ?></strong></td>
                        <td><a href="<?= url('pages/savings/print.php?account_id=' . $account_id . '&trx_id=' . $trx['id']) ?>" target="_blank" class="btn btn-sm btn-text">Bukti</a></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
