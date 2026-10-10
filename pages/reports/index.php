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
    <div class="stat-card">
        <div class="stat-icon">⊞</div>
        <div class="stat-info">
            <div class="stat-label">Posisi Keuangan</div>
            <div class="stat-value">Neraca</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">◈</div>
        <div class="stat-info">
            <div class="stat-label">Laporan Laba Rugi</div>
            <div class="stat-value">SAK EP</div>
        </div>
    </div>
    <?php if (can('reports.kap')): ?>
    <div class="stat-card">
        <div class="stat-icon">⚠</div>
        <div class="stat-info">
            <div class="stat-label">Laporan KAP</div>
            <div class="stat-value">Kolektibilitas</div>
        </div>
    </div>
    <?php endif; ?>
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
                <td><strong>Laporan Arus Kas (Cash Flow Statement)</strong></td>
                <td>Arus kas operasional berbasis transaksi kas/bank dengan breakdown per akun dan periode fleksibel</td>
                <td>
                    <a href="<?= url('pages/reports/cashflow_statement.php') ?>" class="btn btn-sm btn-primary">Lihat</a>
                </td>
            </tr>
            <tr>
                <td><strong>Laporan Posisi Keuangan</strong></td>
                <td>Neraca koperasi: aset, kewajiban, dan ekuitas per tahun</td>
                <td>
                    <a href="<?= url('pages/reports/financial_position.php') ?>" class="btn btn-sm btn-primary">Lihat</a>
                </td>
            </tr>
            <tr>
                <td><strong>Laporan Laba Rugi</strong></td>
                <td>Pendapatan, beban, dan laba bersih koperasi per periode (SAK EP)</td>
                <td>
                    <a href="<?= url('pages/reports/income_statement.php') ?>" class="btn btn-sm btn-primary">Lihat</a>
                </td>
            </tr>
            <?php if (can('reports.kap')): ?>
            <tr>
                <td><strong>Laporan KAP (Kualitas Aktiva Produktif)</strong></td>
                <td>Kolektibilitas kredit: klasifikasi pinjaman berdasarkan keterlambatan (Lancar, KL, Diragukan, Macet) dan NPL ratio</td>
                <td>
                    <a href="<?= url('pages/reports/kap_report.php') ?>" class="btn btn-sm btn-primary">Lihat</a>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
