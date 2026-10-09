<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('reports.view');

$title = 'Laporan';

require __DIR__ . '/../../includes/header.php';
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-icon">◉</div>
        <div class="stat-info">
            <div class="stat-label">Laporan Simpanan</div>
            <div class="stat-value">Per Periode</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">◇</div>
        <div class="stat-info">
            <div class="stat-label">Laporan Pinjaman</div>
            <div class="stat-value">Outstanding</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">▤</div>
        <div class="stat-info">
            <div class="stat-label">Laporan Arus Kas</div>
            <div class="stat-value">Per Tahun</div>
        </div>
    </div>
</div>

<div class="card">
    <h3>Laporan Tersedia</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Nama Laporan</th>
                <th>Deskripsi</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Laporan Simpanan per Periode</strong></td>
                <td>Setoran, penarikan, dan saldo simpanan per anggota berdasarkan bulan/tahun</td>
                <td>
                    <a href="<?= url('pages/reports/savings.php') ?>" class="btn btn-sm btn-primary">Lihat</a>
                </td>
            </tr>
            <tr>
                <td><strong>Laporan Pinjaman Outstanding</strong></td>
                <td>Sisa pokok dan bunga pinjaman per anggota (pinjaman belum lunas)</td>
                <td>
                    <a href="<?= url('pages/reports/loans.php') ?>" class="btn btn-sm btn-primary">Lihat</a>
                </td>
            </tr>
            <tr>
                <td><strong>Laporan Arus Kas</strong></td>
                <td>Pemasukan (setoran + angsuran) vs pengeluaran (penarikan + pencairan) per bulan</td>
                <td>
                    <a href="<?= url('pages/reports/cashflow.php') ?>" class="btn btn-sm btn-primary">Lihat</a>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
