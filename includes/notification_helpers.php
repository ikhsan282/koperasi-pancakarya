<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/whatsapp_helpers.php';

/**
 * Create an in-app notification
 */
function create_notification(
    string $notification_type,
    string $reference_type,
    ?int $reference_id,
    ?int $recipient_user_id,
    ?int $recipient_member_id,
    string $title,
    string $message
): int {
    $stmt = db()->prepare(
        'INSERT INTO notifications (notification_type, reference_type, reference_id, recipient_user_id, recipient_member_id, title, message, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->bind_param('ssiisss', $notification_type, $reference_type, $reference_id, $recipient_user_id, $recipient_member_id, $title, $message);
    $stmt->execute();
    return db()->insert_id;
}

/**
 * Mark notification as read
 */
function mark_notification_read(int $notification_id, ?int $user_id = null): bool
{
    if ($user_id) {
        $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_user_id = ?');
        $stmt->bind_param('ii', $notification_id, $user_id);
    } else {
        $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?');
        $stmt->bind_param('i', $notification_id);
    }
    $stmt->execute();
    return $stmt->affected_rows > 0;
}

/**
 * Get unread notification count for a user
 */
function get_unread_count(?int $user_id = null, ?int $member_id = null): int
{
    if ($user_id) {
        $stmt = db()->prepare('SELECT COUNT(*) as cnt FROM notifications WHERE recipient_user_id = ? AND is_read = 0');
        $stmt->bind_param('i', $user_id);
    } elseif ($member_id) {
        $stmt = db()->prepare('SELECT COUNT(*) as cnt FROM notifications WHERE recipient_member_id = ? AND is_read = 0');
        $stmt->bind_param('i', $member_id);
    } else {
        return 0;
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return (int) ($result['cnt'] ?? 0);
}

/**
 * Send email notification using configured method
 */
function send_email_notification(string $to, string $subject, string $body): bool
{
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    // Get email settings
    $settings = [];
    $stmt = db()->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'email_%'");
    while ($row = $stmt->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }

    $from = $settings['email_from'] ?? 'noreply@koperasi.test';
    $smtp_host = $settings['email_smtp_host'] ?? '';

    // Use SMTP if configured, otherwise use mail()
    if (!empty($smtp_host)) {
        return send_via_smtp($to, $subject, $body, $settings);
    }

    // Fall back to PHP mail()
    $headers = "From: " . str_replace(["\r", "\n"], '', $from) . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return mail($to, $subject, $body, $headers);
}

/**
 * Send SMS notification (placeholder for user integration)
 */
function send_sms_notification(string $phone, string $message): bool
{
    if (empty($phone)) {
        return false;
    }

    // Get SMS settings
    $stmt = db()->prepare("SELECT `value` FROM settings WHERE `key` = 'sms_gateway_url'");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $gateway_url = $result['value'] ?? '';

    if (empty($gateway_url)) {
        // ponytail: SMS gateway not configured - user must integrate their provider
        return false;
    }

    // Placeholder: User should implement their SMS gateway integration here
    // Example: POST to $gateway_url with API key and message
    return false;
}

/**
 * Send notification via SMTP
 */
function send_via_smtp(string $to, string $subject, string $body, array $settings): bool
{
    $host = $settings['email_smtp_host'] ?? '';
    $port = (int) ($settings['email_smtp_port'] ?? 587);
    $user = $settings['email_smtp_user'] ?? '';
    $pass = $settings['email_smtp_pass'] ?? '';
    $from = $settings['email_from'] ?? 'noreply@koperasi.test';

    if (empty($host) || empty($user) || empty($pass)) {
        return false;
    }

    $socket = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$socket) {
        return false;
    }

    $read = function() use ($socket) { return fgets($socket, 512); };
    $write = function($cmd) use ($socket) { fputs($socket, $cmd . "\r\n"); };

    $read(); // banner
    $write("EHLO localhost");
    $read();

    if ($port == 587) {
        $write("STARTTLS");
        $read();
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }
        $write("EHLO localhost");
        $read();
    }

    $write("AUTH LOGIN");
    $read();
    $write(base64_encode($user));
    $read();
    $write(base64_encode($pass));
    $response = $read();
    if (!str_starts_with($response, '235')) {
        fclose($socket);
        return false;
    }

    $write("MAIL FROM:<{$from}>");
    $read();
    $write("RCPT TO:<{$to}>");
    $read();
    $write("DATA");
    $read();

    $write("From: {$from}");
    $write("To: {$to}");
    $write("Subject: {$subject}");
    $write("Content-Type: text/plain; charset=UTF-8");
    $write("");
    $write($body);
    $write(".");
    $read();

    $write("QUIT");
    fclose($socket);

    return true;
}

/**
 * Send notification to member based on their preference
 */
function notify_member(int $member_id, string $title, string $message, string $type = 'general', string $ref_type = 'other', ?int $ref_id = null): array
{
    $stmt = db()->prepare('SELECT email, phone, notification_preference FROM members WHERE id = ?');
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();

    if (!$member) {
        return ['in_app' => false, 'email' => false, 'sms' => false];
    }

    $results = ['in_app' => false, 'email' => false, 'sms' => false];

    // Always create in-app notification
    $notification_id = create_notification($type, $ref_type, $ref_id, null, $member_id, $title, $message);
    $results['in_app'] = $notification_id > 0;

    $preference = $member['notification_preference'] ?? 'email';

    // Send email if preference allows
    if (in_array($preference, ['email', 'both']) && !empty($member['email'])) {
        $results['email'] = send_email_notification($member['email'], $title, $message);
    }

    // Send SMS/WhatsApp if preference allows
    if (in_array($preference, ['sms', 'both']) && !empty($member['phone'])) {
        // Try WhatsApp first (modern replacement for SMS)
        $results['sms'] = send_whatsapp($member['phone'], $message, $ref_type, $ref_id);
        
        // Fall back to SMS gateway if WhatsApp fails
        if (!$results['sms']) {
            $results['sms'] = send_sms_notification($member['phone'], $message);
        }
    }

    return $results;
}
