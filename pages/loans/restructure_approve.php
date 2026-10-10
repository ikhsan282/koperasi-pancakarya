<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.restructure');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'ID restrukturisasi tidak valid.');
    redirect('pages/loans/index.php');
}

$stmt = db()->prepare('SELECT r.*, l.loan_number, l.status AS loan_status, l.amount, m.member_number, m.full_name
                       FROM loan_restructures r
                       JOIN loans l ON r.loan_id = l.id
                       JOIN members m ON l.member_id = m.id
                       WHERE r.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$restructure = $stmt->get_result()->fetch_assoc();

if (!$restructure) {
    flash('error', 'Data restrukturisasi tidak ditemukan.');
    redirect('pages/loans/index.php');
}

if ($restructure['status'] !== 'pending') {
    flash('warning', 'Restrukturisasi ini sudah diproses.');
    redirect('pages/loans/detail.php?id=' . $restructure['loan_id']);
}

$type_labels = [
    'reschedule' => 'Reschedule - Ubah Jadwal Pembayaran',
    'extend_tenor' => 'Extend Tenor - Perpanjang Jangka Waktu',
    'reduce_rate' => 'Reduce Rate - Kurangi Bunga'
];

$title = 'Approval Restrukturisasi - ' . $restructure['loan_number'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions" style="margin-bottom: 1.5rem;">
    <a href="<?= url('pages/loans/detail.php?id=' . $restructure['loan_id']) ?>" class="btn btn-secondary">← Kembali ke Detail Pinjaman</a>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0; color: #c53030;">⚠️ Approval Restrukturisasi Pinjaman</h3>
    
    <div class="alert warning" style="margin-bottom: 1rem;">
        <strong>Perhatian:</strong> Menyetujui restrukturisasi akan mengubah struktur pinjaman dan regenerasi jadwal cicilan. Pastikan sudah diverifikasi dengan benar.
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Data Anggota & Pinjaman</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 45%;">No. Anggota</th><td><?= e($restructure['member_number']) ?></td></tr>
                <tr><th>Nama</th><td><strong><?= e($restructure['full_name']) ?></strong></td></tr>
                <tr><th>No. Pinjaman</th><td><?= e($restructure['loan_number']) ?></td></tr>
                <tr><th>Pokok Pinjaman</th><td><?= rupiah($restructure['amount']) ?></td></tr>
                <tr><th>Status Pinjaman</th><td><span class="badge badge-<?= $restructure['loan_status'] === 'active' ? 'primary' : 'warning' ?>"><?= ucfirst($restructure['loan_status']) ?></span></td></tr>
            </table>
        </div>

        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Detail Restrukturisasi</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 45%;">Jenis</th><td><strong><?= e($type_labels[$restructure['restructure_type']] ?? $restructure['restructure_type']) ?></strong></td></tr>
                <tr><th>Tgl Pengajuan</th><td><?= date('d/m/Y H:i', strtotime($restructure['created_at'])) ?></td></tr>
                <tr><th>Status</th><td><span class="badge badge-warning">Menunggu Approval</span></td></tr>
            </table>
        </div>
    </div>

    <div style="margin-bottom: 1.5rem;">
        <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Alasan Restrukturisasi</h4>
        <div style="padding: 0.75rem; background: #f7fafc; border-left: 4px solid #4299e1; border-radius: 4px;">
            <?= nl2br(e($restructure['reason'])) ?>
        </div>
    </div>

    <?php if (!empty($restructure['notes'])): ?>
    <div style="margin-bottom: 1.5rem;">
        <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Catatan Tambahan</h4>
        <div style="padding: 0.75rem; background: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 4px;">
            <?= nl2br(e($restructure['notes'])) ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0; color: #2b6cb0;">Perbandingan: Sebelum vs Sesudah</h3>
    
    <table class="table">
        <thead>
            <tr>
                <th style="width: 30%;">Item</th>
                <th style="text-align: right;">Sebelum Restrukturisasi</th>
                <th style="text-align: center; width: 80px;">→</th>
                <th style="text-align: right;">Sesudah Restrukturisasi</th>
                <th style="text-align: right;">Perubahan</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Tenor (Bulan)</strong></td>
                <td style="text-align: right;"><?= $restructure['old_term_months'] ?> bulan</td>
                <td style="text-align: center;">→</td>
                <td style="text-align: right;"><strong><?= $restructure['new_term_months'] ?> bulan</strong></td>
                <td style="text-align: right;">
                    <?php 
                    $diff = $restructure['new_term_months'] - $restructure['old_term_months'];
                    if ($diff > 0) echo '<span style="color: #d97706;">+' . $diff . ' bulan</span>';
                    elseif ($diff < 0) echo '<span style="color: #059669;">' . $diff . ' bulan</span>';
                    else echo '<span style="color: #6b7280;">Tidak berubah</span>';
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong>Bunga per Bulan</strong></td>
                <td style="text-align: right;"><?= $restructure['old_interest_rate'] ?>%</td>
                <td style="text-align: center;">→</td>
                <td style="text-align: right;"><strong><?= $restructure['new_interest_rate'] ?>%</strong></td>
                <td style="text-align: right;">
                    <?php 
                    $diff = $restructure['new_interest_rate'] - $restructure['old_interest_rate'];
                    if ($diff > 0) echo '<span style="color: #dc2626;">+' . number_format($diff, 2) . '%</span>';
                    elseif ($diff < 0) echo '<span style="color: #059669;">' . number_format($diff, 2) . '%</span>';
                    else echo '<span style="color: #6b7280;">Tidak berubah</span>';
                    ?>
                </td>
            </tr>
            <tr style="background: #f7fafc;">
                <td><strong>Angsuran per Bulan</strong></td>
                <td style="text-align: right;"><strong><?= rupiah($restructure['old_monthly_payment']) ?></strong></td>
                <td style="text-align: center;">→</td>
                <td style="text-align: right;"><strong style="color: #059669; font-size: 1.1em;"><?= rupiah($restructure['new_monthly_payment']) ?></strong></td>
                <td style="text-align: right;">
                    <?php 
                    $diff = $restructure['new_monthly_payment'] - $restructure['old_monthly_payment'];
                    $pct = $restructure['old_monthly_payment'] > 0 ? ($diff / $restructure['old_monthly_payment']) * 100 : 0;
                    if ($diff > 0) echo '<span style="color: #dc2626;">+' . rupiah($diff) . ' (+' . number_format($pct, 1) . '%)</span>';
                    elseif ($diff < 0) echo '<span style="color: #059669;">' . rupiah($diff) . ' (' . number_format($pct, 1) . '%)</span>';
                    else echo '<span style="color: #6b7280;">Tidak berubah</span>';
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong>Total Bunga</strong></td>
                <td style="text-align: right;"><?= rupiah($restructure['amount'] * ($restructure['old_interest_rate'] / 100) * $restructure['old_term_months']) ?></td>
                <td style="text-align: center;">→</td>
                <td style="text-align: right;"><?= rupiah($restructure['amount'] * ($restructure['new_interest_rate'] / 100) * $restructure['new_term_months']) ?></td>
                <td style="text-align: right;">
                    <?php 
                    $old_total_interest = $restructure['amount'] * ($restructure['old_interest_rate'] / 100) * $restructure['old_term_months'];
                    $new_total_interest = $restructure['amount'] * ($restructure['new_interest_rate'] / 100) * $restructure['new_term_months'];
                    $diff = $new_total_interest - $old_total_interest;
                    if ($diff > 0) echo '<span style="color: #dc2626;">+' . rupiah($diff) . '</span>';
                    elseif ($diff < 0) echo '<span style="color: #059669;">' . rupiah($diff) . '</span>';
                    else echo '<span style="color: #6b7280;">Tidak berubah</span>';
                    ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="card" style="background: #faf5ff; border: 1px solid #d6bcfa;">
    <h4 style="margin-top: 0; color: #553c9e;">Approval Restrukturisasi</h4>
    
    <div class="alert info" style="margin-bottom: 1rem;">
        <strong>ℹ Info:</strong> Setelah disetujui, sistem akan:
        <ul style="margin: 0.5rem 0 0 1.5rem;">
            <li>Update data pinjaman dengan tenor dan bunga baru</li>
            <li>Hapus jadwal cicilan yang belum dibayar</li>
            <li>Generate ulang jadwal cicilan dengan perhitungan baru</li>
            <li>Cicilan yang sudah dibayar tetap dipertahankan</li>
        </ul>
    </div>

    <form method="post" action="<?= url('pages/loans/restructure_approval_process.php?id=' . $id) ?>">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="approve">
        
        <div class="form-group" style="margin-bottom: 1rem;">
            <label for="approval_notes">Catatan Persetujuan (Opsional)</label>
            <textarea id="approval_notes" name="approval_notes" rows="2" placeholder="Tambahkan catatan jika diperlukan..."></textarea>
        </div>
        
        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <button type="submit" class="btn btn-success" onclick="return confirm('Setujui restrukturisasi ini? Jadwal cicilan akan digenerate ulang.')">
                ✓ Setujui Restrukturisasi
            </button>
            <button type="button" class="btn btn-danger" onclick="document.getElementById('reject-form-box').style.display = document.getElementById('reject-form-box').style.display === 'none' ? 'block' : 'none';">✕ Tolak Restrukturisasi...</button>
        </div>
    </form>

    <div id="reject-form-box" style="display: none; margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed #cbd5e0;">
        <form method="post" action="<?= url('pages/loans/restructure_approval_process.php?id=' . $id) ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reject">
            <div class="form-group" style="margin-bottom: 0.75rem;">
                <label for="rejection_notes"><strong>Alasan Penolakan *</strong></label>
                <textarea id="rejection_notes" name="rejection_notes" rows="2" required placeholder="Tuliskan alasan penolakan restrukturisasi..."></textarea>
            </div>
            <button type="submit" class="btn btn-danger" onclick="return confirm('Yakin ingin menolak restrukturisasi ini?')">Kirim Penolakan</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
