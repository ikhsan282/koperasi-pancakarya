<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.view');

$title = 'Laporan';

// Summary stats
$stmt = db()->query('SELECT 
    (SELECT COUNT(*) FROM members WHERE status = "active") AS total_members,
    (SELECT COALESCE(SUM(balance), 0) FROM savings_accounts WHERE status = "active") AS total_savings,
    (SELECT COUNT(*) FROM loans WHERE status = "active") AS active_loans,
    (SELECT COALESCE(SUM(l.amount - COALESCE((SELECT SUM(principal_amount) FROM loan_payments WHERE loan_id = l.id), 0)), 0) 
     FROM loans l WHERE l.status = "active") AS outstanding_loans,
    (SELECT COUNT(*) FROM savings_transactions WHERE MONTH(transaction_date) = MONTH(CURRENT_DATE) AND YEAR(transaction_date) = YEAR(CURRENT_DATE)) AS savings_trx_this_month,
    (SELECT COUNT(*) FROM loan_payments WHERE MONTH(payment_date) = MONTH(CURRENT_DATE) AND YEAR(payment_date) = YEAR(CURRENT_DATE)) AS loan_payments_this_month
');
$stats = $stmt->fetch_assoc();

// Recent activities
$activities = db()->query('SELECT * FROM activity_logs ORDER BY id DESC LIMIT 20');

require __DIR__ . '/../../includes/header.php';
?>

<div class="report-summary">
    <div class="report-card">
        <h4>Anggota Aktif</h4>
        <div class="report-value"><?= number_format($stats['total_members']) ?></div>
        <small>Total anggota terdaftar</small>
    </div>
    <div class="report-card">
        <h4>Total Simpanan</h4>
        <div class="report-value"><?= rupiah($stats['total_savings']) ?></div>
        <small>Saldo seluruh simpanan</small>
    </div>
    <div class="report-card">
        <h4>Pinjaman Aktif</h4>
        <div class="report-value"><?= number_format($stats['active_loans']) ?></div>
        <small><?= rupiah($stats['outstanding_loans']) ?> outstanding</small>
    </div>
</div>

<div class="report-summary">
    <div class="report-card">
        <h4>Transaksi Simpanan</h4>
        <div class="report-value"><?= number_format($stats['savings_trx_this_month']) ?></div>
        <small>Bulan ini</small>
    </div>
    <div class="report-card">
        <h4>Pembayaran Pinjaman</h4>
        <div class="report-value"><?= number_format($stats['loan_payments_this_month']) ?></div>
        <small>Bulan ini</small>
    </div>
</div>

<div class="card">
    <h3>Aktivitas Terbaru</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Waktu</th>
                <th>Aksi</th>
                <th>Keterangan</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($activities->num_rows === 0): ?>
                <tr><td colspan="4" class="text-center">Belum ada aktivitas.</td></tr>
            <?php else: ?>
                <?php while ($row = $activities->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                        <td><span class="badge badge-info"><?= e($row['action']) ?></span></td>
                        <td><?= e($row['description'] ?? '-') ?></td>
                        <td><small><?= e($row['ip_address'] ?? '-') ?></small></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
