<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/loan_tools.php';

require_permission('settings.manage');

$title = 'Pengaturan Sistem';
$errors = [];
$success = false;

// Load current settings
$settings_result = db()->query('SELECT `key`, `value`, `description` FROM settings ORDER BY `key` ASC');
$settings = [];
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['key']] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $penalty_rate = (float) ($_POST['penalty_rate_per_day'] ?? 0);
    $admin_fee = (float) ($_POST['default_admin_fee_pct'] ?? 0);
    $min_collateral = (float) ($_POST['min_collateral_loan_amount'] ?? 0);

    // Validation
    if ($penalty_rate < 0 || $penalty_rate > 100) $errors[] = 'Denda per hari harus antara 0-100%.';
    if ($admin_fee < 0 || $admin_fee > 100) $errors[] = 'Biaya admin harus antara 0-100%.';
    if ($min_collateral < 0) $errors[] = 'Minimum agunan harus positif.';

    if (empty($errors)) {
        $db = db();
        $db->begin_transaction();
        try {
            $stmt = $db->prepare('UPDATE settings SET `value` = ? WHERE `key` = ?');
            
            $key = 'penalty_rate_per_day';
            $val = (string) $penalty_rate;
            $stmt->bind_param('ss', $val, $key);
            $stmt->execute();
            
            $key = 'default_admin_fee_pct';
            $val = (string) $admin_fee;
            $stmt->bind_param('ss', $val, $key);
            $stmt->execute();
            
            $key = 'min_collateral_loan_amount';
            $val = (string) $min_collateral;
            $stmt->bind_param('ss', $val, $key);
            $stmt->execute();
            
            $db->commit();
            log_activity('update_settings', 'Memperbarui pengaturan sistem');
            flash('success', 'Pengaturan berhasil diperbarui.');
            redirect('pages/settings/index.php');
        } catch (Throwable $e) {
            $db->rollback();
            $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <h3>Pengaturan Sistem</h3>
    <p style="color: #718096; margin-bottom: 1.5rem;">Kelola parameter operasional koperasi. Hanya Super Admin yang dapat mengubah pengaturan ini.</p>

    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="form-group">
            <label for="penalty_rate_per_day">Denda Keterlambatan (% per hari) *</label>
            <input type="number" id="penalty_rate_per_day" name="penalty_rate_per_day" step="0.01" min="0" max="100" 
                   value="<?= e($_POST['penalty_rate_per_day'] ?? $settings['penalty_rate_per_day']['value'] ?? '0.5') ?>" required>
            <small style="display: block; margin-top: 0.25rem; color: #718096;">
                Persentase dari jumlah angsuran yang dikenakan sebagai denda per hari keterlambatan. 
                Contoh: 0.5% = Rp1.000.000 × 0.5% × 10 hari = Rp50.000
            </small>
        </div>

        <div class="form-group">
            <label for="default_admin_fee_pct">Biaya Admin Default (%) *</label>
            <input type="number" id="default_admin_fee_pct" name="default_admin_fee_pct" step="0.01" min="0" max="100" 
                   value="<?= e($_POST['default_admin_fee_pct'] ?? $settings['default_admin_fee_pct']['value'] ?? '2.0') ?>" required>
            <small style="display: block; margin-top: 0.25rem; color: #718096;">
                Persentase dari pokok pinjaman yang dikenakan sebagai biaya administrasi. 
                Contoh: 2% = Rp10.000.000 × 2% = Rp200.000
            </small>
        </div>

        <div class="form-group">
            <label for="min_collateral_loan_amount">Minimum Pinjaman Wajib Agunan (Rp) *</label>
            <input type="number" id="min_collateral_loan_amount" name="min_collateral_loan_amount" step="100000" min="0" 
                   value="<?= e($_POST['min_collateral_loan_amount'] ?? $settings['min_collateral_loan_amount']['value'] ?? '5000000') ?>" required>
            <small style="display: block; margin-top: 0.25rem; color: #718096;">
                Batas minimum jumlah pinjaman yang mewajibkan anggota menyerahkan agunan/jaminan.
            </small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Simpan perubahan pengaturan?')">Simpan Pengaturan</button>
            <a href="<?= url('pages/dashboard/index.php') ?>" class="btn btn-secondary">Kembali ke Dashboard</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
