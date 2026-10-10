#!/usr/bin/env php
<?php
/**
 * Cron script: Auto-post monthly savings interest
 * 
 * Run on 1st of each month via crontab:
 * 0 1 1 * * cd /opt/data/projects/koperasi-pancakarya && php cron/monthly_interest.php
 * 
 * Or manually: php cron/monthly_interest.php [month] [year]
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/savings_interest.php';

// CLI mode check
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from command line.');
}

// Check if auto-posting is enabled
$stmt = db()->prepare('SELECT value FROM settings WHERE `key` = "auto_interest_enabled"');
$stmt->execute();
$setting = $stmt->get_result()->fetch_assoc();
$auto_enabled = isset($setting['value']) && $setting['value'] === '1';

if (!$auto_enabled) {
    echo "[SKIP] Auto-posting disabled in settings (auto_interest_enabled=0)\n";
    exit(0);
}

// Get target period (last month by default, or from CLI args)
if (isset($argv[1]) && isset($argv[2])) {
    $target_month = (int) $argv[1];
    $target_year = (int) $argv[2];
} else {
    $last_month = (int) date('n') - 1;
    $target_year = (int) date('Y');
    if ($last_month < 1) {
        $last_month = 12;
        $target_year--;
    }
    $target_month = $last_month;
}

if ($target_month < 1 || $target_month > 12) {
    echo "[ERROR] Invalid month: {$target_month}\n";
    exit(1);
}

$month_name = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
               'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$target_month];

echo "[INFO] Starting auto-post for {$month_name} {$target_year}\n";

// Get accounts eligible for posting
$accounts = get_accounts_for_interest_posting($target_month, $target_year);

if (empty($accounts)) {
    echo "[INFO] No accounts found\n";
    exit(0);
}

// Count what needs posting
$to_post = array_filter($accounts, fn($acc) => !$acc['already_posted'] && $acc['interest_amount'] > 0);
$already_posted = array_filter($accounts, fn($acc) => $acc['already_posted']);

echo "[INFO] Accounts found: " . count($accounts) . "\n";
echo "[INFO] Already posted: " . count($already_posted) . "\n";
echo "[INFO] To post: " . count($to_post) . "\n";

if (empty($to_post)) {
    echo "[INFO] Nothing to post\n";
    exit(0);
}

// Get system user for posting (find first admin/super admin)
$stmt = db()->prepare('SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id 
                      WHERE r.name IN ("Super Admin", "Admin") 
                      ORDER BY r.name ASC LIMIT 1');
$stmt->execute();
$system_user = $stmt->get_result()->fetch_assoc();

if (!$system_user) {
    echo "[ERROR] No admin user found for system posting\n";
    exit(1);
}

$posted_by = (int) $system_user['id'];

// Post interest for each account
db()->begin_transaction();
try {
    $posted_count = 0;
    $total_interest = 0.0;
    $errors = [];
    
    foreach ($to_post as $acc) {
        try {
            $success = post_interest_for_account(
                (int) $acc['id'], 
                $target_month, 
                $target_year, 
                $posted_by
            );
            
            if ($success) {
                $posted_count++;
                $total_interest += $acc['interest_amount'];
                echo "[OK] {$acc['account_number']} ({$acc['member_name']}): " . number_format($acc['interest_amount'], 2) . "\n";
            }
        } catch (Exception $e) {
            $errors[] = "Account {$acc['account_number']}: " . $e->getMessage();
            echo "[ERROR] {$acc['account_number']}: {$e->getMessage()}\n";
        }
    }
    
    if (!empty($errors)) {
        db()->rollback();
        echo "[FAILED] Rolled back due to errors:\n";
        foreach ($errors as $err) {
            echo "  - {$err}\n";
        }
        exit(1);
    }
    
    db()->commit();
    
    echo "\n[SUCCESS] Posted interest for {$posted_count} accounts\n";
    echo "[TOTAL] " . number_format($total_interest, 2) . "\n";
    
    exit(0);
} catch (Exception $e) {
    db()->rollback();
    echo "[FAILED] Transaction failed: {$e->getMessage()}\n";
    exit(1);
}
