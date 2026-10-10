#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * WhatsApp Integration Test
 * 
 * Tests WhatsApp notification functionality
 * Usage: php tests/whatsapp_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp_helpers.php';

echo "=== WhatsApp Integration Test ===\n\n";

// 1. Check if WhatsApp is configured
echo "[1] Checking WhatsApp configuration...\n";
$stmt = db()->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'whatsapp_%'");
$settings = [];
while ($row = $stmt->fetch_assoc()) {
    $settings[$row['key']] = $row['value'];
}

$enabled = ($settings['whatsapp_enabled'] ?? '0') === '1';
$api_url = $settings['whatsapp_api_url'] ?? '';
$api_key = $settings['whatsapp_api_key'] ?? '';

echo "  - Enabled: " . ($enabled ? 'YES' : 'NO') . "\n";
echo "  - API URL: " . ($api_url ?: '(not configured)') . "\n";
echo "  - API Key: " . ($api_key ? '(configured, ' . strlen($api_key) . ' chars)' : '(not configured)') . "\n";

if (!$enabled) {
    echo "\n[WARNING] WhatsApp is disabled. Enable it in Settings to test sending.\n";
}

if (empty($api_url) || empty($api_key)) {
    echo "\n[WARNING] WhatsApp API not fully configured. Configure in Settings.\n";
}

// 2. Check whatsapp_logs table
echo "\n[2] Checking whatsapp_logs table...\n";
$result = db()->query("SHOW TABLES LIKE 'whatsapp_logs'");
if ($result->num_rows === 0) {
    echo "  [ERROR] whatsapp_logs table does not exist!\n";
    echo "  Run migration: database/migration_phase3_enhancements.sql\n";
    exit(1);
}

$result = db()->query("SELECT COUNT(*) as total FROM whatsapp_logs");
$count = $result->fetch_assoc()['total'];
echo "  - Table exists: YES\n";
echo "  - Total logs: {$count}\n";

// 3. Test phone normalization
echo "\n[3] Testing phone normalization...\n";
$test_phones = [
    '+62 812-3456-7890',
    '08123456789',
    '628123456789',
    '62-812-345-6789',
];

foreach ($test_phones as $phone) {
    $normalized = preg_replace('/[^0-9]/', '', $phone);
    echo "  - '{$phone}' => '{$normalized}'\n";
}

// 4. Check for test member
echo "\n[4] Checking for test member with phone...\n";
$stmt = db()->query("SELECT id, member_number, full_name, phone, notification_preference 
                     FROM members WHERE phone IS NOT NULL AND phone != '' LIMIT 5");
$members = $stmt->fetch_all(MYSQLI_ASSOC);

if (empty($members)) {
    echo "  [WARNING] No members with phone numbers found.\n";
} else {
    echo "  Found " . count($members) . " members with phone numbers:\n";
    foreach ($members as $member) {
        echo "    - {$member['member_number']}: {$member['full_name']} ({$member['phone']}) - Pref: {$member['notification_preference']}\n";
    }
}

// 5. Test send (dry run if not configured)
echo "\n[5] Testing send_whatsapp() function...\n";

if ($enabled && !empty($api_url) && !empty($api_key)) {
    echo "  Configuration is active. Ready to send test message.\n";
    echo "  To send a real test, uncomment the code below and add a test phone.\n\n";
    
    // Uncomment to send actual test:
    /*
    $test_phone = '628123456789'; // Replace with your test number
    $test_message = "Test WhatsApp dari Koperasi Pancakarya - " . date('Y-m-d H:i:s');
    $result = send_whatsapp($test_phone, $test_message, 'general', null);
    echo "  - Test send result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
    
    // Check log
    $stmt = db()->prepare("SELECT * FROM whatsapp_logs ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $log = $stmt->get_result()->fetch_assoc();
    echo "  - Last log:\n";
    echo "    Phone: {$log['phone']}\n";
    echo "    Status: {$log['status']}\n";
    echo "    Response: " . substr($log['response'], 0, 100) . "...\n";
    */
} else {
    echo "  [DRY RUN] WhatsApp not configured. Skipping actual send test.\n";
    echo "  Function signature test: ";
    
    // Test function exists
    if (function_exists('send_whatsapp')) {
        echo "PASSED\n";
    } else {
        echo "FAILED - function not found!\n";
        exit(1);
    }
}

// 6. Check recent logs
echo "\n[6] Recent WhatsApp logs:\n";
$stmt = db()->query("SELECT phone, reference_type, status, sent_at 
                     FROM whatsapp_logs 
                     ORDER BY id DESC LIMIT 10");
$logs = $stmt->fetch_all(MYSQLI_ASSOC);

if (empty($logs)) {
    echo "  (no logs yet)\n";
} else {
    foreach ($logs as $log) {
        echo "  - {$log['sent_at']}: {$log['phone']} [{$log['reference_type']}] => {$log['status']}\n";
    }
}

echo "\n=== Test Complete ===\n";
echo "\nTo configure WhatsApp:\n";
echo "1. Go to Settings > WhatsApp Integration\n";
echo "2. Enable WhatsApp notifications\n";
echo "3. Enter API URL (e.g., https://api.fonnte.com/send)\n";
echo "4. Enter API Key/Token from your WhatsApp gateway provider\n";
echo "5. Ensure members have phone numbers in format: 628xxxxxxxxxx\n";
echo "\nSupported gateways:\n";
echo "- Fonnte: https://fonnte.com\n";
echo "- Wablas: https://wablas.com\n";
echo "- Any compatible gateway with same API format\n";
