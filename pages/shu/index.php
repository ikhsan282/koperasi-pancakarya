<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/shu_tools.php';

require_permission('shu.view');
$title = 'Distribusi SHU';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('shu.manage');
    verify_csrf();
    $fiscal_year = (int) ($_POST['fiscal_year'] ?? 0);
    $total_shu = trim((string) ($_POST['total_shu'] ?? ''));
    $pct_modal = (int) ($_POST['pct_jasa_modal'] ?? -1);
    if ($fiscal_year < 2000 || $fiscal_year > 2100) $errors[] = 'Tahun fiskal harus antara 2000 dan 2100.';
    if (!ctype_digit($total_shu) || (int) $total_shu <= 0) $errors[] = 'Total SHU harus bilangan bulat lebih besar dari nol.';
    if ($pct_modal < 0 || $pct_modal > 100) $errors[] = 'Persentase jasa modal harus 0 sampai 100.';

    if (!$errors) {
        redirect("pages/shu/breakdown.php?year={$fiscal_year}&total={$total_shu}&pct={$pct_modal}");
    }
}

$periods = db()->query("SELECT p.*, 
    u.full_name AS finalized_by_name,
    du.full_name AS distributed_by_name,
    (SELECT COUNT(*) FROM shu_distributions d WHERE d.period_id = p.id) AS member_count,
    (SELECT SUM(d.total_shu) FROM shu_distributions d WHERE d.period_id = p.id) AS total_distributed
    FROM shu_periods p 
    LEFT JOIN users u ON u.id = p.finalized_by 
    LEFT JOIN users du ON du.id = p.distributed_by
    ORDER BY p.fiscal_year DESC");

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if (can('shu.manage')): ?>
<div class="card">
    <h3>Hitung SHU Tahun Baru</h3>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="form-row">
            <div class="form-group"><label for="fiscal_year">Tahun Fiskal *</label><input type="number" id="fiscal_year" name="fiscal_year" min="2000" max="2100" value="<?= old('fiscal_year', date('Y')) ?>" required></div>
            <div class="form-group"><label for="total_shu">Total SHU (Rp) *</label><input type="number" id="total_shu" name="total_shu" min="1" step="1" value="<?= old('total_shu') ?>" required></div>
            <div class="form-group"><label for="pct_jasa_modal">Porsi Jasa Modal (%) *</label><input type="number" id="pct_jasa_modal" name="pct_jasa_modal" min="0" max="100" value="<?= old('pct_jasa_modal', 40) ?>" required><small>Sisanya menjadi jasa anggota.</small></div>
        </div>
        <button type="submit" class="btn btn-primary">Hitung Distribusi</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h3>Periode SHU Final</h3>
    <table class="table">
        <thead><tr><th>Tahun</th><th>Total SHU</th><th>Porsi Jasa Modal</th><th>Anggota</th><th>Status</th><th>Difinalisasi</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if ($periods->num_rows === 0): ?><tr><td colspan="7" class="text-center">Belum ada periode SHU final.</td></tr>
        <?php else: while ($period = $periods->fetch_assoc()): ?>
            <tr>
                <td><strong><?= e($period['fiscal_year']) ?></strong></td>
                <td><?= rupiah($period['total_shu']) ?></td>
                <td><?= e($period['pct_jasa_modal']) ?>%</td>
                <td><?= e($period['member_count']) ?> anggota</td>
                <td>
                    <?php if ($period['distributed_at']): ?>
                        <span class="badge badge-success">✓ Diposting</span>
                        <small class="text-muted d-block"><?= date('d M Y', strtotime($period['distributed_at'])) ?></small>
                    <?php else: ?>
                        <span class="badge badge-warning">Belum diposting</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($period['finalized_by_name'] ?? '-') ?>
                    <small class="text-muted d-block"><?= date('d M Y', strtotime($period['finalized_at'])) ?></small>
                </td>
                <td><a href="<?= url('pages/shu/breakdown.php?id=' . $period['id']) ?>" class="btn btn-sm btn-primary">Lihat</a></td>
            </tr>
        <?php endwhile; endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
