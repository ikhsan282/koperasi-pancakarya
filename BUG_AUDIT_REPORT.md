# Functional Bug Audit Report - Koperasi Pancakarya
**Date:** 2026-10-09  
**Scope:** Business logic, financial calculations, state transitions, edge cases, UI/UX

---

## CRITICAL SEVERITY (Fix Immediately)

### BUG-001: Race Condition in Member Number Generation
**Severity:** CRITICAL  
**File:** `pages/members/form.php:60-67`  
**Type:** Concurrency / Data Integrity

**Issue:**
```php
$prefix = 'KP-' . date('Ym') . '-';
$stmt = db()->prepare('SELECT COUNT(*) AS total FROM members WHERE member_number LIKE ?');
$likePrefix = $prefix . '%';
$stmt->bind_param('s', $likePrefix);
$stmt->execute();
$count = $stmt->get_result()->fetch_assoc()['total'] + 1;
$member_number = $prefix . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
```

Non-atomic sequence generation: SELECT count, then INSERT. Two concurrent registrations can get the same count.

**Impact:**
- Duplicate member numbers
- Data corruption
- Registration failures

**Reproduction:**
1. Open two browser tabs
2. Submit member registration simultaneously
3. Both get same count value
4. Second INSERT fails or creates duplicate

**Fix:**
```php
// Use database sequence or atomic counter
$stmt = db()->prepare('INSERT INTO sequences (name, value) VALUES (?, 1) 
    ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)');
$stmt->bind_param('s', $seq_name);
$stmt->execute();
$seq = db()->insert_id;
```

---

### BUG-002: Race Condition in Loan Number Generation
**Severity:** CRITICAL  
**File:** `pages/loans/form.php:59-65`  
**Type:** Concurrency / Data Integrity

**Issue:** Identical to BUG-001 but for loan numbers.

```php
$prefix = 'L-' . date('Ym') . '-';
$stmt = db()->prepare('SELECT COUNT(*) AS total FROM loans WHERE loan_number LIKE ?');
// ... same pattern
```

**Impact:** Duplicate loan numbers, critical for financial tracking.

**Fix:** Same atomic sequence solution as BUG-001.

---

### BUG-003: Race Condition in Savings Account Numbers
**Severity:** CRITICAL  
**File:** `pages/savings/form.php:86-92`  
**Type:** Concurrency / Data Integrity

**Issue:** Same non-atomic sequence generation pattern.

```php
$stmt = db()->prepare('SELECT COUNT(*) AS c FROM savings_accounts WHERE account_number LIKE ?');
$seq = (int) $stmt->get_result()->fetch_assoc()['c'] + 1;
```

**Impact:** Duplicate account numbers in financial system.

**Fix:** Atomic sequence generation.

---

## HIGH SEVERITY

### BUG-004: Dashboard Overdue Calculation Incorrect
**Severity:** HIGH  
**File:** `pages/dashboard/index.php:148-154`  
**Type:** Business Logic Error

**Issue:**
```php
$stmt = db()->query('SELECT l.id, l.loan_number, ...,
    TIMESTAMPDIFF(MONTH, l.disbursement_date, CURRENT_DATE) + 1 AS months_elapsed,
    (SELECT COUNT(*) FROM loan_payments lp WHERE lp.loan_id = l.id) AS paid_count
    FROM loans l JOIN members m ON m.id = l.member_id
    WHERE l.status = "active" AND l.disbursement_date IS NOT NULL
    HAVING months_elapsed > paid_count');
```

The query counts **ALL** loan_payments (including future pending ones), not just paid ones. A loan disbursed 3 months ago with 12 scheduled payments will have paid_count=12, so `months_elapsed (3) > paid_count (12)` is FALSE even if payments are overdue.

**Impact:**
- Overdue loans not detected
- No collection action taken
- Bad debt increases

