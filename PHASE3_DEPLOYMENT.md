# Phase 3 Deployment Guide

**Version:** 3.0.0  
**Release Date:** 2026-10-10  
**Status:** Ready for Production

## 🎯 Features Delivered

### 1. Laporan KAP (Kualitas Aktiva Produktif)
- **File:** `pages/reports/kap_report.php`
- **Fitur:** Klasifikasi kolektibilitas (Lancar, Kurang Lancar, Diragukan, Macet)
- **Metrics:** NPL ratio calculation, days overdue tracking
- **Export:** CSV, XLSX, PDF
- **Permission:** `reports.kap`

### 2. Restrukturisasi Pinjaman
- **Files:** `pages/loans/restructure*.php` (4 files)
- **Tipe:** Reschedule, Extend Tenor, Reduce Rate
- **Workflow:** Request → Admin Approval → Schedule Regeneration
- **Permission:** `loans.restructure`

### 3. Write-off Kredit Macet
- **Files:** `pages/loans/writeoff*.php` (5 files), `pages/reports/writeoff_report.php`
- **Fitur:** Full/partial writeoff, Super Admin approval
- **Integration:** Cash/bank transaction posting
- **Permission:** `loans.writeoff`

### 4. SHU Distribution UI
- **Files:** `pages/shu/distribute.php`, updated `pages/shu/*.php`, `pages/portal.php`
- **Fitur:** Post SHU to member savings, member portal history
- **Tracking:** Distribution status, transaction linking
- **Permission:** `shu.distribute`

### 5. WhatsApp Notifikasi
- **Files:** `includes/whatsapp_helpers.php`, `tests/whatsapp_test.php`
- **Integration:** Loan approval, due reminders
- **Gateways:** Fonnte, Wablas compatible
- **Fallback:** SMS if WhatsApp fails

### 6. Backup/Restore Database
- **Files:** `pages/backup/*.php`, `includes/backup_helpers.php`, `cron/daily_backup.php`
- **Features:** PHP-native backup (no mysqldump), gzip compression, restore with preview
- **Auto-backup:** Cron job with retention policy
- **Permissions:** `backup.create`, `backup.restore`, `backup.delete`

---

## 📦 Migration Required

### Step 1: Run Phase 3 Main Migration
**File:** `database/migration_phase3_enhancements.sql`

```bash
mysql -u root -p koperasi_pancakarya < database/migration_phase3_enhancements.sql
```

**Creates:**
- `loan_restructures` table
- `loan_writeoffs` table
- `whatsapp_logs` table
- Permissions: `loans.restructure`, `loans.writeoff`, `reports.kap`, `shu.distribute`
- Loan fields: `restructure_status`, `writeoff_status`, `writeoff_amount`

### Step 2: Run SHU Distribution Migration
**File:** `database/migration_shu_distribution.sql`

```bash
mysql -u root -p koperasi_pancakarya < database/migration_shu_distribution.sql
```

**Adds:**
- `shu_periods.distributed_at`, `distributed_by` columns
- `shu_distributions.distribution_transaction_id` column

### Step 3: Run Backup Permissions Migration
**File:** `database/add_backup_permissions.sql`

```bash
mysql -u root -p koperasi_pancakarya < database/add_backup_permissions.sql
```

**Adds:**
- `backup.create`, `backup.restore`, `backup.delete` permissions
- Assigns to Admin and Super Admin roles

---

## ⚙️ Configuration Steps

### 1. WhatsApp Gateway Setup
1. Login as Super Admin
2. Go to **Pengaturan Sistem**
3. Scroll to **WhatsApp Configuration**
4. Fill:
   - Enable WhatsApp: ✓
   - API URL: `https://api.fonnte.com/send` (or your gateway)
   - API Key: `<your-key>`
5. Test: `php tests/whatsapp_test.php`

### 2. Backup Directory Setup
```bash
mkdir -p /opt/data/backups
chmod 755 /opt/data/backups
```

In **Pengaturan Sistem → Backup & Restore**:
- Backup Path: `/opt/data/backups`
- Retention Days: `30`
- Auto Backup Enabled: ✓

### 3. Setup Auto Backup Cron (Optional)
```cron
0 2 * * * php /opt/data/projects/koperasi-pancakarya/cron/daily_backup.php
```

### 4. KAP Thresholds (Optional)
In `settings` table, add:
```sql
INSERT INTO settings (setting_key, setting_value) VALUES
('kap_kurang_lancar_days', '90'),
('kap_diragukan_days', '120'),
('kap_macet_days', '180');
```

---

## ✅ Testing Checklist

### Laporan KAP
- [ ] Open `Laporan → KAP Report`
- [ ] Verify kolektibilitas classification matches loan overdue days
- [ ] Test filter by status dropdown
- [ ] Export CSV, XLSX, PDF
- [ ] Verify NPL ratio calculation

