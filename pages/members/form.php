<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sequences.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$is_edit = $id > 0;

if ($is_edit) {
    require_permission('members.edit');
    $title = 'Edit Anggota';
    $stmt = db()->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    if (!$member) {
        flash('error', 'Anggota tidak ditemukan.');
        redirect('pages/members/index.php');
    }
} else {
    require_permission('members.create');
    $title = 'Tambah Anggota';
    $member = [
        'member_number' => '',
        'full_name' => '',
        'id_number' => '',
        'phone' => '',
        'email' => '',
        'address' => '',
        'join_date' => date('Y-m-d'),
        'status' => 'active'
    ];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $full_name = trim($_POST['full_name'] ?? '');
    $id_number = trim($_POST['id_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $join_date = trim($_POST['join_date'] ?? '');
    $status = trim($_POST['status'] ?? 'active');

    if (empty($full_name)) $errors[] = 'Nama lengkap wajib diisi.';
    if (empty($join_date)) $errors[] = 'Tanggal gabung wajib diisi.';

    if (empty($errors)) {
        if ($is_edit) {
            $stmt = db()->prepare('UPDATE members SET full_name = ?, id_number = ?, phone = ?, email = ?, address = ?, join_date = ?, status = ? WHERE id = ?');
            $stmt->bind_param('sssssssi', $full_name, $id_number, $phone, $email, $address, $join_date, $status, $id);
            $stmt->execute();
            log_activity('edit_member', "Mengubah data anggota ID {$id}");
            flash('success', 'Data anggota berhasil diperbarui.');
            redirect('pages/members/index.php');
        } else {
            // Generate member number atomically
            $member_number = generate_member_number();

            $stmt = db()->prepare('INSERT INTO members (member_number, full_name, id_number, phone, email, address, join_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssss', $member_number, $full_name, $id_number, $phone, $email, $address, $join_date, $status);
            $stmt->execute();
            $new_id = db()->insert_id;

            // Automatically create default savings accounts (Pokok, Wajib, Sukarela)
            $types = db()->query('SELECT id FROM savings_types WHERE is_active = 1');
            while ($t = $types->fetch_assoc()) {
                $acc_no = 'SAV-' . $new_id . '-' . $t['id'] . '-' . rand(100, 999);
                $today = date('Y-m-d');
                $acc_stmt = db()->prepare('INSERT INTO savings_accounts (member_id, savings_type_id, account_number, balance, status, opened_date) VALUES (?, ?, ?, 0.00, "active", ?)');
                $acc_stmt->bind_param('iiss', $new_id, $t['id'], $acc_no, $today);
                $acc_stmt->execute();
            }

            log_activity('create_member', "Menambah anggota baru {$member_number}");
            flash('success', 'Anggota berhasil ditambahkan.');
            redirect('pages/members/index.php');
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        
        <?php if ($is_edit): ?>
            <div class="form-group">
                <label>No. Anggota</label>
                <input type="text" value="<?= e($member['member_number']) ?>" disabled>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="full_name">Nama Lengkap *</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($member['full_name']) ?>" required>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="id_number">No. KTP / NIK</label>
                <input type="text" id="id_number" name="id_number" value="<?= e($member['id_number']) ?>">
            </div>
            <div class="form-group">
                <label for="phone">No. HP / WhatsApp</label>
                <input type="text" id="phone" name="phone" value="<?= e($member['phone']) ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($member['email']) ?>">
        </div>

        <div class="form-group">
            <label for="address">Alamat Lengkap</label>
            <textarea id="address" name="address" rows="3"><?= e($member['address']) ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="join_date">Tanggal Bergabung *</label>
                <input type="date" id="join_date" name="join_date" value="<?= e($member['join_date']) ?>" required>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active" <?= $member['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $member['status'] === 'inactive' ? 'selected' : '' ?>>Tidak Aktif</option>
                    <option value="resigned" <?= $member['status'] === 'resigned' ? 'selected' : '' ?>>Keluar</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Simpan Perubahan' : 'Tambah Anggota' ?></button>
            <a href="<?= url('pages/members/index.php') ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
