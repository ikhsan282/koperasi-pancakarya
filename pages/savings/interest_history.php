<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('savings.view');

$title = 'Riwayat Bunga Simpanan';

// Filter parameters
$period_month = isset($_GET['month']) ? (int) $_GET['month'] : 0;
$period_year = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$member_id = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;

// Build query
$sql = 'SELECT sih.*, sa.account_number, m.member_number, m.full_name, st.name AS type_name,
               u.full_name AS posted_by_name
        FROM savings_interest_history sih
        JOIN savings_accounts sa ON sih.savings_account_id = sa.id
        JOIN members m ON sa.member_id = m.id
        JOIN savings_types st ON sa.savings_type_id = st.id
        LEFT JOIN users u ON sih.posted_by = u.id
        WHERE 1=1';

$params = [];
$types = '';

if ($period_month > 0 && $period_year > 0) {
    $sql .= ' AND MONTH(sih.period_start) = ? AND YEAR(sih.period_start) = ?';
    $params[] = $period_month;
    $params[] = $period_year;
    $types .= 'ii';
}

if ($member_id > 0) {
    $sql .= ' AND sa.member_id = ?';
    $params[] = $member_id;
    $types .= 'i';
}

$sql .= ' ORDER BY sih.period_start DESC, m.full_name ASC, sa.account_number ASC';

$stmt = db()->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$history = $stmt->get_result();

// Calculate totals
$total_interest = 0.0;
$total_balance = 0.0;
$history_data = [];
while ($row = $history->fetch_assoc()) {
    $history_data[] = $row;
    $total_interest += (float) $row['interest_amount'];
    $total_balance += (float) $row['balance_base'];
}

// Get member list for filter
$members = db()->query('SELECT id, member_number, full_name FROM members WHERE status = "active" ORDER BY full_name ASC');

// Get distinct periods for filter
$periods_query = 'SELECT DISTINCT YEAR(period_start) AS year, MONTH(period_start) AS month
                  FROM savings_interest_history
                  ORDER BY year DESC, month DESC
                  LIMIT 36';
$periods = db()->query($periods_query);

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form">
        <select name="month" class="ts-select">
            <option value="">-- Semua Bulan --</option>
            <?php 
            $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                       'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            for ($m = 1; $m <= 12; $m++): 
            ?>
                <option value="<?= $m ?>" <?= $period_month === $m ? 'selected' : '' ?>>
                    <?= $months[$m] ?>
                </option>
            <?php endfor; ?>
        </select>
        <select name="year" class="ts-select">
            <option value="">-- Semua Tahun --</option>
            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $period_year === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <select name="member_id" class="ts-select">
            <option value="">-- Semua Anggota --</option>
            <?php while ($m = $members->fetch_assoc()): ?>
                <option value="<?= $m['id'] ?>" <?= $member_id === (int)$m['id'] ? 'selected' : '' ?>>
                    <?= e($m['member_number']) ?> - <?= e($m['full_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if ($period_month || $period_year || $member_id): ?>
            <a href="<?= url('pages/savings/interest_history.php') ?>" class="btn btn-text">Reset</a>
        <?php endif; ?>
    </form>
    <?php if (can('savings.post_interest')): ?>
        <a href="<?= url('pages/savings/interest_posting.php') ?>" class="btn btn-primary">Posting Bunga</a>
    <?php endif; ?>
</div>

<?php if (!empty($history_data)): ?>
<div class="card" style="margin-bottom: 1rem;">
    <strong>Total Periode Ini:</strong> 
    <?= rupiah($total_interest) ?> 
    (dari total saldo <?= rupiah($total_balance) ?>)
</div>
<?php endif; ?>

<div class="card">
    <h3>Riwayat Posting Bunga</h3>
    
    <?php if (empty($history_data)): ?>
        <p class="text-center">Belum ada riwayat posting bunga.
        <?php if (can('savings.post_interest')): ?>
            <a href="<?= url('pages/savings/interest_posting.php') ?>">Posting sekarang</a>
        <?php endif; ?>
        </p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Periode</th>
                    <th>No. Rekening</th>
                    <th>Anggota</th>
                    <th>Jenis</th>
                    <th>Saldo Dasar</th>
                    <th>Rate (%)</th>
                    <th>Bunga</th>
                    <th>Tgl Posting</th>
                    <th>Oleh</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history_data as $row): ?>
                    <tr>
                        <td><?= date('M Y', strtotime($row['period_start'])) ?></td>
                        <td>
                            <a href="<?= url('pages/savings/detail.php?id=' . $row['savings_account_id']) ?>">
                                <?= e($row['account_number']) ?>
                            </a>
                        </td>
                        <td><?= e($row['member_number']) ?> - <?= e($row['full_name']) ?></td>
                        <td><?= e($row['type_name']) ?></td>
                        <td><?= rupiah($row['balance_base']) ?></td>
                        <td><?= number_format($row['interest_rate'], 2) ?>%</td>
                        <td><strong><?= rupiah($row['interest_amount']) ?></strong></td>
                        <td><?= date('d/m/Y', strtotime($row['posted_date'])) ?></td>
                        <td><?= e($row['posted_by_name'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="6" style="text-align: right;">Total:</th>
                    <th><?= rupiah($total_interest) ?></th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
