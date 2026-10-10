<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

$title = 'Dashboard';

$is_member = ($_SESSION['role'] ?? '') === 'Anggota';
$member_id = 0;

if ($is_member) {
    // Data pribadi anggota
    $stmt = db()->prepare('SELECT id FROM members WHERE user_id = ? LIMIT 1');
    $uid = current_user()['id'];
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $member_id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);

    $my_savings = 0.0;
    $my_savings_breakdown = [];
    if ($member_id) {
        $stmt = db()->prepare('SELECT st.name, COALESCE(SUM(sa.balance),0) AS total
                               FROM savings_accounts sa JOIN savings_types st ON st.id = sa.savings_type_id
                               WHERE sa.member_id = ? AND sa.status = "active" GROUP BY st.id ORDER BY st.id');
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $my_savings_breakdown[] = $row;
            $my_savings += (float) $row['total'];
        }

        $stmt = db()->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(l.amount - COALESCE((SELECT SUM(principal_amount) FROM loan_payments lp WHERE lp.loan_id = l.id),0)),0) AS outstanding
                               FROM loans l WHERE l.member_id = ? AND l.status = "active"');
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $loan_info = $stmt->get_result()->fetch_assoc();

        $stmt = db()->prepare('SELECT l.loan_number, l.monthly_payment,
                               (l.term_months - (SELECT COUNT(*) FROM loan_payments lp WHERE lp.loan_id = l.id)) AS remaining_terms,
                               DATE_ADD(COALESCE(l.disbursement_date, l.approval_date, l.application_date),
                                        INTERVAL (SELECT COUNT(*) FROM loan_payments lp WHERE lp.loan_id = l.id) + 1 MONTH) AS next_due
                               FROM loans l
                               WHERE l.member_id = ? AND l.status = "active"
                               ORDER BY l.id LIMIT 5');
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $my_loans = $stmt->get_result();
    }
    require __DIR__ . '/../../includes/header.php';
    ?>

    <div class="dashboard-stats">
        <div class="stat-card">
            <div class="stat-icon">◉</div>
            <div class="stat-info">
                <div class="stat-label">Saldo Simpanan Saya</div>
                <div class="stat-value"><?= rupiah($my_savings) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">◇</div>
            <div class="stat-info">
                <div class="stat-label">Pinjaman Aktif</div>
                <div class="stat-value"><?= rupiah($loan_info['outstanding'] ?? 0) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">▤</div>
            <div class="stat-info">
                <div class="stat-label">Cicilan Tersisa</div>
                <div class="stat-value"><?= number_format((int) ($loan_info['cnt'] ?? 0)) ?> pinjaman</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Simpanan Saya per Jenis</h3>
        <?php if (!$member_id): ?>
            <p class="text-center">Akun Anda belum terhubung dengan data anggota. Hubungi pengurus koperasi.</p>
        <?php elseif (empty($my_savings_breakdown)): ?>
            <p class="text-center">Belum ada rekening simpanan.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Jenis Simpanan</th><th>Saldo</th></tr></thead>
                <tbody>
                    <?php foreach ($my_savings_breakdown as $row): ?>
                        <tr><td><?= e($row['name']) ?></td><td><strong><?= rupiah($row['total']) ?></strong></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Pinjaman Aktif Saya</h3>
        <table class="table">
            <thead><tr><th>No. Pinjaman</th><th>Cicilan/Bulan</th><th>Sisa Angsuran</th><th>Jatuh Tempo Berikutnya</th></tr></thead>
            <tbody>
                <?php if (!isset($my_loans) || $my_loans->num_rows === 0): ?>
                    <tr><td colspan="4" class="text-center">Tidak ada pinjaman aktif.</td></tr>
                <?php else: ?>
                    <?php while ($row = $my_loans->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= e($row['loan_number']) ?></strong></td>
                            <td><?= rupiah($row['monthly_payment']) ?></td>
                            <td><?= (int) $row['remaining_terms'] ?>x</td>
                            <td><?= $row['next_due'] ? date('d/m/Y', strtotime($row['next_due'])) : '-' ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require __DIR__ . '/../../includes/footer.php';
    return;
}

