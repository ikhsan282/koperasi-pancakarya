-- Verification script for Phase 2 Approval Workflow
-- Run this to check if all required schema changes are in place

USE koperasi_pancakarya;

-- Check if loan_approvals table exists
SELECT 'loan_approvals table' AS check_item,
       IF(COUNT(*) > 0, '✓ EXISTS', '✗ MISSING') AS status
FROM information_schema.tables 
WHERE table_schema = 'koperasi_pancakarya' 
  AND table_name = 'loan_approvals';

-- Check if loan_products.approval_levels column exists
SELECT 'loan_products.approval_levels' AS check_item,
       IF(COUNT(*) > 0, '✓ EXISTS', '✗ MISSING') AS status
FROM information_schema.columns
WHERE table_schema = 'koperasi_pancakarya'
  AND table_name = 'loan_products'
  AND column_name = 'approval_levels';

-- Check if loans.current_approval_level column exists
SELECT 'loans.current_approval_level' AS check_item,
       IF(COUNT(*) > 0, '✓ EXISTS', '✗ MISSING') AS status
FROM information_schema.columns
WHERE table_schema = 'koperasi_pancakarya'
  AND table_name = 'loans'
  AND column_name = 'current_approval_level';

-- Check if loans.approve_final permission exists
SELECT 'loans.approve_final permission' AS check_item,
       IF(COUNT(*) > 0, '✓ EXISTS', '✗ MISSING') AS status
FROM permissions
WHERE name = 'loans.approve_final';

-- Show current loan_products configuration
SELECT 'Current loan products approval config' AS info;
SELECT id, name, approval_levels, is_active
FROM loan_products
ORDER BY id;

-- Show any existing loan approvals
SELECT 'Existing loan approvals' AS info;
SELECT COUNT(*) as total_approvals FROM loan_approvals;