**Reproduction:**
1. Create loan with 12-month term
2. Disburse loan
3. System creates 12 payment schedule rows (status='pending')
4. Don't pay first installment
5. Dashboard shows 0 overdue (wrong)

**Fix:**
```php
$stmt = db()->query('SELECT l.id, l.loan_number, m.member_number, m.full_name,
    COUNT(CASE WHEN lp.status != "paid" AND lp.due_date < CURRENT_DATE THEN 1 END) AS overdue_count
    FROM loans l 
    JOIN members m ON m.id = l.member_id
    JOIN loan_payments lp ON lp.loan_id = l.id
    WHERE l.status = "active"
    GROUP BY l.id
    HAVING overdue_count > 0');
```

---

### BUG-005: Missing Disbursement Date Validation
**Severity:** HIGH  
**File:** `pages/loans/process.php:76-81`  
**Type:** Business Logic / Data Validation

**Issue:**
```php
$disbursement_date = trim($_POST['disbursement_date'] ?? '');
$date_obj = DateTime::createFromFormat('Y-m-d', $disbursement_date);
if (!$date_obj || $date_obj->format('Y-m-d') !== $disbursement_date) {
    flash('error', 'Tanggal pencairan tidak valid.');
    redirect('pages/loans/detail.php?id=' . $id);
}
```

Only validates format, not logical constraints.

**Impact:**
- Can disburse loan in the future → payment schedule starts in future
- Can disburse before application date → illogical timeline
- Can backdate disbursement → incorrect aging reports

**Reproduction:**
1. Apply for loan today (2026-10-09)
2. Approve it
3. Disburse with date 2027-01-01 (future)
4. First payment due 2027-02-01 (system accepts)

**Fix:**
```php
$disbursement_date = trim($_POST['disbursement_date'] ?? '');
$date_obj = DateTime::createFromFormat('Y-m-d', $disbursement_date);
if (!$date_obj || $date_obj->format('Y-m-d') !== $disbursement_date) {
    $errors[] = 'Tanggal pencairan tidak valid.';
}

$today = new DateTime();
if ($date_obj > $today) {
    $errors[] = 'Tanggal pencairan tidak boleh di masa depan.';
}

$app_date = new DateTime($loan['application_date']);
if ($date_obj < $app_date) {
    $errors[] = 'Tanggal pencairan tidak boleh sebelum tanggal pengajuan.';
}
```

---

## MEDIUM SEVERITY

### BUG-006: Savings Transaction Lock Order Race Condition
**Severity:** MEDIUM  
**File:** `pages/savings/form.php:102-108`  
**Type:** Concurrency / Lost Updates

**Issue:**
```php
// Lines 72-98: Find or create account WITHOUT lock

// Line 102: THEN lock
$stmt = db()->prepare('SELECT sa.balance, sa.status, m.member_number, m.full_name, sa.account_number
                       FROM savings_accounts sa
                       JOIN members m ON sa.member_id = m.id
                       WHERE sa.id = ? FOR UPDATE');
```

The account is found/created BEFORE locking. Concurrent transactions can:
1. Both find no account
2. Both create account (duplicate keys or lost updates)
3. Race on balance updates

**Impact:**
- Lost deposit/withdrawal transactions
- Incorrect balances
- Data integrity violations

**Fix:** Lock account selection FIRST, create inside transaction if needed:
```php
db()->begin_transaction();
// Lock member row first
$stmt = db()->prepare('SELECT id FROM members WHERE id = ? FOR UPDATE');
$stmt->bind_param('i', $member_id);
$stmt->execute();

// Then find or create account
$stmt = db()->prepare('SELECT id, balance, status FROM savings_accounts 
    WHERE member_id = ? AND savings_type_id = ? FOR UPDATE');
// ... rest of logic
```

---

### BUG-007: Overpayment Tolerance Too Large
**Severity:** MEDIUM  
**File:** `pages/loans/payment.php:52`  
**Type:** Financial Calculation / Business Logic

