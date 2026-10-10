<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.edit');

$payment_id = isset($_GET['payment_id']) ? (int) $_GET['payment_id'] : 0;
if ($payment_id <= 0) {
    flash('error', 'ID jadwal pembayaran tidak valid.');
    redirect('pages/loans/index.php');
}

$stmt = db()->prepare('SELECT p.*, l.loan_number, l.status AS loan_status, l.id AS loan_id, m.member_number, m.full_name
                       FROM loan_payments p
                       JOIN loans l ON p.loan_id = l.id
                       JOIN members m ON l.member_id = m.id
                       WHERE p.id = ?');
$stmt->bind_param('i', $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();

if (!$payment) {
    flash('error', 'Jadwal pembayaran tidak ditemukan.');
    redirect('pages/loans/index.php');
}

if ($payment['loan_status'] !== 'active') {
    flash('error', 'Pembayaran hanya dapat dicatat untuk pinjaman aktif.');
    redirect('pages/loans/detail.php?id=' . $payment['loan_id']);
}

if ($payment['status'] === 'paid') {
    flash('info', 'Angsuran ini sudah lunas.');
    redirect('pages/loans/detail.php?id=' . $payment['loan_id']);
}

$title = 'Bayar Angsuran Ke-' . $payment['payment_number'];
$errors = [];
$remaining_due = max(0, (float) $payment['amount_due'] - (float) $payment['amount_paid']);

// Calculate potential penalty for today's payment
$potential_penalty = calculate_penalty($payment['due_date'], date('Y-m-d'), (float)$payment['amount_due']);
$current_penalty = (float)$payment['penalty_amount'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $payment_date = trim($_POST['payment_date'] ?? '');
    $payment_amount = (float) ($_POST['payment_amount'] ?? 0);
    $date_obj = DateTime::createFromFormat('Y-m-d', $payment_date);

    if (!$date_obj || $date_obj->format('Y-m-d') !== $payment_date) $errors[] = 'Tanggal pembayaran tidak valid.';
    if ($payment_amount <= 0) $errors[] = 'Jumlah pembayaran harus lebih dari nol.';
    
    // Calculate penalty if late
    $penalty_data = calculate_penalty($payment['due_date'], $payment_date, (float)$payment['amount_due']);
    $penalty_amount = $penalty_data['penalty_amount'];
    $days_overdue = $penalty_data['days_overdue'];
    $total_with_penalty = $remaining_due + $penalty_amount;
    
    if ($payment_amount > $total_with_penalty + 0.01) $errors[] = 'Jumlah pembayaran melebihi total tagihan + denda (' . rupiah($total_with_penalty) . ').';

    if (empty($errors)) {
        $new_amount_paid = round((float) $payment['amount_paid'] + $payment_amount, 2);
        $new_penalty = round((float)$payment['penalty_amount'] + $penalty_amount, 2);
        $new_status = ($new_amount_paid + 0.01 >= (float) $payment['amount_due'] + $new_penalty) ? 'paid' : 'pending';
        $uid = current_user()['id'];

        $db = db();
        $db->begin_transaction();
        try {
            $stmt = $db->prepare('UPDATE loan_payments SET payment_date = ?, amount = amount + ?, amount_paid = ?, penalty_amount = ?, days_overdue = ?, status = ?, processed_by = ? WHERE id = ? AND status != "paid"');
            $stmt->bind_param('sddidsii', $payment_date, $payment_amount, $new_amount_paid, $new_penalty, $days_overdue, $new_status, $uid, $payment_id);
            $stmt->execute();

            $stmt = $db->prepare('SELECT COUNT(*) AS unpaid FROM loan_payments WHERE loan_id = ? AND status != "paid"');
            $loan_id = (int) $payment['loan_id'];
            $stmt->bind_param('i', $loan_id);
            $stmt->execute();
            $unpaid = (int) $stmt->get_result()->fetch_assoc()['unpaid'];

            if ($unpaid === 0) {
                $stmt = $db->prepare('UPDATE loans SET status = "completed" WHERE id = ? AND status = "active"');
                $stmt->bind_param('i', $loan_id);
                $stmt->execute();
            }
            
            // Auto-post to cash/bank (debit = uang masuk)
            if ($cashbank_account_id > 0) {
                post_cashbank_transaction(
                    $cashbank_account_id,
                    'debit',
                    $payment_amount,
                    'loan_payment',
                    $payment_id,
                    "Angsuran ke-{$payment['payment_number']} pinjaman {$payment['loan_number']} - {$payment['full_name']}",
                    $payment_date
                );
            }

            $db->commit();
            $penalty_msg = $penalty_amount > 0 ? " (termasuk denda " . rupiah($penalty_amount) . ")" : "";
            log_activity('loan_payment', "Mencatat pembayaran angsuran ke-{$payment['payment_number']} pinjaman {$payment['loan_number']} sebesar " . rupiah($payment_amount) . $penalty_msg);
            flash('success', $new_status === 'paid' ? 'Angsuran berhasil dilunasi.' : 'Pembayaran sebagian berhasil dicatat.');
            redirect('pages/loans/detail.php?id=' . $payment['loan_id']);
        } catch (Throwable $e) {
            $db->rollback();
            $errors[] = 'Gagal mencatat pembayaran: ' . $e->getMessage();
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

<div class="card" style="max-width: 700px; margin: 0 auto;">
    <h3>Catat Pembayaran Angsuran</h3>

    <table class="table-info" style="width: 100%; margin-bottom: 1.5rem;">
        <tr><th style="width: 40%;">No. Pinjaman</th><td><strong><?= e($payment['loan_number']) ?></strong></td></tr>
        <tr><th>Anggota</th><td><?= e($payment['member_number']) ?> - <?= e($payment['full_name']) ?></td></tr>
        <tr><th>Angsuran Ke</th><td><?= $payment['payment_number'] ?></td></tr>
        <tr><th>Jatuh Tempo</th><td><?= date('d/m/Y', strtotime($payment['due_date'])) ?></td></tr>
        <tr><th>Total Tagihan</th><td><?= rupiah($payment['amount_due']) ?></td></tr>
        <tr><th>Sudah Dibayar</th><td><?= rupiah($payment['amount_paid']) ?></td></tr>
        <tr><th>Denda Sebelumnya</th><td><?= rupiah($current_penalty) ?></td></tr>
        <?php if ($potential_penalty['days_overdue'] > 0): ?>
        <tr><th style="color: #c53030;">Denda Keterlambatan</th><td style="color: #c53030;"><strong><?= rupiah($potential_penalty['penalty_amount']) ?></strong> (<?= $potential_penalty['days_overdue'] ?> hari)</td></tr>
        <?php endif; ?>
        <tr><th>Sisa Tagihan</th><td><strong style="color: #c53030; font-size: 1.1rem;"><?= rupiah($remaining_due) ?></strong></td></tr>
        <tr><th><strong>Total Harus Dibayar</strong></th><td><strong style="color: #2d3748; font-size: 1.15rem;"><?= rupiah($remaining_due + $potential_penalty['penalty_amount']) ?></strong></td></tr>
    </table>

    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="form-row">
            <div class="form-group">
                <label for="payment_date">Tanggal Pembayaran *</label>
                <input type="date" id="payment_date" name="payment_date" value="<?= e($_POST['payment_date'] ?? date('Y-m-d')) ?>" required>
            </div>
            <div class="form-group">
                <label for="payment_amount">Jumlah Pembayaran (Rp) *</label>
                <input type="number" id="payment_amount" name="payment_amount" step="0.01" min="0.01" max="<?= $remaining_due + $potential_penalty['penalty_amount'] ?>" value="<?= e($_POST['payment_amount'] ?? (string) ($remaining_due + $potential_penalty['penalty_amount'])) ?>" required>
                <?php if ($potential_penalty['days_overdue'] > 0): ?>
                <small style="display: block; margin-top: 0.25rem; color: #c53030;">Sudah termasuk denda keterlambatan <?= rupiah($potential_penalty['penalty_amount']) ?></small>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (can('cashbank.manage')): ?>
        <div class="form-group">
            <label for="cashbank_account_id">Catat ke Akun Kas/Bank</label>
            <select id="cashbank_account_id" name="cashbank_account_id">
                <option value="">-- Tidak dicatat --</option>
                <?php
                $cb_accounts = db()->query('SELECT id, account_name, balance FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name');
                while ($cba = $cb_accounts->fetch_assoc()): ?>
                    <option value="<?= $cba['id'] ?>"><?= e($cba['account_name']) ?> - Saldo: <?= rupiah($cba['balance']) ?></option>
                <?php endwhile; ?>
            </select>
            <small style="display: block; margin-top: 0.25rem; color: #666;">Opsional: otomatis catat penerimaan uang ke akun kas/bank</small>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Simpan pembayaran angsuran ini?')">Simpan Pembayaran</button>
            <a href="<?= url('pages/loans/detail.php?id=' . $payment['loan_id']) ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
