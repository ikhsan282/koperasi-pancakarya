# Testing Guide: Dual Approval Workflow

## Pre-requisites

1. Database migration applied: `migration_phase2_compliance.sql`
2. Permission `loans.approve_final` assigned to Super Admin role
3. At least 2 test users:
   - Admin role (with `loans.approve`)
   - Super Admin role (with `loans.approve` + `loans.approve_final`)

## Test Scenario 1: Single Approval Product

### Setup
```sql
INSERT INTO loan_products (name, interest_rate, max_tenor_months, min_amount, max_amount, approval_levels, is_active)
VALUES ('Pinjaman Konsumtif', 2.0, 12, 1000000, 5000000, 1, 1);
```

### Steps
1. Login as Admin or Super Admin
2. Navigate to: Loan Products → Create/Edit
3. Set approval_levels = 1 (Single Approval)
4. Save product
5. Create new loan application using this product
6. Verify loan status = 'pending', current_approval_level = 0
7. Navigate to loan detail page
8. Click "✓ Setujui Pinjaman"
9. Add optional notes, submit

### Expected Results
- Loan status changes to 'approved'
- current_approval_level = 1
- 1 record in loan_approvals table:
  ```sql
  SELECT * FROM loan_approvals WHERE loan_id = [loan_id];
  -- approver_level = 1, status = 'approved'
  ```
- Disbursement section appears (blue box)
- "Riwayat Persetujuan" section shows 1 approval

---

## Test Scenario 2: Dual Approval - Full Workflow

### Setup
```sql
INSERT INTO loan_products (name, interest_rate, max_tenor_months, min_amount, max_amount, approval_levels, is_active)
VALUES ('Pinjaman Modal Usaha', 1.8, 24, 5000000, 50000000, 2, 1);
```

### Steps - Level 1 Approval

1. Login as Staff/Bendahara (or create loan as any user)
2. Create loan application using dual approval product
3. Verify: status = 'pending', current_approval_level = 0
4. **Logout, Login as Admin**
5. Navigate to Loans → find the pending loan
6. Verify badge shows: "Pending L1"
7. Open loan detail
8. Verify approval section shows: "Persetujuan Level 1 (Admin)"
9. Enter approval notes: "Level 1 approved by Admin"
10. Click "✓ Setujui Pinjaman"

### Expected Results After L1
- Loan status = 'pending' (unchanged)
- current_approval_level = 1
- Flash message: "Pinjaman telah disetujui Level 1. Menunggu persetujuan Level 2 (Super Admin)."
- 1 record in loan_approvals:
  ```sql
  SELECT * FROM loan_approvals WHERE loan_id = [loan_id];
  -- approver_level = 1, status = 'approved', notes = 'Level 1 approved by Admin'
  ```
- Badge in loan list: "Pending L2"
- Disbursement section NOT visible yet

### Steps - Level 2 Approval

11. **Logout, Login as Super Admin**
12. Navigate to Loans → find the loan (badge: "Pending L2")
13. Open loan detail
14. Verify approval section shows: "Persetujuan Level 2 (Super Admin - Final)"
15. Verify info alert: "Pinjaman ini telah disetujui Level 1 (Admin)"
16. Verify "Riwayat Persetujuan" shows 1 approval (Level 1)
17. Enter approval notes: "Final approval by Super Admin"
18. Click "✓ Setujui Final (Level 2)"

### Expected Results After L2
- Loan status = 'approved'
- current_approval_level = 2
- Flash message: "Pinjaman telah disetujui Level 2 (Final). Silakan lanjutkan pencairan dana."
- 2 records in loan_approvals:
  ```sql
  SELECT * FROM loan_approvals WHERE loan_id = [loan_id] ORDER BY approver_level;
  -- Row 1: approver_level = 1, notes = 'Level 1 approved by Admin'
  -- Row 2: approver_level = 2, notes = 'Final approval by Super Admin'
  ```
- Badge in loan list: "Disetujui"
- Disbursement section visible (blue box)
- "Riwayat Persetujuan" shows 2 approvals with timestamps
- Status workflow footer: "✅ Fully Approved (2 Level)"

---

## Test Scenario 3: Permission Enforcement

### Test 3A: Admin Cannot Approve L2

1. Create dual approval product
2. Create loan, approve L1 as Admin
3. **Stay logged in as Admin**
4. Navigate to loan detail
5. Verify: NO approval section visible
6. Verify: "Riwayat Persetujuan" shows only L1 approval
7. Badge: "Pending L2"

**Expected**: Admin cannot see Level 2 approval button because they lack `loans.approve_final` permission.

### Test 3B: Super Admin Can Approve Both Levels

1. Create dual approval product
2. Create loan (status = pending, level = 0)
3. **Login as Super Admin**
4. Navigate to loan detail
5. Verify: Approval section visible for L1
6. Approve L1
7. Refresh/reopen loan detail
8. Verify: Approval section visible for L2
9. Approve L2

**Expected**: Super Admin can approve both levels (has both permissions).

### Test 3C: Cannot Skip Levels

1. Create dual approval product, create loan
2. **Login as Super Admin**
3. Inspect loan: current_approval_level = 0
4. Try to manually trigger L2 approval (via process.php)
5. Expected behavior: process.php checks `if ($current_level === 1)` before allowing L2

**Expected**: System enforces sequential approval (L1 then L2).

---

## Test Scenario 4: Approval History Display

