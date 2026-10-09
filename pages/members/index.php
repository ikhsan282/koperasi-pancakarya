<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('members.view');

$title = 'Data Anggota';

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$sql = 'SELECT * FROM members WHERE 1=1';
$params = [];
$types = '';

if ($search !== '') {
    $search = addcslashes($search, '%_\\');
    $sql .= ' AND (member_number LIKE ? OR full_name LIKE ? OR phone LIKE ?)';
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

if ($status !== '') {
    $sql .= ' AND status = ?';
    $params[] = $status;
    $types .= 's';
}

$sql .= ' ORDER BY id DESC';

$stmt = db()->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$members = $stmt->get_result();

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form">
        <input type="text" name="search" placeholder="Cari nama, no. anggota, no. HP..." value="<?= e($search) ?>">
        <select name="status">
            <option value="">Semua Status</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktif</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Tidak Aktif</option>
            <option value="resigned" <?= $status === 'resigned' ? 'selected' : '' ?>>Keluar</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if ($search || $status): ?><a href="<?= url('pages/members/index.php') ?>" class="btn btn-text">Reset</a><?php endif; ?>
    </form>
    <?php if (can('members.create')): ?>
        <a href="<?= url('pages/members/form.php') ?>" class="btn btn-primary">+ Tambah Anggota</a>
    <?php endif; ?>
</div>

<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>No. Anggota</th>
                <th>Nama Lengkap</th>
                <th>No. HP</th>
                <th>Tanggal Gabung</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($members->num_rows === 0): ?>
                <tr><td colspan="6" class="text-center">Belum ada data anggota.</td></tr>
            <?php else: ?>
                <?php while ($row = $members->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row['member_number']) ?></strong></td>
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= e($row['phone'] ?? '-') ?></td>
                        <td><?= date('d/m/Y', strtotime($row['join_date'])) ?></td>
                        <td>
                            <span class="badge badge-<?= $row['status'] === 'active' ? 'success' : ($row['status'] === 'inactive' ? 'warning' : 'danger') ?>">
                                <?= $row['status'] === 'active' ? 'Aktif' : ($row['status'] === 'inactive' ? 'Tidak Aktif' : 'Keluar') ?>
                            </span>
                        </td>
                        <td class="actions">
                            <?php if (can('members.edit')): ?>
                                <a href="<?= url('pages/members/form.php?id=' . $row['id']) ?>" class="btn btn-sm btn-secondary">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
