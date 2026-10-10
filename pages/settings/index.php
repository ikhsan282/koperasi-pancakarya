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
    
    // WhatsApp settings
    $wa_enabled = isset($_POST['whatsapp_enabled']) ? '1' : '0';
    $wa_api_url = trim($_POST['whatsapp_api_url'] ?? '');
    $wa_api_key = trim($_POST['whatsapp_api_key'] ?? '');
    
    // Backup settings
    $backup_path = trim($_POST['backup_path'] ?? '/opt/data/backups');
    $backup_retention = (int) ($_POST['backup_retention_days'] ?? 30);
    $auto_backup = isset($_POST['auto_backup_enabled']) ? '1' : '0';

    // Validation
    if ($penalty_rate < 0 || $penalty_rate > 100) $errors[] = 'Denda per hari harus antara 0-100%.';
    if ($admin_fee < 0 || $admin_fee > 100) $errors[] = 'Biaya admin harus antara 0-100%.';
    if ($min_collateral < 0) $errors[] = 'Minimum agunan harus positif.';
    
    if ($wa_enabled === '1' && (empty($wa_api_url) || empty($wa_api_key))) {
        $errors[] = 'API URL dan API Key wajib diisi jika WhatsApp diaktifkan.';
    }
    
    if (empty($backup_path)) $errors[] = 'Path backup tidak boleh kosong.';
    if ($backup_retention < 1 || $backup_retention > 365) $errors[] = 'Retensi backup harus antara 1-365 hari.';

    if (empty($errors)) {
        $db = db();
        $db->begin_transaction();
        try {
            $stmt = $db->prepare('UPDATE settings SET `value` = ? WHERE `key` = ?');
            
            $updates = [
                'penalty_rate_per_day' => (string) $penalty_rate,
                'default_admin_fee_pct' => (string) $admin_fee,
                'min_collateral_loan_amount' => (string) $min_collateral,
                'whatsapp_enabled' => $wa_enabled,
                'whatsapp_api_url' => $wa_api_url,
                'whatsapp_api_key' => $wa_api_key,
                'backup_path' => $backup_path,
                'backup_retention_days' => (string) $backup_retention,
                'auto_backup_enabled' => $auto_backup,
            ];
            
            foreach ($updates as $key => $val) {
                $stmt->bind_param('ss', $val, $key);
                $stmt->execute();
            }
            
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

<div class="card" style="max-width: 900px; margin: 0 auto;">
    <h3>Pengaturan Sistem</h3>
    <p style="color: #718096; margin-bottom: 1.5rem;">Kelola parameter operasional koperasi. Hanya Super Admin yang dapat mengubah pengaturan ini.</p>

    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <h4 style="margin: 1.5rem 0 1rem 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem;">
            Pengaturan Pinjaman
        </h4>

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

        <h4 style="margin: 2rem 0 1rem 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem;">
            Integrasi WhatsApp
        </h4>
        <p style="color: #718096; margin-bottom: 1rem; font-size: 0.875rem;">
            Konfigurasi gateway WhatsApp untuk notifikasi otomatis (mendukung Fonnte, Wablas, dll).
        </p>

        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" id="whatsapp_enabled" name="whatsapp_enabled" value="1"
                       <?= (($_POST['whatsapp_enabled'] ?? $settings['whatsapp_enabled']['value'] ?? '0') === '1') ? 'checked' : '' ?>>
                <span style="margin-left: 0.5rem;">Aktifkan Notifikasi WhatsApp</span>
            </label>
        </div>

        <div class="form-group">
            <label for="whatsapp_api_url">WhatsApp API URL</label>
            <input type="text" id="whatsapp_api_url" name="whatsapp_api_url" placeholder="https://api.fonnte.com/send"
                   value="<?= e($_POST['whatsapp_api_url'] ?? $settings['whatsapp_api_url']['value'] ?? '') ?>">
            <small style="display: block; margin-top: 0.25rem; color: #718096;">
                URL endpoint API gateway WhatsApp (Fonnte: https://api.fonnte.com/send)
            </small>
        </div>

        <div class="form-group">
            <label for="whatsapp_api_key">WhatsApp API Key</label>
            <input type="password" id="whatsapp_api_key" name="whatsapp_api_key" placeholder="Masukkan API key/token"
                   value="<?= e($_POST['whatsapp_api_key'] ?? $settings['whatsapp_api_key']['value'] ?? '') ?>">
            <small style="display: block; margin-top: 0.25rem; color: #718096;">
                Token autentikasi dari provider WhatsApp gateway
            </small>
        </div>

        <h4 style="margin: 2rem 0 1rem 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem;">
            Backup & Restore
        </h4>
        <p style="color: #718096; margin-bottom: 1rem; font-size: 0.875rem;">
            Konfigurasi backup otomatis database. Lihat <a href="<?= url('pages/backup/index.php') ?>" style="color: #3182ce;">Kelola Backup</a> untuk backup manual.
        </p>

        <div class="form-group">
            <label for="backup_path">Lokasi Penyimpanan Backup *</label>
            <input type="text" id="backup_path" name="backup_path" 
                   value="<?= e($_POST['backup_path'] ?? $settings['backup_path']['value'] ?? '/opt/data/backups') ?>" required>
            <small style="display: block; margin-top: 0.25rem; color: #718096;">
                Path direktori untuk menyimpan file backup. Pastikan direktori dapat ditulis oleh web server.
            </small>
        </div>

        <div class="form-group">
            <label for="backup_retention_days">Retensi Backup (hari) *</label>
            <input type="number" id="backup_retention_days" name="backup_retention_days" min="1" max="365" 
                   value="<?= e($_POST['backup_retention_days'] ?? $settings['backup_retention_days']['value'] ?? '30') ?>" required>
            <small style="display: block; margin-top: 0.25rem; color: #718096;">
                Backup yang lebih lama dari nilai ini akan dihapus otomatis (1-365 hari).
            </small>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" id="auto_backup_enabled" name="auto_backup_enabled" value="1"
                       <?= (($_POST['auto_backup_enabled'] ?? $settings['auto_backup_enabled']['value'] ?? '0') === '1') ? 'checked' : '' ?>>
                <span style="margin-left: 0.5rem;">Aktifkan Backup Otomatis Harian</span>
            </label>
            <small style="display: block; margin-top: 0.25rem; color: #718096;">
                Jalankan cron: <code>0 2 * * * php <?= realpath(__DIR__ . '/../../cron/daily_backup.php') ?></code>
            </small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Simpan perubahan pengaturan?')">Simpan Pengaturan</button>
            <a href="<?= url('pages/dashboard/index.php') ?>" class="btn btn-secondary">Kembali ke Dashboard</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
