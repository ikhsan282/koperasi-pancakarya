<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('savings.view');

$member_id = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;

if ($member_id <= 0) {
    http_response_code(404);
    exit('ID anggota tidak valid.');
}

$stmt = db()->prepare('SELECT * FROM members WHERE id = ?');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

if (!$member) {
    http_response_code(404);
    exit('Anggota tidak ditemukan.');
}

if (current_user()['role'] === 'Anggota' && (int) $member['user_id'] !== current_user()['id']) {
    http_response_code(403);
    exit('Anda hanya dapat mencetak buku tabungan sendiri.');
}

$stmt = db()->prepare('SELECT sa.*, st.name AS type_name
                       FROM savings_accounts sa
                       JOIN savings_types st ON sa.savings_type_id = st.id
                       WHERE sa.member_id = ?
                       ORDER BY st.id, sa.opened_date');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = db()->prepare('SELECT strx.*, sa.account_number, st.name AS type_name
                       FROM savings_transactions strx
                       JOIN savings_accounts sa ON sa.id = strx.savings_account_id
                       JOIN savings_types st ON st.id = sa.savings_type_id
                       WHERE sa.member_id = ?
                       ORDER BY strx.transaction_date ASC, strx.id ASC');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total_balance = array_sum(array_column($accounts, 'balance'));
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Buku Tabungan - <?= e($member['full_name']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; font-size: 13px; color: #222; background: #f5f5f5; padding: 1.5rem; }
        .book { max-width: 900px; margin: 0 auto; background: #fff; padding: 2rem 2.5rem; box-shadow: 0 0 8px rgba(0,0,0,.1); }
        .kop { text-align: center; border-bottom: 3px double #222; padding-bottom: .75rem; margin-bottom: 1.5rem; }
        .kop h1 { font-size: 1.4rem; letter-spacing: 1px; text-transform: uppercase; }
        .kop p { font-size: .85rem; color: #555; margin-top: .25rem; }
        h2 { font-size: 1.1rem; text-transform: uppercase; margin: 1.5rem 0 .75rem; letter-spacing: .5px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 1.25rem; }
        table.info th { text-align: left; vertical-align: top; padding: .35rem 0; width: 160px; font-weight: 600; }
        table.info td { padding: .35rem 0; }
        table.trx { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: .9rem; }
        table.trx th, table.trx td { border: 1px solid #555; padding: .4rem .5rem; text-align: left; }
        table.trx th { background: #e8e8e8; font-size: .8rem; text-transform: uppercase; font-weight: 600; }
        table.trx td.num { text-align: right; }
        table.trx tfoot th { background: #d0d0d0; }
        .print-btn { text-align: center; margin-bottom: 1.5rem; }
        .print-btn button { padding: .6rem 2rem; font-size: .95rem; cursor: pointer; }
        .footer-note { margin-top: 2rem; font-size: .75rem; color: #777; text-align: center; border-top: 1px solid #ccc; padding-top: .5rem; }
        @media print {
            body { background: #fff; padding: 0; }
            .book { box-shadow: none; max-width: none; padding: 1rem; }
            .print-btn { display: none; }
            .footer-note { color: #555; }
        }
    </style>
</head>
<body>
<div class="print-btn"><button onclick="window.print()">🖨 Cetak Buku Tabungan</button></div>
<div class="book">
    <div class="kop">
        <h1><?= e(APP_NAME) ?></h1>
        <p>Buku Tabungan Anggota</p>
    </div>

    <table class="info">
        <tr><th>No. Anggota</th><td>: <strong><?= e($member['member_number']) ?></strong></td></tr>
        <tr><th>Nama Lengkap</th><td>: <?= e($member['full_name']) ?></td></tr>
        <tr><th>Alamat</th><td>: <?= e($member['address'] ?: '-') ?></td></tr>
        <tr><th>Telepon</th><td>: <?= e($member['phone'] ?: '-') ?></td></tr>
        <tr><th>Tanggal Cetak</th><td>: <?= date('d/m/Y H:i') ?></td></tr>
    </table>

    <h2>Rekening Simpanan</h2>
    <?php if (empty($accounts)): ?>
        <p>Belum ada rekening simpanan.</p>
    <?php else: ?>
        <table class="info">
            <?php foreach ($accounts as $acc): ?>
            <tr>
                <th><?= e($acc['type_name']) ?></th>
                <td>: <?= e($acc['account_number']) ?> — Saldo: <strong><?= rupiah($acc['balance']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <th><strong>Total Saldo</strong></th>
                <td>: <strong><?= rupiah($total_balance) ?></strong></td>
            </tr>
        </table>
    <?php endif; ?>

    <h2>Mutasi Tabungan</h2>
    <?php if (empty($transactions)): ?>
        <p>Belum ada transaksi.</p>
    <?php else: ?>
        <table class="trx">
            <thead>
                <tr>
                    <th style="width: 80px;">Tanggal</th>
                    <th style="width: 120px;">No. Rekening</th>
                    <th>Jenis Simpanan</th>
                    <th style="width: 70px;">Jenis</th>
                    <th style="width: 100px;">Setoran</th>
                    <th style="width: 100px;">Penarikan</th>
                    <th style="width: 110px;">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $trx): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($trx['transaction_date'])) ?></td>
                        <td><?= e($trx['account_number']) ?></td>
                        <td><?= e($trx['type_name']) ?></td>
                        <td><?= $trx['transaction_type'] === 'deposit' ? 'Setor' : 'Tarik' ?></td>
                        <td class="num"><?= $trx['transaction_type'] === 'deposit' ? rupiah($trx['amount']) : '-' ?></td>
                        <td class="num"><?= $trx['transaction_type'] === 'withdrawal' ? rupiah($trx['amount']) : '-' ?></td>
                        <td class="num"><strong><?= rupiah($trx['balance_after']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" style="text-align: right;">Total</th>
                    <th class="num"><?= rupiah(array_sum(array_column(array_filter($transactions, fn($t) => $t['transaction_type'] === 'deposit'), 'amount'))) ?></th>
                    <th class="num"><?= rupiah(array_sum(array_column(array_filter($transactions, fn($t) => $t['transaction_type'] === 'withdrawal'), 'amount'))) ?></th>
                    <th class="num"><?= rupiah($total_balance) ?></th>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <div class="footer-note">Dokumen ini dicetak dari sistem <?= e(APP_NAME) ?> pada <?= date('d/m/Y H:i') ?></div>
</div>
<script>
if (new URLSearchParams(location.search).get('print') === '1') window.onload = () => window.print();
</script>
</body>
</html>
