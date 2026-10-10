# Database Migrations

## SHU Distribution Enhancement (Phase 3)

To enable SHU posting to savings accounts, run this migration:

```sql
-- Add distribution tracking fields to shu_periods
ALTER TABLE `shu_periods` 
ADD COLUMN `distributed_at` timestamp NULL DEFAULT NULL AFTER `finalized_by`,
ADD COLUMN `distributed_by` int(11) DEFAULT NULL AFTER `distributed_at`,
ADD KEY `distributed_by` (`distributed_by`),
ADD CONSTRAINT `shu_periods_ibfk_2` FOREIGN KEY (`distributed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Add distribution reference to shu_distributions
ALTER TABLE `shu_distributions`
ADD COLUMN `distribution_transaction_id` int(11) DEFAULT NULL AFTER `notes`,
ADD KEY `distribution_transaction_id` (`distribution_transaction_id`),
ADD CONSTRAINT `shu_distributions_ibfk_3` FOREIGN KEY (`distribution_transaction_id`) REFERENCES `savings_transactions` (`id`) ON DELETE SET NULL;
```

**Run via:**
- phpMyAdmin SQL tab, or
- MySQL CLI: `mysql -u root -p koperasi_pancakarya < database/migration_shu_distribution.sql`
- Or through any PHP page with DB connection (import via web interface)

**What it does:**
- Tracks when and by whom SHU was posted to savings accounts
- Links each distribution record to its savings transaction
