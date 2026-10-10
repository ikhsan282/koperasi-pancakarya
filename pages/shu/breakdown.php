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

// PDF only for finalized periods: the draft depends on POSTed adjustments a GET link cannot carry.
if ($id > 0 && ($_GET['format'] ?? '') === 'pdf') {
    require_permission('reports.export');
    require_once __DIR__ . '/../../includes/pdf.php';
    $pdf = new SimplePDF('Rincian SHU ' . $period['fiscal_year'], APP_NAME, true);
    $pdf->heading('Rincian SHU ' . $period['fiscal_year'], 1);
    $pdf->text('Total SHU: ' . rupiah($period['total_shu']) . ' | Jasa modal: ' . $period['pct_jasa_modal'] . '%', 10);
    $pdf->ln(4);
    $tbl = [];
    foreach ($rows as $r) {
        $tbl[] = [
            $r['member_number'], $r['full_name'], rupiah($r['savings_base']), rupiah($r['loan_base']),
            rupiah($r['jasa_modal']), rupiah($r['jasa_anggota']), rupiah($r['adjustment']), rupiah($r['total_shu']),
        ];
    }
    $tbl[] = ['Total', '', rupiah($totals['savings_base']), rupiah($totals['loan_base']), rupiah($totals['jasa_modal']),
        rupiah($totals['jasa_anggota']), rupiah($totals['adjustment']), rupiah($totals['total_shu'])];
    $pdf->table(
        ['No. Anggota', 'Nama', 'Dasar Simpanan', 'Dasar Pinjaman', 'Jasa Modal', 'Jasa Anggota', 'Penyesuaian', 'Total SHU'],
        $tbl
    );
    $pdf->download('Rincian SHU ' . $period['fiscal_year'] . '.pdf');
}

require __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="page-actions">
    <a href="<?= url('pages/shu/index.php') ?>" class="btn btn-secondary">Kembali ke SHU</a>
    <?php if ($id > 0 && can('reports.export')): ?>
        <a href="<?= url('pages/shu/breakdown.php?id=' . $id . '&format=pdf') ?>" class="btn btn-success">Export PDF</a>
    <?php endif; ?>
    <?php if ($id > 0 && !($period['distributed_at'] ?? null) && can('shu.distribute')): ?>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('distributeModal').style.display='block'">
            <i class="bi bi-cash-stack"></i> Posting ke Simpanan
        </button>
    <?php endif; ?>
</div>

<div class="report-summary">
    <div class="report-card"><h4>Total SHU</h4><div class="report-value"><?= rupiah($period['total_shu']) ?></div><small>Tahun <?= e($period['fiscal_year']) ?></small></div>
    <div class="report-card"><h4>Porsi Jasa Modal</h4><div class="report-value"><?= e($period['pct_jasa_modal']) ?>%</div><small>Sisanya jasa anggota <?= 100 - (int) $period['pct_jasa_modal'] ?>%</small></div>
    <div class="report-card"><h4>Total Terdistribusi</h4><div class="report-value"><?= rupiah($totals['total_shu']) ?></div><small><?= abs($totals['total_shu'] - (float) $period['total_shu']) < 0.005 ? 'Sesuai total SHU' : 'Belum sesuai total SHU' ?></small></div>
    <?php if ($period['distributed_at'] ?? null): ?>
    <div class="report-card"><h4>Status Distribusi</h4><div class="report-value text-success">✓ Diposting</div><small><?= date('d M Y', strtotime($period['distributed_at'])) ?></small></div>
    <?php endif; ?>
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

<?php if ($id > 0 && !($period['distributed_at'] ?? null) && can('shu.distribute')): ?>
<!-- Distribution Modal -->
<div id="distributeModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; padding:2rem;">
    <div class="card" style="max-width:500px; margin:auto;">
        <h3>Posting SHU ke Simpanan</h3>
        <p>SHU akan diposting sebagai transaksi deposit ke rekening simpanan anggota.</p>
        <form method="post" action="<?= url('pages/shu/distribute.php') ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="period_id" value="<?= $id ?>">
            <div class="form-group">
                <label for="savings_type_id">Jenis Simpanan *</label>
                <select id="savings_type_id" name="savings_type_id" required>
                    <option value="">-- Pilih Jenis Simpanan --</option>
                    <?php
                    $types = db()->query("SELECT id, name FROM savings_types WHERE status = 'active' ORDER BY name");
                    while ($type = $types->fetch_assoc()):
                    ?>
                    <option value="<?= $type['id'] ?>"><?= e($type['name']) ?></option>
                    <?php endwhile; ?>
                </select>
                <small>Pilih jenis simpanan untuk menerima dana SHU. Akun akan dibuat otomatis jika belum ada.</small>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('distributeModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary" onclick="return confirm('Yakin posting SHU ke simpanan anggota? Transaksi tidak dapat dibatalkan.');">Posting SHU</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
