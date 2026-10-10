# Integrasi WhatsApp Notification

## Deskripsi
Sistem notifikasi WhatsApp terintegrasi untuk mengirim pemberitahuan otomatis kepada anggota koperasi melalui gateway WhatsApp (Fonnte, Wablas, atau provider lain yang kompatibel).

## Fitur
- ✅ Notifikasi persetujuan pinjaman
- ✅ Pengingat angsuran jatuh tempo
- ✅ Konfigurasi via Settings UI
- ✅ Log tracking (tabel `whatsapp_logs`)
- ✅ Fallback ke SMS gateway jika WhatsApp gagal
- ✅ Integrasi dengan preferensi notifikasi member

## Instalasi

### 1. Database Migration
Jalankan migration untuk membuat tabel `whatsapp_logs` dan settings:
```bash
mysql -u root -p koperasi_pancakarya < database/migration_phase3_enhancements.sql
```

### 2. Konfigurasi Gateway WhatsApp

#### Opsi A: Fonnte (Recommended)
1. Daftar di https://fonnte.com
2. Buat device/akun WhatsApp
3. Dapatkan API token dari dashboard
4. Masuk ke **Settings > Integrasi WhatsApp** di aplikasi
5. Centang "Aktifkan Notifikasi WhatsApp"
6. API URL: `https://api.fonnte.com/send`
7. API Key: paste token dari Fonnte

#### Opsi B: Wablas
1. Daftar di https://wablas.com
2. Dapatkan domain dan token
3. API URL: `https://pati.wablas.com/api/send-message`
4. API Key: token dari Wablas

#### Opsi C: Gateway Lain
WhatsApp helper mendukung gateway lain dengan format API:
```
POST {api_url}
Headers: Authorization: {api_key}
Body: {"target": "628xxx", "message": "text"}
```

### 3. Format Nomor Telepon
Pastikan nomor telepon member dalam format internasional tanpa tanda +, spasi, atau dash:
- ✅ Benar: `628123456789`
- ❌ Salah: `+62 812-345-6789`, `08123456789`

## Penggunaan

### Otomatis (via Cron)
Reminder angsuran jatuh tempo dikirim otomatis via cron job:
```bash
# Tambahkan ke crontab
0 8 * * * cd /path/to/koperasi && php cron/loan_due_reminders.php
```

### Manual (via Kode)
```php
require_once 'includes/whatsapp_helpers.php';

// Kirim WhatsApp langsung
$success = send_whatsapp(
    '628123456789',
    'Halo, ini pesan test dari koperasi',
    'general',
    null
);

// Kirim ke member berdasarkan preferensi
$success = notify_member_whatsapp(
    $member_id,
    'Pinjaman Anda telah disetujui',
    'loan_approval',
    $loan_id
);
```

## Testing

### Test Script
Jalankan test untuk verifikasi konfigurasi:
```bash
php tests/whatsapp_test.php
```

Output akan menampilkan:
- Status konfigurasi WhatsApp
- Verifikasi tabel database
- Test normalisasi nomor telepon
- Daftar member dengan nomor telepon
- Log pengiriman terbaru

### Test Pengiriman Real
Edit `tests/whatsapp_test.php` dan uncomment bagian test send:
```php
$test_phone = '628123456789'; // Ganti dengan nomor test Anda
$test_message = "Test WhatsApp dari Koperasi";
$result = send_whatsapp($test_phone, $test_message, 'general', null);
```

## Integration Points

### 1. Loan Approval (Persetujuan Pinjaman)
**File:** `pages/loans/process.php`

Ketika pinjaman disetujui (single atau dual approval), sistem otomatis mengirim WhatsApp:
```
"Pinjaman {loan_number} Anda telah disetujui. Jumlah: Rp 10.000.000. Silakan ambil di kantor koperasi."
```

### 2. Loan Due Reminder (Pengingat Angsuran)
**File:** `cron/loan_due_reminders.php`

