<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_permission('collateral.manage');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'save';
$is_edit = $id > 0;

// Handle delete
if ($action === 'delete' && $is_edit) {
    $stmt = db()->prepare('SELECT loan_id FROM loan_collaterals WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $collateral = $stmt->get_result()->fetch_assoc();
    
    if (!$collateral) {
        flash('error', 'Agunan tidak ditemukan.');
        redirect('pages/loans/index.php');
    }
    
    $stmt = db()->prepare('DELETE FROM loan_collaterals WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    
    log_activity('delete_collateral', "Menghapus agunan ID {$id}");
    flash('success', 'Agunan berhasil dihapus.');
    redirect('pages/collateral/index.php?loan_id=' . $collateral['loan_id']);
}

// Handle save (create/update)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans/index.php');
}

verify_csrf();

if ($is_edit) {
    $stmt = db()->prepare('SELECT * FROM loan_collaterals WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $collateral = $stmt->get_result()->fetch_assoc();
    
    if (!$collateral) {
        flash('error', 'Agunan tidak ditemukan.');
        redirect('pages/loans/index.php');
    }
    
    $loan_id = $collateral['loan_id'];
} else {
    $loan_id = isset($_POST['loan_id']) ? (int) $_POST['loan_id'] : 0;
}

if ($loan_id <= 0) {
    flash('error', 'ID pinjaman tidak valid.');
    redirect('pages/loans/index.php');
}

$collateral_type = trim($_POST['collateral_type'] ?? '');
$description = trim($_POST['description'] ?? '');
$estimated_value = (float) ($_POST['estimated_value'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

$errors = [];
$valid_types = ['vehicle', 'property', 'electronics', 'jewelry', 'other'];

if (!in_array($collateral_type, $valid_types)) {
    $errors[] = 'Jenis agunan tidak valid.';
}
if (empty($description)) {
    $errors[] = 'Deskripsi agunan wajib diisi.';
}
if ($estimated_value <= 0) {
    $errors[] = 'Nilai estimasi harus lebih dari 0.';
}

// File upload handling
$upload_dir = __DIR__ . '/../../uploads/collateral/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$photo_path = $is_edit ? $collateral['photo_path'] : null;
$ownership_proof = $is_edit ? $collateral['ownership_proof'] : null;

// Handle photo upload
if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Gagal mengunggah foto agunan.';
    } else {
        $allowed = ['image/jpeg', 'image/jpg', 'image/png'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime, $allowed)) {
            $errors[] = 'Format foto harus JPG, JPEG, atau PNG.';
        } elseif ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Ukuran foto maksimal 5MB.';
        } else {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $filename = $loan_id . '_' . time() . '_photo.' . $ext;
            $target = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                $photo_path = 'uploads/collateral/' . $filename;
            } else {
                $errors[] = 'Gagal menyimpan foto agunan.';
            }
        }
    }
} elseif (!$is_edit) {
    $errors[] = 'Foto agunan wajib diunggah.';
}

// Handle ownership proof upload
if (isset($_FILES['ownership_proof']) && $_FILES['ownership_proof']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['ownership_proof']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Gagal mengunggah bukti kepemilikan.';
    } else {
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['ownership_proof']['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime, $allowed)) {
            $errors[] = 'Format bukti kepemilikan harus JPG, PNG, atau PDF.';
        } elseif ($_FILES['ownership_proof']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Ukuran file bukti kepemilikan maksimal 5MB.';
        } else {
            $ext = pathinfo($_FILES['ownership_proof']['name'], PATHINFO_EXTENSION);
            $filename = $loan_id . '_' . time() . '_proof.' . $ext;
            $target = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['ownership_proof']['tmp_name'], $target)) {
                $ownership_proof = 'uploads/collateral/' . $filename;
            } else {
                $errors[] = 'Gagal menyimpan bukti kepemilikan.';
            }
        }
    }
} elseif (!$is_edit) {
    $errors[] = 'Bukti kepemilikan wajib diunggah.';
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        flash('error', $error);
    }
    redirect($is_edit ? 'pages/collateral/form.php?id=' . $id : 'pages/collateral/form.php?loan_id=' . $loan_id);
}

// Save to database
if ($is_edit) {
    $stmt = db()->prepare('UPDATE loan_collaterals SET collateral_type = ?, description = ?, estimated_value = ?, photo_path = ?, ownership_proof = ?, notes = ? WHERE id = ?');
    $stmt->bind_param('ssdsssi', $collateral_type, $description, $estimated_value, $photo_path, $ownership_proof, $notes, $id);
    $stmt->execute();
    
    log_activity('update_collateral', "Mengubah agunan ID {$id} untuk pinjaman ID {$loan_id}");
    flash('success', 'Agunan berhasil diperbarui.');
} else {
    $stmt = db()->prepare('INSERT INTO loan_collaterals (loan_id, collateral_type, description, estimated_value, photo_path, ownership_proof, status, notes) VALUES (?, ?, ?, ?, ?, ?, "held", ?)');
    $stmt->bind_param('issdsss', $loan_id, $collateral_type, $description, $estimated_value, $photo_path, $ownership_proof, $notes);
    $stmt->execute();
    $new_id = db()->insert_id;
    
    log_activity('create_collateral', "Menambah agunan baru ID {$new_id} untuk pinjaman ID {$loan_id}");
    flash('success', 'Agunan berhasil ditambahkan.');
}

redirect('pages/collateral/index.php?loan_id=' . $loan_id);
