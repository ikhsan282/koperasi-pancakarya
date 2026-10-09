<?php
declare(strict_types=1);

/**
 * Integration test: an installment reminder is delivered at most once per payment per day.
 *
 * Needs MySQL/MariaDB with database/schema.sql loaded (connection in config/config.php).
 * sendmail_path is a startup-only setting, so point it at the stub when running:
 *   MAIL_LOG=/tmp/mail.log php -d sendmail_path="$PWD/tests/bin/sendmail_stub.sh -t -i" tests/email_dedup_test.php
 * Each delivery appends a DELIVERED line to MAIL_LOG, so the count is real.
 */

$mail_log = getenv('MAIL_LOG') ?: '';
if ($mail_log === '' || !str_contains((string) ini_get('sendmail_path'), 'sendmail_stub.sh')) {
    fwrite(STDERR, "Set MAIL_LOG and -d sendmail_path=tests/bin/sendmail_stub.sh (see header).\n");
    exit(2);
}
@unlink($mail_log);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email_notifications.php';

$db = db();
$db->begin_transaction(); // all fixtures are rolled back at the end

$tag = bin2hex(random_bytes(4));
$email = "dedup-$tag@example.test";
$member_no = "T$tag";
$stmt = $db->prepare("INSERT INTO members (member_number, full_name, email, join_date) VALUES (?, 'Dedup Test', ?, CURDATE())");
$stmt->bind_param('ss', $member_no, $email);
$stmt->execute();
$member_id = $db->insert_id;

$product_name = "Test $tag";
$stmt = $db->prepare("INSERT INTO loan_products (name, interest_rate, max_tenor_months, min_amount, max_amount) VALUES (?, 1.50, 24, 1000000, 20000000)");
$stmt->bind_param('s', $product_name);
$stmt->execute();
$product_id = $db->insert_id;

$loan_number = "L$tag";
$stmt = $db->prepare("INSERT INTO loans (member_id, loan_product_id, loan_number, amount, interest_rate, term_months, monthly_payment, status, application_date, disbursement_date)
                      VALUES (?, ?, ?, 12000000, 1.50, 12, 1180000, 'active', CURDATE(), CURDATE())");
$stmt->bind_param('iis', $member_id, $product_id, $loan_number);
$stmt->execute();
$loan_id = $db->insert_id;

$stmt = $db->prepare("INSERT INTO loan_payments (loan_id, due_date, payment_number, principal_amount, interest_amount, amount_due, balance_remaining, status)
                      VALUES (?, CURDATE(), 1, 1000000, 180000, 1180000, 12000000, 'pending')");
$stmt->bind_param('i', $loan_id);
$stmt->execute();
$payment_id = $db->insert_id;

$failures = [];
$check = function (bool $ok, string $msg) use (&$failures): void {
    if (!$ok) {
        $failures[] = $msg;
    }
};

$first = send_due_reminder($payment_id);
$check($first['status'] === 'sent', "first send should be 'sent', got '{$first['status']}': {$first['message']}");

$second = send_due_reminder($payment_id);
$check($second['status'] === 'skipped', "second same-day send should be 'skipped', got '{$second['status']}'");

$third = send_due_reminder($payment_id);
$check($third['status'] === 'skipped', "third same-day send should be 'skipped', got '{$third['status']}'");

$stmt = $db->prepare("SELECT COUNT(*) AS n, MAX(status) AS st FROM email_logs WHERE email_type = 'loan_due_reminder' AND reference_id = ? AND sent_date = CURDATE()");
$stmt->bind_param('i', $payment_id);
$stmt->execute();
$log = $stmt->get_result()->fetch_assoc();
$check((int) $log['n'] === 1, "email_logs should hold exactly one row, got {$log['n']}");
$check($log['st'] === 'sent', "the email_logs row should be 'sent', got '{$log['st']}'");

$deliveries = is_file($mail_log) ? substr_count((string) file_get_contents($mail_log), 'DELIVERED') : 0;
$check($deliveries === 1, "mail() should deliver exactly once, delivered $deliveries");

$db->rollback();
@unlink($mail_log);

if ($failures) {
    fwrite(STDERR, "email_dedup_test: FAILED\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "email_dedup_test: OK\n");
