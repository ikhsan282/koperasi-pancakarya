<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    redirect('pages/dashboard/index.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username)) $errors[] = 'Username wajib diisi.';
    if (empty($password)) $errors[] = 'Password wajib diisi.';

    if (empty($errors)) {
        $stmt = db()->prepare('SELECT u.id, u.username, u.password, u.full_name, u.is_active, r.name AS role_name 
                               FROM users u 
                               JOIN roles r ON u.role_id = r.id 
                               WHERE u.username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!$user) {
            $errors[] = 'Username atau password salah.';
        } elseif (!$user['is_active']) {
            $errors[] = 'Akun Anda tidak aktif. Hubungi administrator.';
        } elseif (!password_verify($password, $user['password'])) {
            $errors[] = 'Username atau password salah.';
        } else {
            // Load permissions
            $stmt = db()->prepare('SELECT p.name 
                                   FROM permissions p
                                   JOIN role_permissions rp ON p.id = rp.permission_id
                                   JOIN users u ON u.role_id = rp.role_id
                                   WHERE u.id = ?');
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $perms = $stmt->get_result();
            $permissions = [];
            while ($perm = $perms->fetch_assoc()) {
                $permissions[] = $perm['name'];
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role_name'];
            $_SESSION['permissions'] = $permissions;

            // Update last login
            $stmt = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();

            log_activity('login', 'User berhasil masuk');
            redirect('pages/dashboard/index.php');
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Masuk · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= url('public/css/app.css') ?>">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>Koperasi Pancakarya</h1>
                <p>Sistem Manajemen Koperasi</p>
            </div>
            <?php if ($errors): ?>
                <div class="alert error">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" class="auth-form">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?= old('username') ?>" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Masuk</button>
            </form>
            <div class="auth-footer">
                <small>Default: admin / Admin@123</small>
            </div>
        </div>
    </div>
</body>
</html>
