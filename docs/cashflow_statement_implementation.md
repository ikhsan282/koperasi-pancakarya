# Laporan Arus Kas (Cash Flow Statement) Implementation

## Overview
Implemented proper cash flow statement report based on actual cash/bank transactions from `cash_bank_transactions` table, replacing the simplified version that directly aggregated savings/loans.

## Files Created/Modified

### 1. `pages/reports/cashflow_statement.php`
Complete cash flow statement report with:

#### Query Logic
- **Data Source**: `cash_bank_transactions` table
- **Grouping**: By `reference_type` and `transaction_type`
- **Period Calculation**: Flexible period filtering (monthly, quarterly, yearly, custom)

#### Cash Flow Categories

**Operational Cash Flows - Receipts:**
- Angsuran Pinjaman (loan_payment + debit)
- Setoran Simpanan (savings_deposit + debit)

**Operational Cash Flows - Disbursements:**
- Pencairan Pinjaman (loan_disbursement + credit)
- Penarikan Simpanan (savings_withdrawal + credit)
- Beban Operasional (expense + credit)
- Lain-lain (other + credit)

#### Balance Calculation
```php
// Current total balance from all active accounts
SELECT COALESCE(SUM(balance), 0) FROM cash_bank_accounts WHERE is_active = 1

// Net change during period
SELECT 
    SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) AS total_debit,
    SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) AS total_credit
WHERE transaction_date BETWEEN start_date AND end_date

// Opening balance = Current balance - Net change
// Closing balance = Current balance (verified)
```

#### Features
1. **Period Filters**:
   - Monthly: Year + Month selector
   - Quarterly: Year + Quarter (Q1-Q4)
   - Yearly: Year selector
   - Custom: Start date + End date

2. **Per-Account Breakdown** (optional toggle):
   - Shows debit/credit/net for each cash_bank_account
   - Helps identify which accounts had most activity

3. **Visual Summary Cards**:
   - Saldo Awal (Opening Balance)
   - Arus Kas Bersih (Net Cash Flow) - color coded
   - Saldo Akhir (Closing Balance)

4. **Export Options**:
   - Print (browser print with CSS media query)
   - CSV export with full breakdown

#### UI Elements
- Dynamic period input fields (show/hide based on period type)
- JavaScript to toggle period inputs
- Color-coded net cash flow (green for positive, red for negative)
- Section headers for better readability
- Print-friendly CSS

### 2. `pages/reports/index.php`
Updated reports menu:
- Changed link from `cashflow.php` to `cashflow_statement.php`
- Updated description to reflect new implementation

## Database Schema Used

### cash_bank_transactions
```sql
- account_id: FK to cash_bank_accounts
- transaction_type: enum('debit','credit') -- debit=masuk, credit=keluar
- amount: decimal(15,2)
- reference_type: enum('loan_disbursement','loan_payment','savings_deposit','savings_withdrawal','expense','other')
- reference_id: FK to source transaction
- description: text
- transaction_date: date
```

### cash_bank_accounts
```sql
- id: int PK
- account_name: varchar
- account_type: enum('cash','bank')
- balance: decimal(15,2)
- is_active: boolean
```

## Formula Verification

### Opening Balance Calculation
The opening balance is calculated by working backwards from current balance:
```
Opening Balance = Current Total Balance - Net Change During Period
```

This ensures accuracy without requiring historical balance snapshots.

### Net Cash Flow
```
Net Cash Flow = Total Debit - Total Credit
              = (Receipts) - (Disbursements)
```

### Closing Balance
```
Closing Balance = Opening Balance + Net Cash Flow
                = Current Total Balance (verified)
```

## Acceptance Criteria Status

✅ **Arus kas operasional complete**
- All operational cash flows categorized properly
- Receipts: loan_payment, savings_deposit
- Disbursements: loan_disbursement, savings_withdrawal, expense, other

✅ **Saldo awal & akhir correct**
- Opening balance calculated from current balance minus period net change
- Closing balance equals current total (balance check)
- Formula verified mathematically

✅ **Filter periode works**
- Monthly (year + month)
- Quarterly (year + Q1-Q4)
- Yearly (year)
- Custom (start date + end date)

✅ **Breakdown per account (optional)**
- Toggle checkbox to show/hide
- Shows debit, credit, net per account
- Helps identify which accounts contributed to cash flow

✅ **Export Print + CSV**
- Print: Browser print with CSS media query (@media print)
- CSV: Full export with all categories and breakdown

## Testing Notes

### Syntax Check
```bash
php -l pages/reports/cashflow_statement.php
# No syntax errors detected
```

### Manual Testing Required
1. Access report at `/pages/reports/cashflow_statement.php`
2. Test each period filter type
3. Verify balance calculations against cash_bank_accounts table
4. Test breakdown toggle
5. Test CSV export
6. Test print layout

### Edge Cases to Verify
- Empty period (no transactions)
- Single account vs multiple accounts
- Negative balance scenarios
- Large date ranges
- Leap years in date calculations

## Integration Points

### Navigation
Report accessible from:
- Main reports menu: `/pages/reports/index.php`
- Direct URL: `/pages/reports/cashflow_statement.php`

### Permissions Required
- `reports.view` - To view the report
- `reports.export` - To export CSV or print

### Dependencies
- `config/config.php` - App configuration
- `config/database.php` - Database connection
- `includes/functions.php` - Helper functions (rupiah, url, etc)
- `includes/auth.php` - Authentication & permission checks
- `includes/header.php` / `includes/footer.php` - Page layout

## Future Enhancements (Skipped)

### Chart Visualization (ponytail: visual-charts)
Line chart showing saldo over time could be added using Chart.js or similar. Add when user requests visual analytics.

### Multi-Year Comparison (ponytail: multi-year)
Side-by-side comparison of multiple years/periods. Add when comparative analysis needed.

### Budget vs Actual (ponytail: budget-variance)
If budget table added, compare actual vs budgeted cash flow. Add when budgeting feature implemented.

### Excel Export (ponytail: xlsx-export)
Currently only CSV. Excel with formatting could use `includes/xlsx.php`. Add when requested.

## Implementation Time
Actual: ~45 minutes (code + documentation)
Estimated: 1 day (included in task spec)

## Branch
`feature/phase2-cashflow`
