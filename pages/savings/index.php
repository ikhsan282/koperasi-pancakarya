<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('savings.view');

$title = 'Simpanan Anggota';

$member_id = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;

$sql = 'SELECT sa.id, sa.account_number, sa.balance, sa.status,
               sa.member_id, m.member_number, m.full_name,
               st.id AS type_id, st.name AS type_name
        FROM savings_accounts sa
        JOIN members m ON sa.member_id = m.id
        JOIN savings_types st ON sa.savings_type_id = st.id
        WHERE 1=1';
$params = [];
$types = '';

if ($member_id > 0) {
    $sql .= ' AND sa.member_id = ?';
    $params[] = $member_id;
    $types .= 'i';
}

$sql .= ' ORDER BY m.full_name ASC, st.id ASC, sa.id ASC';

$stmt = db()->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Nest: member -> type -> accounts, with subtotals
$grouped = [];
foreach ($rows as $row) {
    $mid = $row['member_id'];
    $tid = $row['type_id'];
    if (!isset($grouped[$mid])) {
        $grouped[$mid] = [
            'member_number' => $row['member_number'],
            'full_name' => $row['full_name'],
            'types' => [],
            'total' => 0.0,
        ];
    }
    if (!isset($grouped[$mid]['types'][$tid])) {
        $grouped[$mid]['types'][$tid] = ['name' => $row['type_name'], 'accounts' => [], 'subtotal' => 0.0];
    }
    $grouped[$mid]['types'][$tid]['accounts'][] = $row;
    $grouped[$mid]['types'][$tid]['subtotal'] += (float) $row['balance'];
    $grouped[$mid]['total'] += (float) $row['balance'];
}

$grand = (float) db()->query('SELECT COALESCE(SUM(balance), 0) AS total FROM savings_accounts')->fetch_assoc()['total'];

$members = db()->query('SELECT id, member_number, full_name FROM members WHERE status = "active" ORDER BY full_name ASC');

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form">
        <select name="member_id">
            <option value="">-- Semua Anggota --</option>
            <?php while ($m = $members->fetch_assoc()): ?>
                <option value="<?= $m['id'] ?>" <?= $member_id === (int)$m['id'] ? 'selected' : '' ?>>
                    <?= e($m['member_number']) ?> - <?= e($m['full_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if ($member_id): ?><a href="<?= url('pages/savings/index.php') ?>" class="btn btn-text">Reset</a><?php endif; ?>
    </form>
    <?php if (can('savings.create')): ?>
        <a href="<?= url('pages/savings/form.php') ?>" class="btn btn-primary">+ Transaksi Simpanan</a>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <strong>Total Saldo Simpanan Seluruh Anggota: <?= rupiah($grand) ?></strong>
</div>

<div class="card">
    <h3>Rekap Simpanan per Anggota</h3>
    <?php if (empty($grouped)): ?>
        <p class="text-center">Belum ada data simpanan.</p>
    <?php else: ?>
        <?php foreach ($grouped as $mid => $g): ?>
            <table class="table" style="margin-bottom: 2rem;">
                <thead>
                    <tr>
                        <th colspan="3">
                            <?= e($g['member_number']) ?> — <?= e($g['full_name']) ?>
                            <a href="<?= url('pages/savings/book.php?member_id=' . $mid) ?>" class="btn btn-sm btn-text" target="_blank">📖 Cetak Buku</a>
                            <?php if (can('savings.create')): ?>
                                <a href="<?= url('pages/savings/form.php?member_id=' . $mid) ?>" class="btn btn-sm btn-text">+ Setoran</a>
                            <?php endif; ?>
                        </th>
                    </tr>
                    <tr>
                        <th>Jenis Simpanan / No. Rekening</th>
                        <th>Status</th>
                        <th>Subtotal Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($g['types'] as $t): ?>
                        <tr>
                            <td colspan="2"><strong><?= e($t['name']) ?></strong></td>
                            <td><strong><?= rupiah($t['subtotal']) ?></strong></td>
                        </tr>
                        <?php foreach ($t['accounts'] as $acc): ?>
                            <tr>
                                <td style="padding-left: 2rem;">
                                    <a href="<?= url('pages/savings/detail.php?id=' . $acc['id']) ?>"><?= e($acc['account_number']) ?></a>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $acc['status'] === 'active' ? 'success' : 'danger' ?>">
                                        <?= $acc['status'] === 'active' ? 'Aktif' : 'Tutup' ?>
                                    </span>
                                </td>
                                <td><?= rupiah($acc['balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align: right;">Total <?= e($g['full_name']) ?></th>
                        <th><?= rupiah($g['total']) ?></th>
                    </tr>
                </tfoot>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
