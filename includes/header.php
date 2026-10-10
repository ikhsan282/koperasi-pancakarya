<?php
require_once __DIR__ . '/notification_helpers.php';
$flash = take_flash();
$title = $title ?? APP_NAME;
$user = current_user();
$unread_notifications = 0;
if ($user['id'] > 0) {
    $unread_notifications = get_unread_count($user['id']);
}
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
    <style>
    .notification-bell { position: relative; font-size: 1.3em; cursor: pointer; margin-left: 15px; }
    .notification-bell .badge { position: absolute; top: -8px; right: -8px; background: #dc3545; color: white; border-radius: 10px; padding: 2px 6px; font-size: 0.65em; min-width: 18px; text-align: center; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main>
        <header class="topbar">
            <button class="menu" type="button" aria-label="Buka menu">☰</button>
            <div><h1><?= e($title) ?></h1><small><?= date('d/m/Y') ?></small></div>
            <?php if ($user['id'] > 0): ?>
                <a href="<?= url('pages/notifications/index.php') ?>" class="notification-bell" title="Notifikasi">
                    🔔
                    <?php if ($unread_notifications > 0): ?>
                        <span class="badge"><?= $unread_notifications ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <button class="dark-toggle" type="button" aria-label="Toggle tema" style="margin-left: auto;">🌙</button>
        </header>
        <section class="content">
            <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