### Restrukturisasi Pinjaman
- [ ] Open loan detail → Click "Restrukturisasi"
- [ ] Submit reschedule request
- [ ] Login as Admin → Approve restructure
- [ ] Verify payment schedule regenerated
- [ ] Check restructure history in loan detail

### Write-off Kredit Macet
- [ ] Open overdue loan → Click "Hapus Buku"
- [ ] Submit full writeoff request
- [ ] Login as Super Admin → Approve writeoff
- [ ] Verify loan status changed to `completed`
- [ ] Check writeoff report: `Laporan → Write-off`

### SHU Distribution
- [ ] Open `SHU → Breakdown` for finalized period
- [ ] Click "Posting ke Simpanan"
- [ ] Select savings type (e.g., "Simpanan Pokok")
- [ ] Confirm distribution
- [ ] Login as member → Check portal SHU history

### WhatsApp Notifikasi
- [ ] Ensure member has phone number in profile
- [ ] Approve a loan → Check WhatsApp sent
- [ ] Wait for due reminder (or run `cron/loan_due_reminders.php` manually)
- [ ] Check `whatsapp_logs` table for delivery status

### Backup/Restore
- [ ] Go to **Backup** menu
- [ ] Click "Buat Backup Baru"
- [ ] Download backup file
- [ ] Upload backup to test restore (use test database!)
- [ ] Verify preview shows schema
- [ ] Type "RESTORE" and confirm

---

## 🚀 Deployment to cPanel

### Upload Files
```bash
# From local to cPanel via SFTP/FTP
rsync -avz --exclude 'config/config.php' \
  /opt/data/projects/koperasi-pancakarya/ \
  user@host:/home/user/public_html/koperasi/
```

### Run Migrations via phpMyAdmin
1. Login to cPanel → phpMyAdmin
2. Select `koperasi_pancakarya` database
3. Go to **SQL** tab
4. Paste content of each migration file (in order):
   - `migration_phase3_enhancements.sql`
   - `migration_shu_distribution.sql`
   - `add_backup_permissions.sql`
5. Execute each

### Verify Permissions
```sql
SELECT p.name, r.name as role
FROM permissions p
JOIN role_permissions rp ON p.id = rp.permission_id
JOIN roles r ON rp.role_id = r.id
WHERE p.name IN ('reports.kap', 'loans.restructure', 'loans.writeoff', 'shu.distribute', 'backup.create', 'backup.restore')
ORDER BY p.name, r.name;
```

### Setup Cron Job (cPanel)
1. Go to **Cron Jobs** in cPanel
2. Add new cron:
   - **Minute:** 0
   - **Hour:** 2
   - **Command:** `php /home/user/public_html/koperasi/cron/daily_backup.php`

---

## 📊 Files Changed Summary

**42 files changed:**
- **New files:** 37 (4,710 lines added)
- **Modified files:** 5
- **Documentation:** 7 new docs

**Key Additions:**
- `pages/reports/kap_report.php` (338 lines)
- `pages/loans/restructure*.php` (4 files, 551 lines)
- `pages/loans/writeoff*.php` (5 files, 655 lines)
- `pages/shu/distribute.php` (135 lines)
- `includes/whatsapp_helpers.php` (118 lines)
- `includes/backup_helpers.php` (311 lines)
- `pages/backup/*.php` (2 files, 436 lines)

---

## 🔗 Documentation

- **Backup System:** `README_BACKUP.md`, `INSTALL_BACKUP.md`
- **Write-off Module:** `WRITEOFF_MODULE.md`
- **Restructure Module:** `docs/RESTRUCTURE_MODULE.md`
- **SHU Distribution:** `docs/SHU_IMPLEMENTATION.md`
- **WhatsApp Integration:** `docs/WHATSAPP_INTEGRATION.md`
- **Deployment Checklist:** `DEPLOYMENT_CHECKLIST.md`

---

## 🎉 Phase 3 Complete

**Git Tag:** `v3.0.0`  
**Commit:** `b6cefc2` - merge: Phase 3 Advanced Features Complete  
**GitHub:** https://github.com/ikhsan282/koperasi-pancakarya/releases/tag/v3.0.0

**Team Credits:**
- Task 1 (KAP Report): Subagent 1 ✅
- Task 2 (Restructure): Subagent 2 ✅
- Task 3 (Write-off): Subagent 3 ✅
- Task 4 (SHU UI): Subagent 4 ✅
- Task 5 (WhatsApp): Subagent 5 ✅
- Task 6 (Backup): Subagent 6 ✅

**Next Steps:**
1. Review this deployment guide
2. Schedule deployment window
3. Run migrations on production
4. Test each feature
5. Configure WhatsApp gateway
6. Setup backup cron job
