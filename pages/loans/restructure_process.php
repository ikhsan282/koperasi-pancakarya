<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/loan_tools.php';

require_permission('loans.restructure');

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

// Validate loan status
if (!in_array($loan['status'], ['active', 'completed'])) {
    flash('error', 'Hanya pinjaman dengan status aktif atau macet yang dapat direstrukturisasi.');
    redirect('pages/loans/detail.php?id=' . $id);
}

// Get form data
$restructure_type = trim($_POST['restructure_type'] ?? '');
$reason = trim($_POST['reason'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!in_array($restructure_type, ['reschedule', 'extend_tenor', 'reduce_rate'])) {
    flash('error', 'Jenis restrukturisasi tidak valid.');
    redirect('pages/loans/restructure.php?id=' . $id);
}

if (empty($reason)) {
    flash('error', 'Alasan restrukturisasi wajib diisi.');
    redirect('pages/loans/restructure.php?id=' . $id);
}

// Get old values
$old_term_months = (int) $loan['term_months'];
$old_interest_rate = (float) $loan['interest_rate'];
$old_monthly_payment = (float) $loan['monthly_payment'];

// Determine new values based on type
$new_term_months = $old_term_months;
$new_interest_rate = $old_interest_rate;

if ($restructure_type === 'extend_tenor') {
    $new_term_months = isset($_POST['new_term_months']) ? (int) $_POST['new_term_months'] : 0;
    
    if ($new_term_months <= $old_term_months) {
        flash('error', 'Tenor baru harus lebih besar dari tenor saat ini.');
        redirect('pages/loans/restructure.php?id=' . $id);
    }
    
    if ($new_term_months > 120) {
        flash('error', 'Tenor maksimal adalah 120 bulan.');
        redirect('pages/loans/restructure.php?id=' . $id);
    }
} elseif ($restructure_type === 'reduce_rate') {
    $new_interest_rate = isset($_POST['new_interest_rate']) ? (float) $_POST['new_interest_rate'] : 0;
    
    if ($new_interest_rate >= $old_interest_rate) {
        flash('error', 'Bunga baru harus lebih kecil dari bunga saat ini.');
        redirect('pages/loans/restructure.php?id=' . $id);
    }
    
    if ($new_interest_rate < 0) {
        flash('error', 'Bunga tidak boleh negatif.');
        redirect('pages/loans/restructure.php?id=' . $id);
    }
}

// Calculate new monthly payment using flat rate calculation
try {
    $calc = calculate_flat_loan((float) $loan['amount'], $new_interest_rate, $new_term_months);
    $new_monthly_payment = $calc['monthly_payment'];
} catch (Exception $e) {
    flash('error', 'Gagal menghitung angsuran baru: ' . $e->getMessage());
    redirect('pages/loans/restructure.php?id=' . $id);
}

// Insert restructure request
$db = db();
$stmt = $db->prepare('INSERT INTO loan_restructures 
                      (loan_id, restructure_type, reason, old_term_months, new_term_months, 
                       old_interest_rate, new_interest_rate, old_monthly_payment, new_monthly_payment, 
                       status, notes) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", ?)');
$stmt->bind_param('issiidddds', 
    $id, 
    $restructure_type, 
    $reason, 
    $old_term_months, 
    $new_term_months, 
    $old_interest_rate, 
    $new_interest_rate, 
    $old_monthly_payment, 
    $new_monthly_payment,
    $notes
);

if ($stmt->execute()) {
    $restructure_id = $db->insert_id;
    log_activity('request_restructure', "Mengajukan restrukturisasi untuk pinjaman {$loan['loan_number']} (ID: {$restructure_id})");
    flash('success', 'Pengajuan restrukturisasi berhasil dibuat. Menunggu persetujuan.');
    redirect('pages/loans/detail.php?id=' . $id);
} else {
    flash('error', 'Gagal menyimpan pengajuan restrukturisasi: ' . $db->error);
    redirect('pages/loans/restructure.php?id=' . $id);
}
