<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/loan_tools.php';

require_permission('loans.create');

$title = 'Pengajuan Pinjaman Baru';
$errors = [];

$products_result = db()->query('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY name ASC');
$products = [];
while ($p = $products_result->fetch_assoc()) {
    $products[] = $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $member_id = (int) ($_POST['member_id'] ?? 0);
    $product_id = (int) ($_POST['loan_product_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $term_months = (int) ($_POST['term_months'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');

    $product = null;
    if ($product_id > 0) {
        $stmt = db()->prepare('SELECT * FROM loan_products WHERE id = ? AND is_active = 1');
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
    }

    if ($member_id <= 0) {
        $errors[] = 'Pilih anggota.';
    } else {
        $stmt = db()->prepare('SELECT id FROM members WHERE id = ? AND status = "active"');
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) $errors[] = 'Anggota tidak valid atau tidak aktif.';
    }

    if (!$product) {
        $errors[] = 'Produk pinjaman tidak valid atau sudah tidak aktif.';
    } else {
        $errors = array_merge($errors, validate_loan_simulation($product, $amount, $term_months));
    }

    if ($amount <= 0) $errors[] = 'Jumlah pinjaman harus lebih dari nol.';
    if ($purpose === '') $errors[] = 'Keperluan pinjaman wajib diisi.';

    if (empty($errors)) {
        $interest_rate = (float) $product['interest_rate'];
        $calculation = calculate_flat_loan($amount, $interest_rate, $term_months);
        $monthly_payment = $calculation['monthly_payment'];

        $prefix = 'L-' . date('Ym') . '-';
        $stmt = db()->prepare('SELECT COUNT(*) AS total FROM loans WHERE loan_number LIKE ?');
        $likePrefix = $prefix . '%';
        $stmt->bind_param('s', $likePrefix);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()['total'] + 1;
        $loan_number = $prefix . str_pad((string) $count, 4, '0', STR_PAD_LEFT);

        $stmt = db()->prepare('INSERT INTO loans (member_id, loan_product_id, loan_number, amount, interest_rate, term_months, monthly_payment, purpose, status, application_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", CURDATE())');
        $stmt->bind_param('iisddids', $member_id, $product_id, $loan_number, $amount, $interest_rate, $term_months, $monthly_payment, $purpose);
        $stmt->execute();

        log_activity('apply_loan', "Pengajuan pinjaman {$loan_number} produk {$product['name']}");
        flash('success', 'Pengajuan pinjaman berhasil dibuat dan menunggu persetujuan.');
        redirect('pages/loans/index.php');
    }
}

$members = db()->query('SELECT id, member_number, full_name FROM members WHERE status = "active" ORDER BY full_name ASC');

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($products)): ?>
    <div class="alert warning">
        Belum ada produk pinjaman aktif. Hubungi administrator untuk menambahkan produk pinjaman terlebih dahulu.
    </div>
