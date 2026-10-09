<?php
$flash = take_flash();
$title = $title ?? APP_NAME;
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= url('public/css/app.css') ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="<?= url('pages/dashboard/index.php') ?>"><span>KP</span><strong>Koperasi<br>Pancakarya</strong></a>
        <nav>
            <a href="<?= url('pages/dashboard/index.php') ?>">▦ Dashboard</a>
            <?php if (can('members.view')): ?><a href="<?= url('pages/members/index.php') ?>">♙ Anggota</a><?php endif; ?>
            <?php if (can('savings.view')): ?><a href="<?= url('pages/savings/index.php') ?>">◉ Simpanan</a><?php endif; ?>
            <?php if (can('loans.view')): ?><a href="<?= url('pages/loans/index.php') ?>">◇ Pinjaman</a><?php endif; ?>
            <?php if (can('reports.view')): ?><a href="<?= url('pages/reports/index.php') ?>">▤ Laporan</a><?php endif; ?>
        </nav>
        <div class="sidebar-user"><small>Masuk sebagai</small><strong><?= e(current_user()['name']) ?></strong><a href="<?= url('pages/auth/logout.php') ?>">Keluar</a></div>
    </aside>
    <main>
        <header class="topbar"><button class="menu" type="button" aria-label="Buka menu">☰</button><div><h1><?= e($title) ?></h1><small><?= date('d/m/Y') ?></small></div></header>
        <section class="content">
            <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
