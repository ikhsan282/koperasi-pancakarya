<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.approve');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$is_edit = $id > 0;

if ($is_edit) {
    $stmt = db()->prepare('SELECT * FROM loan_products WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    if (!$product) {
        flash('error', 'Produk tidak ditemukan.');
        redirect('pages/loan-products/index.php');
    }
    $title = 'Edit Produk Pinjaman';
} else {
    $product = null;
    $title = 'Tambah Produk Pinjaman';
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $interest_rate = (float) ($_POST['interest_rate'] ?? 0);
    $max_tenor_months = (int) ($_POST['max_tenor_months'] ?? 0);
    $min_amount = (float) ($_POST['min_amount'] ?? 0);
    $max_amount = (float) ($_POST['max_amount'] ?? 0);
    $approval_levels = (int) ($_POST['approval_levels'] ?? 1);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Nama produk wajib diisi.';
    if ($interest_rate < 0) $errors[] = 'Bunga tidak valid.';
    if ($max_tenor_months <= 0) $errors[] = 'Tenor maksimal tidak valid.';
    if ($min_amount <= 0) $errors[] = 'Jumlah minimum tidak valid.';
    if ($max_amount <= 0) $errors[] = 'Jumlah maksimum tidak valid.';
    if ($min_amount > $max_amount) $errors[] = 'Jumlah minimum tidak boleh lebih besar dari maksimum.';

    if (empty($errors)) {
        if ($is_edit) {
            $stmt = db()->prepare('UPDATE loan_products SET name=?, interest_rate=?, max_tenor_months=?, min_amount=?, max_amount=?, approval_levels=?, is_active=? WHERE id=?');
            $stmt->bind_param('sdiddiii', $name, $interest_rate, $max_tenor_months, $min_amount, $max_amount, $approval_levels, $is_active, $id);
            $stmt->execute();
            log_activity('edit_loan_product', "Edit produk pinjaman: {$name}");
            flash('success', 'Produk pinjaman berhasil diperbarui.');
        } else {
            $stmt = db()->prepare('INSERT INTO loan_products (name, interest_rate, max_tenor_months, min_amount, max_amount, approval_levels, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sdiddii', $name, $interest_rate, $max_tenor_months, $min_amount, $max_amount, $approval_levels, $is_active);
            $stmt->execute();
            log_activity('create_loan_product', "Tambah produk pinjaman: {$name}");
            flash('success', 'Produk pinjaman berhasil ditambahkan.');
        }
        redirect('pages/loan-products/index.php');
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="form-group">
            <label for="name">Nama Produk *</label>
            <input type="text" id="name" name="name" value="<?= e($product['name'] ?? '') ?>" required>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="interest_rate">Bunga per Bulan (%) *</label>
                <input type="number" id="interest_rate" name="interest_rate" step="0.01" min="0" value="<?= $product['interest_rate'] ?? '1.5' ?>" required>
                <small>Bunga FLAT per bulan</small>
            </div>
            <div class="form-group">
                <label for="max_tenor_months">Tenor Maksimal (Bulan) *</label>
                <input type="number" id="max_tenor_months" name="max_tenor_months" min="1" value="<?= $product['max_tenor_months'] ?? '12' ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="min_amount">Jumlah Minimum (Rp) *</label>
                <input type="number" id="min_amount" name="min_amount" step="10000" min="0" value="<?= $product['min_amount'] ?? '1000000' ?>" required>
            </div>
            <div class="form-group">
                <label for="max_amount">Jumlah Maksimum (Rp) *</label>
                <input type="number" id="max_amount" name="max_amount" step="10000" min="0" value="<?= $product['max_amount'] ?? '20000000' ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label for="approval_levels">Tingkat Persetujuan *</label>
            <select id="approval_levels" name="approval_levels" required>
                <option value="1" <?= ($product['approval_levels'] ?? 1) == 1 ? 'selected' : '' ?>>Single Approval (1 Level)</option>
                <option value="2" <?= ($product['approval_levels'] ?? 1) == 2 ? 'selected' : '' ?>>Dual Approval (2 Level)</option>
            </select>
            <small>Single: langsung approve. Dual: butuh 2 persetujuan (Admin + Super Admin)</small>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="is_active" value="1" <?= ($product['is_active'] ?? 1) ? 'checked' : '' ?>>
                Produk Aktif
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Simpan Perubahan' : 'Tambah Produk' ?></button>
            <a href="<?= url('pages/loan-products/index.php') ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
