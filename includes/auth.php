<?php
declare(strict_types=1);

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        flash('warning', 'Silakan masuk terlebih dahulu.');
        redirect('pages/auth/login.php');
    }
}

function current_user(): array
{
    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['role'] ?? '',
    ];
}

function can(string $permission): bool
{
    return in_array($permission, $_SESSION['permissions'] ?? [], true);
}

function require_permission(string $permission): void
{
    require_login();
    if (!can($permission)) {
        http_response_code(403);
        exit('Anda tidak memiliki izin untuk mengakses halaman ini.');
    }
}
