<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/dashboard/index.php');
}
verify_csrf();

log_activity('logout', 'User keluar');
session_destroy();
header('Location: ' . url('pages/auth/login.php'));
exit;