### Steps
1. Complete a dual approval workflow (Scenario 2)
2. Open loan detail page
3. Locate "Riwayat Persetujuan" section

### Verify Table Contents
- **Headers**: Level | Approver | Status | Waktu | Catatan
- **Row 1**: Level 1 | [Admin Name] | ✓ Disetujui | [timestamp] | [notes]
- **Row 2**: Level 2 | [Super Admin Name] | ✓ Disetujui | [timestamp] | [notes]

### Verify Workflow Status Footer
- Text: "✅ Fully Approved (2 Level)"
- Background: light gray (#f7fafc)

---

## Test Scenario 5: Rejection Handling

### Test 5A: Reject at Level 1
1. Create dual approval loan
2. Login as Admin
3. Open loan detail
4. Click "✕ Tolak Pinjaman"
5. Enter rejection notes: "Insufficient documentation"
6. Submit

**Expected**:
- Loan status = 'rejected'
- rejection_notes field populated
- NO record in loan_approvals (or record with status='rejected')
- Cannot proceed to L2

### Test 5B: Reject at Level 2
1. Create dual approval loan
2. Approve L1 as Admin
3. Login as Super Admin
4. Reject at L2 with notes

**Expected**:
- Loan status = 'rejected'
- loan_approvals has 1 approved record (L1) and 1 rejected record (L2)
- Workflow stops

---

## Test Scenario 6: Badge Display in Loan List

### Steps
1. Create loans with various states:
   - Single approval pending
   - Single approval approved
   - Dual approval pending L1
   - Dual approval pending L2
   - Dual approval fully approved
   - Active loan
   - Rejected loan
2. Navigate to Loans index page

### Verify Badges
| Loan State                    | Badge Text    | Badge Color |
|-------------------------------|---------------|-------------|
| Single pending, level=0       | Menunggu      | Yellow      |
| Dual pending, level=0         | Pending L1    | Yellow      |
| Dual pending, level=1         | Pending L2    | Yellow      |
| Approved (any)                | Disetujui     | Green       |
| Active                        | Aktif         | Green       |
| Completed                     | Lunas         | Blue        |
| Rejected                      | Ditolak       | Red         |

---

## Test Scenario 7: Notes Field Functionality

### Steps
1. Create dual approval loan
2. Approve L1 with notes: "Checked credit history"
3. Approve L2 with notes: "Final review completed"
4. Open loan detail → "Riwayat Persetujuan"

### Verify
- L1 approval row shows: "Checked credit history"
- L2 approval row shows: "Final review completed"
- Notes are optional (can be empty)

---

## Test Scenario 8: Existing Loan Products (Backward Compatibility)

### Setup
```sql
-- Simulate old loan product without approval_levels column
UPDATE loan_products SET approval_levels = NULL WHERE id = 1;
```

### Steps
1. Create loan using product with NULL approval_levels
2. Approve as Admin

**Expected**:
- System treats NULL as approval_levels = 1 (default)
- Single approval workflow
- Backward compatible with existing data

---

## Database Verification Queries

### Check approval workflow state
```sql
SELECT 
    l.id,
    l.loan_number,
    l.status,
    l.current_approval_level,
    lp.approval_levels,
    COUNT(la.id) as approval_count
FROM loans l
LEFT JOIN loan_products lp ON l.loan_product_id = lp.id
LEFT JOIN loan_approvals la ON l.id = la.loan_id
WHERE l.id = [loan_id]
GROUP BY l.id;
```

### Check approval history
```sql
SELECT 
    la.approver_level,
    u.full_name as approver,
    la.status,
    la.notes,
    la.approved_at
FROM loan_approvals la
JOIN users u ON la.approver_id = u.id
WHERE la.loan_id = [loan_id]
ORDER BY la.approver_level;
```

### Check permission assignments
```sql
SELECT 
    r.name as role,
    p.name as permission
FROM role_permissions rp
JOIN roles r ON rp.role_id = r.id
JOIN permissions p ON rp.permission_id = p.id
WHERE p.name IN ('loans.approve', 'loans.approve_final')
ORDER BY r.id, p.name;
```

---

## Common Issues & Troubleshooting

### Issue 1: Approval button not showing
**Symptom**: No approval section visible
**Check**:
- User has `loans.approve` or `loans.approve_final` permission
- Loan status = 'pending'
- For dual approval L2: current_approval_level = 1

### Issue 2: "Permission denied" error
**Symptom**: Error when clicking approve
**Check**:
- Admin trying to approve L2 (needs Super Admin)
- Permission `loans.approve_final` exists in database
- Role has the permission assigned

### Issue 3: Approval history not showing
**Symptom**: "Riwayat Persetujuan" section empty
**Check**:
- loan_approvals table has records for this loan_id
- JOIN with users table successful (approver_id valid)

### Issue 4: Badge shows wrong level
**Symptom**: Badge says "Pending L1" but should be L2
**Check**:
- loans.current_approval_level value
- loan_products.approval_levels value
- SQL query in index.php includes lp.approval_levels

---

## Cleanup After Testing

```sql
-- Delete test loans
DELETE FROM loan_payments WHERE loan_id IN (SELECT id FROM loans WHERE loan_number LIKE 'TEST-%');
DELETE FROM loan_approvals WHERE loan_id IN (SELECT id FROM loans WHERE loan_number LIKE 'TEST-%');
DELETE FROM loans WHERE loan_number LIKE 'TEST-%';

-- Delete test products
DELETE FROM loan_products WHERE name LIKE '%TEST%';
```
