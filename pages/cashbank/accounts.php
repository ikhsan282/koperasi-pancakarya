<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('cashbank.view');

$title = 'Akun Kas & Bank';
$errors = [];
$edit_id = 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can('cashbank.manage')) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $account_name = trim($_POST['account_name'] ?? '');
        $account_type = trim($_POST['account_type'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($account_name === '') $errors[] = 'Nama akun wajib diisi.';
        if (!in_array($account_type, ['cash', 'bank'])) $errors[] = 'Jenis akun tidak valid.';
        if ($account_type === 'bank' && $bank_name === '') $errors[] = 'Nama bank wajib diisi untuk akun bank.';
        
        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    $stmt = db()->prepare('INSERT INTO cash_bank_accounts (account_name, account_type, bank_name, account_number, is_active) VALUES (?, ?, ?, ?, ?)');
                    $stmt->bind_param('ssssi', $account_name, $account_type, $bank_name, $account_number, $is_active);
                    $stmt->execute();
                    log_activity('create_cashbank_account', "Membuat akun {$account_name}");
                    flash('success', 'Akun berhasil ditambahkan.');
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = db()->prepare('UPDATE cash_bank_accounts SET account_name = ?, account_type = ?, bank_name = ?, account_number = ?, is_active = ? WHERE id = ?');
                    $stmt->bind_param('ssssii', $account_name, $account_type, $bank_name, $account_number, $is_active, $id);
                    $stmt->execute();
                    log_activity('update_cashbank_account', "Mengubah akun {$account_name}");
                    flash('success', 'Akun berhasil diperbarui.');
                }
                redirect('pages/cashbank/accounts.php');
            } catch (Exception $e) {
                $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT account_name, balance FROM cash_bank_accounts WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        
        if (!$account) {
            $errors[] = 'Akun tidak ditemukan.';
        } elseif ((float) $account['balance'] != 0) {
            $errors[] = 'Tidak dapat menghapus akun dengan saldo tidak nol (' . rupiah($account['balance']) . ').';
        } else {
            try {
                $stmt = db()->prepare('DELETE FROM cash_bank_accounts WHERE id = ? AND balance = 0');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                log_activity('delete_cashbank_account', "Menghapus akun {$account['account_name']}");
                flash('success', 'Akun berhasil dihapus.');
                redirect('pages/cashbank/accounts.php');
            } catch (Exception $e) {
                $errors[] = 'Gagal menghapus: ' . $e->getMessage();
            }
        }
    }
}

// Get edit data
if (isset($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $stmt = db()->prepare('SELECT * FROM cash_bank_accounts WHERE id = ?');
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_data = $stmt->get_result()->fetch_assoc();
    if (!$edit_data) {
        flash('error', 'Akun tidak ditemukan.');
        redirect('pages/cashbank/accounts.php');
    }
}

// Get all accounts
$accounts = db()->query('SELECT * FROM cash_bank_accounts ORDER BY id ASC');
$total_balance = db()->query('SELECT COALESCE(SUM(balance), 0) AS total FROM cash_bank_accounts WHERE is_active = 1')->fetch_assoc()['total'];

require __DIR__ . '/../../includes/header.php';
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-icon">💰</div>
        <div class="stat-info">
            <div class="stat-label">Total Saldo Kas & Bank</div>
            <div class="stat-value"><?= rupiah($total_balance) ?></div>
        </div>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (can('cashbank.manage')): ?>
<div class="card">
    <h3><?= $edit_id ? 'Edit Akun' : 'Tambah Akun Baru' ?></h3>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="<?= $edit_id ? 'update' : 'create' ?>">
        <?php if ($edit_id): ?>
            <input type="hidden" name="id" value="<?= $edit_id ?>">
        <?php endif; ?>
        
        <div class="form-row">
            <div class="form-group">
                <label for="account_name">Nama Akun *</label>
                <input type="text" id="account_name" name="account_name" value="<?= e($edit_data['account_name'] ?? old('account_name')) ?>" required>
            </div>
            <div class="form-group">
                <label for="account_type">Jenis *</label>
                <select id="account_type" name="account_type" required>
                    <option value="cash" <?= ($edit_data['account_type'] ?? old('account_type')) === 'cash' ? 'selected' : '' ?>>Kas</option>
                    <option value="bank" <?= ($edit_data['account_type'] ?? old('account_type')) === 'bank' ? 'selected' : '' ?>>Bank</option>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="bank_name">Nama Bank</label>
                <input type="text" id="bank_name" name="bank_name" value="<?= e($edit_data['bank_name'] ?? old('bank_name')) ?>" placeholder="Kosongkan jika jenis Kas">
            </div>
            <div class="form-group">
                <label for="account_number">No. Rekening</label>
                <input type="text" id="account_number" name="account_number" value="<?= e($edit_data['account_number'] ?? old('account_number')) ?>">
            </div>
        </div>
        
        <div class="form-group">
            <label>
                <input type="checkbox" name="is_active" value="1" <?= ($edit_data['is_active'] ?? 1) ? 'checked' : '' ?>>
                Aktif
            </label>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $edit_id ? 'Simpan Perubahan' : 'Tambah Akun' ?></button>
            <?php if ($edit_id): ?>
                <a href="<?= url('pages/cashbank/accounts.php') ?>" class="btn btn-secondary">Batal</a>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h3>Daftar Akun</h3>
        <div>
            <a href="<?= url('pages/cashbank/transactions.php') ?>" class="btn btn-secondary">Lihat Transaksi</a>
            <?php if (can('cashbank.manage')): ?>
                <a href="<?= url('pages/cashbank/form.php') ?>" class="btn btn-primary">+ Transaksi Manual</a>
            <?php endif; ?>
        </div>
    </div>
    
    <table class="table">
        <thead>
            <tr>
                <th>Nama Akun</th>
                <th>Jenis</th>
                <th>Bank / No. Rek</th>
                <th>Saldo</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($accounts->num_rows === 0): ?>
                <tr><td colspan="6" class="text-center">Belum ada akun kas/bank.</td></tr>
            <?php else: ?>
                <?php while ($row = $accounts->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row['account_name']) ?></strong></td>
                        <td><?= $row['account_type'] === 'cash' ? 'Kas' : 'Bank' ?></td>
                        <td>
                            <?php if ($row['account_type'] === 'bank'): ?>
                                <?= e($row['bank_name']) ?><br>
                                <small style="color: #666;"><?= e($row['account_number']) ?></small>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><strong><?= rupiah($row['balance']) ?></strong></td>
                        <td>
                            <span class="badge badge-<?= $row['is_active'] ? 'success' : 'secondary' ?>">
                                <?= $row['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= url('pages/cashbank/transactions.php?account_id=' . $row['id']) ?>" class="btn btn-sm btn-text">Transaksi</a>
                            <?php if (can('cashbank.manage')): ?>
                                <a href="<?= url('pages/cashbank/accounts.php?edit=' . $row['id']) ?>" class="btn btn-sm btn-text">Edit</a>
                                <?php if ((float) $row['balance'] == 0): ?>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-text" style="color: #c53030;" onclick="return confirm('Hapus akun ini?')">Hapus</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
