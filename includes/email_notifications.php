<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function email_already_sent_today(string $type, int $reference_id): bool
{
    $stmt = db()->prepare("SELECT id FROM email_logs WHERE email_type = ? AND reference_id = ? AND sent_date = CURDATE() AND status = 'sent' LIMIT 1");
    $stmt->bind_param('si', $type, $reference_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function log_email(string $type, int $reference_id, string $recipient, string $subject, string $status, string $error_message = ''): void
{
    $stmt = db()->prepare('INSERT INTO email_logs (email_type, reference_id, recipient, subject, status, error_message, sent_date) VALUES (?, ?, ?, ?, ?, ?, CURDATE())');
    $stmt->bind_param('sissss', $type, $reference_id, $recipient, $subject, $status, $error_message);
    $stmt->execute();
}

function claim_email_send_today(string $type, int $reference_id, string $recipient, string $subject): bool
{
    try {
        $stmt = db()->prepare("INSERT INTO email_logs (email_type, reference_id, recipient, subject, status, error_message, sent_date)
                               VALUES (?, ?, ?, ?, 'failed', 'Pengiriman sedang diproses', CURDATE())
                               ON DUPLICATE KEY UPDATE
                                   id = LAST_INSERT_ID(IF(status = 'failed', id, 0)),
                                   recipient = IF(status = 'failed', VALUES(recipient), recipient),
                                   subject = IF(status = 'failed', VALUES(subject), subject),
                                   error_message = IF(status = 'failed', VALUES(error_message), error_message)");
        $stmt->bind_param('siss', $type, $reference_id, $recipient, $subject);
        $stmt->execute();
        return $stmt->affected_rows > 0 && ($stmt->insert_id > 0 || db()->insert_id > 0);
    } catch (mysqli_sql_exception) {
        return false;
    }
}

function send_mail(string $to, string $subject, string $body): bool
{
    $from = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@localhost';
    $headers = "From: " . str_replace(["\r", "\n"], '', $from) . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return mail($to, $subject, $body, $headers);
}

function due_reminder_rows(): array
{
    $stmt = db()->prepare("SELECT lp.id AS payment_id, lp.loan_id, lp.payment_number, lp.due_date, lp.amount_due, lp.amount_paid,
                                  l.loan_number, m.member_number, m.full_name, m.email
                           FROM loan_payments lp
                           JOIN loans l ON l.id = lp.loan_id
                           JOIN members m ON m.id = l.member_id
                           WHERE l.status = 'active'
                             AND lp.status != 'paid'
                             AND m.email IS NOT NULL AND m.email != ''
                             AND lp.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                           ORDER BY lp.due_date ASC, m.full_name ASC");
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function send_due_reminder(int $payment_id): array
{
    $stmt = db()->prepare("SELECT lp.id AS payment_id, lp.loan_id, lp.payment_number, lp.due_date, lp.amount_due, lp.amount_paid,
                                  l.loan_number, m.member_number, m.full_name, m.email
                           FROM loan_payments lp
                           JOIN loans l ON l.id = lp.loan_id
                           JOIN members m ON m.id = l.member_id
                           WHERE lp.id = ? AND l.status = 'active' AND lp.status != 'paid'
                           LIMIT 1");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || trim((string) $row['email']) === '') {
        return ['status' => 'error', 'message' => 'Angsuran tidak ditemukan atau anggota belum memiliki email.'];
    }

    $type = 'loan_due_reminder';
    $subject = 'Pengingat angsuran ' . $row['loan_number'];
    if (email_already_sent_today($type, $payment_id) || !claim_email_send_today($type, $payment_id, $row['email'], $subject)) {
        return ['status' => 'skipped', 'message' => 'Pengingat untuk angsuran ini sudah dikirim hari ini.'];
    }

    $remaining = max(0, (float) $row['amount_due'] - (float) $row['amount_paid']);
    $days = (int) ((new DateTimeImmutable('today'))->diff(new DateTimeImmutable($row['due_date']))->format('%r%a'));
    $timing = $days < 0 ? 'sudah lewat ' . abs($days) . ' hari' : ($days === 0 ? 'jatuh tempo hari ini' : 'jatuh tempo dalam ' . $days . ' hari');
    $body = "Yth. " . $row['full_name'] . ",\n\n"
        . "Ini adalah pengingat bahwa angsuran ke-" . $row['payment_number'] . " pinjaman " . $row['loan_number'] . " " . $timing . ".\n"
        . "Jatuh tempo: " . date('d/m/Y', strtotime($row['due_date'])) . "\n"
        . "Sisa tagihan: " . rupiah($remaining) . "\n\n"
        . "Silakan hubungi koperasi untuk informasi pembayaran.\n\nTerima kasih.";
    $sent = send_mail($row['email'], $subject, $body);
    $status = $sent ? 'sent' : 'failed';
    $error = $sent ? '' : 'mail() mengembalikan false';
    $stmt = db()->prepare('UPDATE email_logs SET status = ?, error_message = ? WHERE email_type = ? AND reference_id = ? AND sent_date = CURDATE()');
    $stmt->bind_param('sssi', $status, $error, $type, $payment_id);
    $stmt->execute();

    return [
        'status' => $sent ? 'sent' : 'failed',
        'message' => $sent ? 'Pengingat berhasil dikirim.' : 'Pengiriman gagal. Percobaan tetap dicatat.',
    ];
}
