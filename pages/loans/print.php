<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.view');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    exit('ID tidak valid.');
}

$stmt = db()->prepare('SELECT l.*, m.member_number, m.full_name, m.id_number, m.phone, m.address, lp.name AS product_name, u.full_name AS approver_name
                       FROM loans l
                       JOIN members m ON l.member_id = m.id
                       LEFT JOIN loan_products lp ON l.loan_product_id = lp.id
                       LEFT JOIN users u ON l.approved_by = u.id
                       WHERE l.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();

if (!$loan) {
    exit('Data pinjaman tidak ditemukan.');
}

$p_stmt = db()->prepare('SELECT p.*, u.full_name AS processed_by_name
                         FROM loan_payments p
                         LEFT JOIN users u ON p.processed_by = u.id
                         WHERE p.loan_id = ?
                         ORDER BY p.payment_number ASC');
$p_stmt->bind_param('i', $id);
$p_stmt->execute();
$payments_res = $p_stmt->get_result();

$payments = [];
$total_paid = 0;
$total_due = 0;
while ($p = $payments_res->fetch_assoc()) {
    $payments[] = $p;
    $total_paid += (float) $p['amount_paid'];
    $total_due += (float) ($p['amount_due'] > 0 ? $p['amount_due'] : $p['amount']);
}

function terbilang($n): string {
    $n = (int) abs($n);
    $dasar = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($n < 12) return ' ' . $dasar[$n];
    if ($n < 20) return terbilang($n - 10) . ' belas';
    if ($n < 100) return terbilang((int)($n / 10)) . ' puluh' . terbilang($n % 10);
    if ($n < 200) return ' seratus' . terbilang($n - 100);
    if ($n < 1000) return terbilang((int)($n / 100)) . ' ratus' . terbilang($n % 100);
    if ($n < 2000) return ' seribu' . terbilang($n - 1000);
    if ($n < 1000000) return terbilang((int)($n / 1000)) . ' ribu' . terbilang($n % 1000);
    if ($n < 1000000000) return terbilang((int)($n / 1000000)) . ' juta' . terbilang($n % 1000000);
    if ($n < 1000000000000) return terbilang((int)($n / 1000000000)) . ' miliar' . terbilang($n % 1000000000);
    return (string) $n;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kwitansi & Ringkasan Pinjaman - <?= e($loan['loan_number']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-size: 13px;
            color: #222;
            background: #fff;
            margin: 0;
            padding: 24px;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #333;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .header .org-name {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .header .org-sub {
            font-size: 11px;
            color: #555;
            margin-top: 3px;
        }
        .header .doc-title {
            text-align: right;
        }
        .header .doc-title h2 {
            margin: 0;
            font-size: 16px;
            text-transform: uppercase;
        }
        .header .doc-title .doc-num {
            font-size: 12px;
            color: #444;
            font-family: monospace;
        }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            margin-top: 16px;
            margin-bottom: 8px;
            text-transform: uppercase;
            background: #f0f0f0;
            padding: 4px 8px;
            border-left: 3px solid #333;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 16px;
        }
        table.info-table {
            width: 100%;
            border-collapse: collapse;
        }
        table.info-table th, table.info-table td {
            padding: 4px 6px;
            vertical-align: top;
            text-align: left;
        }
        table.info-table th {
            width: 38%;
            color: #555;
            font-weight: normal;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 12px;
        }
        table.data-table th, table.data-table td {
            border: 1px solid #ccc;
            padding: 6px 8px;
            text-align: left;
        }
        table.data-table th {
            background: #f7f7f7;
            font-weight: bold;
        }
        table.data-table td.num, table.data-table th.num {
            text-align: right;
        }
        table.data-table td.center, table.data-table th.center {
            text-align: center;
        }
        .signatures {
            margin-top: 36px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            text-align: center;
            page-break-inside: avoid;
        }
        .signatures .sign-box {
            padding-top: 60px;
            border-top: 1px solid #333;
            margin-top: 50px;
            font-weight: bold;
        }
        .signatures .sign-role {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        .btn-print {
            background: #2b6cb0;
            color: #fff;
            border: none;
            padding: 8px 16px;
            font-size: 13px;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 16px;
        }
        .btn-print:hover { background: #2c5282; }
        .terbilang-box {
            margin: 12px 0;
            padding: 8px 12px;
            background: #fafafa;
            border: 1px dashed #bbb;
            font-style: italic;
        }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; font-size: 12px; }
            table.data-table th { background: #eee !important; -webkit-print-color-adjust: exact; }
            .section-title { background: #eee !important; -webkit-print-color-adjust: exact; }
            @page { margin: 15mm; size: auto; }
        }
    </style>
</head>
<body>

<div class="no-print" style="margin-bottom: 16px; display: flex; gap: 8px;">
    <button class="btn-print" onclick="window.print()">🖨 Cetak Sekarang</button>
    <button class="btn-print" style="background: #718096;" onclick="window.close()">Tutup</button>
</div>

<div class="header">
    <div>
        <div class="org-name"><?= e(APP_NAME) ?></div>
        <div class="org-sub">Dokumen resmi sistem <?= e(APP_NAME) ?></div>
    </div>
    <div class="doc-title">
        <h2>Kwitansi & Akad Pinjaman</h2>
        <div class="doc-num"><?= e($loan['loan_number']) ?></div>
    </div>
</div>

<div class="grid-2">
    <div>
        <div class="section-title">Identitas Anggota</div>
        <table class="info-table">
            <tr><th>No. Anggota</th><td>: <strong><?= e($loan['member_number']) ?></strong></td></tr>
            <tr><th>Nama Lengkap</th><td>: <?= e($loan['full_name']) ?></td></tr>
            <tr><th>No. Identitas/KTP</th><td>: <?= e($loan['id_number'] ?? '-') ?></td></tr>
            <tr><th>No. Telepon</th><td>: <?= e($loan['phone'] ?? '-') ?></td></tr>
            <tr><th>Alamat</th><td>: <?= e($loan['address'] ?? '-') ?></td></tr>
        </table>
    </div>
    <div>
        <div class="section-title">Rincian Fasilitas Pinjaman</div>
        <table class="info-table">
            <tr><th>Produk Pinjaman</th><td>: <?= e($loan['product_name'] ?? 'Pinjaman Reguler') ?></td></tr>
            <tr><th>Pokok Pinjaman</th><td>: <strong><?= rupiah($loan['amount']) ?></strong></td></tr>
            <tr><th>Bunga Flat</th><td>: <?= $loan['interest_rate'] ?>% per bulan</td></tr>
            <tr><th>Tenor Pinjaman</th><td>: <?= $loan['term_months'] ?> Bulan</td></tr>
            <tr><th>Angsuran / Bulan</th><td>: <strong><?= rupiah($loan['monthly_payment']) ?></strong></td></tr>
            <tr><th>Tgl Pencairan</th><td>: <?= $loan['disbursement_date'] ? date('d/m/Y', strtotime($loan['disbursement_date'])) : '-' ?></td></tr>
            <tr><th>Status Pinjaman</th><td>: <strong><?= strtoupper(e($loan['status'])) ?></strong></td></tr>
        </table>
    </div>
</div>

<div class="terbilang-box">
    <strong>Terbilang:</strong> # <?= ucfirst(trim(terbilang($loan['amount']))) ?> rupiah #
</div>

<div class="section-title">Jadwal & Riwayat Pembayaran Angsuran</div>
<table class="data-table">
    <thead>
        <tr>
            <th class="center" style="width: 40px;">Ke</th>
            <th>Tgl Jatuh Tempo</th>
            <th class="num">Pokok</th>
            <th class="num">Bunga</th>
            <th class="num">Tagihan</th>
            <th>Tgl Bayar</th>
            <th class="num">Jumlah Bayar</th>
            <th class="center">Status</th>
            <th>Penerima</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($payments)): ?>
            <tr><td colspan="9" class="center">Belum ada jadwal angsuran.</td></tr>
        <?php else: ?>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="center"><?= $p['payment_number'] ?></td>
                    <td><?= !empty($p['due_date']) ? date('d/m/Y', strtotime($p['due_date'])) : '-' ?></td>
                    <td class="num"><?= rupiah($p['principal_amount']) ?></td>
                    <td class="num"><?= rupiah($p['interest_amount']) ?></td>
                    <td class="num"><strong><?= rupiah($p['amount_due'] > 0 ? $p['amount_due'] : $p['amount']) ?></strong></td>
                    <td><?= !empty($p['payment_date']) ? date('d/m/Y', strtotime($p['payment_date'])) : '-' ?></td>
                    <td class="num"><?= $p['amount_paid'] > 0 ? rupiah($p['amount_paid']) : ($p['status'] === 'paid' ? rupiah($p['amount']) : '-') ?></td>
                    <td class="center">
                        <strong><?= $p['status'] === 'paid' ? 'LUNAS' : ($p['status'] === 'overdue' ? 'TERLAMBAT' : 'BELUM') ?></strong>
                    </td>
                    <td><small><?= e($p['processed_by_name'] ?? '-') ?></small></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
    <?php if (!empty($payments)): ?>
        <tfoot>
            <tr style="font-weight: bold; background: #fafafa;">
                <td colspan="2" class="center">TOTAL</td>
                <td class="num"><?= rupiah(array_sum(array_column($payments, 'principal_amount'))) ?></td>
                <td class="num"><?= rupiah(array_sum(array_column($payments, 'interest_amount'))) ?></td>
                <td class="num"><?= rupiah($total_due) ?></td>
                <td></td>
                <td class="num"><?= rupiah($total_paid) ?></td>
                <td colspan="2" class="center">Sisa: <?= rupiah(max(0, $total_due - $total_paid)) ?></td>
            </tr>
        </tfoot>
    <?php endif; ?>
</table>

<div class="signatures">
    <div>
        <div>Penerima / Anggota</div>
        <div class="sign-box"><?= e($loan['full_name']) ?></div>
        <div class="sign-role">Anggota Peminjam</div>
    </div>
    <div>
        <div>Disetujui Oleh</div>
        <div class="sign-box"><?= e($loan['approver_name'] ?? 'Pengurus Koperasi') ?></div>
        <div class="sign-role">Pengurus / Komite Kredit</div>
    </div>
    <div>
        <div>Bendahara Koperasi</div>
        <div class="sign-box">( ........................................ )</div>
        <div class="sign-role">Kasir / Bag. Keuangan</div>
    </div>
</div>

<script>
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('print') === '1') {
        window.onload = () => window.print();
    }
</script>
</body>
</html>
