<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

$title = 'Dashboard';

// Stats
$stmt = db()->query('SELECT COUNT(*) AS total FROM members WHERE status = "active"');
$total_members = $stmt->fetch_assoc()['total'];

$stmt = db()->query('SELECT COALESCE(SUM(balance), 0) AS total FROM savings_accounts WHERE status = "active"');
$total_savings = $stmt->fetch_assoc()['total'];

$stmt = db()->query('SELECT COUNT(*) AS total FROM loans WHERE status IN ("pending", "approved")');
$pending_loans = $stmt->fetch_assoc()['total'];

$stmt = db()->query('SELECT COALESCE(SUM(amount - (SELECT COALESCE(SUM(principal_amount), 0) FROM loan_payments WHERE loan_id = loans.id)), 0) AS total 
                     FROM loans WHERE status = "active"');
$outstanding_loans = $stmt->fetch_assoc()['total'];

require __DIR__ . '/../../includes/header.php';
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-icon">♙</div>
        <div class="stat-info">
            <div class="stat-label">Anggota Aktif</div>
            <div class="stat-value"><?= number_format($total_members) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">◉</div>
        <div class="stat-info">
            <div class="stat-label">Total Simpanan</div>
            <div class="stat-value"><?= rupiah($total_savings) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">◇</div>
        <div class="stat-info">
            <div class="stat-label">Pinjaman Menunggu</div>
            <div class="stat-value"><?= number_format($pending_loans) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">▤</div>
        <div class="stat-info">
            <div class="stat-label">Pinjaman Berjalan</div>
            <div class="stat-value"><?= rupiah($outstanding_loans) ?></div>
        </div>
    </div>
</div>

<div class="dashboard-content">
    <div class="card">
        <h3>Selamat Datang, <?= e(current_user()['name']) ?></h3>
        <p>Anda masuk sebagai <strong><?= e(current_user()['role']) ?></strong></p>
        <p>Sistem Manajemen Koperasi Pancakarya telah siap digunakan.</p>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
