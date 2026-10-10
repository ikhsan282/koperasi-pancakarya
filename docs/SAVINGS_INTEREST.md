# Savings Interest Auto-Calculation

Fitur untuk menghitung dan mem-posting bunga simpanan secara otomatis atau manual.

## Komponen

### 1. Helper Functions (`includes/savings_interest.php`)

**`calculate_monthly_interest($account_id, $period_month, $period_year)`**
- Menghitung bunga bulanan untuk rekening simpanan
- Formula: `interest = balance * (annual_rate / 100) / 12`
- Return: array dengan `balance_base`, `interest_rate`, `interest_amount`, `account_number`, `member_name`, `type_name`
- Return `null` jika rekening tidak aktif atau rate = 0

**`post_interest_for_account($account_id, $period_month, $period_year, $posted_by)`**
- Posting bunga untuk satu rekening
- Membuat record di `savings_interest_history`
- Menambah transaksi ke `savings_transactions` (type=deposit)
- Update `savings_accounts.balance` dan `last_interest_date`
- Harus dipanggil dalam transaction

**`get_accounts_for_interest_posting($period_month, $period_year)`**
- Mendapatkan semua rekening yang eligible untuk posting bunga
- Return array dengan info rekening dan calculated interest
- Include flag `already_posted` untuk deteksi duplikasi

### 2. Manual Posting UI (`pages/savings/interest_posting.php`)

Fitur:
- Form pilih bulan/tahun (default: bulan lalu)
- Preview: tabel semua rekening dengan calculated interest
- Ringkasan: jumlah rekening, total bunga
- Konfirmasi: posting bulk untuk semua rekening

Permission: `savings.post_interest`

Workflow:
1. User pilih periode → klik "Preview"
2. Sistem tampilkan tabel preview dengan calculated interest
3. User review → klik "Konfirmasi & Posting Bunga"
4. Sistem posting dalam satu transaction
5. Redirect ke history dengan flash success

### 3. History View (`pages/savings/interest_history.php`)

Fitur:
- List semua posting bunga yang sudah dilakukan
- Filter: bulan, tahun, anggota
- Total interest per periode
- Link ke detail rekening

Permission: `savings.view`

### 4. Cron Script (`cron/monthly_interest.php`)

Fitur:
- Auto-posting bunga setiap bulan
- Bisa dijalankan manual: `php cron/monthly_interest.php [month] [year]`
- Default: posting bulan lalu
- Check setting `auto_interest_enabled`

Setup crontab (jalankan tanggal 1 setiap bulan, jam 01:00):
```bash
0 1 1 * * cd /opt/data/projects/koperasi-pancakarya && php cron/monthly_interest.php
```

### 5. Enhanced Detail View (`pages/savings/detail.php`)

Tambahan:
- Show `last_interest_date` di info rekening
- Tabel riwayat bunga (12 bulan terakhir)
- Link ke full history

## Database

### Tables
- `savings_interest_history`: Record posting bunga
- `savings_accounts.last_interest_date`: Tanggal terakhir posting bunga

### Settings
- `auto_interest_enabled`: 0=disabled, 1=enabled (cron)
- `interest_posting_day`: Tanggal posting setiap bulan (1-28)

## Formula Perhitungan

```
monthly_interest = balance * (annual_rate / 100) / 12
```

Contoh:
- Balance: Rp 1.000.000
- Annual Rate: 2%
- Monthly Interest: 1.000.000 * (2 / 100) / 12 = Rp 1.666,67

## Migration

Sudah ada di `database/migration_phase2_compliance.sql`:
- Table `savings_interest_history`
- Column `savings_accounts.last_interest_date`
- Settings entries
- Permission `savings.post_interest`

## Testing

Run test script:
```bash
php tests/savings_interest_test.php
```

Manual testing:
1. Login sebagai user dengan permission `savings.post_interest`
2. Buka menu "Simpanan" → "Posting Bunga"
3. Pilih bulan lalu, klik "Preview"
4. Review tabel, klik "Konfirmasi & Posting Bunga"
5. Cek "Riwayat Bunga" untuk verifikasi
6. Buka detail rekening, lihat riwayat bunga

## Workflow Bulanan

### Manual (via UI)
1. Tanggal 1 bulan baru
2. Bendahara/Admin login
3. Buka "Posting Bunga"
4. Pilih bulan lalu
5. Preview & Konfirmasi
6. Selesai

### Auto (via Cron)
1. Set `auto_interest_enabled = 1` di settings
2. Setup crontab (lihat di atas)
3. Cron jalan otomatis setiap tanggal 1
4. Log output ke file jika perlu

## Permissions

- `savings.view`: Lihat simpanan & riwayat bunga
- `savings.post_interest`: Posting bunga manual (Bendahara, Super Admin)

## Notes

- Hanya rekening `status='active'` yang diproses
- Hanya savings_types dengan `interest_rate > 0`
- Duplikasi dicegah: check existing record di `savings_interest_history`
- Semua posting dalam transaction (atomic)
- Transaction type untuk bunga: `deposit` dengan description "Bunga [bulan] [tahun]"
