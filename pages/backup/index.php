<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup_helpers.php';

require_permission('backup.create');

$title = 'Backup Database';
$errors = [];
$success = '';

// Handle backup creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    verify_csrf();
    
    $user = current_user();
    $result = create_backup($user['id'], 'manual');
    
    if ($result['success']) {
        log_activity('create_backup', 'Membuat backup manual: ' . basename($result['file']));
        flash('success', 'Backup berhasil dibuat: ' . basename($result['file']));
        redirect('pages/backup/index.php');
    } else {
        $errors[] = $result['error'];
    }
}

// Handle backup deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    verify_csrf();
    
    $backup_id = (int) ($_POST['backup_id'] ?? 0);
    
    if ($backup_id > 0) {
        $stmt = db()->prepare("SELECT backup_file FROM backup_logs WHERE id = ?");
        $stmt->bind_param('i', $backup_id);
        $stmt->execute();
        $backup = $stmt->get_result()->fetch_assoc();
        
        if ($backup) {
            // Get backup path
            $stmt = db()->prepare("SELECT value FROM settings WHERE `key` = 'backup_path'");
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $backup_path = $result['value'] ?? '/opt/data/backups';
            
            $full_path = $backup_path . '/' . $backup['backup_file'];
            
            // Delete file
            if (file_exists($full_path)) {
                unlink($full_path);
            }
            
            // Delete log entry
            $stmt = db()->prepare("DELETE FROM backup_logs WHERE id = ?");
            $stmt->bind_param('i', $backup_id);
            $stmt->execute();
            
            log_activity('delete_backup', 'Menghapus backup: ' . $backup['backup_file']);
            flash('success', 'Backup berhasil dihapus.');
            redirect('pages/backup/index.php');
        }
    }
}

// Handle download
if (isset($_GET['download'])) {
    $backup_id = (int) $_GET['download'];
    
    $stmt = db()->prepare("SELECT backup_file FROM backup_logs WHERE id = ?");
    $stmt->bind_param('i', $backup_id);
    $stmt->execute();
    $backup = $stmt->get_result()->fetch_assoc();
    
    if ($backup) {
        $stmt = db()->prepare("SELECT value FROM settings WHERE `key` = 'backup_path'");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $backup_path = $result['value'] ?? '/opt/data/backups';
        
        $full_path = $backup_path . '/' . $backup['backup_file'];
        
        if (file_exists($full_path)) {
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . basename($full_path) . '"');
            header('Content-Length: ' . filesize($full_path));
            readfile($full_path);
            exit;
        }
    }
    
    flash('error', 'File backup tidak ditemukan.');
    redirect('pages/backup/index.php');
}

// Load backups
$stmt = db()->query("
    SELECT 
        bl.*,
        u.full_name as creator_name
    FROM backup_logs bl
    LEFT JOIN users u ON u.id = bl.created_by
    ORDER BY bl.created_at DESC
    LIMIT 50
");
$backups = $stmt->fetch_all(MYSQLI_ASSOC);

// Load settings
$stmt = db()->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('backup_path', 'backup_retention_days', 'auto_backup_enabled')");
$settings = [];
while ($row = $stmt->fetch_assoc()) {
    $settings[$row['key']] = $row['value'];
}

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="page-actions">
    <h2>Backup Database</h2>
    <div>
        <form method="post" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Buat backup database sekarang?')">
                + Buat Backup Manual
            </button>
        </form>
        <?php if (can('backup.restore')): ?>
            <a href="<?= url('pages/backup/restore.php') ?>" class="btn btn-danger">Restore Database</a>
        <?php endif; ?>
        <a href="<?= url('pages/settings/index.php') ?>" class="btn btn-secondary">Pengaturan Backup</a>
    </div>
</div>

<div class="card">
    <h3>Informasi Backup</h3>
    <table style="margin-bottom: 1rem;">
        <tr>
            <td style="padding: 0.5rem; font-weight: 600;">Lokasi Backup:</td>
            <td style="padding: 0.5rem;"><?= e($settings['backup_path'] ?? '/opt/data/backups') ?></td>
        </tr>
        <tr>
            <td style="padding: 0.5rem; font-weight: 600;">Retensi Backup:</td>
            <td style="padding: 0.5rem;"><?= e($settings['backup_retention_days'] ?? '30') ?> hari</td>
        </tr>
        <tr>
            <td style="padding: 0.5rem; font-weight: 600;">Auto Backup:</td>
            <td style="padding: 0.5rem;">
                <?php if (($settings['auto_backup_enabled'] ?? '0') === '1'): ?>
                    <span style="color: #38a169;">✓ Aktif</span>
                <?php else: ?>
                    <span style="color: #e53e3e;">✗ Tidak Aktif</span>
                <?php endif; ?>
            </td>
        </tr>
    </table>
</div>

<div class="card">
    <h3>Riwayat Backup</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Waktu</th>
                <th>File</th>
                <th>Ukuran</th>
                <th>Tipe</th>
                <th>Status</th>
                <th>Dibuat Oleh</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($backups)): ?>
                <tr><td colspan="7" class="text-center">Belum ada backup.</td></tr>
            <?php else: ?>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($backup['created_at'])) ?></td>
                        <td><code><?= e($backup['backup_file']) ?></code></td>
                        <td>
                            <?php
                            $size = (float) $backup['file_size'];
                            if ($size > 1024 * 1024) {
                                echo number_format($size / (1024 * 1024), 2) . ' MB';
                            } elseif ($size > 1024) {
                                echo number_format($size / 1024, 2) . ' KB';
                            } else {
                                echo $size . ' B';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($backup['backup_type'] === 'manual'): ?>
                                <span class="badge" style="background: #3182ce;">Manual</span>
                            <?php else: ?>
                                <span class="badge" style="background: #805ad5;">Auto</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($backup['status'] === 'success'): ?>
                                <span style="color: #38a169;">✓ Sukses</span>
                            <?php else: ?>
                                <span style="color: #e53e3e;" title="<?= e($backup['error_message']) ?>">✗ Gagal</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($backup['creator_name'] ?? '-') ?></td>
                        <td>
                            <?php if ($backup['status'] === 'success'): ?>
                                <a href="?download=<?= $backup['id'] ?>" class="btn btn-sm btn-secondary">Download</a>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="backup_id" value="<?= $backup['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Hapus backup ini?')">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
