# Implementasi Biaya Admin & Denda Keterlambatan

## Status: ✅ SELESAI

Semua fitur biaya admin dan denda keterlambatan telah diimplementasikan dan siap digunakan.

---

## Cara Install

### 1. Jalankan Migrasi Database

Pilih salah satu metode:

**Via MySQL CLI** (paling reliable):
```bash
mysql -u root -proot koperasi_pancakarya < database/migration_phase1_critical.sql
```

**Via Bash Script**:
```bash
bash migrate.sh
```

**Via phpMyAdmin**:
- Buka phpMyAdmin
- Pilih database `koperasi_pancakarya`
- Tab "Import"
- Upload file `database/migration_phase1_critical.sql`
- Execute

### 2. Verifikasi

```bash
php test_penalty_calculation.php
```

Output harus menunjukkan semua test PASS.

---

## Fitur yang Diimplementasikan

### 1️⃣ Biaya Admin Pinjaman
- **Default**: 2% dari pokok pinjaman
- **Editable**: Bisa diubah per-pinjaman saat pengajuan
- **Tampil di**: Simulasi, detail pinjaman, laporan

**Contoh**:
- Pinjaman: Rp10.000.000
- Admin Fee: 2%
- Biaya: **Rp200.000**

### 2️⃣ Denda Keterlambatan
- **Formula**: `denda = hari_terlambat × (rate/100) × angsuran`
- **Default rate**: 0.5% per hari
- **Auto-calculate**: Saat mencatat pembayaran
- **Akumulatif**: Jika bayar sebagian, denda tetap tercatat

**Contoh**:
- Angsuran: Rp1.000.000
- Terlambat: 10 hari
- Rate: 0.5%/hari
- Denda: **Rp50.000**

### 3️⃣ Pengaturan Sistem
- **URL**: `/pages/settings/index.php`
- **Akses**: Super Admin only
- **Setting**:
  - `penalty_rate_per_day`: Denda per hari (%)
  - `default_admin_fee_pct`: Biaya admin default (%)
  - `min_collateral_loan_amount`: Min. agunan (Rp)

### 4️⃣ Tampilan UI
- Menu "⚙ Pengaturan" di sidebar (Super Admin)
- Biaya admin di detail pinjaman
- Kolom "Denda" di jadwal cicilan
- Denda otomatis di form pembayaran
- Total denda di footer tabel

---

## File Modified

```
includes/
  loan_tools.php          → +39 lines (calculate_penalty, get_setting)
  sidebar.php             → +1 line (menu pengaturan)

pages/loans/
  form.php                → admin fee field, simulasi, save
  payment.php             → auto-calculate & save penalty
  detail.php              → display admin fee & penalty

pages/settings/
  index.php               → NEW (settings management)

database/
  migration_phase1_critical.sql  → ALTER loans, loan_payments, CREATE settings

docs/
  README_BIAYA_DENDA.md   → Installation guide (this file)
  IMPLEMENTATION_REPORT.md → Technical details
  test_penalty_calculation.php → Test suite
```

---

## Testing Manual

### Test Biaya Admin
1. Login → Pinjaman → Ajukan Pinjaman Baru
2. Isi form → Perhatikan field "Biaya Admin (%)" (default 2%)
3. Ubah jadi 3% → Lihat simulasi auto-update
4. Submit → Detail pinjaman → Biaya admin tercantum ✓

### Test Denda
1. Cairkan pinjaman (status active)
2. Set jadwal angsuran jatuh tempo kemarin (manual edit DB)
3. Bayar hari ini → Form otomatis hitung denda
4. Submit → Detail → Denda tercatat di kolom "Denda" ✓

### Test Settings
1. Login sebagai Super Admin
2. Sidebar → "⚙ Pengaturan"
3. Ubah "Denda per hari" jadi 1%
4. Save → Reload → Nilai tersimpan ✓
5. Test dengan role lain → Menu tidak muncul ✓

---

## Formula Reference

**Biaya Admin**:
```
admin_fee_amount = principal × (admin_fee_pct / 100)
```

**Denda**:
```
penalty = days_overdue × (penalty_rate / 100) × amount_due
```

**Total Pembayaran**:
```
total = amount_due + penalty_amount
```

---

## Troubleshooting

### Migration Error: "Duplicate column"
→ Migration sudah pernah dijalankan. Check:
```sql
SHOW COLUMNS FROM loans LIKE 'admin_fee%';
```

### Menu Pengaturan Tidak Muncul
→ User harus punya permission `settings.manage`:
```sql
SELECT * FROM role_permissions rp
JOIN permissions p ON rp.permission_id = p.id
WHERE p.name = 'settings.manage';
```

### Denda Tidak Terhitung
→ Check:
1. Tabel `settings` ada record `penalty_rate_per_day`
2. `payment_date > due_date`
3. Function `calculate_penalty()` ada di `loan_tools.php`

---

## Support

**Dokumentasi lengkap**: `IMPLEMENTATION_REPORT.md`  
**Test suite**: `php test_penalty_calculation.php`  
**Migration file**: `database/migration_phase1_critical.sql`

Implementasi ini production-ready dan telah ditest.
