<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('collateral.view');

$loan_id = isset($_GET['loan_id']) ? (int) $_GET['loan_id'] : 0;
if ($loan_id <= 0) {
    flash('error', 'ID pinjaman tidak valid.');
    redirect('pages/loans/index.php');
}

$stmt = db()->prepare('SELECT l.*, m.member_number, m.full_name FROM loans l JOIN members m ON l.member_id = m.id WHERE l.id = ?');
$stmt->bind_param('i', $loan_id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();

if (!$loan) {
    flash('error', 'Data pinjaman tidak ditemukan.');
    redirect('pages/loans/index.php');
}

$stmt = db()->prepare('SELECT * FROM loan_collaterals WHERE loan_id = ? ORDER BY created_at DESC');
$stmt->bind_param('i', $loan_id);
$stmt->execute();
$collaterals = $stmt->get_result();

$title = 'Agunan Pinjaman - ' . $loan['loan_number'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-actions" style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; justify-content: space-between; align-items: center;">
    <a href="<?= url('pages/loans/detail.php?id=' . $loan_id) ?>" class="btn btn-secondary">← Kembali ke Detail Pinjaman</a>
    <?php if (can('collateral.manage') && in_array($loan['status'], ['pending', 'approved', 'active'])): ?>
        <a href="<?= url('pages/collateral/form.php?loan_id=' . $loan_id) ?>" class="btn btn-primary">+ Tambah Agunan</a>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h3 style="margin-top: 0;">Informasi Pinjaman</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div><strong>No. Pinjaman:</strong> <?= e($loan['loan_number']) ?></div>
        <div><strong>Anggota:</strong> <?= e($loan['full_name']) ?> (<?= e($loan['member_number']) ?>)</div>
        <div><strong>Jumlah:</strong> <?= rupiah($loan['amount']) ?></div>
        <div><strong>Status:</strong> <span class="badge badge-<?= ['pending'=>'warning','approved'=>'info','active'=>'primary','completed'=>'success','rejected'=>'danger'][$loan['status']] ?? 'secondary' ?>"><?= e($loan['status']) ?></span></div>
    </div>
</div>

<div class="card">
    <h3 style="margin-top: 0;">Daftar Agunan</h3>
    
    <?php if ($collaterals->num_rows === 0): ?>
        <div class="alert warning">
            <strong>Belum ada agunan tercatat.</strong>
            <?php if ($loan['amount'] >= 5000000): ?>
                <br>Pinjaman senilai ≥ Rp 5.000.000 wajib memiliki minimal 1 agunan sebelum dapat disetujui.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Jenis</th>
                    <th>Deskripsi</th>
                    <th>Nilai Estimasi</th>
                    <th>Bukti Kepemilikan</th>
                    <th>Status</th>
                    <th>Tgl Kembali</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $types = [
                    'vehicle' => '🚗 Kendaraan',
                    'property' => '🏠 Properti',
                    'electronics' => '💻 Elektronik',
                    'jewelry' => '💍 Perhiasan',
                    'other' => '📦 Lainnya'
                ];
                while ($c = $collaterals->fetch_assoc()):
                ?>
                    <tr>
                        <td><?= $types[$c['collateral_type']] ?? e($c['collateral_type']) ?></td>
                        <td><?= e($c['description']) ?></td>
                        <td><?= rupiah($c['estimated_value']) ?></td>
                        <td>
                            <?php if ($c['photo_path']): ?>
                                <a href="<?= url($c['photo_path']) ?>" target="_blank" class="btn btn-sm btn-secondary">📷 Foto</a>
                            <?php endif; ?>
                            <?php if ($c['ownership_proof']): ?>
                                <a href="<?= url($c['ownership_proof']) ?>" target="_blank" class="btn btn-sm btn-secondary">📄 Dokumen</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c['status'] === 'held'): ?>
                                <span class="badge badge-warning">Ditahan</span>
                            <?php else: ?>
                                <span class="badge badge-success">Dikembalikan</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $c['return_date'] ? date('d/m/Y', strtotime($c['return_date'])) : '-' ?></td>
                        <td>
                            <?php if (can('collateral.manage')): ?>
                                <div style="display: flex; gap: 0.25rem;">
                                    <?php if ($c['status'] === 'held' && in_array($loan['status'], ['completed', 'paid'])): ?>
                                        <a href="<?= url('pages/collateral/return.php?id=' . $c['id']) ?>" class="btn btn-sm btn-success" onclick="return confirm('Tandai agunan ini sebagai sudah dikembalikan?')">✓ Kembalikan</a>
                                    <?php endif; ?>
                                    <?php if (in_array($loan['status'], ['pending', 'approved'])): ?>
                                        <a href="<?= url('pages/collateral/form.php?id=' . $c['id']) ?>" class="btn btn-sm btn-primary">Edit</a>
                                        <a href="<?= url('pages/collateral/process.php?id=' . $c['id'] . '&action=delete') ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus agunan ini?')">Hapus</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
