<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/email_notifications.php';

require_permission('loans.send_reminder');

$title = 'Pengingat Jatuh Tempo';
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $payment_id = (int) ($_POST['payment_id'] ?? 0);
    
    if ($payment_id > 0) {
        $result = send_due_reminder($payment_id);
        $message = [
            'type' => $result['status'] === 'sent' ? 'success' : ($result['status'] === 'skipped' ? 'info' : 'error'),
            'text' => $result['message']
        ];
        log_activity('send_reminder', "Mengirim pengingat angsuran ID {$payment_id}: {$result['status']}");
    }
}

$reminders = due_reminder_rows();

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($message): ?>
<div class="alert <?= e($message['type']) ?>">
    <?= e($message['text']) ?>
</div>
<?php endif; ?>

<div class="card">
    <p><strong>Angsuran jatuh tempo dalam 3 hari ke depan atau sudah lewat.</strong></p>
    <p><small>Email hanya dikirim satu kali per hari per angsuran untuk mencegah spam.</small></p>
</div>

<div class="card">
    <?php if (empty($reminders)): ?>
        <p class="text-center">Tidak ada angsuran yang perlu diingatkan saat ini.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Anggota</th>
                    <th>No. Pinjaman</th>
                    <th>Angsuran Ke</th>
                    <th>Jatuh Tempo</th>
                    <th>Sisa Tagihan</th>
                    <th>Email</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reminders as $row): 
                    $remaining = max(0, (float) $row['amount_due'] - (float) $row['amount_paid']);
                    $due_date = new DateTime($row['due_date']);
                    $today = new DateTime('today');
                    $days = (int) $today->diff($due_date)->format('%r%a');
                    $overdue = $days < 0;
                    $badge = $overdue ? 'danger' : ($days === 0 ? 'warning' : 'info');
                    $timing = $overdue ? abs($days) . ' hari lewat' : ($days === 0 ? 'Hari ini' : $days . ' hari lagi');
                ?>
                    <tr>
                        <td>
                            <?= e($row['member_number']) ?><br>
                            <small><?= e($row['full_name']) ?></small>
                        </td>
                        <td><?= e($row['loan_number']) ?></td>
                        <td>#<?= $row['payment_number'] ?></td>
                        <td>
                            <?= date('d/m/Y', strtotime($row['due_date'])) ?><br>
                            <span class="badge badge-<?= $badge ?>"><?= $timing ?></span>
                        </td>
                        <td><?= rupiah($remaining) ?></td>
                        <td><small><?= e($row['email']) ?></small></td>
                        <td>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="payment_id" value="<?= $row['payment_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Kirim pengingat ke <?= e($row['email']) ?>?')">
                                    📧 Kirim
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
