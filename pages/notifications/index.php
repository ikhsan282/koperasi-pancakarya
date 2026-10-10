<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notification_helpers.php';

require_login();

$title = 'Notifikasi';
$user = current_user();

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    verify_csrf();
    $notification_id = (int) ($_POST['notification_id'] ?? 0);
    mark_notification_read($notification_id, $user['id']);
    redirect('pages/notifications/index.php');
}

// Handle mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    verify_csrf();
    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE recipient_user_id = ? AND is_read = 0');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    flash('success', 'Semua notifikasi telah ditandai sebagai dibaca.');
    redirect('pages/notifications/index.php');
}

// Filter
$filter = $_GET['filter'] ?? 'all';
$sql = 'SELECT n.*, m.full_name, m.member_number 
        FROM notifications n
        LEFT JOIN members m ON m.id = n.recipient_member_id
        WHERE n.recipient_user_id = ?';
$params = [$user['id']];
$types = 'i';

if ($filter === 'unread') {
    $sql .= ' AND n.is_read = 0';
}

$sql .= ' ORDER BY n.created_at DESC LIMIT 100';

$stmt = db()->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$unread_count = get_unread_count($user['id']);

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <h1>Notifikasi <?php if ($unread_count > 0): ?><span class="badge"><?= $unread_count ?></span><?php endif; ?></h1>
    <div>
        <?php if (can('notifications.send')): ?>
            <a href="<?= url('pages/notifications/send.php') ?>" class="btn btn-primary">Kirim Notifikasi</a>
        <?php endif; ?>
        <?php if ($unread_count > 0): ?>
            <form method="post" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" name="mark_all_read" class="btn">Tandai Semua Dibaca</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="filter-bar">
    <a href="?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">Semua</a>
    <a href="?filter=unread" class="<?= $filter === 'unread' ? 'active' : '' ?>">Belum Dibaca (<?= $unread_count ?>)</a>
</div>

<?php if (empty($notifications)): ?>
    <div class="empty-state">
        <p>Tidak ada notifikasi.</p>
    </div>
<?php else: ?>
    <div class="notification-list">
        <?php foreach ($notifications as $notif): ?>
            <div class="notification-item <?= $notif['is_read'] ? 'read' : 'unread' ?>">
                <div class="notification-header">
                    <span class="notification-type"><?= e($notif['notification_type']) ?></span>
                    <span class="notification-date"><?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?></span>
                </div>
                <h3><?= e($notif['title']) ?></h3>
                <p><?= nl2br(e($notif['message'])) ?></p>
                <?php if ($notif['full_name']): ?>
                    <div class="notification-meta">
                        Anggota: <?= e($notif['full_name']) ?> (<?= e($notif['member_number']) ?>)
                    </div>
                <?php endif; ?>
                <?php if (!$notif['is_read']): ?>
                    <form method="post" style="margin-top: 10px;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="notification_id" value="<?= $notif['id'] ?>">
                        <button type="submit" name="mark_read" class="btn btn-small">Tandai Dibaca</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.filter-bar {
    margin: 20px 0;
    padding: 10px 0;
    border-bottom: 1px solid #ddd;
}
.filter-bar a {
    padding: 8px 16px;
    margin-right: 10px;
    text-decoration: none;
    color: #666;
    border-radius: 4px;
}
.filter-bar a.active {
    background: #007bff;
    color: white;
}
.notification-list {
    margin-top: 20px;
}
.notification-item {
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 15px;
}
.notification-item.unread {
    border-left: 4px solid #007bff;
    background: #f8f9fa;
}
.notification-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    font-size: 0.9em;
}
.notification-type {
    background: #e9ecef;
    padding: 4px 8px;
    border-radius: 3px;
    font-weight: 500;
}
.notification-date {
    color: #666;
}
.notification-item h3 {
    margin: 10px 0;
    font-size: 1.1em;
}
.notification-meta {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #eee;
    color: #666;
    font-size: 0.9em;
}
.badge {
    background: #dc3545;
    color: white;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.8em;
    margin-left: 5px;
}
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
