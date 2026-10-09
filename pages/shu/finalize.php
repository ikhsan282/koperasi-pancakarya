<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/shu_tools.php';

require_permission('shu.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/shu/index.php');
}
verify_csrf();

$db = db();
$fiscal_year = (int) ($_POST['fiscal_year'] ?? 0);
$total_shu = (int) ($_POST['total_shu'] ?? 0);
$pct_modal = (int) ($_POST['pct_jasa_modal'] ?? -1);
$adjustments = [];
foreach ($_POST['adjustment'] ?? [] as $mid => $val) {
    $adjustments[(int) $mid] = (int) $val;
}

try {
    if ($fiscal_year < 2000 || $fiscal_year > 2100 || $total_shu <= 0 || $pct_modal < 0 || $pct_modal > 100) {
        throw new InvalidArgumentException('Parameter SHU tidak valid.');
    }

    // Recompute server-side from the database; posted bases are never trusted.
    $bases = shu_member_bases($db, $fiscal_year);
    $dist = apply_shu_adjustments(calculate_shu_distribution($total_shu, $pct_modal, $bases), $adjustments);
    $names = array_column($bases, null, 'member_id');

    $db->begin_transaction();
    $stmt = $db->prepare("INSERT INTO shu_periods (fiscal_year, total_shu, pct_jasa_modal, status, finalized_at, finalized_by)
        VALUES (?, ?, ?, 'finalized', NOW(), ?)");
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $stmt->bind_param('idii', $fiscal_year, $total_shu, $pct_modal, $uid);
    $stmt->execute();
    $period_id = (int) $db->insert_id;

    $stmt = $db->prepare('INSERT INTO shu_distributions (period_id, member_id, savings_base, loan_base, jasa_modal, jasa_anggota, adjustment, total_shu)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($dist as $d) {
        $mid = $d['member_id'];
        $sb = $names[$mid]['savings_base'];
        $lb = $names[$mid]['loan_base'];
        $stmt->bind_param('iiiiiiii', $period_id, $mid, $sb, $lb, $d['jasa_modal'], $d['jasa_anggota'], $d['adjustment'], $d['total_shu']);
        $stmt->execute();
    }
    $db->commit();

    log_activity('finalize_shu', "Finalisasi SHU {$fiscal_year}: " . rupiah($total_shu) . ' (' . count($dist) . ' anggota)');
    flash('success', 'Distribusi SHU tahun ' . $fiscal_year . ' berhasil difinalisasi.');
    redirect('pages/shu/breakdown.php?id=' . $period_id);
} catch (mysqli_sql_exception $e) {
    $db->rollback();
    $msg = $e->getCode() === 1062 ? 'SHU tahun tersebut sudah pernah difinalisasi.' : 'Gagal menyimpan distribusi SHU.';
    error_log('SHU finalize failed: ' . $e->getMessage());
    flash('error', $msg);
    redirect('pages/shu/index.php');
} catch (InvalidArgumentException $e) {
    flash('error', $e->getMessage());
    redirect('pages/shu/index.php');
}
