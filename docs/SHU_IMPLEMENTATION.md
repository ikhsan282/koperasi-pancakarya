# SHU Distribution UI - Implementation Summary

## Completed Features

### 1. SHU Calculation & Preview
- **Existing**: `pages/shu/index.php` - Form untuk fiscal year, total SHU, pct_jasa_modal
- **Existing**: `pages/shu/breakdown.php` - Preview calculation dengan draft mode
- **Formula**: Menggunakan `includes/shu_tools.php` dengan largest-remainder allocation
- **Adjustments**: Support manual adjustments per member (must sum to zero)

### 2. Distribution/Posting to Savings
- **New**: `pages/shu/distribute.php` - Post finalized SHU ke savings accounts
- **Features**:
  - Select target savings type (Simpanan Pokok/Wajib/Sukarela)
  - Auto-create accounts jika belum ada
  - Create deposit transactions dengan reference ke distribution
  - Mark period as distributed with timestamp + user tracking
- **Permission**: `shu.distribute` required

### 3. Distribution Tracking
- **Migration**: `database/migration_shu_distribution.sql`
- **Fields added**:
  - `shu_periods.distributed_at` - timestamp posting
  - `shu_periods.distributed_by` - user yang melakukan posting
  - `shu_distributions.distribution_transaction_id` - link ke savings_transactions
- **Status badges**: "✓ Diposting" vs "Belum diposting" in index

### 4. Member Portal Integration
- **Modified**: `pages/portal.php`
- **New tab**: SHU dengan bottom nav icon (bi-percent)
- **Displays**:
  - Fiscal year cards
  - Breakdown: savings_base, loan_base, jasa_modal, jasa_anggota
  - Total SHU per period
  - Adjustments (if any)
  - Notes (if any)

## Files Modified/Created

```
pages/shu/
  distribute.php         NEW - POST SHU to savings accounts
  breakdown.php          MOD - Add distribution modal + status indicator
  index.php              MOD - Show distribution status column

pages/portal.php         MOD - Add SHU history tab for members

database/
  migration_shu_distribution.sql       NEW - Schema changes
  apply_migration_shu_distribution.php NEW - Migration runner (optional)
  MIGRATION_INSTRUCTIONS.md            NEW - Migration guide

README.md                MOD - Update SHU feature description
```

## Database Migration Required

Run before using distribution feature:

```sql
ALTER TABLE shu_periods 
ADD COLUMN distributed_at TIMESTAMP NULL,
ADD COLUMN distributed_by INT(11) NULL,
ADD CONSTRAINT shu_periods_ibfk_2 FOREIGN KEY (distributed_by) REFERENCES users(id);

ALTER TABLE shu_distributions
ADD COLUMN distribution_transaction_id INT(11) NULL,
ADD CONSTRAINT shu_distributions_ibfk_3 FOREIGN KEY (distribution_transaction_id) REFERENCES savings_transactions(id);
```

## Permissions

- `shu.view` - View SHU periods and breakdown
- `shu.manage` - Calculate, adjust, finalize SHU
- `shu.distribute` - POST finalized SHU to savings accounts (new)

## Workflow

1. **Calculate**: Admin enters fiscal year, total SHU, percentage split → Preview
2. **Adjust**: Optional manual adjustments per member (must sum to zero)
3. **Finalize**: Save to `shu_periods` + `shu_distributions` (immutable)
4. **Distribute**: Select savings type → POST as deposits to member accounts
5. **View**: Members see their SHU history in portal

## Notes

- Calculation uses current savings balance (not year-end snapshot - ponytail: add balance snapshots table)
- Loan base = interest paid during fiscal year (from `loan_payments`)
- Distribution is one-time, irreversible (creates savings transactions)
- Accounts created automatically if member doesn't have target savings type
