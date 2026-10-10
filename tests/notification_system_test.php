<?php
/**
 * Notification System Test
 * Manual test to verify notification system components
 * 
 * Run: php tests/notification_system_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notification_helpers.php';

echo "=== Notification System Test ===\n\n";

// Test 1: Create in-app notification
echo "Test 1: Creating in-app notification...\n";
try {
    $notification_id = create_notification(
        'general',
        'other',
        null,
        null,
        1, // Assuming member ID 1 exists
        'Test Notification',
        'This is a test notification message.'
    );
    echo $notification_id > 0 ? "✓ Created notification ID: {$notification_id}\n" : "✗ Failed to create notification\n";
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

// Test 2: Get unread count
echo "\nTest 2: Getting unread count...\n";
try {
    $count = get_unread_count(null, 1); // Member ID 1
    echo "✓ Unread count for member 1: {$count}\n";
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

// Test 3: Check notification preference field
echo "\nTest 3: Checking member notification_preference...\n";
try {
    $stmt = db()->prepare("SELECT id, full_name, notification_preference FROM members LIMIT 1");
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    if ($member) {
        echo "✓ Member: {$member['full_name']}, Preference: " . ($member['notification_preference'] ?? 'NULL') . "\n";
    } else {
        echo "✗ No members found\n";
    }
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

// Test 4: Check email settings
echo "\nTest 4: Checking email settings...\n";
try {
    $stmt = db()->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'email_%' OR `key` = 'reminder_days_before'");
    $settings = $stmt->fetch_all(MYSQLI_ASSOC);
    if (count($settings) > 0) {
        foreach ($settings as $s) {
            echo "  {$s['key']}: {$s['value']}\n";
        }
    } else {
        echo "⚠ No email settings found (migration may not be applied)\n";
    }
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

// Test 5: Check notifications table
echo "\nTest 5: Checking notifications table structure...\n";
try {
    $result = db()->query("DESCRIBE notifications");
    $columns = $result->fetch_all(MYSQLI_ASSOC);
    $required = ['id', 'notification_type', 'reference_type', 'recipient_user_id', 'recipient_member_id', 'title', 'message', 'is_read'];
    $found = array_column($columns, 'Field');
    $missing = array_diff($required, $found);
    if (empty($missing)) {
        echo "✓ All required columns present\n";
    } else {
        echo "✗ Missing columns: " . implode(', ', $missing) . "\n";
    }
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

// Test 6: Check permissions
echo "\nTest 6: Checking notification permissions...\n";
try {
    $stmt = db()->query("SELECT name FROM permissions WHERE name LIKE 'notifications.%'");
    $perms = $stmt->fetch_all(MYSQLI_ASSOC);
    if (count($perms) > 0) {
        foreach ($perms as $p) {
            echo "  ✓ {$p['name']}\n";
        }
    } else {
        echo "⚠ No notification permissions found (run migration_notifications_permissions.sql)\n";
    }
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

// Test 7: Verify cron script is executable
echo "\nTest 7: Checking cron script...\n";
$cron_file = __DIR__ . '/../cron/loan_due_reminders.php';
if (file_exists($cron_file)) {
    echo "✓ Cron script exists\n";
    if (is_executable($cron_file)) {
        echo "✓ Script is executable\n";
    } else {
        echo "⚠ Script is not executable (run: chmod +x cron/loan_due_reminders.php)\n";
    }
} else {
    echo "✗ Cron script not found\n";
}

echo "\n=== Test Complete ===\n";
echo "\nNext steps:\n";
echo "1. Run permissions migration: mysql < database/migration_notifications_permissions.sql\n";
echo "2. Run phase 2 migration if not done: mysql < database/migration_phase2_compliance.sql\n";
echo "3. Setup cron job: 0 8 * * * php " . realpath($cron_file) . "\n";
echo "4. Test manually: php cron/loan_due_reminders.php\n";
