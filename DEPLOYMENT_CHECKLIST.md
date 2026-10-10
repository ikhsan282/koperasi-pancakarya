# Deployment Checklist - SHU Distribution Feature

## Pre-deployment

- [ ] Run database migration: `database/migration_shu_distribution.sql`
- [ ] Verify `shu.distribute` permission exists (from `migration_phase3_enhancements.sql`)
- [ ] Assign permission to appropriate roles (Super Admin has it by default)

## Migration Steps

```bash
# Option 1: MySQL CLI
mysql -u root -p koperasi_pancakarya < database/migration_shu_distribution.sql

# Option 2: phpMyAdmin
# Import database/migration_shu_distribution.sql via SQL tab

# Option 3: Via application (if mysqli extension loaded)
# Access any admin page and the migration will auto-apply on first use
```

## Verify Installation

1. **Check schema**:
```sql
SHOW COLUMNS FROM shu_periods LIKE 'distributed%';
SHOW COLUMNS FROM shu_distributions LIKE 'distribution_transaction_id';
```

2. **Check permissions**:
```sql
SELECT * FROM permissions WHERE name = 'shu.distribute';
SELECT rp.*, r.name as role_name FROM role_permissions rp 
JOIN roles r ON r.id = rp.role_id 
WHERE permission_id = (SELECT id FROM permissions WHERE name = 'shu.distribute');
```

3. **Test workflow**:
   - Login as Super Admin
   - Navigate to SHU → Calculate new period
   - Preview breakdown
   - Finalize period
   - Click "Posting ke Simpanan"
   - Select savings type
   - Verify transactions created in savings_accounts

## Features Enabled

- ✓ SHU calculation with adjustable split (jasa modal/jasa anggota)
- ✓ Per-member adjustments (must sum to zero)
- ✓ Finalize periods (immutable after save)
- ✓ Post SHU to member savings accounts
- ✓ Track distribution status and timestamp
- ✓ Member portal SHU history view
- ✓ PDF export of finalized distributions

## Rollback

If issues arise:

```sql
-- Remove distribution tracking (optional, does not break existing functionality)
ALTER TABLE shu_distributions DROP FOREIGN KEY shu_distributions_ibfk_3;
ALTER TABLE shu_distributions DROP COLUMN distribution_transaction_id;
ALTER TABLE shu_periods DROP FOREIGN KEY shu_periods_ibfk_2;
ALTER TABLE shu_periods DROP COLUMN distributed_by;
ALTER TABLE shu_periods DROP COLUMN distributed_at;
```

Distribution feature will be unavailable but existing SHU periods remain intact.
