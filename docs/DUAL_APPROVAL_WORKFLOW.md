# Dual Approval Workflow - Documentation

## Overview

Sistem dual approval workflow memungkinkan pinjaman tertentu memerlukan 2 tingkat persetujuan:
- **Level 1**: Admin (permission: `loans.approve`)
- **Level 2**: Super Admin (permission: `loans.approve_final`)

Konfigurasi dilakukan per produk pinjaman, sehingga beberapa produk bisa single approval dan beberapa lainnya dual approval.

## Database Schema

### Table: `loan_approvals`
Mencatat setiap persetujuan yang diberikan:
```sql
CREATE TABLE `loan_approvals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `approver_level` int(11) NOT NULL COMMENT '1=first approval, 2=final approval',
  `approver_id` int(11) NOT NULL,
  `status` enum('approved','rejected') NOT NULL,
  `notes` text,
  `approved_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  KEY `approver_id` (`approver_id`)
);
```

### Column: `loan_products.approval_levels`
```sql
ALTER TABLE `loan_products`
  ADD COLUMN `approval_levels` int(11) NOT NULL DEFAULT 1 
  COMMENT '1=single, 2=dual approval';
```
- `1` = Single approval (langsung disetujui)
- `2` = Dual approval (butuh 2 persetujuan)

### Column: `loans.current_approval_level`
```sql
ALTER TABLE `loans`
  ADD COLUMN `current_approval_level` int(11) NOT NULL DEFAULT 0 
  COMMENT '0=pending, 1=first approved, 2=final approved';
```
- `0` = Belum ada approval
- `1` = Sudah approval level 1 (Admin)
- `2` = Sudah approval level 2 (Super Admin) - Final

## Permission Model

### Permission: `loans.approve`
- Role: Admin, Super Admin
- Fungsi: Approval Level 1 atau single approval

### Permission: `loans.approve_final`
- Role: Super Admin only
- Fungsi: Approval Level 2 (final approval)

## Workflow Logic

### Single Approval (approval_levels = 1)
1. User mengajukan pinjaman → status = `pending`, current_approval_level = 0
2. Admin/Super Admin approve → status = `approved`, current_approval_level = 1
3. Record disimpan ke `loan_approvals` dengan approver_level = 1
4. Dana bisa dicairkan

### Dual Approval (approval_levels = 2)
1. User mengajukan pinjaman → status = `pending`, current_approval_level = 0
2. **Admin approve (Level 1)**:
   - status tetap `pending`
   - current_approval_level = 1
   - Record disimpan ke `loan_approvals` dengan approver_level = 1
3. **Super Admin approve (Level 2)**:
   - status = `approved`
   - current_approval_level = 2
   - Record disimpan ke `loan_approvals` dengan approver_level = 2
4. Dana bisa dicairkan

## Files Modified

### 1. `/pages/loan-products/form.php`
**Added**: Field dropdown untuk konfigurasi approval levels
```php
<select id="approval_levels" name="approval_levels" required>
    <option value="1">Single Approval (1 Level)</option>
    <option value="2">Dual Approval (2 Level)</option>
</select>
```

### 2. `/pages/loans/process.php`
**Modified**: Action `approve` sekarang membaca approval_levels dari produk
- Single approval: langsung set status='approved'
- Dual approval:
  - Level 0→1: update current_approval_level=1, status tetap pending
  - Level 1→2: update status='approved', current_approval_level=2
- Setiap approval dicatat ke `loan_approvals` table
- Permission check: `loans.approve` untuk L1, `loans.approve_final` untuk L2

### 3. `/pages/loans/detail.php`
**Added**: 
- Approval history section (query dari `loan_approvals`)
- Conditional approval buttons (sesuai level dan permission)
- Status workflow display (Pending L1, Pending L2, Fully Approved)

**Query approval history**:
```php
SELECT la.*, u.full_name AS approver_name 
FROM loan_approvals la 
JOIN users u ON la.approver_id = u.id 
WHERE la.loan_id = ? 
ORDER BY la.approver_level ASC
```

### 4. `/pages/loans/index.php`
**Modified**: Status badge sekarang menunjukkan level approval
- `Pending L1` = Menunggu approval level 1
- `Pending L2` = Menunggu approval level 2 (sudah L1)
- `Disetujui` = Fully approved
- `Aktif`, `Lunas`, `Ditolak` = Status lainnya

## Testing Checklist

### Setup
- [ ] Run `migration_phase2_compliance.sql` pada database
- [ ] Verify schema dengan `verify_phase2_approval.sql`
- [ ] Pastikan permission `loans.approve_final` ada di role Super Admin

### Test Cases

