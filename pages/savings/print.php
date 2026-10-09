<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('savings.view');

$account_id = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;
$trx_id = isset($_GET['trx_id']) ? (int) $_GET['trx_id'] : 0;

if ($account_id <= 0) {
    http_response_code(404);
    exit('ID rekening tidak valid.');
}

$stmt = db()->prepare('SELECT sa.*, m.member_number, m.full_name, st.name AS type_name
                       FROM savings_accounts sa
                       JOIN members m ON sa.member_id = m.id
                       JOIN savings_types st ON sa.savings_type_id = st.id
                       WHERE sa.id = ?');
$stmt->bind_param('i', $account_id);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();

if (!$account) {
    http_response_code(404);
    exit('Rekening tidak ditemukan.');
}

$transaction = null;

if ($trx_id > 0) {
    $stmt = db()->prepare('SELECT * FROM savings_transactions WHERE id = ? AND savings_account_id = ?');
    $stmt->bind_param('ii', $trx_id, $account_id);
    $stmt->execute();
    $transaction = $stmt->get_result()->fetch_assoc();
    if (!$transaction) {
        http_response_code(404);
        exit('Transaksi tidak ditemukan.');
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Bukti Transaksi - <?= e($account['account_number']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; font-size: 14px; color: #222; background: #f5f5f5; padding: 2rem; }
        .receipt { max-width: 640px; margin: 0 auto; background: #fff; padding: 2.5rem 3rem; box-shadow: 0 0 8px rgba(0,0,0,.15); }
        .kop { text-align: center; border-bottom: 3px double #222; padding-bottom: .75rem; margin-bottom: 1.5rem; }
        .kop h1 { font-size: 1.4rem; letter-spacing: 1px; text-transform: uppercase; }
        .kop p { font-size: .8rem; color: #555; }
        h2 { text-align: center; font-size: 1.05rem; text-transform: uppercase; margin-bottom: 1.25rem; letter-spacing: 1px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 1.25rem; }
        table.info th { text-align: left; vertical-align: top; padding: .3rem 0; width: 180px; font-weight: 600; }
        table.info td { padding: .3rem 0; }
        table.detail { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        table.detail th, table.detail td { border: 1px solid #444; padding: .5rem .6rem; text-align: left; }
        table.detail th { background: #eee; font-size: .8rem; text-transform: uppercase; }
        .amount { text-align: right; font-weight: 600; font-size: 1.05rem; }
        .sig { display: flex; justify-content: space-between; margin-top: 3rem; text-align: center; }
        .sig > div { width: 40%; }
        .sig .line { margin-top: 4.5rem; border-top: 1px solid #222; padding-top: .25rem; font-size: .85rem; }
        .print-btn { text-align: center; margin-bottom: 1.5rem; }
        .print-btn button { padding: .6rem 2rem; font-size: .95rem; cursor: pointer; }
        .footer-note { margin-top: 2rem; font-size: .75rem; color: #777; text-align: center; border-top: 1px solid #ccc; padding-top: .5rem; }
        @media print {
            body { background: #fff; padding: 0; }
            .receipt { box-shadow: none; max-width: none; padding: 1rem; }
            .print-btn { display: none; }
            .footer-note { color: #444; }
        }
    </style>
</head>
<body>
<div class="print-btn"><button onclick="window.print()">🖨 Cetak</button></div>
<div class="receipt">
    <div class="kop">
        <h1><?= e(APP_NAME) ?></h1>
    </div>

    <?php if ($transaction): ?>
        <h2>Bukti Transaksi Simpanan</h2>
        <table class="info">
            <tr><th>No. Bukti</th><td>: TRX-<?= str_pad((string)$transaction['id'], 6, '0', STR_PAD_LEFT) ?></td></tr>
            <tr><th>Tanggal</th><td>: <?= date('d/m/Y', strtotime($transaction['transaction_date'])) ?></td></tr>
            <tr><th>No. Rekening</th><td>: <?= e($account['account_number']) ?></td></tr>
            <tr><th>Nama Anggota</th><td>: <?= e($account['full_name']) ?> (<?= e($account['member_number']) ?>)</td></tr>
            <tr><th>Jenis Simpanan</th><td>: <?= e($account['type_name']) ?></td></tr>
            <tr><th>Jenis Transaksi</th><td>: <?= $transaction['transaction_type'] === 'deposit' ? 'SETORAN' : 'PENARIKAN' ?></td></tr>
        </table>
        <table class="detail">
            <tr><th>Keterangan</th><th style="text-align: right;">Jumlah</th></tr>
            <tr>
                <td><?= e($transaction['description'] ?: ($transaction['transaction_type'] === 'deposit' ? 'Setoran simpanan' : 'Penarikan simpanan')) ?></td>
                <td class="amount"><?= rupiah($transaction['amount']) ?></td>
            </tr>
            <tr>
                <td><strong>Saldo setelah transaksi</strong></td>
                <td class="amount"><?= rupiah($transaction['balance_after']) ?></td>
            </tr>
        </table>
        <div class="sig">
            <div>Penerima / Anggota<div class="line"></div></div>
            <div>Petugas <?= e(APP_NAME) ?><div class="line"></div></div>
        </div>
    <?php else: ?>
        <h2>Mutasi Rekening Simpanan</h2>
        <table class="info">
            <tr><th>No. Rekening</th><td>: <?= e($account['account_number']) ?></td></tr>
            <tr><th>Nama Anggota</th><td>: <?= e($account['full_name']) ?> (<?= e($account['member_number']) ?>)</td></tr>
            <tr><th>Jenis Simpanan</th><td>: <?= e($account['type_name']) ?></td></tr>
            <tr><th>Saldo Saat Ini</th><td>: <strong><?= rupiah($account['balance']) ?></strong></td></tr>
        </table>

        <?php
        $stmt = db()->prepare('SELECT * FROM savings_transactions WHERE savings_account_id = ? ORDER BY transaction_date ASC, id ASC');
        $stmt->bind_param('i', $account_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $total_deposit = array_sum(array_column(array_filter($rows, fn($r) => $r['transaction_type'] === 'deposit'), 'amount'));
        $total_withdrawal = array_sum(array_column(array_filter($rows, fn($r) => $r['transaction_type'] === 'withdrawal'), 'amount'));
        ?>
        <table class="detail">
            <tr>
                <th style="width: 100px;">Tanggal</th>
                <th>Jenis</th>
                <th style="text-align: right;">Setoran</th>
                <th style="text-align: right;">Penarikan</th>
                <th style="text-align: right;">Saldo</th>
                <th>Keterangan</th>
            </tr>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align: center;">Belum ada transaksi.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $trx): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($trx['transaction_date'])) ?></td>
                        <td><?= $trx['transaction_type'] === 'deposit' ? 'Setoran' : 'Penarikan' ?></td>
                        <td style="text-align: right;"><?= $trx['transaction_type'] === 'deposit' ? rupiah($trx['amount']) : '-' ?></td>
                        <td style="text-align: right;"><?= $trx['transaction_type'] === 'withdrawal' ? rupiah($trx['amount']) : '-' ?></td>
                        <td style="text-align: right;"><?= rupiah($trx['balance_after']) ?></td>
                        <td><?= e($trx['description'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <tr>
                <td colspan="2"><strong>Total</strong></td>
                <td style="text-align: right;"><strong><?= rupiah($total_deposit) ?></strong></td>
                <td style="text-align: right;"><strong><?= rupiah($total_withdrawal) ?></strong></td>
                <td style="text-align: right;"><strong><?= rupiah($account['balance']) ?></strong></td>
                <td></td>
            </tr>
        </table>
        <div class="sig">
            <div>Mengetahui,<div class="line">Pengurus</div></div>
            <div><?= e(APP_NAME) ?>, <?= date('d/m/Y') ?><div class="line">Petugas</div></div>
        </div>
    <?php endif; ?>

    <div class="footer-note">Dokumen ini dicetak dari sistem <?= e(APP_NAME) ?> pada <?= date('d/m/Y H:i') ?></div>
</div>
<script>
if (new URLSearchParams(location.search).get('print') === '1') window.onload = () => window.print();
</script>
</body>
</html>
