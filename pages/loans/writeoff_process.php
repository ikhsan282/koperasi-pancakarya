<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('loans.writeoff');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans/index.php');
}

verify_csrf();

$stmt = db()->prepare('SELECT l.*, m.full_name FROM loans l JOIN members m ON l.member_id = m.id WHERE l.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();

if (!$loan) {
    flash('error', 'Data pinjaman tidak ditemukan.');
    redirect('pages/loans/index.php');
}

if (!in_array($loan['status'], ['active', 'defaulted'])) {
    flash('error', 'Hapus buku hanya dapat dilakukan untuk pinjaman aktif atau macet.');
    redirect('pages/loans/detail.php?id=' . $id);
}

if ($loan['writeoff_status'] !== 'none') {
    flash('error', 'Pinjaman ini sudah dilakukan hapus buku.');
    redirect('pages/loans/detail.php?id=' . $id);
}

$writeoff_type = trim($_POST['writeoff_type'] ?? '');
$writeoff_amount = (float) ($_POST['writeoff_amount'] ?? 0);
$writeoff_date = trim($_POST['writeoff_date'] ?? '');
$reason = trim($_POST['reason'] ?? '');
$outstanding_balance = (float) ($_POST['outstanding_balance'] ?? 0);

$errors = [];

if (!in_array($writeoff_type, ['full', 'partial'])) {
    $errors[] = 'Jenis hapus buku tidak valid.';
}

if ($writeoff_amount <= 0) {
    $errors[] = 'Jumlah hapus buku harus lebih dari nol.';
}

if ($writeoff_amount > $outstanding_balance + 0.01) {
    $errors[] = 'Jumlah hapus buku melebihi sisa tagihan.';
}

$date_obj = DateTime::createFromFormat('Y-m-d', $writeoff_date);
if (!$date_obj || $date_obj->format('Y-m-d') !== $writeoff_date) {
    $errors[] = 'Tanggal hapus buku tidak valid.';
}

if ($date_obj > new DateTime()) {
    $errors[] = 'Tanggal hapus buku tidak boleh di masa depan.';
}

if ($reason === '') {
    $errors[] = 'Alasan hapus buku wajib diisi.';
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        flash('error', $error);
    }
    redirect('pages/loans/writeoff.php?id=' . $id);
}

$db = db();
$db->begin_transaction();

try {
    $stmt = $db->prepare('INSERT INTO loan_writeoffs 
        (loan_id, writeoff_amount, remaining_balance, reason, writeoff_date) 
        VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('iddss', $id, $writeoff_amount, $outstanding_balance, $reason, $writeoff_date);
    $stmt->execute();
    $writeoff_id = $db->insert_id;
    
    $db->commit();
    
    log_activity('loan_writeoff_request', "Mengajukan hapus buku pinjaman {$loan['loan_number']} sebesar " . rupiah($writeoff_amount));
    flash('success', 'Pengajuan hapus buku berhasil. Menunggu persetujuan Super Admin.');
    redirect('pages/loans/writeoff_list.php');
} catch (Throwable $e) {
    $db->rollback();
    flash('error', 'Gagal mengajukan hapus buku: ' . $e->getMessage());
    redirect('pages/loans/writeoff.php?id=' . $id);
}
