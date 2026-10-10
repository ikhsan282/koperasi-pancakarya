<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notification_helpers.php';

require_permission('notifications.send');

$title = 'Kirim Notifikasi';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $recipient_type = $_POST['recipient_type'] ?? 'all';
    $member_id = !empty($_POST['member_id']) ? (int) $_POST['member_id'] : null;
    $notification_title = trim($_POST['title'] ?? '');
    $notification_message = trim($_POST['message'] ?? '');

    if (empty($notification_title)) $errors[] = 'Judul wajib diisi.';
    if (empty($notification_message)) $errors[] = 'Pesan wajib diisi.';
    if ($recipient_type === 'specific' && !$member_id) $errors[] = 'Pilih anggota tujuan.';

    if (empty($errors)) {
        $sent_count = 0;

        if ($recipient_type === 'all') {
            // Send to all active members
            $stmt = db()->query("SELECT id FROM members WHERE status = 'active'");
            $members = $stmt->fetch_all(MYSQLI_ASSOC);
            
            foreach ($members as $member) {
                notify_member(
                    (int) $member['id'],
                    $notification_title,
                    $notification_message,
                    'general',
                    'other',
                    null
                );
                $sent_count++;
            }
        } else {
            // Send to specific member
            notify_member(
                $member_id,
                $notification_title,
                $notification_message,
                'general',
                'other',
                null
            );
            $sent_count = 1;
        }

        log_activity('send_notification', "Mengirim notifikasi ke {$sent_count} anggota");
        flash('success', "Notifikasi berhasil dikirim ke {$sent_count} anggota.");
        redirect('pages/notifications/index.php');
    }
}

// Get active members for dropdown
$members_stmt = db()->query("SELECT id, member_number, full_name FROM members WHERE status = 'active' ORDER BY full_name");
$members = $members_stmt->fetch_all(MYSQLI_ASSOC);

require __DIR__ . '/../../includes/header.php';
?>

<h1><?= e($title) ?></h1>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="form-horizontal">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    
    <div class="form-group">
        <label>Tujuan *</label>
        <select name="recipient_type" id="recipient_type" class="form-control" required onchange="toggleMemberSelect()">
            <option value="all" <?= old('recipient_type', 'all') === 'all' ? 'selected' : '' ?>>Semua Anggota Aktif</option>
            <option value="specific" <?= old('recipient_type') === 'specific' ? 'selected' : '' ?>>Anggota Tertentu</option>
        </select>
    </div>

    <div class="form-group" id="member_select_group" style="display: none;">
        <label>Pilih Anggota *</label>
        <select name="member_id" id="member_id" class="form-control">
            <option value="">-- Pilih Anggota --</option>
            <?php foreach ($members as $member): ?>
                <option value="<?= $member['id'] ?>" <?= old('member_id') == $member['id'] ? 'selected' : '' ?>>
                    <?= e($member['member_number']) ?> - <?= e($member['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label>Judul *</label>
        <input type="text" name="title" class="form-control" value="<?= old('title') ?>" required maxlength="255">
    </div>

    <div class="form-group">
        <label>Pesan *</label>
        <textarea name="message" class="form-control" rows="8" required><?= old('message') ?></textarea>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Kirim Notifikasi</button>
        <a href="<?= url('pages/notifications/index.php') ?>" class="btn">Batal</a>
    </div>
</form>

<script>
function toggleMemberSelect() {
    const recipientType = document.getElementById('recipient_type').value;
    const memberGroup = document.getElementById('member_select_group');
    const memberSelect = document.getElementById('member_id');
    
    if (recipientType === 'specific') {
        memberGroup.style.display = 'block';
        memberSelect.required = true;
    } else {
        memberGroup.style.display = 'none';
        memberSelect.required = false;
    }
}

// Initialize on page load
toggleMemberSelect();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
