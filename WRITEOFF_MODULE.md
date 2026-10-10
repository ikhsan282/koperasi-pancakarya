# Modul Hapus Buku Kredit Macet (Write-off)

## Overview
Modul untuk menghapus buku kredit macet yang tidak dapat ditagih lagi setelah semua upaya penagihan telah dilakukan.

## Database Schema
```sql
-- Table: loan_writeoffs
- id: Primary key
- loan_id: FK ke loans
- writeoff_amount: Jumlah yang dihapus buku
- remaining_balance: Sisa tagihan saat pengajuan
- reason: Alasan hapus buku (TEXT)
- approved_by: FK ke users (NULL = pending)
- approved_at: Timestamp persetujuan
- writeoff_date: Tanggal writeoff
- created_at: Timestamp pengajuan

-- Loans table additions:
- writeoff_status: ENUM('none', 'partial', 'full')
- writeoff_amount: Total amount written off
```

## File Structure
```
pages/loans/
├── writeoff.php              # Form pengajuan writeoff
├── writeoff_process.php      # Backend proses pengajuan
├── writeoff_approve.php      # Interface approval (Super Admin)
├── writeoff_list.php         # Daftar semua writeoff
└── writeoff_detail.php       # Detail writeoff yang sudah disetujui

pages/reports/
└── writeoff_report.php       # Laporan writeoff per periode
```

## User Flow

### 1. Pengajuan Hapus Buku
**Path**: `pages/loans/writeoff.php?id={loan_id}`

- **Syarat**: 
  - Loan status: `active` atau `defaulted`
  - writeoff_status: `none` (belum pernah writeoff)
  - Ada outstanding balance > 0
  
- **Form Fields**:
  - Jenis Writeoff: Full (seluruh sisa) / Partial (sebagian)
  - Jumlah: Auto-calculated untuk full, manual untuk partial
  - Tanggal Writeoff
  - Alasan (required, textarea)

- **Validasi**:
  - Amount > 0 dan <= outstanding balance
  - Tanggal tidak boleh masa depan
  - Reason wajib diisi

- **Hasil**: Record masuk `loan_writeoffs` dengan status pending (approved_by = NULL)

### 2. Persetujuan (Super Admin Only)
**Path**: `pages/loans/writeoff_approve.php?id={writeoff_id}`

- **Permission Required**: `loans.writeoff`

- **Informasi Ditampilkan**:
  - Data anggota & pinjaman
  - Outstanding balance saat pengajuan vs saat ini
  - Jumlah writeoff
  - Alasan lengkap
  
- **Approve Action**:
  1. Update `loan_writeoffs.approved_by` dan `approved_at`
  2. Update `loans`:
     - Full writeoff: `writeoff_status='full'`, `status='completed'`
     - Partial writeoff: `writeoff_status='partial'`
     - Tambahkan ke `writeoff_amount`
  3. Jika full writeoff: Set semua `loan_payments` pending → `paid`
  4. **Opsional**: Post ke `cash_bank_transactions` sebagai expense (kerugian)
  
- **Reject Action**:
  - Delete record dari `loan_writeoffs`
  - Log aktivitas penolakan

### 3. Daftar & Laporan
**List**: `pages/loans/writeoff_list.php`
- Filter: Pending / Approved
- Aksi: Proses (jika pending) / Detail (jika approved)

**Report**: `pages/reports/writeoff_report.php`
- Filter periode (from - to)
- Summary cards: Total loans, Full/Partial count, Total amount
- Export: Print, CSV
- Hanya tampilkan writeoff yang sudah approved

## Integration Points

### Loan Detail Page
**File**: `pages/loans/detail.php`

```php
// Button "Hapus Buku" (show only if eligible)
<?php if (can('loans.writeoff') && in_array($loan['status'], ['active', 'defaulted']) && $loan['writeoff_status'] === 'none'): ?>
    <a href="<?= url('pages/loans/writeoff.php?id=' . $loan['id']) ?>" class="btn btn-danger">📝 Hapus Buku</a>
<?php endif; ?>

// Display writeoff info
<?php if ($loan['writeoff_status'] !== 'none'): ?>
    <tr><th>Status Writeoff</th><td>
        <span class="badge badge-danger">
            <?= $loan['writeoff_status'] === 'full' ? 'Full Writeoff' : 'Partial Writeoff' ?>
        </span>
    </td></tr>
    <tr><th>Jumlah Writeoff</th><td><strong style="color: #c53030;"><?= rupiah($loan['writeoff_amount']) ?></strong></td></tr>
<?php endif; ?>
```

### Cash/Bank Integration
Jika Super Admin memilih akun kas/bank saat approval:
- Transaction type: `credit` (uang keluar / kerugian)
- Reference type: `loan_writeoff`
- Reference ID: `writeoff_id`
- Description: "Hapus buku kredit macet {loan_number} - {member_name}"

## Permission
**Required**: `loans.writeoff`
- Biasanya untuk Super Admin only
- Sudah ada di migration Phase 3

## Business Rules

1. **Eligibility**:
   - Loan harus `active` atau `defaulted`
   - Belum pernah full writeoff
   - Ada sisa tagihan > 0

2. **Full Writeoff**:
   - Menghapus seluruh outstanding balance
   - Loan status → `completed`
   - Semua pending payments → `paid`
   - writeoff_status → `full`

3. **Partial Writeoff**:
   - Menghapus sebagian outstanding
   - Loan tetap `active`/`defaulted`
   - Payments tidak otomatis lunas
   - writeoff_status → `partial`
   - Bisa diajukan partial writeoff lagi di kemudian hari

4. **Approval Required**:
   - Tidak bisa langsung writeoff
   - Harus melalui approval Super Admin
   - Bisa ditolak dengan alasan

## Testing Checklist

- [ ] Form writeoff tampil untuk loan active/defaulted
- [ ] Button "Hapus Buku" tidak muncul untuk loan yang sudah writeoff
- [ ] Validasi form: amount, date, reason
- [ ] Pengajuan masuk ke writeoff_list dengan status pending
- [ ] Super Admin bisa approve
- [ ] Full writeoff: loan jadi completed, payments jadi paid
- [ ] Partial writeoff: loan tetap active, amount terakumulasi
- [ ] Cash/bank transaction tercatat (jika dipilih)
- [ ] Reject writeoff: record terhapus
- [ ] Report menampilkan data yang benar
- [ ] CSV export berfungsi
- [ ] Permission loans.writeoff enforced

## Notes

- **Irreversible**: Setelah approved, tidak bisa dibatalkan
- **Documentation**: Alasan wajib lengkap untuk audit trail
- **Financial Impact**: Writeoff = pengakuan kerugian, harus dicatat dengan benar
- **Activity Log**: Semua aksi tercatat di activity_logs