// ===== Dashboard pengurus =====

// Total simpanan per jenis (doughnut + stat cards)
$savings_by_type = [];
$res = db()->query('SELECT st.name, COALESCE(SUM(sa.balance),0) AS total, COUNT(sa.id) AS accounts
                    FROM savings_types st
                    LEFT JOIN savings_accounts sa ON sa.savings_type_id = st.id AND sa.status = "active"
                    GROUP BY st.id ORDER BY st.id');
while ($row = $res->fetch_assoc()) {
    $savings_by_type[] = $row;
}
$total_savings = array_sum(array_column($savings_by_type, 'total'));

// Total kas & bank balance
$cashbank_balance = 0;
if (can('cashbank.view')) {
    $stmt = db()->query('SELECT COALESCE(SUM(balance), 0) AS total FROM cash_bank_accounts WHERE is_active = 1');
    $cashbank_balance = (float) $stmt->fetch_assoc()['total'];
}

// Total pinjaman aktif (outstanding pokok)
$stmt = db()->query('SELECT COALESCE(SUM(l.amount - COALESCE((SELECT SUM(principal_amount) FROM loan_payments lp WHERE lp.loan_id = l.id),0)),0) AS total
                     FROM loans l WHERE l.status = "active"');
$outstanding_loans = $stmt->fetch_assoc()['total'];

// Angsuran diterima bulan ini
$stmt = db()->query('SELECT COALESCE(SUM(amount),0) AS total FROM loan_payments
                     WHERE MONTH(payment_date) = MONTH(CURRENT_DATE) AND YEAR(payment_date) = YEAR(CURRENT_DATE)');
$installments_this_month = $stmt->fetch_assoc()['total'];

// Pinjaman jatuh tempo belum bayar: angsuran yang sudah lewat jatuh tempo tapi belum dibayar
$stmt = db()->query('SELECT l.id, l.loan_number, m.member_number, m.full_name, l.monthly_payment,
                     COUNT(CASE WHEN lp.status != "paid" AND lp.due_date < CURRENT_DATE THEN 1 END) AS overdue_count
                     FROM loans l 
                     JOIN members m ON m.id = l.member_id
                     JOIN loan_payments lp ON lp.loan_id = l.id
                     WHERE l.status = "active"
                     GROUP BY l.id
                     HAVING overdue_count > 0
                     ORDER BY overdue_count DESC');
$overdue_loans = $stmt->fetch_all(MYSQLI_ASSOC);

// Trend simpanan vs angsuran 6 bulan terakhir
$chart_labels = $chart_savings = $chart_installments = [];
$month_keys = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-{$i} months"));
    $month_keys[$key] = count($chart_labels);
    $chart_labels[] = period_label($key);
}
$chart_savings = array_fill(0, count($chart_labels), 0.0);
$chart_installments = array_fill(0, count($chart_labels), 0.0);

$res = db()->query("SELECT DATE_FORMAT(st.transaction_date, '%Y-%m') AS ym,
                    COALESCE(SUM(CASE WHEN st.transaction_type = 'deposit' THEN st.amount ELSE -st.amount END), 0) AS total
                    FROM savings_transactions st
                    WHERE st.transaction_date >= DATE_SUB(CURRENT_DATE, INTERVAL 5 MONTH)
                    GROUP BY ym");
while ($row = $res->fetch_assoc()) {
    if (isset($month_keys[$row['ym']])) $chart_savings[$month_keys[$row['ym']]] = (float) $row['total'];
}
$res = db()->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
                    FROM loan_payments
                    WHERE payment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 5 MONTH)
                    GROUP BY ym");
while ($row = $res->fetch_assoc()) {
    if (isset($month_keys[$row['ym']])) $chart_installments[$month_keys[$row['ym']]] = (float) $row['total'];
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-icon">♙</div>
        <div class="stat-info">
            <div class="stat-label">Total Simpanan</div>
            <div class="stat-value"><?= rupiah($total_savings) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">◇</div>
        <div class="stat-info">
            <div class="stat-label">Pinjaman Aktif (Outstanding)</div>
            <div class="stat-value"><?= rupiah($outstanding_loans) ?></div>
        </div>
    </div>
    <?php if (can('cashbank.view')): ?>
    <div class="stat-card">
        <div class="stat-icon">💰</div>
        <div class="stat-info">
            <div class="stat-label">Kas & Bank</div>
            <div class="stat-value"><?= rupiah($cashbank_balance) ?></div>
        </div>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-icon">▤</div>
        <div class="stat-info">
            <div class="stat-label">Angsuran Diterima (Bulan Ini)</div>
            <div class="stat-value"><?= rupiah($installments_this_month) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">⌛</div>
        <div class="stat-info">
            <div class="stat-label">Pinjaman Jatuh Tempo Belum Bayar</div>
            <div class="stat-value"><?= count($overdue_loans) ?></div>
        </div>
    </div>
</div>

<div class="dashboard-stats">
    <?php foreach ($savings_by_type as $row): ?>
        <div class="stat-card">
            <div class="stat-icon">◉</div>
            <div class="stat-info">
                <div class="stat-label"><?= e($row['name']) ?></div>
                <div class="stat-value"><?= rupiah($row['total']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="dashboard-content" style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
    <div class="card">
        <h3>Tren Simpanan vs Angsuran (6 Bulan Terakhir)</h3>
        <canvas id="trendChart" height="120"></canvas>
    </div>
    <div class="card">
        <h3>Komposisi Simpanan</h3>
        <canvas id="savingsChart" height="220"></canvas>
    </div>
</div>

<?php if (!empty($overdue_loans)): ?>
<div class="card">
    <h3>Pinjaman Jatuh Tempo Belum Bayar</h3>
    <table class="table">
        <thead><tr><th>No. Pinjaman</th><th>Anggota</th><th>Cicilan</th><th>Tunggakan</th></tr></thead>
        <tbody>
            <?php foreach ($overdue_loans as $row): ?>
                <tr>
                    <td><strong><?= e($row['loan_number']) ?></strong></td>
                    <td><?= e($row['member_number']) ?> - <?= e($row['full_name']) ?></td>
                    <td><?= rupiah($row['monthly_payment']) ?></td>
                    <td><span class="badge badge-danger"><?= (int) $row['overdue_count'] ?> angsuran lewat jatuh tempo</span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    var rupiahTick = function (v) { return 'Rp ' + (v / 1000000).toFixed(1) + 'jt'; };
    var rupiahLabel = function (ctx) { return ctx.dataset.label + ': Rp ' + ctx.parsed.y.toLocaleString('id-ID'); };

    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                {
                    label: 'Simpanan (Setoran Bersih)',
                    data: <?= json_encode($chart_savings) ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'Angsuran Diterima',
                    data: <?= json_encode($chart_installments) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.1)',
                    tension: 0.3,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: { mode: 'index', intersect: false },
            scales: { y: { ticks: { callback: rupiahTick } } },
            plugins: { tooltip: { callbacks: { label: rupiahLabel } } }
        }
    });

    new Chart(document.getElementById('savingsChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($savings_by_type, 'name')) ?>,
            datasets: [{
                data: <?= json_encode(array_map('floatval', array_column($savings_by_type, 'total'))) ?>,
                backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                tooltip: {
                    callbacks: { label: function (ctx) { return ctx.label + ': Rp ' + ctx.parsed.toLocaleString('id-ID'); } }
                }
            }
        }
    });
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
