<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('collateral.manage');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    flash('error', 'ID agunan tidak valid.');
    redirect('pages/loans/index.php');
}

$stmt = db()->prepare('SELECT lc.*, l.status as loan_status FROM loan_collaterals lc JOIN loans l ON lc.loan_id = l.id WHERE lc.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$collateral = $stmt->get_result()->fetch_assoc();

if (!$collateral) {
    flash('error', 'Agunan tidak ditemukan.');
    redirect('pages/loans/index.php');
}

if ($collateral['status'] === 'returned') {
    flash('warning', 'Agunan ini sudah ditandai sebagai dikembalikan.');
    redirect('pages/collateral/index.php?loan_id=' . $collateral['loan_id']);
}

if (!in_array($collateral['loan_status'], ['completed', 'paid'])) {
    flash('error', 'Agunan hanya dapat dikembalikan setelah pinjaman lunas.');
    redirect('pages/collateral/index.php?loan_id=' . $collateral['loan_id']);
}

$return_date = date('Y-m-d');
$stmt = db()->prepare('UPDATE loan_collaterals SET status = "returned", return_date = ? WHERE id = ?');
$stmt->bind_param('si', $return_date, $id);
$stmt->execute();

log_activity('return_collateral', "Mengembalikan agunan ID {$id}");
flash('success', 'Agunan berhasil ditandai sebagai dikembalikan.');
redirect('pages/collateral/index.php?loan_id=' . $collateral['loan_id']);
