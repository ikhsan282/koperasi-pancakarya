-- Add distribution tracking fields to shu_periods
ALTER TABLE `shu_periods` 
ADD COLUMN `distributed_at` timestamp NULL DEFAULT NULL AFTER `finalized_by`,
ADD COLUMN `distributed_by` int(11) DEFAULT NULL AFTER `distributed_at`,
ADD KEY `distributed_by` (`distributed_by`),
ADD CONSTRAINT `shu_periods_ibfk_2` FOREIGN KEY (`distributed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Add distribution_reference to shu_distributions to track posted transaction
ALTER TABLE `shu_distributions`
ADD COLUMN `distribution_transaction_id` int(11) DEFAULT NULL AFTER `notes`,
ADD KEY `distribution_transaction_id` (`distribution_transaction_id`),
ADD CONSTRAINT `shu_distributions_ibfk_3` FOREIGN KEY (`distribution_transaction_id`) REFERENCES `savings_transactions` (`id`) ON DELETE SET NULL;
