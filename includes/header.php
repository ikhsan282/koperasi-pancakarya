<?php
$flash = take_flash();
$title = $title ?? APP_NAME;
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
    <script>
    (function(){try{var t=localStorage.getItem('pancakarya_theme');if(!t)t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
    </script>
    <link rel="manifest" href="<?= url('manifest.json') ?>">
    <meta name="theme-color" content="#2563eb">
    <script>window.APP_URL = <?= json_encode(APP_URL) ?>;</script>
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main>
        <header class="topbar">
            <button class="menu" type="button" aria-label="Buka menu">☰</button>
            <div><h1><?= e($title) ?></h1><small><?= date('d/m/Y') ?></small></div>
            <button class="dark-toggle" type="button" aria-label="Toggle tema" style="margin-left: auto;">🌙</button>
        </header>
        <section class="content">
            <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
