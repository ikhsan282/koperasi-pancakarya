# Fitur Biaya Admin & Denda Keterlambatan

## Instalasi

### 1. Jalankan Migrasi Database

```bash
php run_migration.php
```

Script ini akan:
- Menambah kolom `admin_fee_pct` dan `admin_fee_amount` ke tabel `loans`
- Menambah kolom `penalty_amount` dan `days_overdue` ke tabel `loan_payments`
- Membuat tabel `settings` untuk konfigurasi sistem
- Membuat tabel `cash_bank_accounts` dan `cash_bank_transactions` (fitur kas/bank)
- Membuat tabel `loan_collaterals` (fitur agunan)
- Menambah permission untuk modul baru

### 2. Verifikasi

Setelah migrasi, cek:
- Login sebagai Super Admin
- Buka menu "⚙ Pengaturan" di sidebar
- Pastikan bisa mengakses halaman pengaturan

## Fitur yang Diimplementasikan

### 1. Biaya Admin Pinjaman
- **Lokasi**: Form Pengajuan Pinjaman
- **Default**: 2% dari pokok pinjaman (bisa diubah di Pengaturan)
- **Perhitungan**: `admin_fee_amount = amount × (admin_fee_pct / 100)`
- **Tampil di**:
  - Simulasi angsuran (form pengajuan)
  - Detail pinjaman
  - Laporan

### 2. Denda Keterlambatan
- **Formula**: `penalty = days_overdue × (rate_per_day / 100) × amount_due`
- **Default rate**: 0.5% per hari (bisa diubah di Pengaturan)
- **Auto-calculate**: Saat pembayaran dicatat
- **Contoh**: 
  - Angsuran: Rp1.000.000
  - Terlambat: 10 hari
  - Rate: 0.5% per hari
  - Denda: 10 × 0.5% × Rp1.000.000 = **Rp50.000**

### 3. Halaman Pengaturan Sistem
- **Akses**: Super Admin only (`settings.manage` permission)
- **URL**: `/pages/settings/index.php`
- **Konfigurasi**:
  - `penalty_rate_per_day`: Denda per hari (%)
  - `default_admin_fee_pct`: Biaya admin default (%)
  - `min_collateral_loan_amount`: Min. pinjaman wajib agunan (Rp)

## File yang Dimodifikasi

### Backend
- `includes/loan_tools.php`: Fungsi `calculate_penalty()` dan `get_setting()`
- `pages/loans/form.php`: Field biaya admin, simpan ke database
- `pages/loans/payment.php`: Hitung & catat denda otomatis
- `pages/loans/detail.php`: Tampilkan biaya admin & denda

### Frontend
- Form simulasi: Tampilkan biaya admin di simulasi
- Payment form: Tampilkan denda jika terlambat
- Detail jadwal: Kolom denda

### Baru
- `pages/settings/index.php`: Halaman pengaturan sistem
- `run_migration.php`: Script migrasi database
- `database/migration_phase1_critical.sql`: SQL migrasi

## Testing

### Test Biaya Admin
1. Buat pinjaman baru
2. Lihat field "Biaya Admin (%)" → default 2%
3. Ubah jadi 3% → simulasi otomatis update
4. Submit → cek detail pinjaman, biaya admin tercatat

### Test Denda
1. Cairkan pinjaman (status active)
2. Tunggu jadwal angsuran pertama lewat jatuh tempo
3. Catat pembayaran **setelah** jatuh tempo
4. Sistem auto-calculate denda berdasarkan hari keterlambatan
5. Cek detail → denda tercatat di kolom "Denda"

### Test Pengaturan
1. Login sebagai Super Admin
2. Buka menu "⚙ Pengaturan"
3. Ubah rate denda jadi 1% → Save
4. Buat pembayaran terlambat → denda pakai rate baru

## Catatan Teknis

### Perhitungan Denda
```php
function calculate_penalty(string $due_date, string $payment_date, float $amount_due): array
{
    $days_overdue = max(0, days_between($due_date, $payment_date));
    if ($days_overdue === 0) return ['penalty_amount' => 0.0, 'days_overdue' => 0];
    
    $penalty_rate = (float) get_setting('penalty_rate_per_day', '0.5');
    $penalty_amount = round($days_overdue * ($penalty_rate / 100) * $amount_due, 2);
    
    return ['penalty_amount' => $penalty_amount, 'days_overdue' => $days_overdue];
}
```

### Database Schema

**loans** (tambahan):
- `admin_fee_pct` DECIMAL(5,2) - Persen biaya admin
- `admin_fee_amount` DECIMAL(15,2) - Nominal biaya admin

**loan_payments** (tambahan):
- `penalty_amount` DECIMAL(15,2) - Nominal denda
- `days_overdue` INT - Jumlah hari terlambat

**settings** (baru):
- `key` VARCHAR(100) PRIMARY KEY
- `value` TEXT
- `description` VARCHAR(255)

## Troubleshooting

### Migration Gagal
Jalankan manual via phpMyAdmin atau mysql CLI:
```bash
mysql -u root -p koperasi_pancakarya < database/migration_phase1_critical.sql
```

### Settings Menu Tidak Muncul
Pastikan user punya permission `settings.manage`:
```sql
-- Cek permission Super Admin (role_id=1)
SELECT * FROM role_permissions WHERE role_id = 1;
```

### Denda Tidak Terhitung
1. Cek tabel `settings` ada record `penalty_rate_per_day`
2. Cek payment_date > due_date
3. Cek function `calculate_penalty()` dipanggil di payment.php
