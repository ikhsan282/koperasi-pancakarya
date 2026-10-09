<?php
declare(strict_types=1);

const APP_NAME = 'Koperasi Pancakarya';
const APP_URL = 'http://localhost/koperasi-pancakarya';
const DB_HOST = 'localhost';
const DB_NAME = 'koperasi_pancakarya';
const DB_USER = 'root';
const DB_PASS = '';
const MAIL_FROM = 'noreply@koperasi.test';

date_default_timezone_set('Asia/Jakarta');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();
