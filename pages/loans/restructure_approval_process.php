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
$action = $_POST['action'] ?? '';

$stmt = db()->prepare('SELECT r.*, l.loan_number, l.amount 
                       FROM loan_restructures r
                       JOIN loans l ON r.loan_id = l.id
                       WHERE r.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$restructure = $stmt->get_result()->fetch_assoc();

if (!$restructure) {
    flash('error', 'Data restrukturisasi tidak ditemukan.');
    redirect('pages/loans/index.php');
}

if ($restructure['status'] !== 'pending') {
    flash('warning', 'Restrukturisasi ini sudah diproses.');
    redirect('pages/loans/detail.php?id=' . $restructure['loan_id']);
}

$user_id = current_user()['id'];
$db = db();

if ($action === 'approve') {
    $approval_notes = trim($_POST['approval_notes'] ?? '');
    
    $db->begin_transaction();
    try {
        // Update restructure record
        $stmt = $db->prepare('UPDATE loan_restructures 
                             SET status = "approved", approved_by = ?, approved_at = NOW(), notes = ? 
                             WHERE id = ?');
        $notes = $approval_notes ?: $restructure['notes'];
        $stmt->bind_param('isi', $user_id, $notes, $id);
        $stmt->execute();
        
        // Update loan with new terms
        $stmt = $db->prepare('UPDATE loans 
                             SET term_months = ?, interest_rate = ?, monthly_payment = ? 
                             WHERE id = ?');
        $stmt->bind_param('iddi', 
            $restructure['new_term_months'],
            $restructure['new_interest_rate'],
            $restructure['new_monthly_payment'],
            $restructure['loan_id']
        );
        $stmt->execute();
        
        // Delete unpaid loan_payments
        $stmt = $db->prepare('DELETE FROM loan_payments WHERE loan_id = ? AND status = "pending"');
        $stmt->bind_param('i', $restructure['loan_id']);
        $stmt->execute();
        $deleted_count = $stmt->affected_rows;
        
        // Get count of paid payments to continue numbering
        $stmt = $db->prepare('SELECT COALESCE(MAX(payment_number), 0) as last_num FROM loan_payments WHERE loan_id = ?');
        $stmt->bind_param('i', $restructure['loan_id']);
        $stmt->execute();
        $last_num = $stmt->get_result()->fetch_assoc()['last_num'];
        
        // Get loan disbursement date for schedule calculation
        $stmt = $db->prepare('SELECT disbursement_date FROM loans WHERE id = ?');
        $stmt->bind_param('i', $restructure['loan_id']);
        $stmt->execute();
        $loan_data = $stmt->get_result()->fetch_assoc();
        
        if (empty($loan_data['disbursement_date'])) {
            throw new Exception('Loan disbursement date not found.');
        }
        
        // Calculate remaining term
        $remaining_term = $restructure['new_term_months'] - $last_num;
        
        if ($remaining_term > 0) {
            // Generate new payment schedule
            $calc = calculate_flat_loan(
                (float) $restructure['amount'],
                (float) $restructure['new_interest_rate'],
                (int) $restructure['new_term_months']
            );
            
            $principal_monthly = $calc['principal_monthly'];
            $interest_monthly = $calc['interest_monthly'];
            $monthly_payment = $calc['monthly_payment'];
            
            // Start from the month after last paid payment
            $base_date = new DateTime($loan_data['disbursement_date']);
            $base_date->modify('+' . $last_num . ' months');
            
            $stmt = $db->prepare('INSERT INTO loan_payments 
                                 (loan_id, payment_number, due_date, principal_amount, interest_amount, amount, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, "pending")');
            
            for ($i = 1; $i <= $remaining_term; $i++) {
                $payment_number = $last_num + $i;
                $due_date = clone $base_date;
                $due_date->modify('+' . $i . ' months');
                $due_date_str = $due_date->format('Y-m-d');
                
                $stmt->bind_param('iisddd',
                    $restructure['loan_id'],
                    $payment_number,
                    $due_date_str,
                    $principal_monthly,
                    $interest_monthly,
                    $monthly_payment
                );
                $stmt->execute();
            }
        }
        
        $db->commit();
        
        log_activity('approve_restructure', "Menyetujui restrukturisasi untuk pinjaman {$restructure['loan_number']} (ID Restruktur: {$id})");
        flash('success', "Restrukturisasi berhasil disetujui. {$deleted_count} cicilan dihapus, {$remaining_term} cicilan baru digenerate.");
        redirect('pages/loans/detail.php?id=' . $restructure['loan_id']);
        
    } catch (Exception $e) {
        $db->rollback();
        flash('error', 'Gagal memproses approval: ' . $e->getMessage());
        redirect('pages/loans/restructure_approve.php?id=' . $id);
    }
    
} elseif ($action === 'reject') {
    $rejection_notes = trim($_POST['rejection_notes'] ?? '');
    
    if (empty($rejection_notes)) {
        flash('error', 'Alasan penolakan wajib diisi.');
        redirect('pages/loans/restructure_approve.php?id=' . $id);
    }
    
    $stmt = $db->prepare('UPDATE loan_restructures 
                         SET status = "rejected", approved_by = ?, approved_at = NOW(), notes = ? 
                         WHERE id = ?');
    $stmt->bind_param('isi', $user_id, $rejection_notes, $id);
    
    if ($stmt->execute()) {
        log_activity('reject_restructure', "Menolak restrukturisasi untuk pinjaman {$restructure['loan_number']} (ID Restruktur: {$id})");
        flash('success', 'Restrukturisasi ditolak.');
        redirect('pages/loans/detail.php?id=' . $restructure['loan_id']);
    } else {
        flash('error', 'Gagal menolak restrukturisasi: ' . $db->error);
        redirect('pages/loans/restructure_approve.php?id=' . $id);
    }
    
} else {
    flash('error', 'Aksi tidak valid.');
    redirect('pages/loans/detail.php?id=' . $restructure['loan_id']);
}
