<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/shu_tools.php';

require_permission('shu.view');
$title = 'Rincian SHU';
$db = db();
$id = (int) ($_GET['id'] ?? 0);
$errors = [];
$rows = [];
$summary = [];

if ($id > 0) {
    // Finalized period: read-only view of stored distribution
    $stmt = $db->prepare('SELECT * FROM shu_periods WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $period = $stmt->get_result()->fetch_assoc();
    if (!$period) {
        flash('error', 'Periode SHU tidak ditemukan.');
        redirect('pages/shu/index.php');
    }
    $stmt = $db->prepare('SELECT d.*, m.member_number, m.full_name FROM shu_distributions d
        JOIN members m ON m.id = d.member_id WHERE d.period_id = ? ORDER BY m.full_name');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $title = 'Rincian SHU ' . $period['fiscal_year'];
} else {
    // Draft preview: computed live from inputs, adjustable before finalizing
    $fiscal_year = (int) ($_GET['year'] ?? 0);
    $total_shu = (int) ($_GET['total'] ?? 0);
    $pct_modal = (int) ($_GET['pct'] ?? -1);
    if ($fiscal_year < 2000 || $fiscal_year > 2100 || $total_shu <= 0 || $pct_modal < 0 || $pct_modal > 100) {
        flash('error', 'Parameter SHU tidak valid.');
        redirect('pages/shu/index.php');
    }
    $period = ['fiscal_year' => $fiscal_year, 'total_shu' => $total_shu, 'pct_jasa_modal' => $pct_modal, 'status' => 'draft'];
    $title = 'Draft SHU ' . $fiscal_year;
    try {
        $bases = shu_member_bases($db, $fiscal_year);
        $dist = calculate_shu_distribution($total_shu, $pct_modal, $bases);
        $adjustments = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
        }
        foreach ($_POST['adjustment'] ?? [] as $mid => $val) {
            $adjustments[(int) $mid] = (int) $val;
        }
        $dist = apply_shu_adjustments($dist, $adjustments);
        $names = array_column($bases, null, 'member_id');
        foreach ($dist as $d) {
            $rows[] = $d + ['member_number' => $names[$d['member_id']]['member_number'], 'full_name' => $names[$d['member_id']]['full_name'],
                'savings_base' => $names[$d['member_id']]['savings_base'], 'loan_base' => $names[$d['member_id']]['loan_base']];
        }
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
        $rows = [];
    }
}

$totals = [
    'savings_base' => array_sum(array_column($rows, 'savings_base')),
    'loan_base' => array_sum(array_column($rows, 'loan_base')),
    'jasa_modal' => array_sum(array_column($rows, 'jasa_modal')),
    'jasa_anggota' => array_sum(array_column($rows, 'jasa_anggota')),
    'adjustment' => array_sum(array_column($rows, 'adjustment')),
    'total_shu' => array_sum(array_column($rows, 'total_shu')),
];
$is_draft = ($period['status'] ?? '') !== 'finalized';

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="page-actions">
    <a href="<?= url('pages/shu/index.php') ?>" class="btn btn-secondary">Kembali ke SHU</a>
</div>

<div class="report-summary">
    <div class="report-card"><h4>Total SHU</h4><div class="report-value"><?= rupiah($period['total_shu']) ?></div><small>Tahun <?= e($period['fiscal_year']) ?></small></div>
    <div class="report-card"><h4>Porsi Jasa Modal</h4><div class="report-value"><?= e($period['pct_jasa_modal']) ?>%</div><small>Sisanya jasa anggota <?= 100 - (int) $period['pct_jasa_modal'] ?>%</small></div>
    <div class="report-card"><h4>Total Terdistribusi</h4><div class="report-value"><?= rupiah($totals['total_shu']) ?></div><small><?= abs($totals['total_shu'] - (float) $period['total_shu']) < 0.005 ? 'Sesuai total SHU' : 'Belum sesuai total SHU' ?></small></div>
</div>

<div class="card">
    <h3><?= $is_draft ? 'Draft Pembagian (dapat disesuaikan)' : 'Pembagian SHU Final' ?></h3>
    <?php if ($is_draft && $rows): ?><form method="post" action="<?= url('pages/shu/breakdown.php?year=' . $period['fiscal_year'] . '&total=' . (int) $period['total_shu'] . '&pct=' . $period['pct_jasa_modal']) ?>" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <?php endif; ?>
    <table class="table">
        <thead>
            <tr>
                <th>No. Anggota</th><th>Nama</th><th>Dasar Simpanan</th><th>Dasar Pinjaman (Bunga)</th>
                <th>Jasa Modal</th><th>Jasa Anggota</th><th>Penyesuaian</th><th>Total SHU</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center">Tidak ada anggota aktif untuk dihitung.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['member_number']) ?></td>
                <td><?= e($r['full_name']) ?></td>
                <td><?= rupiah($r['savings_base']) ?></td>
                <td><?= rupiah($r['loan_base']) ?></td>
                <td><?= rupiah($r['jasa_modal']) ?></td>
                <td><?= rupiah($r['jasa_anggota']) ?></td>
                <td>
                    <?php if ($is_draft): ?>
                        <input type="number" name="adjustment[<?= (int) $r['member_id'] ?>]" value="<?= (int) $r['adjustment'] ?>" step="1" aria-label="Penyesuaian <?= e($r['full_name']) ?>" style="max-width:140px;">
                    <?php else: ?>
                        <?= rupiah($r['adjustment']) ?>
                    <?php endif; ?>
                </td>
                <td><strong><?= rupiah($r['total_shu']) ?></strong></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2">Total</th>
                <th><?= rupiah($totals['savings_base']) ?></th>
                <th><?= rupiah($totals['loan_base']) ?></th>
                <th><?= rupiah($totals['jasa_modal']) ?></th>
                <th><?= rupiah($totals['jasa_anggota']) ?></th>
                <th><?= rupiah($totals['adjustment']) ?></th>
                <th><?= rupiah($totals['total_shu']) ?></th>
            </tr>
        </tfoot>
    </table>

    <?php if ($is_draft && $rows): ?>
        <p><small>Penyesuaian harus berjumlah nol. Tekan "Hitung Ulang" untuk memperbarui, lalu "Finalisasi" untuk menyimpan.</small></p>
        <div class="form-actions">
            <button type="submit" class="btn btn-secondary" formaction="<?= url('pages/shu/breakdown.php?year=' . $period['fiscal_year'] . '&total=' . (int) $period['total_shu'] . '&pct=' . $period['pct_jasa_modal']) ?>">Hitung Ulang</button>
            <button type="submit" class="btn btn-primary" formaction="<?= url('pages/shu/finalize.php') ?>" onclick="return confirm('Finalisasi distribusi SHU ini? Data tidak dapat diubah setelah disimpan.');">Finalisasi</button>
        </div>
        <input type="hidden" name="fiscal_year" value="<?= (int) $period['fiscal_year'] ?>">
        <input type="hidden" name="total_shu" value="<?= (int) $period['total_shu'] ?>">
        <input type="hidden" name="pct_jasa_modal" value="<?= (int) $period['pct_jasa_modal'] ?>">
    </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
