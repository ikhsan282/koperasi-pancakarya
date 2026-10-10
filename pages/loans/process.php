<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notification_helpers.php';
require_once __DIR__ . '/../../includes/whatsapp_helpers.php';

require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans/index.php');
}

verify_csrf();
$action = $_POST['action'] ?? '';

$stmt = db()->prepare('SELECT * FROM loans WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();

if (!$loan) {
    flash('error', 'Data pinjaman tidak ditemukan.');
    redirect('pages/loans/index.php');
}

if (!can('loans.approve')) {
    http_response_code(403);
    exit('Anda tidak memiliki izin untuk memproses pinjaman.');
}

if ($action === 'approve') {
    if ($loan['status'] !== 'pending') {
        flash('error', 'Hanya pinjaman berstatus pending yang dapat disetujui.');
        redirect('pages/loans/detail.php?id=' . $id);
    }

    // Validate collateral requirement for loans ≥ Rp 5,000,000
    if ((float) $loan['amount'] >= 5000000) {
        $c_stmt = db()->prepare('SELECT COUNT(*) as total FROM loan_collaterals WHERE loan_id = ?');
        $c_stmt->bind_param('i', $id);
        $c_stmt->execute();
        $collateral_count = $c_stmt->get_result()->fetch_assoc()['total'];
        
        if ($collateral_count === 0) {
            flash('error', 'Pinjaman ≥ Rp 5.000.000 wajib memiliki minimal 1 agunan sebelum dapat disetujui.');
            redirect('pages/loans/detail.php?id=' . $id);
        }
    }

    // Get loan product approval configuration
    $product_id = (int) $loan['loan_product_id'];
    $approval_levels = 1; // default single approval
    if ($product_id > 0) {
        $p_stmt = db()->prepare('SELECT approval_levels FROM loan_products WHERE id = ?');
        $p_stmt->bind_param('i', $product_id);
        $p_stmt->execute();
        $product = $p_stmt->get_result()->fetch_assoc();
        if ($product) {
            $approval_levels = (int) $product['approval_levels'];
        }
    }

    $uid = current_user()['id'];
    $current_level = (int) $loan['current_approval_level'];
    $notes = trim($_POST['approval_notes'] ?? '');

    $db = db();
    $db->begin_transaction();
    try {
        if ($approval_levels === 1) {
            // Single approval: approve directly
            if (!can('loans.approve')) {
                throw new Exception('Anda tidak memiliki izin untuk menyetujui pinjaman.');
            }
            
            $stmt = $db->prepare('UPDATE loans SET status = "approved", approval_date = NOW(), approved_by = ?, current_approval_level = 1, rejection_notes = NULL WHERE id = ?');
            $stmt->bind_param('ii', $uid, $id);
            $stmt->execute();

            // Record approval
            $ins = $db->prepare('INSERT INTO loan_approvals (loan_id, approver_level, approver_id, status, notes) VALUES (?, 1, ?, "approved", ?)');
            $ins->bind_param('iis', $id, $uid, $notes);
            $ins->execute();

            log_activity('approve_loan', "Menyetujui pinjaman {$loan['loan_number']} (single approval)");
            
            // Send WhatsApp notification to member
            $member_id = (int) $loan['member_id'];
            $wa_message = "Pinjaman {$loan['loan_number']} Anda telah disetujui. Jumlah: " . rupiah($loan['amount']) . ". Silakan ambil di kantor koperasi.";
            notify_member_whatsapp($member_id, $wa_message, 'loan_approval', $id);
            
            flash('success', 'Pinjaman telah disetujui. Silakan lanjutkan pencairan dana.');
        } else {
            // Dual approval workflow
            if ($current_level === 0) {
                // First approval (Admin)
                if (!can('loans.approve')) {
                    throw new Exception('Anda tidak memiliki izin untuk menyetujui pinjaman level 1.');
                }
                
                $stmt = $db->prepare('UPDATE loans SET current_approval_level = 1 WHERE id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();

                // Record first approval
                $ins = $db->prepare('INSERT INTO loan_approvals (loan_id, approver_level, approver_id, status, notes) VALUES (?, 1, ?, "approved", ?)');
                $ins->bind_param('iis', $id, $uid, $notes);
                $ins->execute();

                log_activity('approve_loan_level1', "Menyetujui pinjaman {$loan['loan_number']} - Level 1");
                flash('success', 'Pinjaman telah disetujui Level 1. Menunggu persetujuan Level 2 (Super Admin).');
            } elseif ($current_level === 1) {
                // Final approval (Super Admin)
                if (!can('loans.approve_final')) {
                    throw new Exception('Anda tidak memiliki izin untuk menyetujui pinjaman level 2 (final).');
                }
                
                $stmt = $db->prepare('UPDATE loans SET status = "approved", approval_date = NOW(), approved_by = ?, current_approval_level = 2, rejection_notes = NULL WHERE id = ?');
                $stmt->bind_param('ii', $uid, $id);
                $stmt->execute();

                // Record final approval
                $ins = $db->prepare('INSERT INTO loan_approvals (loan_id, approver_level, approver_id, status, notes) VALUES (?, 2, ?, "approved", ?)');
                $ins->bind_param('iis', $id, $uid, $notes);
                $ins->execute();

                log_activity('approve_loan_level2', "Menyetujui pinjaman {$loan['loan_number']} - Level 2 (Final)");
                
                // Send WhatsApp notification to member
                $member_id = (int) $loan['member_id'];
                $wa_message = "Pinjaman {$loan['loan_number']} Anda telah disetujui. Jumlah: " . rupiah($loan['amount']) . ". Silakan ambil di kantor koperasi.";
                notify_member_whatsapp($member_id, $wa_message, 'loan_approval', $id);
                
                flash('success', 'Pinjaman telah disetujui Level 2 (Final). Silakan lanjutkan pencairan dana.');
            } else {
                throw new Exception('Status approval tidak valid.');
            }
        }
        
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        flash('error', 'Gagal menyetujui pinjaman: ' . $e->getMessage());
    }
    redirect('pages/loans/detail.php?id=' . $id);
}

if ($action === 'reject') {
    if ($loan['status'] !== 'pending') {
        flash('error', 'Hanya pinjaman berstatus pending yang dapat ditolak.');
        redirect('pages/loans/detail.php?id=' . $id);
    }

    $notes = trim($_POST['rejection_notes'] ?? '');
    if ($notes === '') {
        flash('error', 'Alasan penolakan wajib diisi.');
        redirect('pages/loans/detail.php?id=' . $id);
    }

    $uid = current_user()['id'];
    $stmt = db()->prepare('UPDATE loans SET status = "rejected", approved_by = ?, rejection_notes = ? WHERE id = ? AND status = "pending"');
    $stmt->bind_param('isi', $uid, $notes, $id);
    $stmt->execute();

    log_activity('reject_loan', "Menolak pinjaman {$loan['loan_number']}: {$notes}");
    flash('success', 'Pinjaman telah ditolak dengan catatan.');
    redirect('pages/loans/detail.php?id=' . $id);
}

if ($action === 'disburse') {
    if ($loan['status'] !== 'approved') {
        flash('error', 'Hanya pinjaman yang telah disetujui yang dapat dicairkan.');
        redirect('pages/loans/detail.php?id=' . $id);
    }

    $disbursement_date = trim($_POST['disbursement_date'] ?? '');
    $cashbank_account_id = (int) ($_POST['cashbank_account_id'] ?? 0);
    
    $date_obj = DateTime::createFromFormat('Y-m-d', $disbursement_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $disbursement_date) {
        flash('error', 'Tanggal pencairan tidak valid.');
        redirect('pages/loans/detail.php?id=' . $id);
    }
    
    // Validate disbursement date constraints
    $today = new DateTime();
    $app_date = new DateTime($loan['application_date']);
    if ($date_obj > $today) {
        flash('error', 'Tanggal pencairan tidak boleh di masa depan.');
        redirect('pages/loans/detail.php?id=' . $id);
    }
    if ($date_obj < $app_date) {
        flash('error', 'Tanggal pencairan tidak boleh sebelum tanggal pengajuan (' . $app_date->format('d/m/Y') . ').');
        redirect('pages/loans/detail.php?id=' . $id);
    }

    require_once __DIR__ . '/../../includes/cashbank_helpers.php';
    
    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('UPDATE loans SET status = "active", disbursement_date = ? WHERE id = ? AND status = "approved"');
        $stmt->bind_param('si', $disbursement_date, $id);
        $stmt->execute();

        $term = (int) $loan['term_months'];
        $amount = (float) $loan['amount'];
        $rate = (float) $loan['interest_rate'];
        $principal_base = round($amount / $term, 2);
        $interest_per_month = round($amount * ($rate / 100), 2);
        $total_principal_scheduled = 0.0;
        $start_date = new DateTime($disbursement_date);

        $ins = $db->prepare('INSERT INTO loan_payments (loan_id, due_date, payment_date, amount, principal_amount, interest_amount, amount_due, amount_paid, status, balance_remaining, payment_number, processed_by) VALUES (?, ?, NULL, 0, ?, ?, ?, 0, "pending", ?, ?, NULL)');

        for ($i = 1; $i <= $term; $i++) {
            $due_date = (clone $start_date)->modify('+' . $i . ' month')->format('Y-m-d');
            $principal = ($i === $term) ? round($amount - $total_principal_scheduled, 2) : $principal_base;
            $total_principal_scheduled += $principal;
            $amount_due = round($principal + $interest_per_month, 2);
            $balance_remaining = max(0, round($amount - $total_principal_scheduled, 2));

            $ins->bind_param('isddddi', $id, $due_date, $principal, $interest_per_month, $amount_due, $balance_remaining, $i);
            $ins->execute();
        }
        
        // Auto-post to cash/bank (credit = uang keluar)
        if ($cashbank_account_id > 0) {
            post_cashbank_transaction(
                $cashbank_account_id,
                'credit',
                $amount,
                'loan_disbursement',
                $id,
                "Pencairan pinjaman {$loan['loan_number']} - {$loan['member_name']}",
                $disbursement_date
            );
        }

        $db->commit();
        log_activity('disburse_loan', "Mencairkan pinjaman {$loan['loan_number']} dan membuat {$term} jadwal angsuran");
        flash('success', 'Dana berhasil dicairkan dan jadwal angsuran telah dibuat.');
    } catch (Throwable $e) {
        $db->rollback();
        flash('error', 'Gagal mencairkan pinjaman: ' . $e->getMessage());
    }
    redirect('pages/loans/detail.php?id=' . $id);
}

flash('error', 'Aksi tidak dikenal.');
redirect('pages/loans/detail.php?id=' . $id);
