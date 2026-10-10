<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/savings_interest.php';

require_permission('savings.post_interest');

$title = 'Posting Bunga Simpanan';

$errors = [];
$preview_data = [];
$confirmed = false;

// Get current month/year as default
$default_month = (int) date('n');
$default_year = (int) date('Y');

// If last month not yet posted, suggest that
$last_month = $default_month - 1;
$last_year = $default_year;
if ($last_month < 1) {
    $last_month = 12;
    $last_year--;
}

$period_month = isset($_GET['month']) || isset($_POST['month']) 
    ? (int) ($_POST['month'] ?? $_GET['month']) 
    : $last_month;
$period_year = isset($_GET['year']) || isset($_POST['year']) 
    ? (int) ($_POST['year'] ?? $_GET['year']) 
    : $last_year;

// Preview mode
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['preview'])) {
    if ($period_month < 1 || $period_month > 12) {
        $errors[] = 'Bulan harus antara 1-12.';
    }
    if ($period_year < 2000 || $period_year > 2100) {
        $errors[] = 'Tahun tidak valid.';
    }
    
    if (empty($errors)) {
        $preview_data = get_accounts_for_interest_posting($period_month, $period_year);
    }
}

// Confirm and post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    verify_csrf();
    
    if ($period_month < 1 || $period_month > 12) {
        $errors[] = 'Bulan harus antara 1-12.';
    }
    if ($period_year < 2000 || $period_year > 2100) {
        $errors[] = 'Tahun tidak valid.';
    }
    
    if (empty($errors)) {
        $accounts = get_accounts_for_interest_posting($period_month, $period_year);
        $user_id = current_user()['id'];
        
        db()->begin_transaction();
        try {
            $posted_count = 0;
            $skipped_count = 0;
            $total_interest = 0.0;
            
            foreach ($accounts as $acc) {
                if ($acc['already_posted']) {
                    $skipped_count++;
                    continue;
                }
                
                if ($acc['interest_amount'] <= 0) {
                    $skipped_count++;
                    continue;
                }
                
                $success = post_interest_for_account(
                    (int) $acc['id'], 
                    $period_month, 
                    $period_year, 
                    $user_id
                );
                
                if ($success) {
                    $posted_count++;
                    $total_interest += $acc['interest_amount'];
                }
            }
            
            db()->commit();
            
            $month_name = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                           'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$period_month];
            
            log_activity('savings_interest_posted', 
                "Posted bunga {$month_name} {$period_year}: {$posted_count} accounts, total " . rupiah($total_interest));
            
            flash('success', "Berhasil posting bunga untuk {$posted_count} rekening. Total bunga: " . rupiah($total_interest) . 
                  ($skipped_count > 0 ? " ({$skipped_count} rekening dilewati)" : ''));
            redirect('pages/savings/interest_history.php?month=' . $period_month . '&year=' . $period_year);
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Gagal posting bunga: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <h3>Posting Bunga Simpanan</h3>
    <p>Posting bunga bulanan untuk semua rekening simpanan yang memiliki bunga.</p>
    
    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <form method="get" class="form" style="margin-bottom: 2rem;">
        <div class="form-row">
            <div class="form-group">
                <label for="month">Bulan</label>
                <select id="month" name="month" required>
                    <?php 
                    $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                               'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    for ($m = 1; $m <= 12; $m++): 
                    ?>
                        <option value="<?= $m ?>" <?= $period_month === $m ? 'selected' : '' ?>>
                            <?= $months[$m] ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="year">Tahun</label>
                <select id="year" name="year" required>
                    <?php for ($y = $default_year; $y >= $default_year - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $period_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group" style="align-self: flex-end;">
                <button type="submit" name="preview" value="1" class="btn btn-secondary">Preview</button>
            </div>
        </div>
    </form>
</div>

<?php if (!empty($preview_data)): ?>
<div class="card">
    <h3>Preview: 
        <?php 
        $month_name = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                       'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$period_month];
        echo "{$month_name} {$period_year}";
        ?>
    </h3>
    
    <?php
    $total_preview = 0.0;
    $count_to_post = 0;
    $count_already = 0;
    foreach ($preview_data as $acc) {
        if ($acc['already_posted']) {
            $count_already++;
        } elseif ($acc['interest_amount'] > 0) {
            $count_to_post++;
            $total_preview += $acc['interest_amount'];
        }
    }
    ?>
    
    <p><strong>Ringkasan:</strong></p>
    <ul>
        <li>Akan diposting: <strong><?= $count_to_post ?> rekening</strong>, total bunga: <strong><?= rupiah($total_preview) ?></strong></li>
        <?php if ($count_already > 0): ?>
            <li>Sudah diposting: <?= $count_already ?> rekening (akan dilewati)</li>
        <?php endif; ?>
        <li>Tanggal posting: <strong><?= date('d/m/Y') ?></strong></li>
    </ul>
    
    <table class="table">
        <thead>
            <tr>
                <th>No. Rekening</th>
                <th>Anggota</th>
                <th>Jenis</th>
                <th>Saldo</th>
                <th>Rate (%)</th>
                <th>Bunga</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($preview_data as $acc): ?>
                <tr <?= $acc['already_posted'] ? 'style="opacity: 0.5;"' : '' ?>>
                    <td><?= e($acc['account_number']) ?></td>
                    <td><?= e($acc['member_number']) ?> - <?= e($acc['member_name']) ?></td>
                    <td><?= e($acc['type_name']) ?></td>
                    <td><?= rupiah($acc['balance']) ?></td>
                    <td><?= number_format($acc['interest_rate'], 2) ?>%</td>
                    <td><?= rupiah($acc['interest_amount']) ?></td>
                    <td>
                        <?php if ($acc['already_posted']): ?>
                            <span class="badge badge-secondary">Sudah Posted</span>
                        <?php elseif ($acc['interest_amount'] > 0): ?>
                            <span class="badge badge-success">Siap</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Skip</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5" style="text-align: right;">Total yang akan diposting:</th>
                <th><?= rupiah($total_preview) ?></th>
                <th></th>
            </tr>
        </tfoot>
    </table>
    
    <?php if ($count_to_post > 0): ?>
        <form method="post" style="margin-top: 1rem;">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="month" value="<?= $period_month ?>">
            <input type="hidden" name="year" value="<?= $period_year ?>">
            <button type="submit" name="confirm" value="1" class="btn btn-primary" 
                    onclick="return confirm('Yakin posting bunga untuk <?= $count_to_post ?> rekening (total <?= rupiah($total_preview) ?>)?');">
                ✓ Konfirmasi & Posting Bunga
            </button>
        </form>
    <?php else: ?>
        <p class="text-center" style="margin-top: 1rem; color: #666;">
            Tidak ada rekening yang perlu diposting bunga.
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
