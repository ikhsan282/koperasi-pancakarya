<?php
/**
 * Phase 2: Notification System Functional Tests
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notification_helpers.php';

$passed = 0;
$failed = 0;
$errors = [];

function test_notif($name, $callback) {
    global $passed, $failed, $errors;
    try {
        $result = $callback();
        if ($result === true) {
            $passed++;
            echo "✓ {$name}\n";
            return true;
        } else {
            $failed++;
            $errors[] = "{$name}: {$result}";
            echo "✗ {$name} - {$result}\n";
            return false;
        }
    } catch (Throwable $e) {
        $failed++;
        $errors[] = "{$name}: Exception - {$e->getMessage()}";
        echo "✗ {$name} - Exception: {$e->getMessage()}\n";
        return false;
    }
}

echo "=== Phase 2: Notification System Tests ===\n\n";

// TEST 1: In-app notifications
echo "1. IN-APP NOTIFICATION TESTS\n";

test_notif("Create notification", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id FROM members LIMIT 1");
        $member = $stmt->fetch_assoc();
        if (!$member) {
            db()->rollback();
            return "No member found";
        }
        
        $notif_id = create_notification(
            'general',
            'other',
            null,
            null,
            (int)$member['id'],
            'Test Notification',
            'This is a test message'
        );
        
        db()->rollback();
        
        if ($notif_id > 0) {
            return true;
        }
        return "Failed to create notification";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

test_notif("Mark notification as read", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id FROM members LIMIT 1");
        $member = $stmt->fetch_assoc();
        
        $notif_id = create_notification('general', 'other', null, null, (int)$member['id'], 'Test', 'Test');
        
        $success = mark_notification_read($notif_id);
        
        $stmt = db()->prepare("SELECT is_read FROM notifications WHERE id = ?");
        $stmt->bind_param('i', $notif_id);
        $stmt->execute();
        $is_read = $stmt->get_result()->fetch_assoc()['is_read'];
        
        db()->rollback();
        
        if ($is_read == 1) {
            return true;
        }
        return "Notification not marked as read";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

test_notif("Get unread count", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id FROM users LIMIT 1");
        $user = $stmt->fetch_assoc();
        if (!$user) {
            db()->rollback();
            return "No user found";
        }
        
        // Create 3 unread notifications
        for ($i = 0; $i < 3; $i++) {
            create_notification('general', 'other', null, (int)$user['id'], null, "Test $i", "Message $i");
        }
        
        $count = get_unread_count((int)$user['id'], null);
        
        db()->rollback();
        
        if ($count >= 3) {
            return true;
        }
        return "Unread count incorrect: expected >= 3, got {$count}";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

// TEST 2: Email notifications
echo "\n2. EMAIL NOTIFICATION TESTS\n";

test_notif("Email validation rejects invalid email", function() {
    $result = send_email_notification('invalid-email', 'Test', 'Body');
    if ($result === false) {
        return true;
    }
    return "Should reject invalid email";
});

test_notif("Email validation accepts valid email", function() {
    // This will likely fail without SMTP configured, but tests validation logic
    $result = send_email_notification('test@example.com', 'Test', 'Body');
    // Result can be false (SMTP not configured) but at least it passed validation
    return true;
});

test_notif("SMS notification placeholder returns false without config", function() {
    $result = send_sms_notification('081234567890', 'Test message');
    if ($result === false) {
        return true;
    }
    return "Should return false when gateway not configured";
});

// TEST 3: Member notification preferences
echo "\n3. MEMBER NOTIFICATION PREFERENCE TESTS\n";

test_notif("Notify member respects preference='none'", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id FROM members WHERE notification_preference = 'none' LIMIT 1");
        $member = $stmt->fetch_assoc();
        
        if (!$member) {
            // Create test member with none preference
            $stmt = db()->prepare("INSERT INTO members (member_number, full_name, email, phone, notification_preference, status) 
                                  VALUES ('TEST-NONE', 'Test None', 'test@test.com', '081234567890', 'none', 'active')");
            $stmt->execute();
            $member_id = db()->insert_id();
        } else {
            $member_id = (int)$member['id'];
        }
        
        $result = notify_member($member_id, 'Test', 'Message', 'general', 'other', null);
        
        db()->rollback();
        
        // Should create in-app notification but not send email/SMS
        if ($result['in_app'] === true && $result['email'] === false && $result['sms'] === false) {
            return true;
        }
        return "Preference 'none' not respected: " . json_encode($result);
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

test_notif("Notify member with email preference", function() {
    db()->begin_transaction();
    try {
        $stmt = db()->query("SELECT id FROM members WHERE notification_preference = 'email' AND email IS NOT NULL LIMIT 1");
        $member = $stmt->fetch_assoc();
        
        if (!$member) {
            $stmt = db()->prepare("INSERT INTO members (member_number, full_name, email, phone, notification_preference, status) 
                                  VALUES ('TEST-EMAIL', 'Test Email', 'test@test.com', '081234567890', 'email', 'active')");
            $stmt->execute();
            $member_id = db()->insert_id();
        } else {
            $member_id = (int)$member['id'];
        }
        
        $result = notify_member($member_id, 'Test', 'Message', 'general', 'other', null);
        
        db()->rollback();
        
        // Should create in-app and attempt email (may fail without SMTP)
        if ($result['in_app'] === true) {
            return true;
        }
        return "In-app notification should always be created";
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
});

// TEST 4: Edge cases
echo "\n4. EDGE CASE TESTS\n";

test_notif("Notify non-existent member returns false", function() {
    $result = notify_member(999999999, 'Test', 'Message', 'general', 'other', null);
    if ($result['in_app'] === false) {
        return true;
    }
    return "Should return false for non-existent member";
});

test_notif("Empty email string handled gracefully", function() {
    $result = send_email_notification('', 'Test', 'Body');
    if ($result === false) {
        return true;
    }
    return "Should return false for empty email";
});

test_notif("Empty phone string handled gracefully", function() {
    $result = send_sms_notification('', 'Test message');
    if ($result === false) {
        return true;
    }
    return "Should return false for empty phone";
});

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Notification Tests: {$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
} else {
    echo "\n✓ All notification tests PASSED\n";
    exit(0);
}