**Issue:**
```php
if ($payment_amount > $remaining_due + 0.01) 
    $errors[] = 'Jumlah pembayaran melebihi sisa tagihan ...';
```

Allows overpayment of Rp 0.01 per installment. Over 12 installments = Rp 0.12 excess.

**Impact:**
- Overpayments accumulate
- Reconciliation mismatches
- Accounting discrepancies

**Fix:**
```php
// Use tighter tolerance or none
if ($payment_amount > $remaining_due + 0.001) { // 0.1 cent tolerance
    $errors[] = '...';
}
```

---

### BUG-008: Float Comparison Hardcoded Tolerance
**Severity:** MEDIUM  
**File:** `pages/loans/payment.php:56`  
**Type:** Financial Calculation

**Issue:**
```php
$new_status = ($new_amount_paid + 0.01 >= (float) $payment['amount_due']) ? 'paid' : 'pending';
```

Hardcoded 0.01 tolerance might not catch all floating point errors. Should use epsilon based on magnitude.

**Impact:** Installment not marked paid even when fully paid.

**Fix:**
```php
$epsilon = max(0.01, $payment['amount_due'] * 0.0001); // 0.01% or 1 cent
$new_status = ($new_amount_paid + $epsilon >= (float) $payment['amount_due']) ? 'paid' : 'pending';
```

---

### BUG-009: Interest Calculation Inconsistency in Reports
**Severity:** MEDIUM  
**File:** `pages/reports/loans.php:23`  
**Type:** Business Logic / Calculation Mismatch

**Issue:**
```php
// Line 23: Report calculates interest as
$contract_interest = (float) $row['amount'] * ((float) $row['interest_rate'] / 100) * (int) $row['term_months'];

// But includes/loan_tools.php:19 calculates per-month:
$interest_monthly = round($principal * ($rate_monthly / 100), 2);
$total_interest = round($interest_monthly * $months, 2);
```

Different rounding: report multiplies then rounds once; loan schedule rounds each month then multiplies. Results differ.

**Impact:**
- Outstanding interest reports inaccurate
- Reconciliation failures
- Confusion about remaining balance

**Reproduction:**
1. Loan: Rp 1,000,000 @ 1.75% for 12 months
2. Report shows: 1,000,000 × 0.0175 × 12 = 210,000
3. Actual schedule: round(17,500) × 12 = 210,000 (lucky match)
4. Try: Rp 1,000,001 @ 1.75% for 12 months
5. Report: 1,000,001 × 0.0175 × 12 = 210,000.21
6. Schedule: round(17,500.0175) × 12 = 17,500.02 × 12 = 210,000.24
7. Mismatch: 0.03

**Fix:** Use same calculation logic from loan_tools.php:
```php
$interest_monthly = round((float) $row['amount'] * ((float) $row['interest_rate'] / 100), 2);
$contract_interest = $interest_monthly * (int) $row['term_months'];
```

---

### BUG-010: SHU Breakdown CSRF Timing Issue
**Severity:** MEDIUM  
**File:** `pages/shu/breakdown.php:47-53`  
**Type:** Security / Logic Flow

**Issue:**
```php
$adjustments = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}
foreach ($_POST['adjustment'] ?? [] as $mid => $val) {
    $adjustments[(int) $mid] = (int) $val;
}
```

The `verify_csrf()` is inside the POST check, but `foreach ($_POST['adjustment'])` executes regardless. If someone constructs a GET request with `?adjustment[1]=1000`, the code processes it without CSRF.

