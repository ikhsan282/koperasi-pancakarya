<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('collateral.manage');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$loan_id = isset($_GET['loan_id']) ? (int) $_GET['loan_id'] : 0;
$is_edit = $id > 0;

if ($is_edit) {
    $stmt = db()->prepare('SELECT * FROM loan_collaterals WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $collateral = $stmt->get_result()->fetch_assoc();
    
    if (!$collateral) {
        flash('error', 'Agunan tidak ditemukan.');
        redirect('pages/loans/index.php');
    }
    
    $loan_id = $collateral['loan_id'];
    $title = 'Edit Agunan';
} else {
    if ($loan_id <= 0) {
        flash('error', 'ID pinjaman tidak valid.');
        redirect('pages/loans/index.php');
    }
    
    $collateral = [
        'collateral_type' => 'vehicle',
        'description' => '',
        'estimated_value' => '',
        'notes' => ''
    ];
    $title = 'Tambah Agunan';
}

$stmt = db()->prepare('SELECT l.*, m.full_name FROM loans l JOIN members m ON l.member_id = m.id WHERE l.id = ?');
$stmt->bind_param('i', $loan_id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();

if (!$loan) {
    flash('error', 'Data pinjaman tidak ditemukan.');
    redirect('pages/loans/index.php');
}

if (!in_array($loan['status'], ['pending', 'approved', 'active'])) {
    flash('error', 'Agunan hanya dapat ditambah/edit untuk pinjaman yang sedang berjalan.');
    redirect('pages/collateral/index.php?loan_id=' . $loan_id);
}

$errors = [];

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions" style="margin-bottom: 1.5rem;">
    <a href="<?= url('pages/collateral/index.php?loan_id=' . $loan_id) ?>" class="btn btn-secondary">← Kembali</a>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <strong>Pinjaman:</strong> <?= e($loan['loan_number']) ?> - <?= e($loan['full_name']) ?> - <?= rupiah($loan['amount']) ?>
</div>

<div class="card">
    <h3 style="margin-top: 0;"><?= $title ?></h3>
    
    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <form method="post" action="<?= url('pages/collateral/process.php' . ($is_edit ? '?id=' . $id : '')) ?>" enctype="multipart/form-data" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <?php if (!$is_edit): ?>
            <input type="hidden" name="loan_id" value="<?= $loan_id ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label for="collateral_type">Jenis Agunan *</label>
            <select id="collateral_type" name="collateral_type" required>
                <option value="vehicle" <?= $collateral['collateral_type'] === 'vehicle' ? 'selected' : '' ?>>🚗 Kendaraan (Motor/Mobil)</option>
                <option value="property" <?= $collateral['collateral_type'] === 'property' ? 'selected' : '' ?>>🏠 Properti (Tanah/Rumah)</option>
                <option value="electronics" <?= $collateral['collateral_type'] === 'electronics' ? 'selected' : '' ?>>💻 Elektronik (Laptop/HP)</option>
                <option value="jewelry" <?= $collateral['collateral_type'] === 'jewelry' ? 'selected' : '' ?>>💍 Perhiasan (Emas/Berlian)</option>
                <option value="other" <?= $collateral['collateral_type'] === 'other' ? 'selected' : '' ?>>📦 Lainnya</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="description">Deskripsi Agunan *</label>
            <textarea id="description" name="description" rows="3" required placeholder="Merk, model, tahun, kondisi, ciri-ciri, dll"><?= e($collateral['description'] ?? '') ?></textarea>
            <small>Contoh: Honda Beat 2020, warna hitam, STNK lengkap, kondisi baik</small>
        </div>
        
        <div class="form-group">
            <label for="estimated_value">Nilai Estimasi (Rp) *</label>
            <input type="number" id="estimated_value" name="estimated_value" value="<?= e($collateral['estimated_value'] ?? '') ?>" required min="0" step="1000" placeholder="10000000">
        </div>
        
        <div class="form-group">
            <label for="photo">Foto Agunan <?= $is_edit ? '' : '*' ?></label>
            <input type="file" id="photo" name="photo" accept="image/jpeg,image/jpg,image/png" <?= $is_edit ? '' : 'required' ?>>
            <small>Format: JPG, JPEG, PNG. Maksimal 5MB.</small>
            <?php if ($is_edit && !empty($collateral['photo_path'])): ?>
                <div style="margin-top: 0.5rem;">
                    <a href="<?= url($collateral['photo_path']) ?>" target="_blank">📷 Lihat foto saat ini</a>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="form-group">
            <label for="ownership_proof">Bukti Kepemilikan (BPKB/Sertifikat/Nota) <?= $is_edit ? '' : '*' ?></label>
            <input type="file" id="ownership_proof" name="ownership_proof" accept="image/jpeg,image/jpg,image/png,application/pdf" <?= $is_edit ? '' : 'required' ?>>
            <small>Format: JPG, PNG, PDF. Maksimal 5MB.</small>
            <?php if ($is_edit && !empty($collateral['ownership_proof'])): ?>
                <div style="margin-top: 0.5rem;">
                    <a href="<?= url($collateral['ownership_proof']) ?>" target="_blank">📄 Lihat dokumen saat ini</a>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="form-group">
            <label for="notes">Catatan Tambahan</label>
            <textarea id="notes" name="notes" rows="2" placeholder="Catatan internal (opsional)"><?= e($collateral['notes'] ?? '') ?></textarea>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Update Agunan' : 'Simpan Agunan' ?></button>
            <a href="<?= url('pages/collateral/index.php?loan_id=' . $loan_id) ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
