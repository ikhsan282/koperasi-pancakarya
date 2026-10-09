<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$is_view = $id > 0;

if ($is_view) {
    require_permission('loans.view');
    $stmt = db()->prepare('SELECT l.*, m.member_number, m.full_name 
                           FROM loans l 
                           JOIN members m ON l.member_id = m.id 
                           WHERE l.id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $loan = $stmt->get_result()->fetch_assoc();
    if (!$loan) {
        flash('error', 'Data pinjaman tidak ditemukan.');
        redirect('pages/loans/index.php');
    }
    $title = 'Detail Pinjaman - ' . $loan['loan_number'];

    // Payments history
    $p_stmt = db()->prepare('SELECT * FROM loan_payments WHERE loan_id = ? ORDER BY payment_number ASC');
    $p_stmt->bind_param('i', $id);
    $p_stmt->execute();
    $payments = $p_stmt->get_result();
} else {
    require_permission('loans.create');
    $title = 'Pengajuan Pinjaman Baru';
    $loan = null;
}

$errors = [];

// Handle approval / status changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'approve' && can('loans.approve')) {
        $stmt = db()->prepare('UPDATE loans SET status = "approved", approval_date = NOW(), approved_by = ? WHERE id = ?');
        $uid = current_user()['id'];
        $stmt->bind_param('ii', $uid, $id);
        $stmt->execute();
        log_activity('approve_loan', "Menyetujui pinjaman {$loan['loan_number']}");
        flash('success', 'Pinjaman telah disetujui.');
        redirect('pages/loans/form.php?id=' . $id);
    } elseif ($action === 'disburse' && can('loans.approve')) {
        $stmt = db()->prepare('UPDATE loans SET status = "active", disbursement_date = NOW() WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_activity('disburse_loan', "Mencairkan pinjaman {$loan['loan_number']}");
        flash('success', 'Pinjaman telah dicairkan dan berstatus aktif.');
        redirect('pages/loans/form.php?id=' . $id);
    } elseif ($action === 'reject' && can('loans.approve')) {
        $stmt = db()->prepare('UPDATE loans SET status = "rejected" WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_activity('reject_loan', "Menolak pinjaman {$loan['loan_number']}");
        flash('success', 'Pinjaman telah ditolak.');
        redirect('pages/loans/form.php?id=' . $id);
    } elseif ($action === 'pay_installment' && can('loans.create')) {
        $payment_amount = (float) ($_POST['payment_amount'] ?? 0);
        if ($payment_amount <= 0) {
            $errors[] = 'Jumlah pembayaran tidak valid.';
        } else {
            // Count existing payments
            $c_stmt = db()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(principal_amount), 0) AS total_principal FROM loan_payments WHERE loan_id = ?');
            $c_stmt->bind_param('i', $id);
            $c_stmt->execute();
            $p_info = $c_stmt->get_result()->fetch_assoc();
            $payment_number = $p_info['total'] + 1;
            
            // Standard simple installment breakdown
            $interest_rate = (float) $loan['interest_rate'];
            $interest_amount = ($loan['amount'] * ($interest_rate / 100)) / $loan['term_months'];
            $principal_amount = $payment_amount - $interest_amount;
            if ($principal_amount < 0) {
                $principal_amount = 0;
                $interest_amount = $payment_amount;
            }
            $remaining = $loan['amount'] - ($p_info['total_principal'] + $principal_amount);
            if ($remaining < 0) $remaining = 0;

            db()->begin_transaction();
            try {
                $ins_stmt = db()->prepare('INSERT INTO loan_payments (loan_id, payment_date, amount, principal_amount, interest_amount, balance_remaining, payment_number, processed_by) 
                                           VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)');
                $uid = current_user()['id'];
                $ins_stmt->bind_param('iddddii', $id, $payment_amount, $principal_amount, $interest_amount, $remaining, $payment_number, $uid);
                $ins_stmt->execute();

                if ($remaining <= 0 || $payment_number >= $loan['term_months']) {
                    $upd_stmt = db()->prepare('UPDATE loans SET status = "paid" WHERE id = ?');
                    $upd_stmt->bind_param('i', $id);
                    $upd_stmt->execute();
                }

                db()->commit();
                log_activity('loan_payment', "Pembayaran cicilan ke-{$payment_number} pinjaman {$loan['loan_number']}");
                flash('success', 'Pembayaran cicilan berhasil dicatat.');
                redirect('pages/loans/form.php?id=' . $id);
            } catch (Exception $e) {
                db()->rollback();
                $errors[] = 'Gagal mencatat pembayaran: ' . $e->getMessage();
            }
        }
    }
}

