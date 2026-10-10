<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/loan_tools.php';

require_login();

$user = current_user();
if ($user['role'] !== 'Anggota') {
    redirect('pages/dashboard/index.php');
}

$stmt = db()->prepare("SELECT id FROM members WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

if (!$member) {
    http_response_code(403);
    exit('Data anggota tidak ditemukan atau tidak aktif.');
}

$products = db()->query('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY name ASC')->fetch_all(MYSQLI_ASSOC);
$errors = [];
$simulation = null;
$selected_product = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = (int) ($_POST['loan_product_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $tenor = (int) ($_POST['term_months'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM loan_products WHERE id = ? AND is_active = 1');
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $selected_product = $stmt->get_result()->fetch_assoc();

    if (!$selected_product) {
        $errors[] = 'Produk pinjaman tidak valid.';
    } else {
        $errors = validate_loan_simulation($selected_product, $amount, $tenor);
        if (empty($errors)) {
            try {
                $simulation = calculate_flat_loan($amount, (float) $selected_product['interest_rate'], $tenor);
                $simulation['principal'] = $amount;
                $simulation['rate'] = (float) $selected_product['interest_rate'];
                $simulation['tenor'] = $tenor;
                $simulation['product_name'] = $selected_product['name'];
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulasi Pinjaman - <?= e(APP_NAME) ?></title>
    <link rel="manifest" href="<?= url('manifest.json') ?>">
    <meta name="theme-color" content="#2563eb">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <script>
    (function(){try{var t=localStorage.getItem('pancakarya_theme');if(!t)t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
    </script>
    <style>
        :root {
            --page-bg: #f8f9fa;
            --surface: #ffffff;
            --border-color: #dee2e6;
            --text-color: #212529;
        }
        html[data-bs-theme="dark"] {
            --page-bg: #212529;
            --surface: #343a40;
            --border-color: #495057;
            --text-color: #f8f9fa;
        }
        body {
            background: var(--page-bg);
            color: var(--text-color);
            padding: 1rem;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .form-label { font-weight: 600; margin-bottom: 0.5rem; display: block; }
        .form-control, .form-select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.25rem;
            background: var(--surface);
            color: var(--text-color);
        }
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #5568d3; }
        .btn-secondary { background: #6c757d; color: white; }
        .alert { padding: 1rem; border-radius: 0.25rem; margin-bottom: 1rem; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        .result-box {
            background: linear-gradient(135deg, #60a5fa 0%, #2563eb 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            margin-top: 1rem;
        }
        .result-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .result-row:last-child { border: none; }
        .result-row.highlight { font-size: 1.25rem; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 800px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Simulasi Pinjaman</h2>
            <a href="<?= url('pages/portal.php') ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <h5 class="mb-3">Hitung Angsuran Pinjaman</h5>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Produk Pinjaman</label>
                    <select name="loan_product_id" class="form-select ts-select" required>
                        <option value="">-- Pilih Produk --</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($selected_product && $selected_product['id'] === $p['id']) ? 'selected' : '' ?>>
                            <?= e($p['name']) ?> — <?= $p['interest_rate'] ?>%/bln, maks <?= $p['max_tenor_months'] ?> bln
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($selected_product): ?>
                    <small class="text-muted d-block mt-2">
                        Plafon: <?= rupiah($selected_product['min_amount']) ?> s/d <?= rupiah($selected_product['max_amount']) ?>
                    </small>
                    <?php endif; ?>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Jumlah Pinjaman (Rp)</label>
                        <input type="number" name="amount" class="form-control" step="10000" min="1" value="<?= e($_POST['amount'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tenor (Bulan)</label>
                        <input type="number" name="term_months" class="form-control" min="1" value="<?= e($_POST['term_months'] ?? '12') ?>" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-calculator"></i> Simulasikan</button>
            </form>
        </div>

        <?php if ($simulation): ?>
        <div class="result-box">
            <h5 class="mb-3"><i class="bi bi-check-circle"></i> Hasil Simulasi</h5>
            <div class="result-row">
                <span>Produk:</span>
                <strong><?= e($simulation['product_name']) ?></strong>
            </div>
            <div class="result-row">
                <span>Pokok Pinjaman:</span>
                <strong><?= rupiah($simulation['principal']) ?></strong>
            </div>
            <div class="result-row">
                <span>Bunga Flat:</span>
                <strong><?= $simulation['rate'] ?>% per bulan</strong>
            </div>
            <div class="result-row">
                <span>Tenor:</span>
                <strong><?= $simulation['tenor'] ?> bulan</strong>
            </div>
            <hr style="border-color: rgba(255,255,255,0.3); margin: 1rem 0;">
            <div class="result-row">
                <span>Pokok per Bulan:</span>
                <strong><?= rupiah($simulation['principal_monthly']) ?></strong>
            </div>
            <div class="result-row">
                <span>Bunga per Bulan:</span>
                <strong><?= rupiah($simulation['interest_monthly']) ?></strong>
            </div>
            <div class="result-row highlight">
                <span>Angsuran per Bulan:</span>
                <strong><?= rupiah($simulation['monthly_payment']) ?></strong>
            </div>
            <hr style="border-color: rgba(255,255,255,0.3); margin: 1rem 0;">
            <div class="result-row">
                <span>Total Bunga:</span>
                <strong><?= rupiah($simulation['total_interest']) ?></strong>
            </div>
            <div class="result-row highlight">
                <span>Total Pembayaran:</span>
                <strong><?= rupiah($simulation['total_payment']) ?></strong>
            </div>
            <small class="d-block mt-3" style="opacity: 0.8;">
                <i class="bi bi-info-circle"></i> Simulasi ini menggunakan metode bunga flat. Angsuran sebenarnya akan diproses setelah pengajuan disetujui.
            </small>
        </div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script>document.querySelectorAll('.ts-select').forEach(el => new TomSelect(el));</script>
</body>
</html>
