<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('shu.distribute');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/shu/index.php');
}
verify_csrf();

$db = db();
$period_id = (int) ($_POST['period_id'] ?? 0);
$savings_type_id = (int) ($_POST['savings_type_id'] ?? 0);

try {
    if ($period_id <= 0 || $savings_type_id <= 0) {
        throw new InvalidArgumentException('Parameter tidak valid.');
    }

    // Get period
    $stmt = $db->prepare('SELECT * FROM shu_periods WHERE id = ? AND status = "finalized"');
    $stmt->bind_param('i', $period_id);
    $stmt->execute();
    $period = $stmt->get_result()->fetch_assoc();
    
    if (!$period) {
        throw new InvalidArgumentException('Periode SHU tidak ditemukan atau belum difinalisasi.');
    }

    if ($period['distributed_at']) {
        throw new InvalidArgumentException('SHU periode ini sudah didistribusikan.');
    }

    // Verify savings type exists
    $stmt = $db->prepare('SELECT id, name FROM savings_types WHERE id = ?');
    $stmt->bind_param('i', $savings_type_id);
    $stmt->execute();
    $savings_type = $stmt->get_result()->fetch_assoc();
    
    if (!$savings_type) {
        throw new InvalidArgumentException('Jenis simpanan tidak ditemukan.');
    }

    // Get all distributions for this period
    $stmt = $db->prepare('SELECT d.*, m.member_number, m.full_name 
        FROM shu_distributions d
        JOIN members m ON m.id = d.member_id
        WHERE d.period_id = ? AND d.total_shu > 0
        ORDER BY m.member_number');
    $stmt->bind_param('i', $period_id);
    $stmt->execute();
    $distributions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($distributions)) {
        throw new InvalidArgumentException('Tidak ada distribusi SHU untuk diposting.');
    }

    $db->begin_transaction();

    $posted_count = 0;
    $total_posted = 0;
    $transaction_date = date('Y-m-d');
    $description = "SHU Tahun Fiskal {$period['fiscal_year']}";
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    foreach ($distributions as $dist) {
        // Get or create savings account for this member and type
        $stmt = $db->prepare('SELECT id, balance FROM savings_accounts 
            WHERE member_id = ? AND savings_type_id = ? AND status = "active" LIMIT 1');
        $stmt->bind_param('ii', $dist['member_id'], $savings_type_id);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();

        if (!$account) {
            // Create account if doesn't exist
            $account_number = 'SA-' . str_pad((string)$dist['member_id'], 6, '0', STR_PAD_LEFT) . '-' . $savings_type_id;
            $stmt = $db->prepare('INSERT INTO savings_accounts (member_id, savings_type_id, account_number, balance, opened_date, status)
                VALUES (?, ?, ?, 0, ?, "active")');
            $stmt->bind_param('iiss', $dist['member_id'], $savings_type_id, $account_number, $transaction_date);
            $stmt->execute();
            $account_id = (int) $db->insert_id;
            $old_balance = 0;
        } else {
            $account_id = (int) $account['id'];
            $old_balance = (float) $account['balance'];
        }

        $amount = (float) $dist['total_shu'];
        $new_balance = $old_balance + $amount;

        // Insert transaction
        $stmt = $db->prepare('INSERT INTO savings_transactions 
            (savings_account_id, transaction_type, amount, balance_after, transaction_date, description, processed_by)
            VALUES (?, "deposit", ?, ?, ?, ?, ?)');
        $stmt->bind_param('iddssi', $account_id, $amount, $new_balance, $transaction_date, $description, $uid);
        $stmt->execute();
        $transaction_id = (int) $db->insert_id;

        // Update account balance
        $stmt = $db->prepare('UPDATE savings_accounts SET balance = ? WHERE id = ?');
        $stmt->bind_param('di', $new_balance, $account_id);
        $stmt->execute();

        // Link transaction to distribution
        $stmt = $db->prepare('UPDATE shu_distributions SET distribution_transaction_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $transaction_id, $dist['id']);
        $stmt->execute();

        $posted_count++;
        $total_posted += $amount;
    }

    // Mark period as distributed
    $stmt = $db->prepare('UPDATE shu_periods SET distributed_at = NOW(), distributed_by = ? WHERE id = ?');
    $stmt->bind_param('ii', $uid, $period_id);
    $stmt->execute();

    $db->commit();

    log_activity('distribute_shu', "Distribusi SHU {$period['fiscal_year']}: " . rupiah($total_posted) . " ke {$posted_count} anggota");
    flash('success', "SHU berhasil didistribusikan ke {$posted_count} anggota (Total: " . rupiah($total_posted) . ")");
    redirect('pages/shu/breakdown.php?id=' . $period_id);

} catch (mysqli_sql_exception $e) {
    $db->rollback();
    error_log('SHU distribution failed: ' . $e->getMessage());
    flash('error', 'Gagal mendistribusikan SHU: ' . $e->getMessage());
    redirect('pages/shu/breakdown.php?id=' . $period_id);
} catch (Exception $e) {
    flash('error', $e->getMessage());
    redirect('pages/shu/breakdown.php?id=' . $period_id);
}
