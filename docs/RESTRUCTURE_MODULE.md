# Loan Restructuring Module

## Overview
Complete implementation of loan restructuring for Koperasi Pancakarya, allowing modification of loan terms for active or problematic loans.

## Features Implemented

### 1. Restructure Request Form (`pages/loans/restructure.php`)
- **Access**: Active or completed loans only
- **3 Restructure Types**:
  - **Reschedule**: Reset payment schedule (same terms)
  - **Extend Tenor**: Increase loan duration, reduce monthly payment
  - **Reduce Rate**: Lower interest rate, reduce monthly payment
- **Live Preview**: JavaScript calculates new monthly payment before submission
- **Validation**: Client-side and server-side checks

### 2. Process Handler (`pages/loans/restructure_process.php`)
- Validates loan status (active/completed only)
- Calculates new monthly payment using `calculate_flat_loan()`
- Inserts to `loan_restructures` table with `status='pending'`
- Logs activity for audit trail

### 3. Approval Page (`pages/loans/restructure_approve.php`)
- Shows side-by-side comparison (old vs new)
- Displays changes in tenor, rate, monthly payment, total interest
- Color-coded improvements (green) vs increases (red)
- Approve/Reject workflow

### 4. Approval Process (`pages/loans/restructure_approval_process.php`)
- **On Approve**:
  1. Updates loan record (term_months, interest_rate, monthly_payment)
  2. Deletes unpaid loan_payments (keeps paid intact)
  3. Regenerates payment schedule from last paid + 1
  4. Sets restructure status='approved'
  5. Records approver and timestamp
- **On Reject**: Updates status='rejected' with reason

### 5. Integration (`pages/loans/detail.php`)
- **Restructure Button**: Shows for active/completed loans when user has `loans.restructure` permission
- **History Section**: Table showing all restructures (pending/approved/rejected) with:
  - Date, type, changes summary
  - Reason (truncated)
  - Status badge
  - Review button for pending items

## Database Schema
Uses existing `loan_restructures` table from `migration_phase3_enhancements.sql`:
- Tracks old/new values for term, rate, monthly payment
- Approval workflow (pending → approved/rejected)
- Foreign keys to loans and users

## Permission Required
- `loans.restructure` - Already assigned to Super Admin and Admin roles in Phase 3 migration

## Technical Details

### Calculation Method
Uses flat-rate calculation via `calculate_flat_loan()`:
```php
principal_monthly = principal / tenor
interest_monthly = principal × (rate% / 100)
monthly_payment = principal_monthly + interest_monthly
```

### Payment Schedule Regeneration
1. Count paid payments: `SELECT MAX(payment_number) WHERE status='paid'`
2. Delete unpaid: `DELETE WHERE status='pending'`
3. Calculate remaining term: `new_term_months - paid_count`
4. Generate new schedule starting from `disbursement_date + paid_count months`

### Validation Rules
- Extend tenor: `new_term > old_term` and `new_term ≤ 120 months`
- Reduce rate: `new_rate < old_rate` and `new_rate ≥ 0`
- Reschedule: No changes to term/rate, just regenerate schedule

## Files Created
1. `pages/loans/restructure.php` (12.6 KB) - Request form
2. `pages/loans/restructure_process.php` (4.3 KB) - Form handler
3. `pages/loans/restructure_approve.php` (11.9 KB) - Approval interface
4. `pages/loans/restructure_approval_process.php` (6.7 KB) - Approval handler

## Files Modified
1. `pages/loans/detail.php` - Added restructure button + history section

## Testing Checklist
- [ ] Request restructure for active loan
- [ ] Request extend tenor (verify new payment < old)
- [ ] Request reduce rate (verify new payment < old)
- [ ] Request reschedule (verify terms unchanged)
- [ ] Approve restructure (verify schedule regenerated)
- [ ] Reject restructure (verify no changes to loan)
- [ ] View history in loan detail
- [ ] Verify paid payments preserved after regeneration
- [ ] Test permission: non-admin cannot access

## URLs
- Request: `/pages/loans/restructure.php?id={loan_id}`
- Approve: `/pages/loans/restructure_approve.php?id={restructure_id}`
- Detail: `/pages/loans/detail.php?id={loan_id}` (shows button + history)

## Notes
- Reschedule is useful when payments are skipped but terms remain valid
- Extend tenor reduces burden but increases total interest paid
- Reduce rate is a concession for financial hardship
- All restructures require approval (no auto-approval)
- Activity log tracks all actions for compliance
