<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.view');

$title = 'Produk Pinjaman';

$products = db()->query('SELECT * FROM loan_products ORDER BY is_active DESC, name ASC');

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions">
    <?php if (can('loans.approve')): ?>
        <a href="<?= url('pages/loan-products/form.php') ?>" class="btn btn-primary">+ Tambah Produk</a>
    <?php endif; ?>
    <a href="<?= url('pages/loans/index.php') ?>" class="btn btn-secondary">Kembali ke Pinjaman</a>
</div>

<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>Nama Produk</th>
                <th>Bunga/Bulan</th>
                <th>Tenor Maks</th>
                <th>Min. Pinjaman</th>
                <th>Maks. Pinjaman</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($products->num_rows === 0): ?>
                <tr><td colspan="7" class="text-center">Belum ada produk pinjaman.</td></tr>
            <?php else: ?>
                <?php while ($p = $products->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($p['name']) ?></strong></td>
                        <td><?= $p['interest_rate'] ?>%</td>
                        <td><?= $p['max_tenor_months'] ?> Bulan</td>
                        <td><?= rupiah($p['min_amount']) ?></td>
                        <td><?= rupiah($p['max_amount']) ?></td>
                        <td>
                            <span class="badge badge-<?= $p['is_active'] ? 'success' : 'secondary' ?>">
                                <?= $p['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td class="actions">
                            <?php if (can('loans.approve')): ?>
                                <a href="<?= url('pages/loan-products/form.php?id=' . $p['id']) ?>" class="btn btn-sm btn-secondary">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
