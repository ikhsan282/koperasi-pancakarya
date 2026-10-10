<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup_helpers.php';

require_permission('backup.restore');

$title = 'Restore Database';
$errors = [];
$preview_data = null;
$uploaded_file = null;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    verify_csrf();
    
    $file = $_FILES['backup_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmp_path = $file['tmp_name'];
        $filename = basename($file['name']);
        
        // Validate file extension
        if (!preg_match('/\.(sql|sql\.gz|gz)$/i', $filename)) {
            $errors[] = 'File harus berformat .sql atau .sql.gz';
        } else {
            // Move to temp location
            $temp_dir = sys_get_temp_dir();
            $uploaded_file = $temp_dir . '/restore_' . time() . '_' . $filename;
            move_uploaded_file($tmp_path, $uploaded_file);
            
            // Get preview
            $result = preview_backup($uploaded_file, 50);
            if ($result['success']) {
                $preview_data = $result['preview'];
            } else {
                $errors[] = $result['error'];
            }
        }
    } else {
        $errors[] = 'Gagal upload file: ' . $file['error'];
    }
}

// Handle restore confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_restore'])) {
    verify_csrf();
    
    $file_path = $_POST['file_path'] ?? '';
    $confirmation = $_POST['confirmation'] ?? '';
    
    if ($confirmation !== 'RESTORE') {
        $errors[] = 'Konfirmasi tidak sesuai. Ketik: RESTORE';
    } elseif (!file_exists($file_path)) {
        $errors[] = 'File tidak ditemukan.';
    } else {
        $user = current_user();
        $result = restore_backup($file_path, $user['id']);
        
        if ($result['success']) {
            // Clean up uploaded file
            @unlink($file_path);
            
            log_activity('restore_backup', 'Restore database dari ' . basename($file_path));
            flash('success', 'Database berhasil di-restore. Silakan login kembali.');
            
            // Force logout after restore
            session_destroy();
            redirect('pages/auth/login.php');
        } else {
            $errors[] = $result['error'];
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<style>
.danger-zone {
    border: 3px solid #e53e3e;
    background: #fff5f5;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}
.danger-zone h3 {
    color: #c53030;
    margin-top: 0;
}
.danger-zone ul {
    color: #742a2a;
    margin: 1rem 0;
}
.preview-box {
    background: #f7fafc;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 1rem;
    font-family: monospace;
    font-size: 0.875rem;
    max-height: 400px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-all;
}
</style>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="page-actions">
    <h2>Restore Database</h2>
    <a href="<?= url('pages/backup/index.php') ?>" class="btn btn-secondary">← Kembali ke Backup</a>
</div>

<div class="danger-zone">
    <h3>⚠️ PERINGATAN PENTING</h3>
    <p><strong>Restore database akan:</strong></p>
    <ul>
        <li>Menghapus SEMUA data yang ada saat ini</li>
        <li>Mengganti dengan data dari file backup</li>
        <li>Tidak dapat dibatalkan (irreversible)</li>
        <li>Semua user akan logout otomatis</li>
    </ul>
    <p style="margin-bottom: 0;"><strong>Pastikan Anda telah membuat backup terbaru sebelum restore!</strong></p>
</div>

<?php if ($preview_data === null): ?>
    <!-- Step 1: Upload backup file -->
    <div class="card">
        <h3>Upload File Backup</h3>
        <p style="color: #718096; margin-bottom: 1.5rem;">
            Upload file backup (.sql atau .sql.gz) untuk melihat preview dan melakukan restore.
        </p>
        
        <form method="post" enctype="multipart/form-data" class="form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            
            <div class="form-group">
                <label for="backup_file">File Backup *</label>
                <input type="file" id="backup_file" name="backup_file" accept=".sql,.sql.gz,.gz" required>
                <small style="display: block; margin-top: 0.25rem; color: #718096;">
                    Format yang didukung: .sql, .sql.gz
                </small>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Upload & Preview</button>
                <a href="<?= url('pages/backup/index.php') ?>" class="btn btn-secondary">Batal</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <!-- Step 2: Preview and confirm restore -->
    <div class="card">
        <h3>Preview Backup File</h3>
        <p style="color: #718096; margin-bottom: 1rem;">
            Berikut adalah 50 baris pertama dari file backup. Periksa apakah ini file yang benar.
        </p>
        
        <div class="preview-box"><?= e($preview_data) ?></div>
    </div>
    
    <div class="card" style="border: 2px solid #e53e3e;">
        <h3 style="color: #c53030;">Konfirmasi Restore</h3>
        <p style="color: #742a2a; margin-bottom: 1.5rem;">
            Untuk melanjutkan restore, ketik <strong>RESTORE</strong> (huruf besar) pada kolom di bawah.
        </p>
        
        <form method="post" class="form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="file_path" value="<?= e($uploaded_file) ?>">
            <input type="hidden" name="confirm_restore" value="1">
            
            <div class="form-group">
                <label for="confirmation">Ketik RESTORE untuk konfirmasi *</label>
                <input type="text" id="confirmation" name="confirmation" 
                       placeholder="RESTORE" 
                       autocomplete="off" 
                       style="font-family: monospace; font-size: 1.2rem; text-align: center;"
                       required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-danger" 
                        style="font-weight: 600;"
                        onclick="return confirm('Anda yakin akan restore database? Semua data saat ini akan hilang!')">
                    🔥 RESTORE DATABASE SEKARANG
                </button>
                <a href="<?= url('pages/backup/index.php') ?>" class="btn btn-secondary">Batal</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
