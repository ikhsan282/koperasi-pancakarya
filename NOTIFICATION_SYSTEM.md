# Sistem Notifikasi Jatuh Tempo - Koperasi Pancakarya

## Overview

Sistem notifikasi otomatis untuk pengingat angsuran pinjaman yang akan jatuh tempo, dengan dukungan in-app, email, dan SMS.

## Fitur

- ✅ Notifikasi in-app dengan badge counter
- ✅ Notifikasi email (PHP mail() atau SMTP)
- ✅ Notifikasi SMS (placeholder untuk integrasi gateway)
- ✅ Cron job otomatis untuk pengingat jatuh tempo
- ✅ Preferensi notifikasi per anggota (email/sms/both/none)
- ✅ Pengiriman notifikasi manual (broadcast)
- ✅ Dashboard notifikasi dengan filter

## File yang Dibuat/Dimodifikasi

### Backend
- `includes/notification_helpers.php` - Core notification functions
- `cron/loan_due_reminders.php` - Automated reminder script
- `cron/README.md` - Cron setup documentation

### UI
- `pages/notifications/index.php` - Notification inbox
- `pages/notifications/send.php` - Manual notification sender
- `pages/members/form.php` - Updated with notification preference field

### Integration
- `includes/header.php` - Added notification bell badge
- `includes/sidebar.php` - Added notification menu item

### Database
- `database/migration_notifications_permissions.sql` - Permissions for notification features

## Setup

### 1. Run Database Migrations

```bash
# Run phase 2 migration (if not already done)
mysql -u root -p koperasi_pancakarya < database/migration_phase2_compliance.sql

# Run notification permissions
mysql -u root -p koperasi_pancakarya < database/migration_notifications_permissions.sql
```

### 2. Setup Cron Job

Add to crontab to run daily at 8:00 AM:

```bash
crontab -e
```

Add line:
```
0 8 * * * /usr/bin/php /opt/data/projects/koperasi-pancakarya/cron/loan_due_reminders.php
```

### 3. Configure Settings

Login sebagai admin dan update settings berikut di menu **Pengaturan**:

**Email Configuration:**
- `email_from` - Alamat pengirim (default: noreply@koperasi.test)
- `email_smtp_host` - SMTP host (kosongkan untuk PHP mail())
- `email_smtp_port` - SMTP port (default: 587)
- `email_smtp_user` - SMTP username
- `email_smtp_pass` - SMTP password

**Reminder Settings:**
- `reminder_days_before` - Hari sebelum jatuh tempo (default: 3)

**SMS Configuration (Optional):**
- `sms_gateway_url` - URL gateway SMS
- `sms_api_key` - API key gateway

## Penggunaan

### Member Notification Preferences

Di form anggota (Members → Edit), pilih preferensi notifikasi:
- **Email** - Notifikasi via email saja
- **SMS** - Notifikasi via SMS saja (jika gateway configured)
- **Email & SMS** - Kedua channel
- **Tidak Ada** - Hanya in-app notification

### Viewing Notifications

Klik ikon 🔔 di header untuk melihat notifikasi. Badge merah menunjukkan jumlah notifikasi belum dibaca.

### Sending Manual Notifications

Admin/Super Admin dapat mengirim notifikasi manual:
1. Navigasi ke **Notifikasi → Kirim Notifikasi**
2. Pilih tujuan (semua anggota atau spesifik)
3. Isi judul dan pesan
4. Kirim

### Testing Cron Job

Run manual untuk testing:

```bash
php cron/loan_due_reminders.php
```

Output akan menunjukkan jumlah notifikasi terkirim dan status.

## SMS Gateway Integration

Fungsi `send_sms_notification()` di `includes/notification_helpers.php` adalah placeholder. Untuk mengaktifkan:

1. Daftar di SMS gateway provider (Zenziva, Twilio, dll)
2. Update settings `sms_gateway_url` dan `sms_api_key`
3. Edit fungsi `send_sms_notification()` dengan API gateway Anda

Contoh integrasi:

```php
function send_sms_notification(string $phone, string $message): bool
{
    $stmt = db()->prepare("SELECT value FROM settings WHERE `key` IN ('sms_gateway_url', 'sms_api_key')");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
    
    $gateway_url = $settings['sms_gateway_url'] ?? '';
    $api_key = $settings['sms_api_key'] ?? '';
    
    if (empty($gateway_url) || empty($api_key)) {
        return false;
    }
    
    // Example: POST to gateway
    $ch = curl_init($gateway_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'api_key' => $api_key,
            'phone' => $phone,
            'message' => $message
        ]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $status >= 200 && $status < 300;
}
```

## API Functions

### `create_notification()`
```php
create_notification(
    string $notification_type,  // 'loan_due_reminder', 'loan_approved', etc
    string $reference_type,     // 'loan', 'loan_payment', 'savings', etc
    ?int $reference_id,         // ID of related record
    ?int $recipient_user_id,    // For staff users
    ?int $recipient_member_id,  // For members
    string $title,
    string $message
): int // Returns notification ID
```

### `notify_member()`
```php
notify_member(
    int $member_id,
    string $title,
    string $message,
    string $type = 'general',
    string $ref_type = 'other',
    ?int $ref_id = null
): array // ['in_app' => bool, 'email' => bool, 'sms' => bool]
```

### `mark_notification_read()`
```php
mark_notification_read(int $notification_id, ?int $user_id = null): bool
```

### `get_unread_count()`
```php
get_unread_count(?int $user_id = null, ?int $member_id = null): int
```

## Testing

Run test suite:

```bash
php tests/notification_system_test.php
```

## Permissions

- `notifications.view` - Melihat notifikasi (semua role)
- `notifications.send` - Kirim notifikasi manual (Admin, Super Admin)

## Troubleshooting

**Email tidak terkirim:**
1. Check settings `email_from`, `email_smtp_*`
2. Test SMTP connection: `telnet smtp.example.com 587`
3. Check PHP mail() enabled: `php -i | grep mail`

**Cron tidak jalan:**
1. Check cron log: `grep CRON /var/log/syslog`
2. Test manual: `php cron/loan_due_reminders.php`
3. Check file permissions: `chmod +x cron/loan_due_reminders.php`

**Notifikasi tidak muncul:**
1. Run test: `php tests/notification_system_test.php`
2. Check migrations applied
3. Check permissions granted to user role

## Future Enhancements

- Push notifications via service worker
- WhatsApp integration via Business API
- Telegram bot integration
- Notification templates with variables
- Scheduled/delayed notifications
- Notification history export