#### Test 1: Single Approval Product
1. Create loan product dengan approval_levels = 1
2. Ajukan pinjaman dengan produk tersebut
3. Login sebagai Admin → approve
4. Verify: status = 'approved', current_approval_level = 1
5. Verify: 1 record di loan_approvals dengan approver_level = 1
6. Verify: bisa dicairkan

#### Test 2: Dual Approval Product - Happy Path
1. Create loan product dengan approval_levels = 2
2. Ajukan pinjaman dengan produk tersebut
3. Login sebagai Admin → approve (Level 1)
   - Verify: status = 'pending', current_approval_level = 1
   - Verify: 1 record di loan_approvals (level 1)
   - Verify: belum bisa dicairkan
4. Login sebagai Super Admin → approve (Level 2)
   - Verify: status = 'approved', current_approval_level = 2
   - Verify: 2 records di loan_approvals (level 1 dan 2)
   - Verify: bisa dicairkan

#### Test 3: Permission Check
1. Login sebagai Admin
2. Buka pinjaman dual approval yang sudah L1
3. Verify: tidak ada button approve (karena butuh loans.approve_final)
4. Login sebagai Super Admin
5. Verify: ada button "Setujui Final (Level 2)"

#### Test 4: Approval History
1. Approve pinjaman dual approval sampai selesai
2. Buka detail pinjaman
3. Verify: section "Riwayat Persetujuan" tampil
4. Verify: 2 rows (level 1 dan level 2)
5. Verify: approver name, timestamp, notes tampil

#### Test 5: Backward Compatibility
1. Verify existing loans (sebelum migration) masih bisa diproses
2. Verify loan products tanpa approval_levels (NULL/default) dianggap single approval

## UI Elements

### Product Form
- Dropdown: "Tingkat Persetujuan"
  - Single Approval (1 Level)
  - Dual Approval (2 Level)
- Default: Single Approval

### Loan Detail - Approval Section
**Pending L1 (Admin)**:
```
┌─────────────────────────────────────────────┐
│ Persetujuan Level 1 (Admin)                │
│                                             │
│ Catatan Persetujuan: [textarea]            │
│ [✓ Setujui Pinjaman] [✕ Tolak Pinjaman]  │
└─────────────────────────────────────────────┘
```

**Pending L2 (Super Admin)**:
```
┌─────────────────────────────────────────────┐
│ Persetujuan Level 2 (Super Admin - Final)  │
│ ℹ Pinjaman ini telah disetujui Level 1     │
│                                             │
│ Catatan Persetujuan: [textarea]            │
│ [✓ Setujui Final (Level 2)] [✕ Tolak]     │
└─────────────────────────────────────────────┘
```

### Approval History Table
| Level    | Approver     | Status      | Waktu           | Catatan |
|----------|--------------|-------------|-----------------|---------|
| Level 1  | Admin Name   | ✓ Disetujui | 10/10/26 10:00 | OK      |
| Level 2  | Super Admin  | ✓ Disetujui | 10/10/26 14:30 | Final   |

### Loan Index Status Badges
- 🟡 `Pending L1` - Menunggu approval level 1
- 🟡 `Pending L2` - Menunggu approval level 2
- 🟢 `Disetujui` - Fully approved
- 🟢 `Aktif` - Sedang berjalan
- 🔵 `Lunas` - Selesai
- 🔴 `Ditolak` - Rejected

## Business Rules

1. **Collateral Requirement**: Pinjaman ≥ Rp 5.000.000 wajib punya agunan sebelum bisa diapprove (existing rule, tetap berlaku)

2. **Sequential Approval**: Dual approval harus berurutan (L1 dulu, baru L2). Tidak bisa skip level.

3. **Permission-based**: 
   - Admin tidak bisa approve L2
   - Super Admin bisa approve L1 atau L2
   - Tapi idealnya L1 untuk Admin, L2 untuk Super Admin

4. **Rejection**: Rejection di level mana pun langsung mengubah status ke 'rejected' (tidak peduli approval_levels)

5. **Product Config**: approval_levels bisa diubah kapan saja, tapi hanya berlaku untuk pinjaman BARU. Pinjaman existing tetap pakai aturan lama.

## Migration Notes

Migration SQL sudah include:
- CREATE TABLE loan_approvals
- ALTER loan_products ADD approval_levels
- ALTER loans ADD current_approval_level
- INSERT permission loans.approve_final
- INSERT role_permissions untuk Super Admin

Run: `database/migration_phase2_compliance.sql`

## Future Enhancements

- [ ] Email notification saat butuh approval L2
- [ ] Audit log untuk setiap perubahan approval
- [ ] Bulk approval untuk multiple loans
- [ ] Configurable approval rules (by amount, by tenor, etc)
- [ ] 3-level approval untuk pinjaman besar
- [ ] Auto-escalation jika approval L1 stuck > N hari