// Handle new loan application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_view) {
    verify_csrf();
    $member_id = (int) ($_POST['member_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $interest_rate = (float) ($_POST['interest_rate'] ?? 1.5); // 1.5% default per month
    $term_months = (int) ($_POST['term_months'] ?? 12);
    $purpose = trim($_POST['purpose'] ?? '');

    if ($member_id <= 0) $errors[] = 'Pilih anggota.';
    if ($amount <= 0) $errors[] = 'Jumlah pinjaman harus lebih dari 0.';
    if ($term_months <= 0) $errors[] = 'Jangka waktu tidak valid.';

    if (empty($errors)) {
        // Calculate monthly payment: (Amount / term) + (Amount * rate / 100)
        $principal_per_month = $amount / $term_months;
        $interest_per_month = $amount * ($interest_rate / 100);
        $monthly_payment = $principal_per_month + $interest_per_month;

        $prefix = 'L-' . date('Ym') . '-';
        $stmt = db()->prepare('SELECT COUNT(*) AS total FROM loans WHERE loan_number LIKE ?');
        $likePrefix = $prefix . '%';
        $stmt->bind_param('s', $likePrefix);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['total'] + 1;
        $loan_number = $prefix . str_pad((string)$count, 4, '0', STR_PAD_LEFT);

        $stmt = db()->prepare('INSERT INTO loans (member_id, loan_number, amount, interest_rate, term_months, monthly_payment, purpose, status, application_date) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, "pending", NOW())');
        $stmt->bind_param('isddids', $member_id, $loan_number, $amount, $interest_rate, $term_months, $monthly_payment, $purpose);
        $stmt->execute();

        log_activity('apply_loan', "Pengajuan pinjaman {$loan_number}");
        flash('success', 'Pengajuan pinjaman berhasil dibuat.');
        redirect('pages/loans/index.php');
    }
}

$members = db()->query('SELECT id, member_number, full_name FROM members WHERE status = "active" ORDER BY full_name ASC');

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($is_view): ?>
    <div class="card">
        <h3>Informasi Pinjaman: <?= e($loan['loan_number']) ?></h3>
        <table class="table-info">
            <tr><th>No. Pinjaman</th><td><?= e($loan['loan_number']) ?></td></tr>
            <tr><th>Anggota</th><td><?= e($loan['member_number']) ?> - <?= e($loan['full_name']) ?></td></tr>
            <tr><th>Jumlah Pinjaman</th><td><strong><?= rupiah($loan['amount']) ?></strong></td></tr>
            <tr><th>Bunga per Bulan</th><td><?= $loan['interest_rate'] ?>%</td></tr>
            <tr><th>Jangka Waktu</th><td><?= $loan['term_months'] ?> Bulan</td></tr>
            <tr><th>Cicilan per Bulan</th><td><strong><?= rupiah($loan['monthly_payment']) ?></strong></td></tr>
            <tr><th>Keperluan</th><td><?= e($loan['purpose'] ?? '-') ?></td></tr>
            <tr><th>Status</th><td><span class="badge badge-primary"><?= e($loan['status']) ?></span></td></tr>
        </table>

        <div class="form-actions" style="margin-top: 1.5rem;">
            <?php if ($loan['status'] === 'pending' && can('loans.approve')): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn btn-success">Setujui</button>
                </form>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="reject">
                    <button type="submit" class="btn btn-danger">Tolak</button>
                </form>
            <?php elseif ($loan['status'] === 'approved' && can('loans.approve')): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="disburse">
                    <button type="submit" class="btn btn-primary">Cairkan Dana</button>
                </form>
            <?php endif; ?>
            <a href="<?= url('pages/loans/index.php') ?>" class="btn btn-secondary">Kembali</a>
        </div>
    </div>

    <?php if ($loan['status'] === 'active'): ?>
        <div class="card">
            <h3>Bayar Cicilan</h3>
            <form method="post" class="form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="pay_installment">
                <div class="form-group">
                    <label for="payment_amount">Jumlah Pembayaran (Rp)</label>
                    <input type="number" id="payment_amount" name="payment_amount" value="<?= $loan['monthly_payment'] ?>" required>
                </div>
                <button type="submit" class="btn btn-primary">Catat Pembayaran Cicilan</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3>Riwayat Pembayaran Cicilan</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Ke</th>
                    <th>Tanggal</th>
                    <th>Total Bayar</th>
                    <th>Pokok</th>
                    <th>Bunga</th>
                    <th>Sisa Pokok</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($payments->num_rows === 0): ?>
                    <tr><td colspan="6" class="text-center">Belum ada pembayaran cicilan.</td></tr>
                <?php else: ?>
                    <?php while ($p = $payments->fetch_assoc()): ?>
                        <tr>
                            <td><?= $p['payment_number'] ?></td>
                            <td><?= date('d/m/Y', strtotime($p['payment_date'])) ?></td>
                            <td><?= rupiah($p['amount']) ?></td>
                            <td><?= rupiah($p['principal_amount']) ?></td>
                            <td><?= rupiah($p['interest_amount']) ?></td>
                            <td><?= rupiah($p['balance_remaining']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>

    <div class="card">
        <form method="post" class="form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="form-group">
                <label for="member_id">Pilih Anggota *</label>
                <select id="member_id" name="member_id" required>
                    <option value="">-- Pilih Anggota --</option>
                    <?php while ($m = $members->fetch_assoc()): ?>
                        <option value="<?= $m['id'] ?>"><?= e($m['member_number']) ?> - <?= e($m['full_name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="amount">Jumlah Pinjaman (Rp) *</label>
                    <input type="number" id="amount" name="amount" min="100000" step="10000" required>
                </div>
                <div class="form-group">
                    <label for="interest_rate">Bunga per Bulan (%) *</label>
                    <input type="number" id="interest_rate" name="interest_rate" value="1.5" min="0" step="0.1" required>
                </div>
                <div class="form-group">
                    <label for="term_months">Jangka Waktu (Bulan) *</label>
                    <select id="term_months" name="term_months" required>
                        <option value="6">6 Bulan</option>
                        <option value="12" selected>12 Bulan</option>
                        <option value="24">24 Bulan</option>
                        <option value="36">36 Bulan</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="purpose">Keperluan Pinjaman</label>
                <textarea id="purpose" name="purpose" rows="3"></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Ajukan Pinjaman</button>
                <a href="<?= url('pages/loans/index.php') ?>" class="btn btn-secondary">Batal</a>
            </div>
        </form>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
