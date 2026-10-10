# Dual Approval Workflow - Implementation Summary

## Completed: October 10, 2026

### What Was Implemented

**Core dual approval workflow** for loan applications with configurable single or dual approval per product.

### Files Modified

1. **`pages/loan-products/form.php`**
   - Added approval_levels dropdown (1=Single, 2=Dual)
   - Updated INSERT/UPDATE queries to save approval_levels

2. **`pages/loans/process.php`**
   - Refactored approve action to read product approval_levels
   - Single approval: direct approve (status='approved', level=1)
   - Dual approval: 
     - L0→L1: Admin approves, status stays 'pending', level=1
     - L1→L2: Super Admin approves, status='approved', level=2
   - Records every approval in loan_approvals table
   - Permission checks: loans.approve (L1), loans.approve_final (L2)
   - Transaction-safe with rollback on error

3. **`pages/loans/detail.php`**
   - Added approval_levels to main query (from loan_products)
   - Added loan_approvals query for approval history
   - Conditional approval section based on:
     - Product approval_levels (1 or 2)
     - Current approval level (0, 1, or 2)
     - User permissions (loans.approve, loans.approve_final)
   - New "Riwayat Persetujuan" section showing:
     - Approval level, approver name, status, timestamp, notes
     - Workflow status footer (Pending L1/L2, Fully Approved)
   - Optional approval notes textarea

4. **`pages/loans/index.php`**
   - Added approval_levels to query (JOIN loan_products)
   - Smart status badges:
     - "Pending L1" for dual approval awaiting first approval
     - "Pending L2" for dual approval awaiting final approval
     - "Menunggu" for single approval pending

### Database Schema (Phase 2 Migration)

**Required**: `migration_phase2_compliance.sql` must be applied
- Table: `loan_approvals` (loan_id, approver_level, approver_id, status, notes)
- Column: `loan_products.approval_levels` (1 or 2, default 1)
- Column: `loans.current_approval_level` (0, 1, or 2)
- Permission: `loans.approve_final` (Super Admin only)

### How It Works

**Single Approval (approval_levels=1)**:
```
Pending (level=0) → [Admin approves] → Approved (level=1) → Can disburse
```

**Dual Approval (approval_levels=2)**:
```
Pending (level=0) → [Admin approves L1] → Pending (level=1) → [Super Admin approves L2] → Approved (level=2) → Can disburse
```

### Permission Model

| Role        | Permission           | Can Do                |
|-------------|----------------------|-----------------------|
| Admin       | loans.approve        | Approve L1            |
| Super Admin | loans.approve        | Approve L1            |
| Super Admin | loans.approve_final  | Approve L2 (final)    |

### Backward Compatibility

- Existing loans without approval_levels: treated as single approval (default=1)
- Existing single-approval flow: unchanged, still works
- NULL approval_levels: defaults to 1

### UI Changes

**Loan Products Form**: Dropdown to select 1 or 2 levels
**Loan Detail**: 
  - Shows approval level required (L1 or L2)
  - Shows approval history table
  - Shows workflow status
  - Only shows approve button if user has permission for current level
**Loan Index**: Badge shows "Pending L1", "Pending L2", or standard statuses

### Testing

See `docs/TESTING_DUAL_APPROVAL.md` for comprehensive test scenarios.

Quick smoke test:
1. Create product with approval_levels=2
2. Apply for loan
3. Login as Admin → approve → verify status stays 'pending', level=1
4. Login as Super Admin → approve → verify status='approved', level=2
5. Check approval history shows 2 records

### Documentation

- `docs/DUAL_APPROVAL_WORKFLOW.md` - Full technical documentation
- `docs/TESTING_DUAL_APPROVAL.md` - Test scenarios and checklists
- `database/verify_phase2_approval.sql` - Schema verification queries

### Known Limitations

- Sequential approval only (cannot skip L1 to go straight to L2)
- No approval delegation or substitution
- No time-based auto-escalation
- No bulk approval UI
- Rejection at any level terminates workflow (no return to previous level)

### Next Steps (Not Implemented)

- Email notifications for pending L2 approvals
- Dashboard widget for pending approvals count
- Approval deadline/SLA tracking
- 3-level approval for very large loans
- Approval reassignment (if approver unavailable)
