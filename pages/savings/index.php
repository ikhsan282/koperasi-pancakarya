<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('savings.view');

$title = 'Simpanan Anggota';

$search = trim($_GET['search'] ?? '');
$type = isset($_GET['type']) ? (int) $_GET['type'] : 0;

$sql = 'SELECT sa.*, m.member_number, m.full_name, st.name AS type_name
        FROM savings_accounts sa
        JOIN members m ON sa.member_id = m.id
        JOIN savings_types st ON sa.savings_type_id = st.id
        WHERE 1=1';
$params = [];
$types = '';

if ($search !== '') {
    $sql .= ' AND (m.member_number LIKE ? OR m.full_name LIKE ? OR sa.account_number LIKE ?)';
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

if ($type > 0) {
    $sql .= ' AND sa.savings_type_id = ?';
    $params[] = $type;
    $types .= 'i';
}

$sql .= ' ORDER BY sa.id DESC';

$stmt = db()->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$accounts = $stmt->get_result();

$savings_types = db()->query('SELECT * FROM savings_types WHERE is_active = 1 ORDER BY id');

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form">
        <input type="text" name="search" placeholder="Cari anggota, no. rekening..." value="<?= e($search) ?>">
        <select name="type">
            <option value="">Semua Jenis</option>
            <?php while ($t = $savings_types->fetch_assoc()): ?>
                <option value="<?= $t['id'] ?>" <?= $type === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endwhile; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if ($search || $type): ?><a href="<?= url('pages/savings/index.php') ?>" class="btn btn-text">Reset</a><?php endif; ?>
    </form>
    <?php if (can('savings.create')): ?>
        <a href="<?= url('pages/savings/form.php') ?>" class="btn btn-primary">+ Setoran</a>
    <?php endif; ?>
</div>

<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>No. Rekening</th>
                <th>Anggota</th>
                <th>Jenis Simpanan</th>
                <th>Saldo</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($accounts->num_rows === 0): ?>
                <tr><td colspan="6" class="text-center">Belum ada rekening simpanan.</td></tr>
            <?php else: ?>
                <?php
                $accounts->data_seek(0);
                while ($row = $accounts->fetch_assoc()):
                ?>
                    <tr>
                        <td><strong><?= e($row['account_number']) ?></strong></td>
                        <td>
                            <?= e($row['member_number']) ?><br>
                            <small><?= e($row['full_name']) ?></small>
                        </td>
                        <td><?= e($row['type_name']) ?></td>
                        <td><strong><?= rupiah($row['balance']) ?></strong></td>
                        <td>
                            <span class="badge badge-<?= $row['status'] === 'active' ? 'success' : 'danger' ?>">
                                <?= $row['status'] === 'active' ? 'Aktif' : 'Tutup' ?>
                            </span>
                        </td>
                        <td class="actions">
                            <a href="<?= url('pages/savings/form.php?account_id=' . $row['id']) ?>" class="btn btn-sm btn-primary">Transaksi</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
