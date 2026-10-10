<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/loan_tools.php';

require_permission('loans.restructure');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'ID pinjaman tidak valid.');
    redirect('pages/loans/index.php');
}

$stmt = db()->prepare('SELECT l.*, m.member_number, m.full_name, lp.name AS product_name
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

// Validate loan status
if (!in_array($loan['status'], ['active', 'completed'])) {
    flash('error', 'Hanya pinjaman dengan status aktif atau macet yang dapat direstrukturisasi.');
    redirect('pages/loans/detail.php?id=' . $id);
}

// Get payment statistics
$p_stmt = db()->prepare('SELECT COUNT(*) as total, 
                         SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as paid_count,
                         SUM(CASE WHEN status = "paid" THEN amount_paid ELSE 0 END) as total_paid
                         FROM loan_payments WHERE loan_id = ?');
$p_stmt->bind_param('i', $id);
$p_stmt->execute();
$payment_stats = $p_stmt->get_result()->fetch_assoc();

$remaining_balance = ((float)$loan['amount'] + (float)$loan['amount'] * ((float)$loan['interest_rate'] / 100) * (int)$loan['term_months']) - (float)$payment_stats['total_paid'];

$title = 'Restrukturisasi Pinjaman - ' . $loan['loan_number'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions" style="margin-bottom: 1.5rem;">
    <a href="<?= url('pages/loans/detail.php?id=' . $loan['id']) ?>" class="btn btn-secondary">← Kembali ke Detail Pinjaman</a>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0; color: #c53030;">⚠️ Restrukturisasi Pinjaman</h3>
    <div class="alert warning" style="margin-bottom: 1rem;">
        <strong>Perhatian:</strong> Restrukturisasi akan mengubah struktur pinjaman. Pastikan anggota telah setuju dengan perubahan yang akan dilakukan.
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Data Anggota</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 45%;">No. Anggota</th><td><?= e($loan['member_number']) ?></td></tr>
                <tr><th>Nama</th><td><strong><?= e($loan['full_name']) ?></strong></td></tr>
                <tr><th>No. Pinjaman</th><td><?= e($loan['loan_number']) ?></td></tr>
                <tr><th>Produk</th><td><?= e($loan['product_name'] ?? 'Umum') ?></td></tr>
            </table>
        </div>

        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Kondisi Saat Ini</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 45%;">Pokok Pinjaman</th><td><?= rupiah($loan['amount']) ?></td></tr>
                <tr><th>Tenor</th><td><strong><?= $loan['term_months'] ?> Bulan</strong></td></tr>
                <tr><th>Bunga/Bulan</th><td><strong><?= $loan['interest_rate'] ?>%</strong></td></tr>
                <tr><th>Angsuran/Bulan</th><td><strong style="color: #2b6cb0;"><?= rupiah($loan['monthly_payment']) ?></strong></td></tr>
            </table>
        </div>

        <div>
            <h4 style="font-size: 1rem; color: #555; margin-bottom: 0.5rem;">Progres Pembayaran</h4>
            <table class="table-info" style="width: 100%;">
                <tr><th style="width: 45%;">Total Cicilan</th><td><?= $payment_stats['total'] ?> cicilan</td></tr>
                <tr><th>Sudah Dibayar</th><td><?= $payment_stats['paid_count'] ?> cicilan</td></tr>
                <tr><th>Total Terbayar</th><td><?= rupiah($payment_stats['total_paid']) ?></td></tr>
                <tr><th>Sisa Kewajiban</th><td><strong style="color: #c53030;"><?= rupiah($remaining_balance) ?></strong></td></tr>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <h3 style="margin-top: 0;">Form Restrukturisasi</h3>
    
    <form method="post" action="<?= url('pages/loans/restructure_process.php?id=' . $loan['id']) ?>" id="restructure-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        
        <div class="form-group">
            <label for="restructure_type">Jenis Restrukturisasi *</label>
            <select id="restructure_type" name="restructure_type" required onchange="toggleRestructureFields()">
                <option value="">-- Pilih Jenis --</option>
                <option value="reschedule">Reschedule - Ubah Jadwal Pembayaran</option>
                <option value="extend_tenor">Extend Tenor - Perpanjang Jangka Waktu</option>
                <option value="reduce_rate">Reduce Rate - Kurangi Bunga</option>
            </select>
            <small style="color: #666; display: block; margin-top: 0.25rem;">
                • <strong>Reschedule:</strong> Buat ulang jadwal cicilan dengan term/rate yang sama<br>
                • <strong>Extend Tenor:</strong> Perpanjang jangka waktu, angsuran bulanan lebih kecil<br>
                • <strong>Reduce Rate:</strong> Turunkan bunga, angsuran bulanan lebih kecil
            </small>
        </div>

        <div class="form-group">
            <label for="reason">Alasan Restrukturisasi *</label>
            <textarea id="reason" name="reason" rows="3" required placeholder="Jelaskan alasan restrukturisasi, misal: kesulitan ekonomi anggota, dampak pandemi, dll."></textarea>
        </div>

        <div id="extend-fields" style="display: none;">
            <div class="form-group">
                <label for="new_term_months">Tenor Baru (Bulan) *</label>
                <input type="number" id="new_term_months" name="new_term_months" min="<?= $loan['term_months'] + 1 ?>" max="120" placeholder="Contoh: <?= $loan['term_months'] + 6 ?>" onchange="calculatePreview()">
                <small style="color: #666;">Tenor saat ini: <?= $loan['term_months'] ?> bulan. Minimal: <?= $loan['term_months'] + 1 ?> bulan</small>
            </div>
        </div>

        <div id="rate-fields" style="display: none;">
            <div class="form-group">
                <label for="new_interest_rate">Bunga Baru (%/Bulan) *</label>
                <input type="number" id="new_interest_rate" name="new_interest_rate" step="0.01" min="0" max="<?= $loan['interest_rate'] - 0.01 ?>" placeholder="Contoh: <?= max(0, $loan['interest_rate'] - 0.5) ?>" onchange="calculatePreview()">
                <small style="color: #666;">Bunga saat ini: <?= $loan['interest_rate'] ?>%. Maksimal: <?= max(0, $loan['interest_rate'] - 0.01) ?>%</small>
            </div>
        </div>

        <div id="preview-box" style="display: none; margin: 1.5rem 0; padding: 1rem; background: #f7fafc; border-left: 4px solid #4299e1; border-radius: 4px;">
            <h4 style="margin-top: 0; color: #2b6cb0;">Preview Restrukturisasi</h4>
            <table class="table-info" style="max-width: 600px;">
                <tr>
                    <th style="width: 40%;">Item</th>
                    <th style="text-align: center;">Sebelum</th>
                    <th style="text-align: center;">Sesudah</th>
                </tr>
                <tr>
                    <td>Tenor</td>
                    <td style="text-align: center;"><span id="old-tenor"><?= $loan['term_months'] ?></span> bulan</td>
                    <td style="text-align: center;"><strong id="new-tenor">-</strong> bulan</td>
                </tr>
                <tr>
                    <td>Bunga/Bulan</td>
                    <td style="text-align: center;"><span id="old-rate"><?= $loan['interest_rate'] ?></span>%</td>
                    <td style="text-align: center;"><strong id="new-rate">-</strong>%</td>
                </tr>
                <tr>
                    <td>Angsuran/Bulan</td>
                    <td style="text-align: center;"><?= rupiah($loan['monthly_payment']) ?></td>
                    <td style="text-align: center;"><strong id="new-payment" style="color: #38a169;">-</strong></td>
                </tr>
            </table>
        </div>

        <div class="form-group">
            <label for="notes">Catatan Tambahan (Opsional)</label>
            <textarea id="notes" name="notes" rows="2" placeholder="Catatan internal untuk approval..."></textarea>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary" id="submit-btn" disabled>Ajukan Restrukturisasi</button>
            <a href="<?= url('pages/loans/detail.php?id=' . $loan['id']) ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<script>
const loanData = {
    amount: <?= $loan['amount'] ?>,
    termMonths: <?= $loan['term_months'] ?>,
    interestRate: <?= $loan['interest_rate'] ?>,
    monthlyPayment: <?= $loan['monthly_payment'] ?>
};

function toggleRestructureFields() {
    const type = document.getElementById('restructure_type').value;
    const extendFields = document.getElementById('extend-fields');
    const rateFields = document.getElementById('rate-fields');
    const submitBtn = document.getElementById('submit-btn');
    const previewBox = document.getElementById('preview-box');
    
    extendFields.style.display = 'none';
    rateFields.style.display = 'none';
    previewBox.style.display = 'none';
    submitBtn.disabled = true;
    
    // Clear required attributes
    document.getElementById('new_term_months').required = false;
    document.getElementById('new_interest_rate').required = false;
    
    if (type === 'extend_tenor') {
        extendFields.style.display = 'block';
        document.getElementById('new_term_months').required = true;
    } else if (type === 'reduce_rate') {
        rateFields.style.display = 'block';
        document.getElementById('new_interest_rate').required = true;
    } else if (type === 'reschedule') {
        previewBox.style.display = 'block';
        document.getElementById('new-tenor').textContent = loanData.termMonths;
        document.getElementById('new-rate').textContent = loanData.interestRate;
        document.getElementById('new-payment').textContent = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(loanData.monthlyPayment);
        submitBtn.disabled = false;
    }
}

function calculatePreview() {
    const type = document.getElementById('restructure_type').value;
    const previewBox = document.getElementById('preview-box');
    const submitBtn = document.getElementById('submit-btn');
    
    let newTenor = loanData.termMonths;
    let newRate = loanData.interestRate;
    
    if (type === 'extend_tenor') {
        const input = parseFloat(document.getElementById('new_term_months').value);
        if (!input || input <= loanData.termMonths) {
            submitBtn.disabled = true;
            return;
        }
        newTenor = input;
    } else if (type === 'reduce_rate') {
        const input = parseFloat(document.getElementById('new_interest_rate').value);
        if (!input || input >= loanData.interestRate || input < 0) {
            submitBtn.disabled = true;
            return;
        }
        newRate = input;
    }
    
    // Calculate new monthly payment (flat rate)
    const principalMonthly = loanData.amount / newTenor;
    const interestMonthly = loanData.amount * (newRate / 100);
    const newMonthlyPayment = principalMonthly + interestMonthly;
    
    document.getElementById('new-tenor').textContent = newTenor;
    document.getElementById('new-rate').textContent = newRate.toFixed(2);
    document.getElementById('new-payment').textContent = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(newMonthlyPayment);
    
    previewBox.style.display = 'block';
    submitBtn.disabled = false;
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
