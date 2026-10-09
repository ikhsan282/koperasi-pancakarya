# Critical Bug Fixes - 2026-10-09

## Summary
Fixed 5 CRITICAL + HIGH severity bugs in Koperasi Pancakarya system.

## CRITICAL: Race Conditions in Number Generation (3 bugs)

### Root Cause
All unique identifier generation used non-atomic SELECT COUNT + INSERT pattern:
```php
$stmt = db()->prepare('SELECT COUNT(*) AS total FROM table WHERE field LIKE ?');
$count = $result + 1;
// Race window here - another process can get same count
INSERT ... VALUES ($prefix . $count);
```

### Solution
Created atomic sequence generation using MySQL LAST_INSERT_ID pattern:
```php
UPDATE sequences SET current_value = LAST_INSERT_ID(current_value + 1) WHERE name = ?
```

### Fixed Files
1. **BUG-001**: `pages/members/form.php` - Member numbers (KP-YYYYMM-XXXX)
2. **BUG-002**: `pages/loans/form.php` - Loan numbers (L-YYYYMM-XXXX)
3. **BUG-003**: `pages/savings/form.php` - Account numbers (SIM-{member}-{type}-XX)

### New Infrastructure
- `includes/sequences.php` - Atomic sequence functions
- `database/migrations/001_add_sequences_table.sql` - Migration for existing DB
- `database/schema.sql` - Updated for fresh installs

## HIGH: Dashboard Overdue Calculation Incorrect

### BUG-004: `pages/dashboard/index.php:148-154`

**Problem**: Query calculated overdue as `months_elapsed - paid_count`, which:
- Doesn't account for actual due dates
- Counts all payments including pending ones
- Fails after disbursement creates loan_payments schedule

**Fix**: Now counts actual unpaid past-due payments:
```sql
SELECT COUNT(CASE WHEN lp.status != 'paid' AND lp.due_date < CURRENT_DATE THEN 1 END) AS overdue_count
FROM loans l JOIN loan_payments lp ON lp.loan_id = l.id
WHERE l.status = 'active'
HAVING overdue_count > 0
```

## HIGH: Missing Disbursement Date Validation

### BUG-005: `pages/loans/process.php:76-81`

**Problem**: Only validated date format, allowing:
- Future disbursement dates
- Dates before loan application

**Fix**: Added logical validation:
```php
if ($date_obj > $today) {
    flash('error', 'Tanggal pencairan tidak boleh di masa depan.');
}
if ($date_obj < $app_date) {
    flash('error', 'Tanggal pencairan tidak boleh sebelum tanggal pengajuan.');
}
```

## Migration Instructions

For existing installations:
```bash
mysql -u root -p koperasi_pancakarya < database/migrations/001_add_sequences_table.sql
```

For fresh installations:
```bash
mysql -u root -p koperasi_pancakarya < database/schema.sql
```

## Testing Recommendations

1. **Concurrent member registration**: Test with Apache Bench or parallel curl requests
2. **Disbursement validation**: Try future dates, past dates before application
3. **Dashboard overdue**: Create loan, disburse, let payment due date pass, verify display
