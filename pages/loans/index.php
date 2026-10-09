<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.view');

$title = 'Data Pinjaman';

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$sql = 'SELECT l.*, m.member_number, m.full_name
        FROM loans l
        JOIN members m ON l.member_id = m.id
        WHERE 1=1';
$params = [];
$types = '';

if ($search !== '') {
    $sql .= ' AND (m.member_number LIKE ? OR m.full_name LIKE ? OR l.loan_number LIKE ?)';
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

if ($status !== '') {
    $sql .= ' AND l.status = ?';
    $params[] = $status;
    $types .= 's';
}

$sql .= ' ORDER BY l.id DESC';

$stmt = db()->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$loans = $stmt->get_result();

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form">
        <input type="text" name="search" placeholder="Cari anggota, no. pinjaman..." value="<?= e($search) ?>">
        <select name="status">
            <option value="">Semua Status</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Menunggu</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Disetujui</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktif</option>
            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Lunas</option>
            <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if ($search || $status): ?><a href="<?= url('pages/loans/index.php') ?>" class="btn btn-text">Reset</a><?php endif; ?>
    </form>
    <?php if (can('loans.create')): ?>
        <a href="<?= url('pages/loans/form.php') ?>" class="btn btn-primary">+ Ajukan Pinjaman</a>
    <?php endif; ?>
</div>

<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>No. Pinjaman</th>
                <th>Anggota</th>
                <th>Jumlah</th>
                <th>Jangka Waktu</th>
                <th>Cicilan/Bulan</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($loans->num_rows === 0): ?>
                <tr><td colspan="7" class="text-center">Belum ada data pinjaman.</td></tr>
            <?php else: ?>
                <?php while ($row = $loans->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row['loan_number']) ?></strong></td>
                        <td>
                            <?= e($row['member_number']) ?><br>
                            <small><?= e($row['full_name']) ?></small>
                        </td>
                        <td><?= rupiah($row['amount']) ?></td>
                        <td><?= $row['term_months'] ?> Bulan</td>
                        <td><?= rupiah($row['monthly_payment']) ?></td>
                        <td>
                            <?php
                            $badge_class = match($row['status']) {
                                'approved', 'active' => 'success',
                                'pending' => 'warning',
                                'paid' => 'info',
                                default => 'danger'
                            };
                            $status_label = match($row['status']) {
                                'pending' => 'Menunggu',
                                'approved' => 'Disetujui',
                                'active' => 'Aktif',
                                'paid' => 'Lunas',
                                'rejected' => 'Ditolak',
                                'defaulted' => 'Macet',
                                default => $row['status']
                            };
                            ?>
                            <span class="badge badge-<?= $badge_class ?>"><?= $status_label ?></span>
                        </td>
                        <td class="actions">
                            <a href="<?= url('pages/loans/form.php?id=' . $row['id']) ?>" class="btn btn-sm btn-secondary">Detail</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
