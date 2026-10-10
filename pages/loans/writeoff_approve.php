<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cashbank_helpers.php';

require_permission('loans.writeoff');

$writeoff_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($writeoff_id <= 0) {
    flash('error', 'ID writeoff tidak valid.');
    redirect('pages/loans/writeoff_list.php');
}

$stmt = db()->prepare('SELECT w.*, l.loan_number, l.amount AS loan_amount, l.status AS loan_status,
                              l.writeoff_status, m.member_number, m.full_name, m.phone
                       FROM loan_writeoffs w
                       JOIN loans l ON w.loan_id = l.id
                       JOIN members m ON l.member_id = m.id
                       WHERE w.id = ?');
$stmt->bind_param('i', $writeoff_id);
$stmt->execute();
$writeoff = $stmt->get_result()->fetch_assoc();

if (!$writeoff) {
    flash('error', 'Data writeoff tidak ditemukan.');
    redirect('pages/loans/writeoff_list.php');
}

if ($writeoff['approved_by']) {
    flash('info', 'Writeoff ini sudah disetujui.');
    redirect('pages/loans/writeoff_list.php');
}

$loan_id = (int) $writeoff['loan_id'];

// Calculate current outstanding balance
$p_stmt = db()->prepare('SELECT SUM(amount_due + penalty_amount - amount_paid) AS outstanding 
                         FROM loan_payments 
                         WHERE loan_id = ? AND status = "pending"');
$p_stmt->bind_param('i', $loan_id);
$p_stmt->execute();
$current_outstanding = (float) ($p_stmt->get_result()->fetch_assoc()['outstanding'] ?? 0);

// Handle approval POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    
    $action = $_POST['action'];
    
    if ($action === 'approve') {
        $cashbank_account_id = (int) ($_POST['cashbank_account_id'] ?? 0);
        $approval_notes = trim($_POST['approval_notes'] ?? '');
        
        $writeoff_amount = (float) $writeoff['writeoff_amount'];
        $is_full_writeoff = ($writeoff_amount >= $current_outstanding - 0.01);
        $writeoff_type = $is_full_writeoff ? 'full' : 'partial';
        
        $db = db();
        $db->begin_transaction();
        
        try {
            $uid = current_user()['id'];
            
            // Update writeoff record
            $stmt = $db->prepare('UPDATE loan_writeoffs SET approved_by = ?, approved_at = NOW() WHERE id = ?');
            $stmt->bind_param('ii', $uid, $writeoff_id);
            $stmt->execute();
            
            // Update loan
            if ($is_full_writeoff) {
                $stmt = $db->prepare('UPDATE loans SET writeoff_status = "full", writeoff_amount = ?, status = "completed" WHERE id = ?');
                $stmt->bind_param('di', $writeoff_amount, $loan_id);
                $stmt->execute();
                
                // Mark all pending payments as paid with writeoff note
                $stmt = $db->prepare('UPDATE loan_payments SET status = "paid", payment_date = ?, processed_by = ? WHERE loan_id = ? AND status = "pending"');
                $writeoff_date = $writeoff['writeoff_date'];
                $stmt->bind_param('sii', $writeoff_date, $uid, $loan_id);
                $stmt->execute();
            } else {
                $new_total_writeoff = (float) $writeoff['writeoff_amount'];
                $stmt = $db->prepare('UPDATE loans SET writeoff_status = "partial", writeoff_amount = writeoff_amount + ? WHERE id = ?');
                $stmt->bind_param('di', $new_total_writeoff, $loan_id);
                $stmt->execute();
            }
            
            // Post to cash/bank as expense (credit = uang keluar / kerugian)
            if ($cashbank_account_id > 0) {
                post_cashbank_transaction(
                    $cashbank_account_id,
                    'credit',
                    $writeoff_amount,
                    'loan_writeoff',
                    $writeoff_id,
                    "Hapus buku kredit macet {$writeoff['loan_number']} - {$writeoff['full_name']}",
                    $writeoff['writeoff_date']
                );
            }
            
            $db->commit();
            
            log_activity('loan_writeoff_approve', "Menyetujui hapus buku pinjaman {$writeoff['loan_number']} sebesar " . rupiah($writeoff_amount));
            flash('success', 'Hapus buku berhasil disetujui dan diproses.');
            redirect('pages/loans/writeoff_list.php');
        } catch (Throwable $e) {
            $db->rollback();
            flash('error', 'Gagal memproses persetujuan: ' . $e->getMessage());
        }
    } elseif ($action === 'reject') {
        $rejection_notes = trim($_POST['rejection_notes'] ?? '');
        
        if ($rejection_notes === '') {
            flash('error', 'Alasan penolakan wajib diisi.');
        } else {
            $db = db();
            try {
                $stmt = $db->prepare('DELETE FROM loan_writeoffs WHERE id = ?');
                $stmt->bind_param('i', $writeoff_id);
                $stmt->execute();
                
                log_activity('loan_writeoff_reject', "Menolak hapus buku pinjaman {$writeoff['loan_number']}: {$rejection_notes}");
                flash('success', 'Pengajuan hapus buku telah ditolak.');
                redirect('pages/loans/writeoff_list.php');
            } catch (Throwable $e) {
                flash('error', 'Gagal menolak writeoff: ' . $e->getMessage());
            }
        }
    }
}

$title = 'Persetujuan Hapus Buku - ' . $writeoff['loan_number'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions" style="margin-bottom: 1.5rem;">
    <a href="<?= url('pages/loans/writeoff_list.php') ?>" class="btn btn-secondary">← Kembali ke Daftar</a>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0;">Detail Pengajuan Hapus Buku</h3>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Data Anggota</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 40%;">No. Anggota</th><td><?= e($writeoff['member_number']) ?></td></tr>
                <tr><th>Nama Lengkap</th><td><strong><?= e($writeoff['full_name']) ?></strong></td></tr>
                <tr><th>No. Telepon</th><td><?= e($writeoff['phone'] ?? '-') ?></td></tr>
            </table>
        </div>
        
        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Data Pinjaman</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 40%;">No. Pinjaman</th><td><strong><?= e($writeoff['loan_number']) ?></strong></td></tr>
                <tr><th>Pokok Pinjaman</th><td><?= rupiah($writeoff['loan_amount']) ?></td></tr>
                <tr><th>Status</th><td>
                    <span class="badge badge-<?= $writeoff['loan_status'] === 'defaulted' ? 'danger' : 'warning' ?>">
                        <?= $writeoff['loan_status'] === 'defaulted' ? 'Macet' : ucfirst($writeoff['loan_status']) ?>
                    </span>
                </td></tr>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0; color: #c53030;">Detail Hapus Buku</h3>
    
    <table class="table-info" style="max-width: 600px; margin-bottom: 1rem;">
        <tr><th style="width: 45%;">Tanggal Writeoff</th><td><?= date('d/m/Y', strtotime($writeoff['writeoff_date'])) ?></td></tr>
        <tr><th>Sisa Tagihan Saat Pengajuan</th><td><?= rupiah($writeoff['remaining_balance']) ?></td></tr>
        <tr><th>Sisa Tagihan Saat Ini</th><td><strong><?= rupiah($current_outstanding) ?></strong></td></tr>
        <tr><th>Jumlah yang Dihapus</th><td><strong style="color: #c53030; font-size: 1.15rem;"><?= rupiah($writeoff['writeoff_amount']) ?></strong></td></tr>
        <tr><th>Jenis Writeoff</th><td>
            <span class="badge badge-<?= $writeoff['writeoff_amount'] >= $writeoff['remaining_balance'] - 0.01 ? 'danger' : 'warning' ?>">
                <?= $writeoff['writeoff_amount'] >= $writeoff['remaining_balance'] - 0.01 ? 'Full Writeoff' : 'Partial Writeoff' ?>
            </span>
        </td></tr>
    </table>
    
    <div>
        <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem;">Alasan Hapus Buku:</h4>
        <div style="background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 1rem; white-space: pre-wrap;">
<?= e($writeoff['reason']) ?>
        </div>
    </div>
</div>

<div class="card" style="background: #faf5ff; border: 1px solid #d6bcfa;">
    <h3 style="margin-top: 0; color: #553c9e;">Persetujuan Hapus Buku</h3>
    
    <div class="alert warning" style="margin-bottom: 1.5rem;">
        <strong>⚠ Perhatian:</strong> Setelah disetujui, hapus buku tidak dapat dibatalkan. 
        <?php if ($writeoff['writeoff_amount'] >= $current_outstanding - 0.01): ?>
            Pinjaman akan ditandai <strong>lunas</strong> dan semua cicilan pending akan ditutup.
        <?php else: ?>
            Sebagian sisa tagihan akan dihapuskan, sisanya tetap dapat ditagih.
        <?php endif; ?>
    </div>
    
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="approve">
        
        <?php if (can('cashbank.manage')): ?>
        <div class="form-group">
            <label for="cashbank_account_id">Catat ke Akun Kas/Bank sebagai Kerugian</label>
            <select id="cashbank_account_id" name="cashbank_account_id">
                <option value="">-- Tidak dicatat --</option>
                <?php
                $cb_accounts = db()->query('SELECT id, account_name, balance FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name');
                while ($cba = $cb_accounts->fetch_assoc()): ?>
                    <option value="<?= $cba['id'] ?>">
                        <?= e($cba['account_name']) ?> - Saldo: <?= rupiah($cba['balance']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <small style="display: block; margin-top: 0.25rem; color: #666;">
                Opsional: catat writeoff sebagai pengeluaran (kerugian kredit macet)
            </small>
        </div>
        <?php endif; ?>
        
        <div class="form-group">
            <label for="approval_notes">Catatan Persetujuan (Opsional)</label>
            <textarea id="approval_notes" name="approval_notes" rows="2" 
                      placeholder="Tambahkan catatan jika diperlukan..."></textarea>
        </div>
        
        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <button type="submit" class="btn btn-success" 
                    onclick="return confirm('Setujui hapus buku kredit macet ini? Tindakan tidak dapat dibatalkan.')">
                ✓ Setujui Hapus Buku
            </button>
            <button type="button" class="btn btn-danger" 
                    onclick="document.getElementById('reject-form-box').style.display = 'block'">
                ✕ Tolak Pengajuan...
            </button>
        </div>
    </form>
    
    <div id="reject-form-box" style="display: none; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px dashed #cbd5e0;">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reject">
            <div class="form-group">
                <label for="rejection_notes"><strong>Alasan Penolakan *</strong></label>
                <textarea id="rejection_notes" name="rejection_notes" rows="3" required 
                          placeholder="Tuliskan alasan penolakan pengajuan hapus buku..."></textarea>
            </div>
            <button type="submit" class="btn btn-danger" 
                    onclick="return confirm('Yakin ingin menolak pengajuan hapus buku ini?')">
                Kirim Penolakan
            </button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
