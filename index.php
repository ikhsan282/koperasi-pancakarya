<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
redirect(empty($_SESSION['user_id']) ? 'pages/auth/login.php' : 'pages/dashboard/index.php');
