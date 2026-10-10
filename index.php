<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

// Authenticated users: route by role
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'Anggota') {
        redirect('pages/portal.php');
    }
    redirect('pages/dashboard/index.php');
}

// Public landing page
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary: #2563eb; --primary-dark: #1e40af; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        
        .navbar { backdrop-filter: blur(10px); background: rgba(255,255,255,0.95) !important; }
        .navbar-brand { font-size: 1.5rem; font-weight: 700; background: linear-gradient(135deg, #60a5fa 0%, #2563eb 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .hero { background: linear-gradient(135deg, #60a5fa 0%, #2563eb 100%); color: white; padding: 8rem 0 6rem; position: relative; overflow: hidden; }
        .hero::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M0 0h60v60H0z' fill='none'/%3E%3Cpath d='M30 0v60M0 30h60' stroke='rgba(255,255,255,0.05)' stroke-width='1'/%3E%3C/svg%3E"); opacity: 0.3; }
        .hero-content { position: relative; z-index: 1; }
        .hero h1 { font-size: 3.5rem; font-weight: 800; line-height: 1.2; margin-bottom: 1.5rem; }
        .hero p { font-size: 1.25rem; opacity: 0.95; margin-bottom: 2rem; }
        .btn-hero { padding: 1rem 2.5rem; font-size: 1.1rem; font-weight: 600; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); transition: all 0.3s; }
        .btn-hero:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,0,0,0.2); }
        
        .feature-card { background: white; border-radius: 16px; padding: 2.5rem 2rem; box-shadow: 0 2px 12px rgba(0,0,0,0.08); transition: all 0.3s; border: 2px solid transparent; height: 100%; }
        .feature-card:hover { transform: translateY(-8px); box-shadow: 0 12px 40px rgba(37,99,235,0.15); border-color: rgba(37,99,235,0.1); }
        .feature-icon { width: 72px; height: 72px; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; }
        .feature-icon i { font-size: 2rem; color: var(--primary); }
        .feature-card h3 { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 1rem; }
        .feature-card p { color: #64748b; line-height: 1.7; margin: 0; }
        
        .stats-section { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding: 5rem 0; }
        .stat-box { text-align: center; }
        .stat-number { font-size: 3rem; font-weight: 800; background: linear-gradient(135deg, #60a5fa 0%, #2563eb 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 0.5rem; }
        .stat-label { color: #64748b; font-size: 1.1rem; font-weight: 500; }
        
        .cta-section { background: white; padding: 5rem 0; }
        .cta-box { background: linear-gradient(135deg, #60a5fa 0%, #2563eb 100%); border-radius: 24px; padding: 4rem 3rem; color: white; text-align: center; position: relative; overflow: hidden; }
        .cta-box::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); }
        .cta-box h2 { font-size: 2.5rem; font-weight: 800; margin-bottom: 1.5rem; position: relative; z-index: 1; }
        .cta-box p { font-size: 1.2rem; opacity: 0.95; margin-bottom: 2rem; position: relative; z-index: 1; }
        
        footer { background: #1e293b; color: #94a3b8; padding: 3rem 0; }
        footer h5 { color: white; font-weight: 700; margin-bottom: 1.5rem; font-size: 1.1rem; }
        footer a { color: #94a3b8; text-decoration: none; transition: color 0.3s; }
        footer a:hover { color: #60a5fa; }
        footer .social-link { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; background: rgba(96,165,250,0.1); border-radius: 10px; margin-right: 0.75rem; transition: all 0.3s; }
        footer .social-link:hover { background: var(--primary); color: white; transform: translateY(-2px); }
        
        @media (max-width: 768px) {
            .hero { padding: 5rem 0 4rem; }
            .hero h1 { font-size: 2.5rem; }
            .hero p { font-size: 1.1rem; }
            .feature-card { padding: 2rem 1.5rem; }
            .stat-number { font-size: 2.5rem; }
            .cta-box { padding: 3rem 2rem; }
            .cta-box h2 { font-size: 2rem; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg fixed-top shadow-sm">
        <div class="container">
            <span class="navbar-brand"><?= e(APP_NAME) ?></span>
            <a href="pages/auth/login.php" class="btn btn-primary">
                <i class="bi bi-box-arrow-in-right me-2"></i>Masuk
            </a>
        </div>
    </nav>

    <section class="hero">
        <div class="container hero-content">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <h1 class="mb-4">Bersama Membangun<br>Kesejahteraan Anggota</h1>
                    <p class="lead mb-4">Platform koperasi digital untuk mengelola simpanan, pinjaman, dan SHU dengan sistem yang aman, transparan, dan mudah digunakan</p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="pages/auth/login.php" class="btn btn-light btn-hero">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Mulai Sekarang
                        </a>
                        <a href="#features" class="btn btn-outline-light btn-hero">
                            Pelajari Lebih Lanjut
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 mt-5" id="features">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold mb-3" style="font-size: 2.5rem; color: #1e293b;">Fitur Lengkap untuk Anggota</h2>
                <p class="text-muted" style="font-size: 1.2rem;">Kelola keuangan koperasi dengan mudah dan aman</p>
            </div>
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-piggy-bank-fill"></i>
                        </div>
                        <h3>Simpanan Fleksibel</h3>
                        <p>Kelola simpanan pokok, wajib, dan sukarela dengan sistem yang aman dan transparan. Cek saldo dan riwayat transaksi kapan saja.</p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                        <h3>Pinjaman Cepat</h3>
                        <p>Ajukan pinjaman dengan proses cepat dan bunga kompetitif. Simulasi cicilan online untuk perencanaan keuangan lebih baik.</p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <h3>Distribusi SHU</h3>
                        <p>Nikmati bagi hasil (SHU) tahunan yang transparan berdasarkan jasa modal dan jasa anggota dengan perhitungan otomatis.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="stats-section">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4 mb-md-0">
                    <div class="stat-box">
                        <div class="stat-number">100%</div>
                        <div class="stat-label">Aman & Terpercaya</div>
                    </div>
                </div>
                <div class="col-md-4 mb-4 mb-md-0">
                    <div class="stat-box">
                        <div class="stat-number">24/7</div>
                        <div class="stat-label">Akses Portal Anggota</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <div class="stat-number"><i class="bi bi-shield-fill-check"></i></div>
                        <div class="stat-label">Data Terenkripsi</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container">
            <div class="cta-box">
                <h2>Siap Bergabung dengan Kami?</h2>
                <p>Daftar sebagai anggota dan mulai kelola keuangan Anda bersama koperasi</p>
                <a href="pages/auth/login.php" class="btn btn-light btn-hero">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Masuk ke Portal
                </a>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <h5 class="mb-3"><?= e(APP_NAME) ?></h5>
                    <p>Platform digital untuk mengelola simpanan, pinjaman, dan SHU koperasi dengan sistem yang modern dan terpercaya.</p>
                </div>
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <h5>Tautan Cepat</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="pages/auth/login.php"><i class="bi bi-arrow-right me-2"></i>Masuk</a></li>
                        <li class="mb-2"><a href="pages/loans/simulator.php"><i class="bi bi-arrow-right me-2"></i>Simulasi Pinjaman</a></li>
                        <li class="mb-2"><a href="#features"><i class="bi bi-arrow-right me-2"></i>Fitur</a></li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <h5>Hubungi Kami</h5>
                    <p class="mb-3">
                        <i class="bi bi-envelope me-2"></i>info@koperasi.test<br>
                        <i class="bi bi-telephone me-2"></i>+62 xxx xxxx xxxx
                    </p>
                    <div>
                        <a href="#" class="social-link"><i class="bi bi-facebook"></i></a>
                        <a href="#" class="social-link"><i class="bi bi-instagram"></i></a>
                        <a href="#" class="social-link"><i class="bi bi-twitter"></i></a>
                    </div>
                </div>
            </div>
            <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">
            <div class="text-center">
                <p class="mb-0">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Semua hak dilindungi.</p>
            </div>
        </div>
    </footer>
</body>
</html>
