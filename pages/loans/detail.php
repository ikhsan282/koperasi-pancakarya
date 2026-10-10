<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.view');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'ID pinjaman tidak valid.');
    redirect('pages/loans/index.php');
}

$stmt = db()->prepare('SELECT l.*, m.member_number, m.full_name, m.phone, m.address, lp.name AS product_name, lp.approval_levels, u.full_name AS approver_name
                       FROM loans l
                       JOIN members m ON l.member_id = m.id
                       LEFT JOIN loan_products lp ON l.loan_product_id = lp.id
                       LEFT JOIN users u ON l.approved_by = u.id
                       WHERE l.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();

if (!$loan) {
    flash('error', 'Data pinjaman tidak ditemukan.');
    redirect('pages/loans/index.php');
}

$p_stmt = db()->prepare('SELECT * FROM loan_payments WHERE loan_id = ? ORDER BY payment_number ASC');
$p_stmt->bind_param('i', $id);
$p_stmt->execute();
$payments = $p_stmt->get_result();

$paid_count = 0;
$total_paid = 0;
$all_payments = [];
while ($p = $payments->fetch_assoc()) {
    $all_payments[] = $p;
    if ($p['status'] === 'paid') {
        $paid_count++;
        $total_paid += (float) $p['amount_paid'];
    }
}

// Fetch collaterals
$c_stmt = db()->prepare('SELECT COUNT(*) as total FROM loan_collaterals WHERE loan_id = ?');
$c_stmt->bind_param('i', $id);
$c_stmt->execute();
$collateral_count = $c_stmt->get_result()->fetch_assoc()['total'];

// Fetch approval history
$a_stmt = db()->prepare('SELECT la.*, u.full_name AS approver_name FROM loan_approvals la JOIN users u ON la.approver_id = u.id WHERE la.loan_id = ? ORDER BY la.approver_level ASC');
$a_stmt->bind_param('i', $id);
$a_stmt->execute();
$approvals = $a_stmt->get_result();

$title = 'Detail Pinjaman - ' . $loan['loan_number'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions" style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; justify-content: space-between;">
    <a href="<?= url('pages/loans/index.php') ?>" class="btn btn-secondary">Kembali ke Daftar</a>
    <div style="display: flex; gap: 0.5rem;">
        <?php if (can('collateral.view')): ?>
            <a href="<?= url('pages/collateral/index.php?loan_id=' . $loan['id']) ?>" class="btn btn-secondary">📦 Agunan (<?= $collateral_count ?>)</a>
        <?php endif; ?>
        <?php if (in_array($loan['status'], ['active', 'completed', 'paid'])): ?>
            <a href="<?= url('pages/loans/print.php?id=' . $loan['id']) ?>" target="_blank" class="btn btn-primary">🖨 Cetak Kwitansi/Ringkasan</a>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color, #eee); padding-bottom: 0.75rem; margin-bottom: 1rem;">
        <h3 style="margin: 0;">Informasi Pinjaman: <?= e($loan['loan_number']) ?></h3>
        <div>
            <?php
            $badge_classes = ['approved' => 'info', 'active' => 'primary', 'completed' => 'success', 'rejected' => 'danger'];
            $status_labels = ['pending' => 'Menunggu Approval', 'approved' => 'Disetujui', 'active' => 'Aktif Berjalan', 'completed' => 'Lunas Selesai', 'rejected' => 'Ditolak'];
            $badge_class = $badge_classes[$loan['status']] ?? 'warning';
            $status_label = $status_labels[$loan['status']] ?? $loan['status'];
            ?>
            <span class="badge badge-<?= $badge_class ?>" style="font-size: 0.95rem; padding: 0.35rem 0.65rem;"><?= $status_label ?></span>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Data Anggota</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 40%;">No. Anggota</th><td><?= e($loan['member_number']) ?></td></tr>
                <tr><th>Nama Lengkap</th><td><strong><?= e($loan['full_name']) ?></strong></td></tr>
                <tr><th>No. Telepon</th><td><?= e($loan['phone'] ?? '-') ?></td></tr>
                <tr><th>Alamat</th><td><?= e($loan['address'] ?? '-') ?></td></tr>
            </table>
        </div>

        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Detail Pinjaman</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 40%;">Produk</th><td><?= e($loan['product_name'] ?? 'Umum') ?></td></tr>
                <tr><th>Pokok Pinjaman</th><td><strong><?= rupiah($loan['amount']) ?></strong></td></tr>
                <?php if (!empty($loan['admin_fee_pct']) && $loan['admin_fee_pct'] > 0): ?>
                <tr><th>Biaya Admin</th><td><?= rupiah($loan['admin_fee_amount']) ?> (<?= $loan['admin_fee_pct'] ?>%)</td></tr>
                <?php endif; ?>
                <tr><th>Bunga / Bulan</th><td><?= $loan['interest_rate'] ?>% (Flat)</td></tr>
                <tr><th>Tenor</th><td><?= $loan['term_months'] ?> Bulan</td></tr>
                <tr><th>Angsuran / Bulan</th><td><strong style="color: #2b6cb0;"><?= rupiah($loan['monthly_payment']) ?></strong></td></tr>
            </table>
        </div>

        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Status & Progres</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 45%;">Tgl Pengajuan</th><td><?= date('d/m/Y', strtotime($loan['application_date'])) ?></td></tr>
                <tr><th>Tgl Pencairan</th><td><?= $loan['disbursement_date'] ? date('d/m/Y', strtotime($loan['disbursement_date'])) : '-' ?></td></tr>
                <tr><th>Disetujui Oleh</th><td><?= e($loan['approver_name'] ?? '-') ?></td></tr>
                <tr><th>Total Terbayar</th><td><?= rupiah($total_paid) ?></td></tr>
                <tr><th>Progres Angsuran</th><td><?= $paid_count ?> dari <?= count($all_payments) ?> cicilan</td></tr>
                <?php if ($loan['rejection_notes']): ?>
                    <tr><th>Catatan Penolakan</th><td style="color: #c53030;"><?= e($loan['rejection_notes']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php if ($approvals->num_rows > 0 || ((int)($loan['approval_levels'] ?? 1) === 2 && (int)$loan['current_approval_level'] > 0)): ?>
<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0;">Riwayat Persetujuan</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Level</th>
                <th>Approver</th>
                <th>Status</th>
                <th>Waktu</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($approvals->num_rows === 0): ?>
                <tr><td colspan="5" class="text-center">Belum ada riwayat approval</td></tr>
            <?php else: ?>
                <?php $approvals->data_seek(0); while ($appr = $approvals->fetch_assoc()): ?>
                    <tr>
                        <td><strong>Level <?= $appr['approver_level'] ?></strong></td>
                        <td><?= e($appr['approver_name']) ?></td>
                        <td>
                            <span class="badge badge-<?= $appr['status'] === 'approved' ? 'success' : 'danger' ?>">
                                <?= $appr['status'] === 'approved' ? '✓ Disetujui' : '✕ Ditolak' ?>
                            </span>
                        </td>
                        <td><?= date('d/m/Y H:i', strtotime($appr['approved_at'])) ?></td>
                        <td><?= e($appr['notes'] ?? '-') ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ((int)($loan['approval_levels'] ?? 1) === 2): ?>
        <div style="margin-top: 1rem; padding: 0.75rem; background: #f7fafc; border-radius: 4px; font-size: 0.9rem;">
            <strong>Status Workflow:</strong> 
            <?php
            $curr_lvl = (int)$loan['current_approval_level'];
            if ($loan['status'] === 'pending' && $curr_lvl === 0) {
                echo '⏳ Menunggu Approval Level 1 (Admin)';
            } elseif ($loan['status'] === 'pending' && $curr_lvl === 1) {
                echo '⏳ Menunggu Approval Level 2 (Super Admin - Final)';
            } elseif ($loan['status'] === 'approved' && $curr_lvl === 2) {
                echo '✅ Fully Approved (2 Level)';
            } elseif ($loan['status'] === 'approved') {
                echo '✅ Approved';
            }
            ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$approval_levels = (int) ($loan['approval_levels'] ?? 1);
$current_level = (int) $loan['current_approval_level'];
$can_approve_l1 = can('loans.approve');
$can_approve_l2 = can('loans.approve_final');
$show_approval_section = false;

if ($loan['status'] === 'pending') {
    if ($approval_levels === 1 && $can_approve_l1) {
        $show_approval_section = true;
    } elseif ($approval_levels === 2) {
        if ($current_level === 0 && $can_approve_l1) {
            $show_approval_section = true;
        } elseif ($current_level === 1 && $can_approve_l2) {
            $show_approval_section = true;
        }
    }
}
?>

<?php if ($show_approval_section): ?>
    <div class="card" style="margin-bottom: 1.5rem; background: #faf5ff; border: 1px solid #d6bcfa;">
        <h4 style="margin-top: 0; color: #553c9e;">
            <?php if ($approval_levels === 2): ?>
                Persetujuan Level <?= $current_level + 1 ?> <?= $current_level === 0 ? '(Admin)' : '(Super Admin - Final)' ?>
            <?php else: ?>
                Persetujuan / Penolakan Pinjaman
            <?php endif; ?>
        </h4>
        
        <?php if ($approval_levels === 2 && $current_level === 1): ?>
            <div class="alert info" style="margin-bottom: 1rem;">
                <strong>ℹ Level 2 Approval:</strong> Pinjaman ini telah disetujui Level 1 (Admin). Diperlukan persetujuan final dari Super Admin.
            </div>
        <?php endif; ?>
        
        <?php if ($loan['amount'] >= 5000000 && $collateral_count === 0): ?>
            <div class="alert warning" style="margin-bottom: 1rem;">
                <strong>⚠ Agunan Wajib:</strong> Pinjaman ≥ Rp 5.000.000 wajib memiliki minimal 1 agunan sebelum dapat disetujui.
                <a href="<?= url('pages/collateral/index.php?loan_id=' . $loan['id']) ?>" style="text-decoration: underline;">Tambah agunan sekarang →</a>
            </div>
        <?php endif; ?>
        
        <form method="post" action="<?= url('pages/loans/process.php?id=' . $loan['id']) ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="approve">
            
            <div class="form-group" style="margin-bottom: 1rem;">
                <label for="approval_notes">Catatan Persetujuan (Opsional)</label>
                <textarea id="approval_notes" name="approval_notes" rows="2" placeholder="Tambahkan catatan jika diperlukan..." style="width: 100%; max-width: 600px;"></textarea>
            </div>
            
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <button type="submit" class="btn btn-success" onclick="return confirm('Setujui pengajuan pinjaman ini?')">
                    ✓ <?= $approval_levels === 2 && $current_level === 1 ? 'Setujui Final (Level 2)' : 'Setujui Pinjaman' ?>
                </button>
                <button type="button" class="btn btn-danger" onclick="document.getElementById('reject-form-box').style.display = document.getElementById('reject-form-box').style.display === 'none' ? 'block' : 'none';">✕ Tolak Pinjaman...</button>
            </div>
        </form>

        <div id="reject-form-box" style="display: none; margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed #cbd5e0;">
            <form method="post" action="<?= url('pages/loans/process.php?id=' . $loan['id']) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reject">
                <div class="form-group" style="margin-bottom: 0.75rem;">
                    <label for="rejection_notes"><strong>Alasan Penolakan *</strong></label>
                    <textarea id="rejection_notes" name="rejection_notes" rows="2" required placeholder="Tuliskan alasan penolakan pinjaman..." style="width: 100%; max-width: 600px; display: block;"></textarea>
                </div>
                <button type="submit" class="btn btn-danger" onclick="return confirm('Yakin ingin menolak pinjaman ini?')">Kirim Penolakan</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($loan['status'] === 'approved' && can('loans.approve')): ?>
    <div class="card" style="margin-bottom: 1.5rem; background: #ebf8ff; border: 1px solid #bee3f8;">
        <h4 style="margin-top: 0; color: #2b6cb0;">Pencairan Dana (Disbursement)</h4>
        <p style="margin-bottom: 1rem; font-size: 0.95rem;">Pinjaman telah disetujui. Silakan tentukan tanggal pencairan dana untuk mengaktifkan pinjaman dan membuat jadwal cicilan.</p>
        <form method="post" action="<?= url('pages/loans/process.php?id=' . $loan['id']) ?>" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="disburse">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="disbursement_date" style="font-weight: 600;">Tanggal Pencairan *</label>
                <input type="date" id="disbursement_date" name="disbursement_date" value="<?= date('Y-m-d') ?>" required style="padding: 0.4rem 0.6rem;">
            </div>
            <?php if (can('cashbank.manage')): ?>
            <div class="form-group" style="margin-bottom: 0;">
                <label for="cashbank_account_id" style="font-weight: 600;">Akun Kas/Bank</label>
                <select id="cashbank_account_id" name="cashbank_account_id" style="padding: 0.4rem 0.6rem;">
                    <option value="">-- Tidak dicatat --</option>
                    <?php
                    $cb_accounts = db()->query('SELECT id, account_name, balance FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name');
                    while ($cba = $cb_accounts->fetch_assoc()): ?>
                        <option value="<?= $cba['id'] ?>"><?= e($cba['account_name']) ?> (<?= rupiah($cba['balance']) ?>)</option>
                    <?php endwhile; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" onclick="return confirm('Cairkan dana dan buat jadwal angsuran sekarang?')">Cairkan Dana & Buat Jadwal</button>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h3 style="margin: 0;">Jadwal Angsuran & Riwayat Pembayaran</h3>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th style="width: 60px;">Ke</th>
                <th>Jatuh Tempo</th>
                <th>Pokok</th>
                <th>Bunga</th>
                <th>Denda</th>
                <th>Total Tagihan</th>
                <th>Tgl Bayar</th>
                <th>Jumlah Bayar</th>
                <th>Status</th>
                <?php if (can('loans.edit') && $loan['status'] === 'active'): ?>
                    <th>Aksi</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($all_payments)): ?>
                <tr><td colspan="<?= (can('loans.edit') && $loan['status'] === 'active') ? 10 : 9 ?>" class="text-center">Jadwal angsuran akan digenerate otomatis setelah pinjaman dicairkan (status disburse).</td></tr>
            <?php else: ?>
                <?php foreach ($all_payments as $p): ?>
                    <?php
                    $is_overdue = ($p['status'] === 'pending' && !empty($p['due_date']) && strtotime($p['due_date']) < strtotime(date('Y-m-d')));
                    $st = $is_overdue ? 'overdue' : $p['status'];
                    $b_class = ['paid' => 'success', 'overdue' => 'danger'][$st] ?? 'warning';
                    $l_label = ['paid' => 'Lunas', 'overdue' => 'Jatuh Tempo'][$st] ?? 'Belum Bayar';
                    ?>
                    <tr>
                        <td style="font-weight: bold;"><?= $p['payment_number'] ?></td>
                        <td><?= !empty($p['due_date']) ? date('d/m/Y', strtotime($p['due_date'])) : '-' ?></td>
                        <td><?= rupiah($p['principal_amount']) ?></td>
                        <td><?= rupiah($p['interest_amount']) ?></td>
                        <td><?= $p['penalty_amount'] > 0 ? rupiah($p['penalty_amount']) : '-' ?></td>
                        <td><strong><?= rupiah($p['amount_due'] > 0 ? $p['amount_due'] : $p['amount']) ?></strong></td>
                        <td><?= !empty($p['payment_date']) ? date('d/m/Y', strtotime($p['payment_date'])) : '-' ?></td>
                        <td><?= $p['amount_paid'] > 0 ? rupiah($p['amount_paid']) : ($p['status'] === 'paid' ? rupiah($p['amount']) : '-') ?></td>
                        <td><span class="badge badge-<?= $b_class ?>"><?= $l_label ?></span></td>
                        <?php if (can('loans.edit') && $loan['status'] === 'active'): ?>
                            <td>
                                <?php if ($p['status'] !== 'paid'): ?>
                                    <a href="<?= url('pages/loans/payment.php?payment_id=' . $p['id']) ?>" class="btn btn-sm btn-primary">Bayar</a>
                                <?php else: ?>
                                    <span style="color: green; font-size: 0.85rem;">✓ Selesai</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <?php if (!empty($all_payments)): ?>
            <tfoot>
                <tr style="font-weight: bold; background: #f7fafc;">
                    <td colspan="2">TOTAL</td>
                    <td><?= rupiah(array_sum(array_column($all_payments, 'principal_amount'))) ?></td>
                    <td><?= rupiah(array_sum(array_column($all_payments, 'interest_amount'))) ?></td>
                    <td><?= rupiah(array_sum(array_column($all_payments, 'penalty_amount'))) ?></td>
                    <td><?= rupiah(array_sum(array_map(fn($x) => $x['amount_due'] > 0 ? $x['amount_due'] : $x['amount'], $all_payments))) ?></td>
                    <td></td>
                    <td><?= rupiah($total_paid) ?></td>
                    <td colspan="<?= (can('loans.edit') && $loan['status'] === 'active') ? 2 : 1 ?>"></td>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
