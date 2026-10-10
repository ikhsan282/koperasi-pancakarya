<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Send WhatsApp message via configured gateway (Fonnte/Wablas)
 * 
 * @param string $phone Phone number (format: 628123456789)
 * @param string $message Message content
 * @param string $reference_type Type: loan_payment, loan_approval, savings_deposit, general
 * @param int|null $reference_id Related record ID
 * @return bool Success status
 */
function send_whatsapp(string $phone, string $message, string $reference_type = 'general', ?int $reference_id = null): bool
{
    // Normalize phone: remove +, spaces, dashes
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (empty($phone) || strlen($phone) < 10) {
        log_whatsapp($phone, $message, $reference_type, $reference_id, 'failed', 'Invalid phone number');
        return false;
    }
    
    // Get WhatsApp settings
    $stmt = db()->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'whatsapp_%'");
    $settings = [];
    while ($row = $stmt->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
    
    $enabled = ($settings['whatsapp_enabled'] ?? '0') === '1';
    $api_url = trim($settings['whatsapp_api_url'] ?? '');
    $api_key = trim($settings['whatsapp_api_key'] ?? '');
    
    if (!$enabled || empty($api_url) || empty($api_key)) {
        log_whatsapp($phone, $message, $reference_type, $reference_id, 'failed', 'WhatsApp not configured or disabled');
        return false;
    }
    
    // Build request payload (supports Fonnte & Wablas format)
    $payload = json_encode([
        'target' => $phone,
        'message' => $message,
    ]);
    
    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: ' . $api_key,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || $http_code < 200 || $http_code >= 300) {
        $error = $curl_error ?: "HTTP {$http_code}: " . substr((string) $response, 0, 200);
        log_whatsapp($phone, $message, $reference_type, $reference_id, 'failed', $error);
        return false;
    }
    
    log_whatsapp($phone, $message, $reference_type, $reference_id, 'sent', (string) $response);
    return true;
}

/**
 * Log WhatsApp send attempt
 */
function log_whatsapp(string $phone, string $message, string $reference_type, ?int $reference_id, string $status, string $response): void
{
    $stmt = db()->prepare(
        'INSERT INTO whatsapp_logs (phone, message, reference_type, reference_id, status, response, sent_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->bind_param('sssiss', $phone, $message, $reference_type, $reference_id, $status, $response);
    $stmt->execute();
}

/**
 * Send WhatsApp notification to member based on their preference
 * 
 * @param int $member_id Member ID
 * @param string $message WhatsApp message content
 * @param string $reference_type Type: loan_payment, loan_approval, savings_deposit, general
 * @param int|null $reference_id Related record ID
 * @return bool Success status
 */
function notify_member_whatsapp(int $member_id, string $message, string $reference_type = 'general', ?int $reference_id = null): bool
{
    $stmt = db()->prepare('SELECT phone, notification_preference FROM members WHERE id = ?');
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    
    if (!$member || empty($member['phone'])) {
        return false;
    }
    
    $preference = $member['notification_preference'] ?? 'email';
    
    // Send WhatsApp if preference is 'sms' or 'both'
    if (in_array($preference, ['sms', 'both'])) {
        return send_whatsapp($member['phone'], $message, $reference_type, $reference_id);
    }
    
    return false;
}
