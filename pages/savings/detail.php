<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('savings.view');

$account_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($account_id <= 0) {
    flash('error', 'ID rekening tidak valid.');
    redirect('pages/savings/index.php');
}

$stmt = db()->prepare('SELECT sa.*, m.member_number, m.full_name, st.name AS type_name
                       FROM savings_accounts sa
                       JOIN members m ON sa.member_id = m.id
                       JOIN savings_types st ON sa.savings_type_id = st.id
                       WHERE sa.id = ?');
$stmt->bind_param('i', $account_id);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();

if (!$account) {
    flash('error', 'Rekening tidak ditemukan.');
    redirect('pages/savings/index.php');
}

$title = 'Detail Rekening - ' . $account['account_number'];

// Get all transactions
$stmt = db()->prepare('SELECT st.*, u.full_name AS processed_by_name
                       FROM savings_transactions st
                       LEFT JOIN users u ON st.processed_by = u.id
                       WHERE st.savings_account_id = ?
                       ORDER BY st.transaction_date DESC, st.id DESC');
$stmt->bind_param('i', $account_id);
$stmt->execute();
$transactions = $stmt->get_result();

// Calculate summary
$stmt = db()->prepare('SELECT 
                       COUNT(*) AS total_transactions,
                       COALESCE(SUM(CASE WHEN transaction_type = "deposit" THEN amount ELSE 0 END), 0) AS total_deposits,
                       COALESCE(SUM(CASE WHEN transaction_type = "withdrawal" THEN amount ELSE 0 END), 0) AS total_withdrawals
                       FROM savings_transactions
                       WHERE savings_account_id = ?');
$stmt->bind_param('i', $account_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// Get interest history for this account
$stmt = db()->prepare('SELECT sih.*, u.full_name AS posted_by_name
                       FROM savings_interest_history sih
                       LEFT JOIN users u ON sih.posted_by = u.id
                       WHERE sih.savings_account_id = ?
                       ORDER BY sih.period_start DESC
                       LIMIT 12');
$stmt->bind_param('i', $account_id);
$stmt->execute();
$interest_history = $stmt->get_result();

require __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
        <div>
            <h3>Informasi Rekening</h3>
        </div>
        <div>
            <a href="<?= url('pages/savings/book.php?member_id=' . $account['member_id']) ?>" class="btn btn-secondary" target="_blank">📖 Buku Tabungan</a>
            <a href="<?= url('pages/savings/print.php?account_id=' . $account_id) ?>" class="btn btn-secondary" target="_blank">🖨 Cetak Mutasi</a>
            <?php if (can('savings.create')): ?>
                <a href="<?= url('pages/savings/form.php?account_id=' . $account_id) ?>" class="btn btn-primary">+ Transaksi Baru</a>
            <?php endif; ?>
        </div>
    </div>
    
    <table class="table-info">
        <tr><th>No. Rekening</th><td><?= e($account['account_number']) ?></td></tr>
        <tr><th>Anggota</th><td><?= e($account['member_number']) ?> - <?= e($account['full_name']) ?></td></tr>
        <tr><th>Jenis Simpanan</th><td><?= e($account['type_name']) ?></td></tr>
        <tr><th>Tanggal Buka</th><td><?= date('d/m/Y', strtotime($account['opened_date'])) ?></td></tr>
        <tr><th>Status</th><td>
            <span class="badge badge-<?= $account['status'] === 'active' ? 'success' : 'danger' ?>">
                <?= $account['status'] === 'active' ? 'Aktif' : 'Tutup' ?>
            </span>
        </td></tr>
        <tr><th>Saldo Saat Ini</th><td><strong><?= rupiah($account['balance']) ?></strong></td></tr>
        <?php if ($account['last_interest_date']): ?>
        <tr><th>Terakhir Posting Bunga</th><td><?= date('d/m/Y', strtotime($account['last_interest_date'])) ?></td></tr>
        <?php endif; ?>
    </table>
</div>

<div class="card">
    <h3>Ringkasan Transaksi</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
        <div style="padding: 1rem; background: var(--success-bg, #e8f5e9); border-radius: 4px;">
            <small style="color: #666;">Total Setoran</small>
            <div style="font-size: 1.25rem; font-weight: 600; color: #2e7d32;"><?= rupiah($summary['total_deposits']) ?></div>
            <small style="color: #666;"><?= $summary['total_transactions'] ?> transaksi</small>
        </div>
        <div style="padding: 1rem; background: var(--warning-bg, #fff3e0); border-radius: 4px;">
            <small style="color: #666;">Total Penarikan</small>
            <div style="font-size: 1.25rem; font-weight: 600; color: #f57c00;"><?= rupiah($summary['total_withdrawals']) ?></div>
        </div>
        <div style="padding: 1rem; background: var(--primary-bg, #e3f2fd); border-radius: 4px;">
            <small style="color: #666;">Saldo Akhir</small>
            <div style="font-size: 1.25rem; font-weight: 600; color: #1976d2;"><?= rupiah($account['balance']) ?></div>
        </div>
    </div>
</div>

<?php if ($interest_history->num_rows > 0): ?>
<div class="card">
    <h3>Riwayat Bunga (12 bulan terakhir)</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Periode</th>
                <th>Saldo Dasar</th>
                <th>Rate (%)</th>
                <th>Bunga</th>
                <th>Tgl Posting</th>
                <th>Oleh</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($ih = $interest_history->fetch_assoc()): ?>
                <tr>
                    <td><?= date('M Y', strtotime($ih['period_start'])) ?></td>
                    <td><?= rupiah($ih['balance_base']) ?></td>
                    <td><?= number_format($ih['interest_rate'], 2) ?>%</td>
                    <td><strong><?= rupiah($ih['interest_amount']) ?></strong></td>
                    <td><?= date('d/m/Y', strtotime($ih['posted_date'])) ?></td>
                    <td><small><?= e($ih['posted_by_name'] ?? '-') ?></small></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <a href="<?= url('pages/savings/interest_history.php?member_id=' . $account['member_id']) ?>" class="btn btn-text">Lihat Semua Riwayat Bunga →</a>
</div>
<?php endif; ?>

<div class="card">
    <h3>Mutasi Rekening</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Jenis</th>
                <th>Jumlah</th>
                <th>Saldo Sebelum</th>
                <th>Saldo Sesudah</th>
                <th>Keterangan</th>
                <th>Petugas</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($transactions->num_rows === 0): ?>
                <tr><td colspan="7" class="text-center">Belum ada transaksi.</td></tr>
            <?php else: ?>
                <?php while ($trx = $transactions->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($trx['transaction_date'])) ?></td>
                        <td>
                            <span class="badge badge-<?= $trx['transaction_type'] === 'deposit' ? 'success' : 'warning' ?>">
                                <?= $trx['transaction_type'] === 'deposit' ? 'Setoran' : 'Penarikan' ?>
                            </span>
                        </td>
                        <td style="text-align: right; font-weight: 500;">
                            <?= $trx['transaction_type'] === 'deposit' ? '+' : '-' ?><?= rupiah($trx['amount']) ?>
                        </td>
                        <td style="text-align: right;"><?= rupiah($trx['balance_before']) ?></td>
                        <td style="text-align: right;"><strong><?= rupiah($trx['balance_after']) ?></strong></td>
                        <td><small><?= e($trx['description'] ?: '-') ?></small></td>
                        <td><small><?= e($trx['processed_by_name'] ?: '-') ?></small></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="form-actions">
    <a href="<?= url('pages/savings/index.php') ?>" class="btn btn-secondary">« Kembali</a>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
