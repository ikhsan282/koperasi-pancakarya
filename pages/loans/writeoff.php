<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.writeoff');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'ID pinjaman tidak valid.');
    redirect('pages/loans/index.php');
}

$stmt = db()->prepare('SELECT l.*, m.member_number, m.full_name, m.phone, lp.name AS product_name
                       FROM loans l
                       JOIN members m ON l.member_id = m.id
                       LEFT JOIN loan_products lp ON l.loan_product_id = lp.id
                       WHERE l.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();

if (!$loan) {
    flash('error', 'Data pinjaman tidak ditemukan.');
    redirect('pages/loans/index.php');
}

// Only allow write-off for active or defaulted loans
if (!in_array($loan['status'], ['active', 'defaulted'])) {
    flash('error', 'Hapus buku hanya dapat dilakukan untuk pinjaman aktif atau macet.');
    redirect('pages/loans/detail.php?id=' . $id);
}

// Check if already written off
if ($loan['writeoff_status'] !== 'none') {
    flash('error', 'Pinjaman ini sudah dilakukan hapus buku.');
    redirect('pages/loans/detail.php?id=' . $id);
}

// Calculate outstanding balance
$p_stmt = db()->prepare('SELECT SUM(amount_due + penalty_amount - amount_paid) AS outstanding 
                         FROM loan_payments 
                         WHERE loan_id = ? AND status = "pending"');
$p_stmt->bind_param('i', $id);
$p_stmt->execute();
$outstanding = (float) ($p_stmt->get_result()->fetch_assoc()['outstanding'] ?? 0);

if ($outstanding <= 0) {
    flash('error', 'Tidak ada sisa tagihan yang perlu dihapus buku.');
    redirect('pages/loans/detail.php?id=' . $id);
}

$title = 'Hapus Buku Kredit Macet - ' . $loan['loan_number'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions" style="margin-bottom: 1.5rem;">
    <a href="<?= url('pages/loans/detail.php?id=' . $loan['id']) ?>" class="btn btn-secondary">← Kembali ke Detail Pinjaman</a>
</div>

<div class="alert warning" style="margin-bottom: 1.5rem;">
    <strong>⚠ Peringatan:</strong> Hapus buku (write-off) adalah tindakan yang tidak dapat dibatalkan setelah disetujui. 
    Pastikan semua upaya penagihan telah dilakukan dan didokumentasikan dengan baik.
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0;">Informasi Pinjaman</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
        <div>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 40%;">No. Pinjaman</th><td><strong><?= e($loan['loan_number']) ?></strong></td></tr>
                <tr><th>No. Anggota</th><td><?= e($loan['member_number']) ?></td></tr>
                <tr><th>Nama Anggota</th><td><strong><?= e($loan['full_name']) ?></strong></td></tr>
                <tr><th>No. Telepon</th><td><?= e($loan['phone'] ?? '-') ?></td></tr>
            </table>
        </div>
        <div>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 40%;">Produk</th><td><?= e($loan['product_name'] ?? 'Umum') ?></td></tr>
                <tr><th>Pokok Pinjaman</th><td><?= rupiah($loan['amount']) ?></td></tr>
                <tr><th>Tenor</th><td><?= $loan['term_months'] ?> Bulan</td></tr>
                <tr><th>Status</th><td>
                    <span class="badge badge-<?= $loan['status'] === 'defaulted' ? 'danger' : 'warning' ?>">
                        <?= $loan['status'] === 'defaulted' ? 'Macet' : 'Aktif' ?>
                    </span>
                </td></tr>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <h3 style="margin-top: 0; color: #c53030;">Formulir Hapus Buku Kredit Macet</h3>
    
    <div style="background: #fff5f5; border: 1px solid #feb2b2; border-radius: 4px; padding: 1rem; margin-bottom: 1.5rem;">
        <div style="font-size: 0.95rem; margin-bottom: 0.5rem;"><strong>Total Sisa Tagihan (Outstanding):</strong></div>
        <div style="font-size: 1.5rem; font-weight: bold; color: #c53030;"><?= rupiah($outstanding) ?></div>
        <small style="display: block; margin-top: 0.5rem; color: #666;">
            Termasuk pokok, bunga, dan denda yang belum terbayar
        </small>
    </div>

    <form method="post" action="<?= url('pages/loans/writeoff_process.php?id=' . $loan['id']) ?>" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="outstanding_balance" value="<?= $outstanding ?>">

        <div class="form-group">
            <label for="writeoff_type">Jenis Hapus Buku *</label>
            <select id="writeoff_type" name="writeoff_type" required onchange="updateWriteoffAmount()">
                <option value="full">Hapus Buku Penuh (Seluruh Sisa Tagihan)</option>
                <option value="partial">Hapus Buku Sebagian (Partial Write-off)</option>
            </select>
            <small style="display: block; margin-top: 0.25rem; color: #666;">
                Hapus buku penuh: seluruh sisa tagihan dihapuskan. Hapus buku sebagian: hanya sebagian yang dihapuskan.
            </small>
        </div>

        <div class="form-group" id="amount-group">
            <label for="writeoff_amount">Jumlah yang Dihapus Buku (Rp) *</label>
            <input type="number" id="writeoff_amount" name="writeoff_amount" 
                   step="0.01" min="0.01" max="<?= $outstanding ?>" 
                   value="<?= $outstanding ?>" required>
            <small style="display: block; margin-top: 0.25rem; color: #666;">
                Maksimal: <?= rupiah($outstanding) ?>
            </small>
        </div>

        <div class="form-group">
            <label for="writeoff_date">Tanggal Hapus Buku *</label>
            <input type="date" id="writeoff_date" name="writeoff_date" 
                   value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
            <label for="reason">Alasan Hapus Buku *</label>
            <textarea id="reason" name="reason" rows="5" required 
                      placeholder="Jelaskan alasan hapus buku kredit macet ini, termasuk upaya penagihan yang telah dilakukan dan kondisi anggota..."></textarea>
            <small style="display: block; margin-top: 0.25rem; color: #666;">
                Dokumentasikan dengan lengkap: kronologi tunggakan, upaya penagihan, kondisi debitur, dll.
            </small>
        </div>

        <div class="alert info" style="margin-bottom: 1.5rem;">
            <strong>ℹ Catatan:</strong> Pengajuan hapus buku akan masuk status <strong>Menunggu Persetujuan</strong> 
            dan memerlukan persetujuan dari Super Admin sebelum dapat dieksekusi.
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-danger" 
                    onclick="return confirm('Apakah Anda yakin ingin mengajukan hapus buku untuk pinjaman ini?')">
                Ajukan Hapus Buku
            </button>
            <a href="<?= url('pages/loans/detail.php?id=' . $loan['id']) ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<script>
function updateWriteoffAmount() {
    const type = document.getElementById('writeoff_type').value;
    const amountInput = document.getElementById('writeoff_amount');
    const outstanding = <?= $outstanding ?>;
    
    if (type === 'full') {
        amountInput.value = outstanding;
        amountInput.readOnly = true;
        amountInput.style.background = '#f7fafc';
    } else {
        amountInput.readOnly = false;
        amountInput.style.background = '';
        amountInput.value = '';
    }
}

// Initialize on page load
updateWriteoffAmount();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
