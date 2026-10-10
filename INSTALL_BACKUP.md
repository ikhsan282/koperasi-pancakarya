# Backup & Restore System - Installation Guide

## Quick Start

### 1. Jalankan Migration
```bash
# Via Web (recommended)
# Login sebagai Super Admin, akses phpMyAdmin atau database tool, run:
mysql -u root koperasi_pancakarya < database/add_backup_permissions.sql

# Atau via PHP (jika mysqli extension tersedia)
php database/run_migrations.php database/add_backup_permissions.sql
```

### 2. Setup Direktori Backup
```bash
sudo mkdir -p /opt/data/backups
sudo chown www-data:www-data /opt/data/backups
sudo chmod 755 /opt/data/backups
```

### 3. Konfigurasi (via Web UI)
1. Login sebagai Super Admin
2. Buka **Pengaturan Sistem**
3. Scroll ke **Backup & Restore**
4. Set:
   - Lokasi: `/opt/data/backups`
   - Retensi: `30` hari
   - ✓ Aktifkan Backup Otomatis (jika perlu)

### 4. Setup Cron (Opsional)
```bash
crontab -e
# Tambahkan:
0 2 * * * php /opt/data/projects/koperasi-pancakarya/cron/daily_backup.php >> /var/log/koperasi-backup.log 2>&1
```

## Verifikasi

### Test Backup Manual
1. Buka menu **💾 Backup**
2. Klik **+ Buat Backup Manual**
3. Tunggu proses (5-30 detik tergantung ukuran DB)
4. Cek file backup muncul di tabel

### Test Download
1. Klik **Download** pada backup
2. File .sql.gz terdownload

### Test Restore (⚠️ HATI-HATI)
1. Buat backup dulu!
2. Buka **Restore Database**
3. Upload file .sql atau .sql.gz
4. Review preview
5. Ketik **RESTORE** untuk konfirmasi
6. Klik **RESTORE DATABASE SEKARANG**

## Troubleshooting

### Permissions Error
```bash
# Fix direktori
sudo chown -R www-data:www-data /opt/data/backups
sudo chmod -R 755 /opt/data/backups
```

### PHP Memory/Timeout
```ini
# Edit php.ini
max_execution_time = 300
memory_limit = 512M
upload_max_filesize = 100M
post_max_size = 100M
```

### Cron Not Running
```bash
# Test manual
php /opt/data/projects/koperasi-pancakarya/cron/daily_backup.php

# Check log
tail -f /var/log/koperasi-backup.log
```

## Files Created

```
includes/backup_helpers.php         # Core backup/restore functions
pages/backup/index.php              # Backup management UI
pages/backup/restore.php            # Restore UI
cron/daily_backup.php               # Auto backup cron
database/add_backup_permissions.sql # Permissions migration
database/run_migrations.php         # Migration runner
README_BACKUP.md                    # Full documentation
```

## Security

- ✓ Backup files di luar webroot
- ✓ RBAC: backup.create (Admin+), backup.restore (Super Admin only)
- ✓ Konfirmasi eksplisit untuk restore
- ✓ Activity logging semua operasi
- ✓ User logout paksa setelah restore

## Next Steps

1. Login sebagai Super Admin
2. Run migration (add permissions)
3. Test backup manual
4. Setup cron untuk auto backup
5. Document restore procedure untuk team
