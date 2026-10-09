<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah(float|int|string $amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('Sesi formulir kedaluwarsa. Silakan muat ulang halaman.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = compact('type', 'message');
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function old(string $key, mixed $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

function log_activity(string $action, string $description = ''): void
{
    $user_id = $_SESSION['user_id'] ?? null;
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $stmt = db()->prepare('INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $user_id, $action, $description, $ip);
    $stmt->execute();
}

function period_label(string $period): string
{
    $date = DateTime::createFromFormat('Y-m', $period);
    $months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    return $date ? $months[(int) $date->format('n')] . ' ' . $date->format('Y') : $period;
}
