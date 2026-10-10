#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Loan Due Reminder Cron Job
 * 
 * Run daily via crontab:
 * 0 8 * * * php /path/to/cron/loan_due_reminders.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notification_helpers.php';
require_once __DIR__ . '/../includes/whatsapp_helpers.php';

// Get reminder days setting
$stmt = db()->prepare("SELECT value FROM settings WHERE `key` = 'reminder_days_before'");
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$reminder_days = (int) ($result['value'] ?? 3);

// Query upcoming due payments
$stmt = db()->prepare("
    SELECT 
        lp.id as payment_id,
        lp.loan_id,
        lp.payment_number,
        lp.due_date,
        lp.amount_due,
        lp.amount_paid,
        l.loan_number,
        l.member_id,
        m.member_number,
        m.full_name,
        m.email,
        m.phone,
        m.notification_preference
    FROM loan_payments lp
    JOIN loans l ON l.id = lp.loan_id
    JOIN members m ON m.id = l.member_id
    WHERE l.status = 'active'
      AND lp.status = 'pending'
      AND lp.due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
    ORDER BY m.full_name
");
$stmt->bind_param('i', $reminder_days);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$sent_count = 0;
$error_count = 0;

foreach ($payments as $payment) {
    $remaining = max(0, (float) $payment['amount_due'] - (float) $payment['amount_paid']);
    
    $title = "Pengingat Angsuran Ke-{$payment['payment_number']}";
    $message = "Yth. {$payment['full_name']},\n\n"
        . "Angsuran ke-{$payment['payment_number']} untuk pinjaman {$payment['loan_number']} akan jatuh tempo dalam {$reminder_days} hari.\n"
        . "Jatuh tempo: " . date('d/m/Y', strtotime($payment['due_date'])) . "\n"
        . "Jumlah tagihan: " . rupiah($remaining) . "\n\n"
        . "Mohon lakukan pembayaran sebelum tanggal jatuh tempo.\n\n"
        . "Terima kasih.";

    try {
        $result = notify_member(
            (int) $payment['member_id'],
            $title,
            $message,
            'loan_due_reminder',
            'loan_payment',
            (int) $payment['payment_id']
        );
        
        // Send WhatsApp notification if preference allows
        $whatsapp_sent = false;
        if (in_array($payment['notification_preference'], ['sms', 'both']) && !empty($payment['phone'])) {
            $wa_message = "Pengingat: Angsuran pinjaman {$payment['loan_number']} jatuh tempo " 
                . date('d/m/Y', strtotime($payment['due_date'])) . ". Total: " . rupiah($remaining) 
                . ". Mohon segera dibayar. Terima kasih.";
            $whatsapp_sent = send_whatsapp($payment['phone'], $wa_message, 'loan_payment', (int) $payment['payment_id']);
        }

        if ($result['in_app']) {
            $wa_status = $whatsapp_sent ? ' + WA' : '';
            $sent_count++;
            echo "[OK] Notifikasi terkirim ke {$payment['full_name']} (#{$payment['member_number']}){$wa_status}\n";
        } else {
            $error_count++;
            echo "[ERROR] Gagal membuat notifikasi untuk {$payment['full_name']}\n";
        }
    } catch (Exception $e) {
        $error_count++;
        echo "[ERROR] Exception untuk {$payment['full_name']}: {$e->getMessage()}\n";
    }
}

echo "\n=== Ringkasan ===\n";
echo "Total angsuran yang akan jatuh tempo: " . count($payments) . "\n";
echo "Notifikasi terkirim: {$sent_count}\n";
echo "Gagal: {$error_count}\n";
