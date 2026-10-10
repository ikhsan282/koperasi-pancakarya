# Cron Jobs - Koperasi Pancakarya

## Pengingat Jatuh Tempo Pinjaman

Script `loan_due_reminders.php` mengirim notifikasi pengingat angsuran pinjaman yang akan jatuh tempo.

### Setup Cron Job

Tambahkan ke crontab untuk menjalankan otomatis setiap hari pukul 08:00:

```bash
crontab -e
```

Tambahkan baris berikut:
```
0 8 * * * /usr/bin/php /path/to/koperasi-pancakarya/cron/loan_due_reminders.php
```

Ganti `/path/to/koperasi-pancakarya` dengan path lengkap instalasi aplikasi.

### Menjalankan Manual

Untuk testing atau menjalankan manual:

```bash
php cron/loan_due_reminders.php
```

### Konfigurasi

Edit pengaturan di menu **Pengaturan** untuk mengubah:
- `reminder_days_before`: Berapa hari sebelum jatuh tempo notifikasi dikirim (default: 3 hari)
- `email_from`: Alamat email pengirim
- `email_smtp_*`: Konfigurasi SMTP (opsional, default menggunakan PHP mail())

### Integrasi SMS

Fungsi `send_sms_notification()` di `includes/notification_helpers.php` adalah placeholder. 

Untuk mengaktifkan SMS:
1. Daftar di provider SMS gateway (misal: Zenziva, Twilio, dll)
2. Tambahkan setting `sms_gateway_url` dan `sms_api_key` di tabel settings
3. Edit fungsi `send_sms_notification()` untuk integrasi dengan provider Anda

Contoh integrasi:
```php
function send_sms_notification(string $phone, string $message): bool
{
    $gateway_url = get_setting('sms_gateway_url');
    $api_key = get_setting('sms_api_key');
    
    // Contoh request ke gateway
    $data = [
        'api_key' => $api_key,
        'phone' => $phone,
        'message' => $message
    ];
    
    $ch = curl_init($gateway_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $status >= 200 && $status < 300;
}
```