<?php else: ?>
<div class="card">
    <form method="post" class="form" id="loan-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="form-group">
            <label for="member_id">Pilih Anggota *</label>
            <select id="member_id" name="member_id" class="ts-select" required>
                <option value="">-- Pilih Anggota --</option>
                <?php while ($m = $members->fetch_assoc()): ?>
                    <option value="<?= $m['id'] ?>" <?= (int)($_POST['member_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= e($m['member_number']) ?> - <?= e($m['full_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="loan_product_id">Produk Pinjaman *</label>
            <select id="loan_product_id" name="loan_product_id" class="ts-select" required>
                <option value="">-- Pilih Produk Pinjaman --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>"
                            data-rate="<?= $p['interest_rate'] ?>"
                            data-min="<?= $p['min_amount'] ?>"
                            data-max="<?= $p['max_amount'] ?>"
                            data-tenor="<?= $p['max_tenor_months'] ?>"
                            <?= (int)($_POST['loan_product_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= e($p['name']) ?> — <?= $p['interest_rate'] ?>%/bln, tenor maks. <?= $p['max_tenor_months'] ?> bln
                    </option>
                <?php endforeach; ?>
            </select>
            <div id="product-info" style="display: none; margin-top: 0.5rem; padding: 0.75rem; background: #f7fafc; border-radius: 4px; font-size: 0.9rem;"></div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="amount">Jumlah Pinjaman (Rp) *</label>
                <input type="number" id="amount" name="amount" step="10000" min="1" value="<?= e($_POST['amount'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="term_months">Tenor (Bulan) *</label>
                <input type="number" id="term_months" name="term_months" min="1" value="<?= e($_POST['term_months'] ?? '12') ?>" required>
            </div>
        </div>

        <div id="simulation" style="display: none; margin: 1.25rem 0; padding: 1rem; background: #ebf8ff; border: 1px solid #bee3f8; border-radius: 6px;">
            <h4 style="margin: 0 0 0.75rem; color: #2b6cb0;">Simulasi Angsuran (Bunga Flat)</h4>
            <table class="table-info" style="width: 100%; max-width: 600px;">
                <tr><th>Pokok Pinjaman</th><td id="sim-principal">-</td></tr>
                <tr><th>Pokok / Bulan</th><td id="sim-principal-monthly">-</td></tr>
                <tr><th>Bunga / Bulan</th><td id="sim-interest-monthly">-</td></tr>
                <tr><th><strong>Angsuran / Bulan</strong></th><td id="sim-monthly"><strong>-</strong></td></tr>
                <tr><th>Total Bunga</th><td id="sim-total-interest">-</td></tr>
                <tr><th>Total Pembayaran</th><td id="sim-total"><strong>-</strong></td></tr>
            </table>
            <small style="display: block; margin-top: 0.5rem; color: #4a5568;">Rumus: pokok/tenor + (pokok × bunga bulanan). Total bunga = pokok × bunga × tenor.</small>
        </div>

        <div class="form-group">
            <label for="purpose">Keperluan Pinjaman *</label>
            <textarea id="purpose" name="purpose" rows="3" required placeholder="Jelaskan keperluan pinjaman..."><?= e($_POST['purpose'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Ajukan Pinjaman</button>
            <a href="<?= url('pages/loans/index.php') ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<script>
(function () {
    const product = document.getElementById('loan_product_id');
    const amount = document.getElementById('amount');
    const tenor = document.getElementById('term_months');
    const info = document.getElementById('product-info');
    const simulation = document.getElementById('simulation');
    const rupiah = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');

    function update() {
        const opt = product.options[product.selectedIndex];
        if (!opt || !opt.value) {
            info.style.display = 'none';
            simulation.style.display = 'none';
            return;
        }
        const rate = parseFloat(opt.dataset.rate);
        const min = parseFloat(opt.dataset.min);
        const max = parseFloat(opt.dataset.max);
        const maxTenor = parseInt(opt.dataset.tenor, 10);

        amount.min = min;
        amount.max = max;
        tenor.max = maxTenor;
        info.innerHTML = '<strong>Ketentuan:</strong> Pinjaman ' + rupiah(min) + ' s/d ' + rupiah(max) + ', tenor maksimal ' + maxTenor + ' bulan, bunga flat ' + rate + '% per bulan.';
        info.style.display = 'block';

        const principal = parseFloat(amount.value);
        const months = parseInt(tenor.value, 10);
        if (principal > 0 && months > 0) {
            const principalMonthly = principal / months;
            const interestMonthly = principal * rate / 100;
            const monthly = principalMonthly + interestMonthly;
            const totalInterest = interestMonthly * months;
            const total = principal + totalInterest;
            document.getElementById('sim-principal').textContent = rupiah(principal);
            document.getElementById('sim-principal-monthly').textContent = rupiah(principalMonthly);
            document.getElementById('sim-interest-monthly').textContent = rupiah(interestMonthly) + ' (' + rate + '%)';
            document.getElementById('sim-monthly').innerHTML = '<strong>' + rupiah(monthly) + '</strong>';
            document.getElementById('sim-total-interest').textContent = rupiah(totalInterest);
            document.getElementById('sim-total').innerHTML = '<strong>' + rupiah(total) + '</strong>';
            simulation.style.display = 'block';
        } else {
            simulation.style.display = 'none';
        }
    }

    product.addEventListener('change', update);
    amount.addEventListener('input', update);
    tenor.addEventListener('input', update);
    update();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
