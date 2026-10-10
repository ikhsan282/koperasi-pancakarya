<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.writeoff');

$title = 'Daftar Hapus Buku Kredit Macet';

$status_filter = trim($_GET['status'] ?? '');

$sql = 'SELECT w.*, l.loan_number, l.amount AS loan_amount, m.member_number, m.full_name,
               u.full_name AS approver_name
        FROM loan_writeoffs w
        JOIN loans l ON w.loan_id = l.id
        JOIN members m ON l.member_id = m.id
        LEFT JOIN users u ON w.approved_by = u.id
        WHERE 1=1';
$params = [];
$types = '';

if ($status_filter === 'pending') {
    $sql .= ' AND w.approved_by IS NULL';
} elseif ($status_filter === 'approved') {
    $sql .= ' AND w.approved_by IS NOT NULL';
}

$sql .= ' ORDER BY w.id DESC';

$stmt = db()->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$writeoffs = $stmt->get_result();

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <form method="get" class="search-form">
        <select name="status">
            <option value="">Semua Status</option>
            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Menunggu Persetujuan</option>
            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Disetujui</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if ($status_filter): ?><a href="<?= url('pages/loans/writeoff_list.php') ?>" class="btn btn-text">Reset</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <h3 style="margin-top: 0;">Daftar Hapus Buku Kredit Macet</h3>
    
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>No. Pinjaman</th>
                <th>Anggota</th>
                <th>Pokok Pinjaman</th>
                <th>Jumlah Writeoff</th>
                <th>Tgl Writeoff</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($writeoffs->num_rows === 0): ?>
                <tr><td colspan="8" class="text-center">Belum ada data hapus buku.</td></tr>
            <?php else: ?>
                <?php while ($row = $writeoffs->fetch_assoc()): ?>
                    <tr>
                        <td><strong>#<?= $row['id'] ?></strong></td>
                        <td><?= e($row['loan_number']) ?></td>
                        <td>
                            <?= e($row['member_number']) ?><br>
                            <small><?= e($row['full_name']) ?></small>
                        </td>
                        <td><?= rupiah($row['loan_amount']) ?></td>
                        <td><strong style="color: #c53030;"><?= rupiah($row['writeoff_amount']) ?></strong></td>
                        <td><?= date('d/m/Y', strtotime($row['writeoff_date'])) ?></td>
                        <td>
                            <?php if ($row['approved_by']): ?>
                                <span class="badge badge-success">Disetujui</span>
                                <small style="display: block; margin-top: 0.25rem; color: #666;">
                                    <?= e($row['approver_name']) ?><br>
                                    <?= date('d/m/Y H:i', strtotime($row['approved_at'])) ?>
                                </small>
                            <?php else: ?>
                                <span class="badge badge-warning">Menunggu Approval</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <?php if (!$row['approved_by'] && can('loans.writeoff')): ?>
                                <a href="<?= url('pages/loans/writeoff_approve.php?id=' . $row['id']) ?>" class="btn btn-sm btn-primary">Proses</a>
                            <?php else: ?>
                                <a href="<?= url('pages/loans/writeoff_detail.php?id=' . $row['id']) ?>" class="btn btn-sm btn-secondary">Detail</a>
                            <?php endif; ?>
                            <a href="<?= url('pages/loans/detail.php?id=' . $row['loan_id']) ?>" class="btn btn-sm btn-secondary">Lihat Pinjaman</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
