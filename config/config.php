<?php
defined('APP_NAME') || define('APP_NAME', 'Koperasi Pancakarya');
defined('APP_VERSION') || define('APP_VERSION', '1.0.0');
defined('APP_URL') || define('APP_URL', 'http://localhost:8080/koperasi-pancakarya');
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Jakarta');

// Database
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', 'koperasi_pancakarya');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

// Email
defined('MAIL_FROM') || define('MAIL_FROM', 'noreply@koperasi.test');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', 'Koperasi Pancakarya');

// Session
defined('SESSION_LIFETIME') || define('SESSION_LIFETIME', 7200); // 2 hours

// Pagination
defined('PER_PAGE') || define('PER_PAGE', 20);

date_default_timezone_set(APP_TIMEZONE);

// Start session once
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
