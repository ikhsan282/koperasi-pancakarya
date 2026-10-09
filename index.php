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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .hero { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 5rem 0; }
        .feature-icon { font-size: 3rem; color: #667eea; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container">
            <span class="navbar-brand fw-bold"><?= e(APP_NAME) ?></span>
            <a href="pages/auth/login.php" class="btn btn-primary">Masuk</a>
        </div>
    </nav>

    <section class="hero text-center">
        <div class="container">
            <h1 class="display-4 fw-bold mb-3">Bersama Membangun Kesejahteraan</h1>
            <p class="lead mb-4">Platform koperasi digital untuk mengelola simpanan dan pinjaman dengan mudah</p>
            <a href="pages/auth/login.php" class="btn btn-light btn-lg">Mulai Sekarang</a>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4 text-center">
                    <i class="bi bi-piggy-bank feature-icon"></i>
                    <h3 class="mt-3">Simpanan</h3>
                    <p class="text-muted">Kelola simpanan pokok, wajib, dan sukarela dengan sistem yang aman dan transparan</p>
                </div>
                <div class="col-md-4 text-center">
                    <i class="bi bi-cash-coin feature-icon"></i>
                    <h3 class="mt-3">Pinjaman</h3>
                    <p class="text-muted">Ajukan pinjaman dengan proses cepat dan bunga kompetitif untuk kebutuhan Anda</p>
                </div>
                <div class="col-md-4 text-center">
                    <i class="bi bi-people feature-icon"></i>
                    <h3 class="mt-3">Keanggotaan</h3>
                    <p class="text-muted">Bergabung dengan komunitas koperasi dan nikmati berbagai manfaat bersama</p>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-light py-5">
        <div class="container text-center">
            <h2 class="mb-4">Siap Bergabung?</h2>
            <p class="lead mb-4">Daftar sebagai anggota dan mulai kelola keuangan Anda bersama kami</p>
            <a href="pages/auth/login.php" class="btn btn-primary btn-lg">Masuk ke Portal</a>
        </div>
    </section>

    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container text-center">
            <p class="mb-0">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Semua hak dilindungi.</p>
        </div>
    </footer>
</body>
</html>