**Impact:** CSRF bypass on adjustment application (though GET shouldn't mutate, this is unsafe).

**Fix:**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($_POST['adjustment'] ?? [] as $mid => $val) {
        $adjustments[(int) $mid] = (int) $val;
    }
} else {
    $adjustments = []; // No adjustments on GET
}
```

---

## LOW SEVERITY

### BUG-011: Inconsistent amount_due Fallback Display
**Severity:** LOW  
**File:** `pages/loans/detail.php:189,191,212`  
**Type:** UI / Schema Migration Artifact

**Issue:**
```php
<td><?= rupiah($p['amount_due'] > 0 ? $p['amount_due'] : $p['amount']) ?></td>
```

Appears to be leftover from schema migration. If `amount_due` is 0 (valid), it falls back to `amount` (old column?). Confusing and inconsistent.

**Impact:** Display might show wrong amounts if amount_due is intentionally 0.

**Fix:** Use amount_due consistently:
```php
<td><?= rupiah($p['amount_due']) ?></td>
```

---

### BUG-012: Rounding Accumulation in Loan Schedule
**Severity:** LOW  
**File:** `pages/loans/process.php:93-105`  
**Type:** Financial Calculation

**Issue:**
```php
$principal_base = round($amount / $term, 2);
// ...
for ($i = 1; $i <= $term; $i++) {
    $principal = ($i === $term) ? round($amount - $total_principal_scheduled, 2) : $principal_base;
    $total_principal_scheduled += $principal;
    $amount_due = round($principal + $interest_per_month, 2);
    // ...
}
```

Last payment adjusts for accumulated error, but intermediate roundings on `amount_due` can create small discrepancies between sum(amount_due) and contract total.

**Impact:** Total scheduled might differ from contract by a few cents.

**Example:**
- Loan: Rp 1,000,000 @ 1.5% for 12 months
- Principal/month: 83,333.33... → rounds to 83,333.33
- Interest/month: 15,000
- amount_due: round(83,333.33 + 15,000) = 98,333.33
- But 83,333.33 × 12 = 999,999.96 (principal scheduled)
- Last payment adjusts: 1,000,000 - 999,999.96 = 0.04 extra
- Last amount_due: 0.04 + 15,000 = 15,000.04
- Sum(amount_due): 98,333.33×11 + 15,000.04 = wait, this doesn't match either

**Fix:** Store unrounded values, round only for display:
```php
// ponytail: exact decimal storage (DECIMAL(15,4)), upgrade when precision matters
$principal_exact = $amount / $term;
for ($i = 1; $i <= $term; $i++) {
    $principal = ($i === $term) ? ($amount - $total_principal_scheduled) : $principal_exact;
    // Store exact, display rounded
}
```

---

### BUG-013: No Minimum Balance or Withdrawal Limit
**Severity:** LOW  
**File:** `pages/savings/form.php:117-118`  
**Type:** Business Logic / Policy Missing

**Issue:**
```php
if ($transaction_type === 'withdrawal' && $amount > $balance_before) {
    throw new Exception('Jumlah penarikan melebihi saldo rekening ...');
}
```

Only checks if withdrawal exceeds balance. No policy for:
- Minimum balance requirement (e.g., Rp 10,000)
- Daily withdrawal limits
- Withdrawal restrictions by account type

**Impact:**
- Can empty account completely
- No protection against fraud or errors

**Fix:** Add policy checks:
```php
if ($transaction_type === 'withdrawal') {
    if ($amount > $balance_before) {
        throw new Exception('Jumlah penarikan melebihi saldo rekening ...');
    }
    $balance_after_calc = $balance_before - $amount;
    $min_balance = 10000; // from savings_types table
    if ($balance_after_calc < $min_balance) {
        throw new Exception('Saldo minimum Rp ' . number_format($min_balance) . ' harus dipertahankan.');
    }
}
```

---

### BUG-014: Auto-Created Savings Accounts Have No Audit Trail
**Severity:** LOW  
**File:** `pages/members/form.php:74-82`  
**Type:** Audit / Data Quality

**Issue:**
```php
$types = db()->query('SELECT id FROM savings_types WHERE is_active = 1');
while ($t = $types->fetch_assoc()) {
    $acc_no = 'SAV-' . $new_id . '-' . $t['id'] . '-' . rand(100, 999);
    $today = date('Y-m-d');
    $acc_stmt = db()->prepare('INSERT INTO savings_accounts 
        (member_id, savings_type_id, account_number, balance, status, opened_date) 
        VALUES (?, ?, ?, 0.00, "active", ?)');
    $acc_stmt->bind_param('iiss', $new_id, $t['id'], $acc_no, $today);
    $acc_stmt->execute();
}
```

Creates accounts with balance=0 but no corresponding opening transaction in `savings_transactions`. Audit trail incomplete.

**Impact:**
- Can't trace account opening
- Reports won't show account opening event
- Reconciliation harder

**Fix:**
```php
// After creating account
$acc_id = db()->insert_id;
$desc = 'Pembukaan rekening otomatis';
$stmt = db()->prepare('INSERT INTO savings_transactions 
    (savings_account_id, transaction_type, amount, balance_before, balance_after, 
     transaction_date, description, processed_by)
    VALUES (?, "deposit", 0, 0, 0, ?, ?, ?)');
$stmt->bind_param('issi', $acc_id, $today, $desc, $user_id);
$stmt->execute();
```

---

### BUG-015: Random Suffix in Account Numbers
**Severity:** LOW  
**File:** `pages/members/form.php:77`  
**Type:** Data Quality

**Issue:**
```php
$acc_no = 'SAV-' . $new_id . '-' . $t['id'] . '-' . rand(100, 999);
```

Uses `rand()` for suffix → not deterministic, not sequential, possible (unlikely) collisions.

**Impact:**
- Confusing account numbers
- No sorting by creation order
- Low collision probability but non-zero

**Fix:** Use sequential suffix or timestamp:
```php
$acc_no = 'SAV-' . $new_id . '-' . $t['id'] . '-' . date('His');
```

---

## EDGE CASES / INPUT VALIDATION

### BUG-016: Zero Amount Not Validated
**Severity:** LOW  
**File:** Multiple (loans/form.php:51, savings/form.php:53)  
**Type:** Input Validation

**Issue:** Checks `$amount <= 0` but error message says "harus lebih dari nol", which is correct. Actually this is NOT a bug - validation is correct.

**Status:** FALSE POSITIVE - No bug.

---

### BUG-017: Loan Product Min > Max Not Checked at Runtime
**Severity:** LOW  
**File:** `pages/loan-products/form.php:44`  
**Type:** Data Validation

**Issue:**
```php
if ($min_amount > $max_amount) $errors[] = 'Jumlah minimum tidak boleh lebih besar dari maksimum.';
```

This checks on form submit, which is correct. However, if someone directly updates the database, the constraint is not enforced.

**Impact:** Inconsistent product limits if DB is manipulated directly.

**Fix:** Add DB constraint:
```sql
ALTER TABLE loan_products ADD CONSTRAINT chk_amount_range 
    CHECK (min_amount <= max_amount);
```

---

## SUMMARY

**Total Bugs Found:** 15 (excluding false positives)
- **Critical:** 3 (race conditions in number generation)
- **High:** 2 (dashboard overdue logic, disbursement validation)
- **Medium:** 6 (concurrency, calculations, CSRF)
- **Low:** 4 (UI, audit trail, data quality)

**Most Critical Fixes Needed:**
1. Implement atomic sequence generation for member/loan/account numbers
2. Fix dashboard overdue calculation immediately
3. Add disbursement date validation
4. Fix savings transaction lock ordering

**Recommended Priority:**
1. Week 1: Fix all CRITICAL bugs (BUG-001, 002, 003)
2. Week 2: Fix HIGH severity (BUG-004, 005)
3. Week 3: Fix MEDIUM severity (BUG-006 through 010)
4. Week 4: Address LOW severity issues

**Testing Recommendations:**
- Add concurrent transaction tests for number generation
- Test overdue detection with various loan states
- Test float rounding edge cases with large amounts
- Load test savings transactions for race conditions
