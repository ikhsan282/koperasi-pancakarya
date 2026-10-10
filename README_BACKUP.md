# Backup & Restore System

Sistem backup dan restore database untuk Koperasi Pancakarya.

## Fitur

### 1. Backup Manual
- Buat backup database secara manual melalui UI
- File backup otomatis di-compress dengan gzip
- Download file backup ke lokal
- Hapus backup lama

### 2. Backup Otomatis
- Backup otomatis harian via cron job
- Konfigurasi retensi backup (default: 30 hari)
- Auto-cleanup backup yang kadaluarsa

### 3. Restore Database
- Upload file backup (.sql atau .sql.gz)
- Preview isi backup sebelum restore
- Konfirmasi eksplisit untuk keamanan
- Warning: restore akan menghapus semua data existing

### 4. Pengaturan
- Lokasi penyimpanan backup
- Retensi backup (berapa hari)
- Enable/disable auto backup

## Setup

### 1. Permissions
Permissions sudah ditambahkan:
- `backup.create` - Membuat backup (Admin+)
- `backup.restore` - Restore database (Super Admin only)
- `backup.delete` - Hapus backup (Super Admin only)

### 2. Direktori Backup
```bash
# Buat direktori backup
sudo mkdir -p /opt/data/backups
sudo chown www-data:www-data /opt/data/backups
sudo chmod 755 /opt/data/backups
```

### 3. Cron Job untuk Auto Backup
```bash
# Edit crontab
crontab -e

# Tambahkan baris ini (backup setiap jam 2 pagi)
0 2 * * * php /opt/data/projects/koperasi-pancakarya/cron/daily_backup.php >> /var/log/koperasi-backup.log 2>&1
```

### 4. Konfigurasi Settings
Akses: **Pengaturan Sistem** → **Backup & Restore**

- **Lokasi Penyimpanan Backup**: `/opt/data/backups` (default)
- **Retensi Backup**: `30` hari (default)
- **Aktifkan Backup Otomatis**: Centang untuk enable

## Penggunaan

### Buat Backup Manual
1. Buka menu **💾 Backup**
2. Klik **+ Buat Backup Manual**
3. Tunggu proses selesai
4. File backup akan muncul di tabel riwayat

### Download Backup
1. Buka menu **💾 Backup**
2. Pada tabel riwayat, klik **Download** pada backup yang diinginkan
3. File .sql.gz akan terdownload

### Restore Database
⚠️ **PERINGATAN**: Restore akan menghapus SEMUA data existing!

1. **Buat backup terbaru terlebih dahulu!**
2. Buka menu **💾 Backup**
3. Klik **Restore Database**
4. Upload file backup (.sql atau .sql.gz)
5. Review preview isi file
6. Ketik **RESTORE** untuk konfirmasi
7. Klik **RESTORE DATABASE SEKARANG**
8. Semua user akan logout otomatis setelah restore

### Hapus Backup
1. Buka menu **💾 Backup**
2. Klik **Hapus** pada backup yang ingin dihapus
3. Konfirmasi penghapusan

## Struktur File

```
includes/
  backup_helpers.php         # Fungsi backup/restore
pages/
  backup/
    index.php                # UI: list, create, download, delete
    restore.php              # UI: upload & restore
  settings/
    index.php                # Konfigurasi backup
cron/
  daily_backup.php           # Cron job auto backup
database/
  add_backup_permissions.sql # Migration permissions
```

## Teknologi

- **Backup**: PHP-native SQL dump (tidak perlu mysqldump CLI)
- **Kompresi**: gzip (PHP zlib extension)
- **Storage**: File system dengan log di database
- **Security**: RBAC permissions, konfirmasi eksplisit, user logout setelah restore

## Troubleshooting

### Backup gagal: "Permission denied"
```bash
# Pastikan direktori writable
sudo chown -R www-data:www-data /opt/data/backups
sudo chmod -R 755 /opt/data/backups
```

### Restore gagal: "max_execution_time"
```bash
# Edit php.ini, tingkatkan time limit
max_execution_time = 300
upload_max_filesize = 100M
post_max_size = 100M
```

### Auto backup tidak jalan
```bash
# Cek cron log
tail -f /var/log/koperasi-backup.log

# Test manual
php /opt/data/projects/koperasi-pancakarya/cron/daily_backup.php
```

## Best Practices

1. **Selalu buat backup sebelum restore**
2. **Simpan backup penting ke storage eksternal** (cloud, NAS, dll)
3. **Test restore di staging environment** sebelum production
4. **Monitor ukuran direktori backup** untuk menghindari disk penuh
5. **Review retensi backup** sesuai kebutuhan compliance

## Keamanan

- Backup files disimpan di luar web root (tidak bisa diakses via HTTP)
- Restore hanya bisa dilakukan Super Admin
- Konfirmasi eksplisit diperlukan untuk restore
- Activity logs mencatat semua operasi backup/restore
- Password database tidak pernah di-log atau ditampilkan
