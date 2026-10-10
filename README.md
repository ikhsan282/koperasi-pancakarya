# Koperasi Pancakarya

Sistem Manajemen Koperasi - PHP Native dengan MySQLi

## Fitur

- **Manajemen Anggota**: Pendaftaran, profil, status keanggotaan
- **Simpanan**: Simpanan Pokok, Wajib, dan Sukarela dengan transaksi setoran/penarikan
- **Pinjaman**: Pengajuan, persetujuan, pencairan, dan cicilan pinjaman
- **Simulasi Pinjaman**: Kalkulator angsuran untuk anggota sebelum mengajukan
- **Buku Tabungan**: Cetak mutasi lengkap per anggota dengan saldo berjalan
- **Pengingat Jatuh Tempo**: Kirim email pengingat angsuran dengan dedup harian
- **Distribusi SHU**: Hitung jasa modal dari saldo simpanan dan jasa anggota dari bunga pinjaman dibayar, sesuaikan per anggota, lalu finalisasi per tahun
- **Laporan**: Dashboard statistik, log aktivitas, serta ekspor Excel untuk laporan simpanan, pinjaman, dan arus kas
- **Role-Based Access Control**: Super Admin, Admin, Bendahara, Anggota
- **Activity Logging**: Audit trail lengkap
- **PWA & Portal Mobile**: Portal anggota dapat dipasang di ponsel dan memiliki fallback offline
- **Dark Mode**: Tema gelap persisten untuk dashboard dan portal

## Teknologi

- PHP 8.5+ (Native, tanpa framework)
- MySQL 5.7+ / MariaDB 10.3+
- MySQLi dengan prepared statements
- Bootstrap 5.3.8 + Bootstrap Icons 1.13.2 (CDN)
- Chart.js 4.5.1 (CDN)
- Tom Select 2.3.1 (CDN)
- Vanilla JavaScript
- PWA (Web App Manifest + Service Worker)
- Generator PDF dan XLSX native tanpa Composer

## Instalasi

### 1. Clone Repository

```bash
git clone https://github.com/ikhsan282/koperasi-pancakarya.git
cd koperasi-pancakarya
```

### 2. Konfigurasi Database

Buat database MySQL:

```sql
CREATE DATABASE koperasi_pancakarya CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Import schema:

```bash
mysql -u root -p koperasi_pancakarya < database/schema.sql
```

### 3. Konfigurasi Aplikasi

Edit `config/config.php` sesuai environment Anda:

```php
const APP_URL = 'http://localhost:8080/koperasi-pancakarya';
const DB_HOST = 'localhost';
const DB_NAME = 'koperasi_pancakarya';
const DB_USER = 'root';
const DB_PASS = '';
const MAIL_FROM = 'noreply@domain-koperasi-anda';
```

### 4. Set Permissions

```bash
chmod -R 755 uploads/
```

### 5. Akses Aplikasi

Buka browser: `http://localhost:8080/koperasi-pancakarya`

**Login Default:**
- Username: `admin`
- Password: `P@ssw0rd`

## Struktur Direktori

```
koperasi-pancakarya/
├── config/
│   ├── config.php          # Konfigurasi utama
│   └── database.php        # Koneksi database
├── includes/
│   ├── auth.php           # Autentikasi & otorisasi
│   ├── functions.php      # Helper functions
│   ├── header.php         # Template header
│   └── footer.php         # Template footer
├── pages/
│   ├── auth/              # Login, logout
│   ├── dashboard/         # Dashboard utama
│   ├── members/           # CRUD anggota
│   ├── savings/           # Simpanan & transaksi
│   ├── loans/             # Pinjaman & cicilan
│   ├── shu/               # Distribusi SHU tahunan
│   └── reports/           # Laporan
├── public/
│   ├── css/              # Stylesheet
│   └── js/               # JavaScript
├── database/
│   └── schema.sql        # Database schema
├── uploads/              # File uploads (protected)
└── index.php             # Entry point
```

## Role & Permissions

| Role | Permissions |
|------|------------|
| **Super Admin** | Akses penuh semua fitur |
| **Admin** | Manajemen anggota, simpanan, pinjaman |
| **Bendahara** | View semua data, laporan, reports |
| **Anggota** | View data sendiri saja |

## Keamanan

- ✅ Prepared statements (SQL injection protection)
- ✅ CSRF tokens
- ✅ Password hashing (bcrypt)
- ✅ Session security (httponly, samesite)
- ✅ Upload directory protection (.htaccess)
- ✅ Role-based access control
- ✅ Activity logging

## Development

Database memiliki **16 tabel**:
- roles, permissions, role_permissions
- users, members
- savings_types, savings_accounts, savings_transactions
- loan_products, loans, loan_payments
- sequences
- activity_logs, email_logs
- shu_periods, shu_distributions

## License

MIT License - bebas digunakan untuk keperluan komersial dan non-komersial.

## Support

Untuk pertanyaan dan dukungan, buka issue di GitHub repository.
