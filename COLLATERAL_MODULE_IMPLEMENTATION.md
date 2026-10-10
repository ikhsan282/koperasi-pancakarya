# Agunan (Collateral) Module Implementation

**Date:** 2026-10-10  
**Status:** Complete ✓

## Files Created

### Backend CRUD
- `pages/collateral/index.php` - List all collaterals for a loan with status badges
- `pages/collateral/form.php` - Add/edit form with photo & document uploads
- `pages/collateral/process.php` - Save/delete handler with file validation
- `pages/collateral/return.php` - Mark collateral as returned after loan completion

### Security
- `uploads/collateral/.htaccess` - Prevents PHP execution, allows only jpg/jpeg/png/pdf
- `uploads/collateral/index.php` - Returns 403 Forbidden for directory access

## Files Modified

### Integration with Loans Module
- `pages/loans/detail.php`:
  - Added "📦 Agunan (N)" button in page header
  - Displays collateral count badge
  - Warning alert when loan ≥ Rp5jt has no collateral (pending approval)

- `pages/loans/process.php`:
  - Added validation in `approve` action
  - Blocks approval for loans ≥ Rp5,000,000 without collateral
  - Error message: "Pinjaman ≥ Rp 5.000.000 wajib memiliki minimal 1 agunan sebelum dapat disetujui."

## Features Implemented

### 1. CRUD Operations
- **Create**: Add new collateral with photo + ownership proof uploads
- **Read**: List all collaterals per loan with details
- **Update**: Edit collateral details and replace files
- **Delete**: Remove collateral (only for pending/approved loans)

### 2. Collateral Types
- 🚗 Vehicle (Motor/Mobil)
- 🏠 Property (Tanah/Rumah)
- 💻 Electronics (Laptop/HP)
- 💍 Jewelry (Emas/Berlian)
- 📦 Other (Lainnya)

### 3. File Upload System
- **Photo**: Required on create, optional on update
- **Ownership Proof**: BPKB/Sertifikat/Nota - required on create
- **Validation**: 
  - Allowed types: JPG, JPEG, PNG, PDF
  - Max size: 5MB per file
  - MIME type verification using finfo
- **Filename Pattern**: `{loan_id}_{timestamp}_{photo|proof}.{ext}`
- **Storage**: `uploads/collateral/` directory

### 4. Status Tracking
- **held** (default): Collateral is being held by koperasi
- **returned**: Collateral returned to member after loan completion

### 5. Business Rules
- Loans ≥ Rp 5,000,000 require at least 1 collateral before approval
- Collateral can only be added/edited for pending/approved/active loans
- Collateral can only be marked as returned after loan is completed/paid
- Collateral is automatically deleted when parent loan is deleted (CASCADE)

### 6. Permissions
Uses existing RBAC pattern:
- `collateral.view` - View collateral list and details
- `collateral.manage` - Add, edit, delete, and return collateral

## Database Schema

Already defined in `database/migration_phase1_critical.sql`:

```sql
CREATE TABLE loan_collaterals (
  id INT PRIMARY KEY AUTO_INCREMENT,
  loan_id INT NOT NULL,
  collateral_type ENUM('vehicle','property','electronics','jewelry','other'),
  description TEXT NOT NULL,
  estimated_value DECIMAL(15,2) NOT NULL,
  photo_path VARCHAR(255),
  ownership_proof VARCHAR(255),
  status ENUM('held','returned') DEFAULT 'held',
  return_date DATE,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
);
```

## UI/UX

### Navigation Flow
1. Loans List → Loan Detail → "📦 Agunan (N)" button → Collateral List
2. Collateral List → "+ Tambah Agunan" or "Edit" buttons → Form
3. Form → Save → Back to Collateral List

### Approval Warning
When viewing a loan detail page with:
- Status = pending
- Amount ≥ Rp 5,000,000
- No collateral

Shows warning alert:
> ⚠ **Agunan Wajib:** Pinjaman ≥ Rp 5.000.000 wajib memiliki minimal 1 agunan sebelum dapat disetujui. [Tambah agunan sekarang →]

### Collateral List Table
- Columns: Jenis | Deskripsi | Nilai Estimasi | Bukti Kepemilikan | Status | Tgl Kembali | Aksi
- Action buttons:
  - "✓ Kembalikan" (only for held collateral on completed loans)
  - "Edit" (only for pending/approved loans)
  - "Hapus" (only for pending/approved loans)

## Security Measures

1. **CSRF Protection**: All forms use `csrf_token()` and `verify_csrf()`
2. **Permission Checks**: Every page enforces RBAC with `require_permission()`
3. **File Upload Security**:
   - MIME type validation (not just extension)
   - Size limits enforced
   - .htaccess prevents PHP execution in uploads/
   - Only whitelisted file types allowed
4. **SQL Injection**: All queries use prepared statements with parameter binding
5. **XSS Prevention**: All output uses `e()` function for HTML escaping

## Testing Checklist

### Manual Testing Required
- [ ] Create collateral for loan < Rp5jt (should work)
- [ ] Create collateral for loan ≥ Rp5jt (should work)
- [ ] Try to approve loan ≥ Rp5jt without collateral (should fail)
- [ ] Add collateral, then approve (should succeed)
- [ ] Upload photo (JPG/PNG)
- [ ] Upload ownership proof (PDF)
- [ ] Try to upload PHP file (should be rejected)
- [ ] Edit existing collateral
- [ ] Delete collateral from pending loan
- [ ] Complete loan, then mark collateral as returned
- [ ] Verify files are saved in uploads/collateral/
- [ ] Verify .htaccess blocks PHP execution

### Database Testing
```bash
# Run migration if not already done
mysql -u root -proot koperasi_pancakarya < database/migration_phase1_critical.sql

# Verify table exists
mysql -u root -proot koperasi_pancakarya -e "DESCRIBE loan_collaterals"

# Verify permissions exist
mysql -u root -proot koperasi_pancakarya -e "SELECT * FROM permissions WHERE name LIKE 'collateral.%'"
```

## Code Statistics
- New files: 8 (4 PHP, 2 security, 2 documentation)
- Modified files: 2 (loans/detail.php, loans/process.php)
- Total new lines: 484
- PHP syntax: ✓ All files validated

## Known Limitations

1. No image preview/thumbnail in list view
2. No file size displayed in UI
3. Files are not deleted when collateral record is deleted (orphaned files)
4. No audit trail for collateral status changes
5. No validation that estimated value ≥ certain % of loan amount

## Future Enhancements (Not Implemented)

- Image thumbnails in collateral list
- File cleanup job for orphaned uploads
- Collateral value vs loan amount ratio validation
- Collateral history/audit log
- Email notification when collateral is returned
- Bulk collateral import from Excel
- Collateral appraisal workflow

## Deployment Notes

1. Ensure database migration is run:
   ```bash
   mysql -u root -proot koperasi_pancakarya < database/migration_phase1_critical.sql
   ```

2. Verify directory permissions:
   ```bash
   chmod 755 pages/collateral
   chmod 755 uploads/collateral
   ```

3. Check web server can write to uploads/collateral/:
   ```bash
   chown www-data:www-data uploads/collateral/
   ```

4. Grant permissions to appropriate roles via admin interface

## Git Branch
Branch: `feature/phase1-collateral` (to be created from master)

## References
- Task breakdown: `/opt/data/cache/scratch/phase1_task_breakdown.md`
- Database migration: `database/migration_phase1_critical.sql`
- Existing pattern reference: `pages/loans/`, `pages/members/`
