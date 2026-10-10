<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.writeoff');

$writeoff_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($writeoff_id <= 0) {
    flash('error', 'ID writeoff tidak valid.');
    redirect('pages/loans/writeoff_list.php');
}

$stmt = db()->prepare('SELECT w.*, l.loan_number, l.amount AS loan_amount, l.status AS loan_status,
                              l.writeoff_status, m.member_number, m.full_name, m.phone,
                              u.full_name AS approver_name
                       FROM loan_writeoffs w
                       JOIN loans l ON w.loan_id = l.id
                       JOIN members m ON l.member_id = m.id
                       LEFT JOIN users u ON w.approved_by = u.id
                       WHERE w.id = ?');
$stmt->bind_param('i', $writeoff_id);
$stmt->execute();
$writeoff = $stmt->get_result()->fetch_assoc();

if (!$writeoff) {
    flash('error', 'Data writeoff tidak ditemukan.');
    redirect('pages/loans/writeoff_list.php');
}

$title = 'Detail Hapus Buku - ' . $writeoff['loan_number'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions" style="margin-bottom: 1.5rem;">
    <a href="<?= url('pages/loans/writeoff_list.php') ?>" class="btn btn-secondary">← Kembali ke Daftar</a>
    <a href="<?= url('pages/loans/detail.php?id=' . $writeoff['loan_id']) ?>" class="btn btn-secondary">Lihat Pinjaman</a>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0;">Detail Hapus Buku Kredit Macet</h3>
    
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
                <tr><th>Status Pinjaman</th><td>
                    <span class="badge badge-<?= $writeoff['loan_status'] === 'completed' ? 'info' : 'danger' ?>">
                        <?= ucfirst($writeoff['loan_status']) ?>
                    </span>
                </td></tr>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0; color: #c53030;">Informasi Hapus Buku</h3>
    
    <table class="table-info" style="max-width: 600px; margin-bottom: 1rem;">
        <tr><th style="width: 45%;">Tanggal Writeoff</th><td><?= date('d/m/Y', strtotime($writeoff['writeoff_date'])) ?></td></tr>
        <tr><th>Sisa Tagihan</th><td><?= rupiah($writeoff['remaining_balance']) ?></td></tr>
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

<?php if ($writeoff['approved_by']): ?>
<div class="card" style="background: #f0fff4; border: 1px solid #9ae6b4;">
    <h3 style="margin-top: 0; color: #22543d;">Informasi Persetujuan</h3>
    <table class="table-info" style="max-width: 600px;">
        <tr><th style="width: 45%;">Disetujui Oleh</th><td><strong><?= e($writeoff['approver_name']) ?></strong></td></tr>
        <tr><th>Tanggal Persetujuan</th><td><?= date('d/m/Y H:i', strtotime($writeoff['approved_at'])) ?></td></tr>
        <tr><th>Status</th><td><span class="badge badge-success">Disetujui & Diproses</span></td></tr>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