Berjalan via cron job harian, mengirim reminder X hari sebelum jatuh tempo:
```
"Pengingat: Angsuran pinjaman P-2024-001 jatuh tempo 15/10/2024. Total: Rp 500.000. Mohon segera dibayar. Terima kasih."
```

### 3. Settings UI
**File:** `pages/settings/index.php`

Admin/Super Admin dapat mengonfigurasi:
- Enable/disable WhatsApp
- API URL
- API Key
- Validasi: URL dan Key wajib diisi jika WhatsApp diaktifkan

## Database Schema

### Tabel `whatsapp_logs`
```sql
CREATE TABLE `whatsapp_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `reference_type` enum('loan_payment','loan_approval','savings_deposit','general'),
  `reference_id` int(11) DEFAULT NULL,
  `status` enum('sent','failed','queued') NOT NULL,
  `response` text COMMENT 'API response',
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `phone` (`phone`),
  KEY `reference` (`reference_type`, `reference_id`),
  KEY `sent_at` (`sent_at`)
);
```

### Settings
```sql
INSERT INTO settings (`key`, `value`, `description`) VALUES
('whatsapp_api_url', '', 'WhatsApp Gateway API URL'),
('whatsapp_api_key', '', 'WhatsApp Gateway API Key'),
('whatsapp_enabled', '0', 'Enable WhatsApp notifications');
```

## Troubleshooting

### WhatsApp tidak terkirim
1. Cek `whatsapp_logs` untuk error message:
   ```sql
   SELECT * FROM whatsapp_logs ORDER BY id DESC LIMIT 10;
   ```
2. Verifikasi konfigurasi di Settings
3. Test API gateway dengan curl:
   ```bash
   curl -X POST https://api.fonnte.com/send \
     -H "Authorization: YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"target":"628123456789","message":"test"}'
   ```

### Member tidak menerima notifikasi
1. Cek nomor telepon member (format: 628xxx)
2. Cek notification_preference member (harus 'sms' atau 'both')
3. Pastikan WhatsApp enabled di Settings

### Response 401/403
- API Key salah atau expired
- Update API Key di Settings

### Response timeout
- URL gateway salah
- Network issue dari server
- Cek koneksi internet server

## Files Modified/Created

### New Files
- `includes/whatsapp_helpers.php` - Core WhatsApp functions
- `tests/whatsapp_test.php` - Test script
- `docs/WHATSAPP_INTEGRATION.md` - This documentation

### Modified Files
- `includes/notification_helpers.php` - Integrated WhatsApp into notify_member()
- `pages/settings/index.php` - Added WhatsApp configuration UI
- `pages/loans/process.php` - Send WhatsApp on loan approval
- `cron/loan_due_reminders.php` - Send WhatsApp reminders

## API Reference

### `send_whatsapp($phone, $message, $reference_type, $reference_id)`
Kirim WhatsApp message via configured gateway.

**Parameters:**
- `$phone` (string): Phone number in format 628xxx
- `$message` (string): Message content
- `$reference_type` (string): loan_payment, loan_approval, savings_deposit, general
- `$reference_id` (int|null): Related record ID

**Returns:** `bool` - Success status

### `notify_member_whatsapp($member_id, $message, $reference_type, $reference_id)`
Kirim WhatsApp ke member berdasarkan preferensi.

**Parameters:**
- `$member_id` (int): Member ID
- `$message` (string): Message content
- `$reference_type` (string): Reference type
- `$reference_id` (int|null): Reference ID

**Returns:** `bool` - Success status

## Security Notes
- API Key disimpan di database (plain text)
- Pertimbangkan enkripsi untuk production
- Batasi akses Settings hanya untuk Super Admin
- Log response disimpan untuk audit trail

## Future Enhancements
- [ ] Template message management
- [ ] Bulk WhatsApp sending
- [ ] Media/image attachment support
- [ ] WhatsApp Business API integration
- [ ] Encrypt API key di database
- [ ] Rate limiting per gateway
- [ ] Queue system untuk pengiriman massal
