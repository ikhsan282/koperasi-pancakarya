<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

// Role check: only Anggota can access portal
$user = current_user();
if ($user['role'] !== 'Anggota') {
    redirect('pages/dashboard/index.php');
}

// Get member linked to this user
$stmt = db()->prepare("SELECT * FROM members WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

if (!$member) {
    http_response_code(403);
    exit('Data anggota tidak ditemukan atau tidak aktif.');
}

$member_id = (int) $member['id'];

// BERANDA: Total saldo simpanan
$stmt = db()->prepare("
    SELECT COALESCE(SUM(sa.balance), 0) as total_balance
    FROM savings_accounts sa
    JOIN members m ON m.id = sa.member_id
    WHERE m.user_id = ? AND sa.status = 'active'
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$total_savings = $stmt->get_result()->fetch_assoc()['total_balance'];

// BERANDA: Pinjaman aktif
$stmt = db()->prepare("
    SELECT l.*, 
           (l.amount * (1 + l.interest_rate/100)) - COALESCE(SUM(lp.amount), 0) as remaining
    FROM loans l
    JOIN members m ON m.id = l.member_id
    LEFT JOIN loan_payments lp ON lp.loan_id = l.id
    WHERE m.user_id = ? AND l.status = 'active'
    GROUP BY l.id
    ORDER BY l.disbursement_date DESC
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$active_loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// BERANDA: Angsuran jatuh tempo berikutnya (approximate - first unpaid installment)
$next_due = null;
if (!empty($active_loans)) {
    foreach ($active_loans as $loan) {
        $stmt = db()->prepare("
            SELECT COUNT(*) as paid_count
            FROM loan_payments
            WHERE loan_id = ?
        ");
        $stmt->bind_param('i', $loan['id']);
        $stmt->execute();
        $paid_count = (int) $stmt->get_result()->fetch_assoc()['paid_count'];
        
        if ($paid_count < $loan['term_months']) {
            $next_payment_number = $paid_count + 1;
            $disbursement = new DateTime($loan['disbursement_date']);
            $due_date = $disbursement->modify("+{$next_payment_number} months");
            
            if (!$next_due || $due_date < new DateTime($next_due['due_date'])) {
                $next_due = [
                    'loan_number' => $loan['loan_number'],
                    'amount' => $loan['monthly_payment'],
                    'due_date' => $due_date->format('Y-m-d'),
                    'payment_number' => $next_payment_number
                ];
            }
        }
    }
}

// BERANDA: 5 transaksi terakhir (simpanan only)
$stmt = db()->prepare("
    SELECT st.*, sa.account_number, sty.name as savings_type_name
    FROM savings_transactions st
    JOIN savings_accounts sa ON sa.id = st.savings_account_id
    JOIN savings_types sty ON sty.id = sa.savings_type_id
    JOIN members m ON m.id = sa.member_id
    WHERE m.user_id = ?
    ORDER BY st.transaction_date DESC, st.id DESC
    LIMIT 5
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$recent_transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// SIMPANAN: Rekening simpanan per jenis
$stmt = db()->prepare("
    SELECT sa.*, sty.name as type_name, sty.interest_rate
    FROM savings_accounts sa
    JOIN savings_types sty ON sty.id = sa.savings_type_id
    JOIN members m ON m.id = sa.member_id
    WHERE m.user_id = ?
    ORDER BY sty.id, sa.opened_date
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$savings_accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// SIMPANAN: Mutasi per akun (akan di-load via AJAX atau pre-load first account)
$savings_transactions = [];
if (!empty($savings_accounts)) {
    $first_account_id = (int) $savings_accounts[0]['id'];
    $stmt = db()->prepare("
        SELECT st.*
        FROM savings_transactions st
        JOIN savings_accounts sa ON sa.id = st.savings_account_id
        JOIN members m ON m.id = sa.member_id
        WHERE st.savings_account_id = ? AND m.user_id = ?
        ORDER BY st.transaction_date DESC, st.id DESC
        LIMIT 50
    ");
    $stmt->bind_param('ii', $first_account_id, $user['id']);
    $stmt->execute();
    $savings_transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// PINJAMAN: All loans with payment schedule
$stmt = db()->prepare("
    SELECT l.*
    FROM loans l
    JOIN members m ON m.id = l.member_id
    WHERE m.user_id = ?
    ORDER BY l.application_date DESC
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$all_loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Build loan payment schedules
$loan_schedules = [];
foreach ($all_loans as $loan) {
    $stmt = db()->prepare("SELECT * FROM loan_payments WHERE loan_id = ? ORDER BY payment_number");
    $stmt->bind_param('i', $loan['id']);
    $stmt->execute();
    $loan_schedules[$loan['id']] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Anggota - <?= e(APP_NAME) ?></title>
    <link rel="manifest" href="<?= url('manifest.json') ?>">
    <meta name="theme-color" content="#667eea">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
    <script>
    (function(){try{var t=localStorage.getItem('pancakarya_theme');if(!t)t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
    </script>
    <style>
        :root {
            --page-bg: #f8f9fa;
            --surface: #ffffff;
            --surface-muted: #f8f9fa;
            --border-color: #dee2e6;
            --text-color: #212529;
            --muted-color: #6c757d;
        }
        html[data-bs-theme="dark"] {
            --page-bg: #212529;
            --surface: #343a40;
            --surface-muted: #495057;
            --border-color: #495057;
            --text-color: #f8f9fa;
            --muted-color: #adb5bd;
        }
        body {
            background: var(--page-bg);
            color: var(--text-color);
            padding-bottom: 70px;
        }
        .top-bar {
            background: var(--surface);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--surface);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-around;
            padding: 0.5rem 0;
            z-index: 100;
        }
        .bottom-nav-item {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            color: var(--muted-color);
            text-decoration: none;
            transition: color 0.2s;
        }
        .bottom-nav-item.active {
            color: #667eea;
        }
        .bottom-nav-item i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }
        .bottom-nav-item span {
            font-size: 0.75rem;
        }
        .tab-content > .tab-pane {
            display: none;
        }
        .tab-content > .tab-pane.active {
            display: block;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #667eea;
        }
        .transaction-item {
            background: var(--surface);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
        }
        .badge-deposit { background: #28a745; }
        .badge-withdrawal { background: #dc3545; }
        .install-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: none;
        }
        .install-banner.show { display: block; }
    </style>
</head>
<body>
    <div class="top-bar d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><?= e(APP_NAME) ?></h5>
        <div>
            <button class="btn btn-sm btn-link dark-toggle text-decoration-none">
                <i class="bi bi-moon-stars"></i>
            </button>
            <a href="<?= url('pages/auth/logout.php') ?>" class="btn btn-sm btn-link text-decoration-none">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>

    <div id="installBanner" class="install-banner container">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <strong>Install Aplikasi</strong>
                <p class="mb-0 small">Akses lebih cepat dari layar utama</p>
            </div>
            <div>
                <button id="installBtn" class="btn btn-light btn-sm me-2">Install</button>
                <button id="dismissInstall" class="btn btn-outline-light btn-sm">Tutup</button>
            </div>
        </div>
    </div>

    <div class="container mt-3">
        <div class="tab-content">
            <!-- TAB BERANDA -->
            <div class="tab-pane active" id="tab-beranda">
                <h6 class="mb-3">Selamat datang, <?= e($member['full_name']) ?></h6>
                
                <div class="stat-card">
                    <div class="text-muted small">Total Simpanan</div>
                    <div class="stat-value"><?= rupiah($total_savings) ?></div>
                </div>

                <?php if (!empty($active_loans)): ?>
                <div class="stat-card">
                    <div class="text-muted small">Pinjaman Aktif</div>
                    <div class="stat-value"><?= count($active_loans) ?> Pinjaman</div>
                    <?php if ($next_due): ?>
                    <hr>
                    <div class="small">
                        <strong>Angsuran Berikutnya:</strong><br>
                        <?= e($next_due['loan_number']) ?> - Cicilan #<?= $next_due['payment_number'] ?><br>
                        <?= rupiah($next_due['amount']) ?> | Jatuh tempo: <?= date('d M Y', strtotime($next_due['due_date'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <h6 class="mt-4 mb-2">Transaksi Terakhir</h6>
                <?php if (empty($recent_transactions)): ?>
                <p class="text-muted">Belum ada transaksi.</p>
                <?php else: ?>
                <?php foreach ($recent_transactions as $trx): ?>
                <div class="transaction-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="badge <?= $trx['transaction_type'] === 'deposit' ? 'badge-deposit' : 'badge-withdrawal' ?>">
                                <?= $trx['transaction_type'] === 'deposit' ? 'Setor' : 'Tarik' ?>
                            </span>
                            <div class="small mt-1"><?= e($trx['savings_type_name']) ?></div>
                            <div class="small text-muted"><?= e($trx['account_number']) ?></div>
                        </div>
                        <div class="text-end">
                            <strong><?= rupiah($trx['amount']) ?></strong>
                            <div class="small text-muted"><?= date('d M Y', strtotime($trx['transaction_date'])) ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- TAB SIMPANAN -->
            <div class="tab-pane" id="tab-simpanan">
                <h6 class="mb-3">Rekening Simpanan</h6>
                <?php if (empty($savings_accounts)): ?>
                <p class="text-muted">Belum ada rekening simpanan.</p>
                <?php else: ?>
                <?php foreach ($savings_accounts as $acc): ?>
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?= e($acc['type_name']) ?></strong>
                            <div class="small text-muted"><?= e($acc['account_number']) ?></div>
                            <?php if ($acc['interest_rate'] > 0): ?>
                            <div class="small text-muted">Bunga: <?= e($acc['interest_rate']) ?>%</div>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <div class="stat-value" style="font-size: 1.25rem;"><?= rupiah($acc['balance']) ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <h6 class="mt-4 mb-2">Mutasi Terakhir</h6>
                <?php if (empty($savings_transactions)): ?>
                <p class="text-muted">Belum ada transaksi.</p>
                <?php else: ?>
                <?php foreach ($savings_transactions as $trx): ?>
                <div class="transaction-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="badge <?= $trx['transaction_type'] === 'deposit' ? 'badge-deposit' : 'badge-withdrawal' ?>">
                                <?= $trx['transaction_type'] === 'deposit' ? 'Setor' : 'Tarik' ?>
                            </span>
                            <div class="small mt-1"><?= e($trx['description'] ?? '-') ?></div>
                        </div>
                        <div class="text-end">
                            <strong><?= rupiah($trx['amount']) ?></strong>
                            <div class="small text-muted"><?= date('d M Y', strtotime($trx['transaction_date'])) ?></div>
                            <div class="small text-muted">Saldo: <?= rupiah($trx['balance_after']) ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- TAB PINJAMAN -->
            <div class="tab-pane" id="tab-pinjaman">
                <h6 class="mb-3">Daftar Pinjaman</h6>
                <?php if (empty($all_loans)): ?>
                <p class="text-muted">Belum ada pinjaman.</p>
                <?php else: ?>
                <?php foreach ($all_loans as $loan): 
                    $total_debt = $loan['amount'] * (1 + $loan['interest_rate']/100);
                    $paid = 0;
                    if (isset($loan_schedules[$loan['id']])) {
                        foreach ($loan_schedules[$loan['id']] as $pay) {
                            $paid += $pay['amount'];
                        }
                    }
                    $remaining = $total_debt - $paid;
                    
                    $status_colors = [
                        'pending' => 'warning',
                        'approved' => 'info',
                        'rejected' => 'danger',
                        'active' => 'success',
                        'paid' => 'secondary',
                        'defaulted' => 'danger'
                    ];
                    $status_labels = [
                        'pending' => 'Menunggu',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                        'active' => 'Aktif',
                        'paid' => 'Lunas',
                        'defaulted' => 'Macet'
                    ];
                ?>
                <div class="stat-card mb-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <strong><?= e($loan['loan_number']) ?></strong>
                            <span class="badge bg-<?= $status_colors[$loan['status']] ?> ms-2">
                                <?= $status_labels[$loan['status']] ?>
                            </span>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <div class="small text-muted">Pokok</div>
                            <div><?= rupiah($loan['amount']) ?></div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">Bunga</div>
                            <div><?= e($loan['interest_rate']) ?>%</div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">Tenor</div>
                            <div><?= e($loan['term_months']) ?> bulan</div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">Angsuran/bln</div>
                            <div><?= rupiah($loan['monthly_payment']) ?></div>
                        </div>
                    </div>
                    <?php if ($loan['status'] === 'active' || $loan['status'] === 'paid'): ?>
                    <hr>
                    <div class="small">
                        <strong>Progress:</strong> 
                        Terbayar <?= rupiah($paid) ?> dari <?= rupiah($total_debt) ?>
                        (sisa <?= rupiah($remaining) ?>)
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($loan_schedules[$loan['id']])): ?>
                    <hr>
                    <div class="small"><strong>Riwayat Pembayaran:</strong></div>
                    <div style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($loan_schedules[$loan['id']] as $pay): ?>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span>Cicilan #<?= e($pay['payment_number']) ?></span>
                            <span><?= rupiah($pay['amount']) ?></span>
                            <span class="text-muted"><?= date('d M Y', strtotime($pay['payment_date'])) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- TAB PROFIL -->
            <div class="tab-pane" id="tab-profil">
                <h6 class="mb-3">Data Anggota</h6>
                <div class="stat-card">
                    <?php if ($member['photo']): ?>
                    <div class="text-center mb-3">
                        <img src="<?= url($member['photo']) ?>" alt="Foto" class="rounded-circle" style="width: 100px; height: 100px; object-fit: cover;">
                    </div>
                    <?php endif; ?>
                    <table class="table table-sm">
                        <tr>
                            <td class="text-muted" style="width: 40%;">No. Anggota</td>
                            <td><strong><?= e($member['member_number']) ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Nama Lengkap</td>
                            <td><?= e($member['full_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">No. KTP</td>
                            <td><?= e($member['id_number'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Telepon</td>
                            <td><?= e($member['phone'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Email</td>
                            <td><?= e($member['email'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Alamat</td>
                            <td><?= e($member['address'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tgl Bergabung</td>
                            <td><?= date('d M Y', strtotime($member['join_date'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Status</td>
                            <td><span class="badge bg-success"><?= ucfirst($member['status']) ?></span></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="#tab-beranda" class="bottom-nav-item active" data-tab="tab-beranda">
            <i class="bi bi-house-door"></i>
            <span>Beranda</span>
        </a>
        <a href="#tab-simpanan" class="bottom-nav-item" data-tab="tab-simpanan">
            <i class="bi bi-piggy-bank"></i>
            <span>Simpanan</span>
        </a>
        <a href="#tab-pinjaman" class="bottom-nav-item" data-tab="tab-pinjaman">
            <i class="bi bi-cash-coin"></i>
            <span>Pinjaman</span>
        </a>
        <a href="#tab-profil" class="bottom-nav-item" data-tab="tab-profil">
            <i class="bi bi-person"></i>
            <span>Profil</span>
        </a>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Tab switching
    document.querySelectorAll('.bottom-nav-item').forEach(item => {
        item.addEventListener('click', e => {
            e.preventDefault();
            const tabId = item.dataset.tab;
            
            document.querySelectorAll('.bottom-nav-item').forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            
            window.scrollTo(0, 0);
        });
    });

    // Dark mode toggle
    document.addEventListener('click', e => {
        const toggle = e.target.closest('.dark-toggle');
        if (!toggle) return;
        
        const current = localStorage.getItem('pancakarya_theme') || 
                       (matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light');
        const next = current === 'dark' ? 'light' : 'dark';
        
        localStorage.setItem('pancakarya_theme', next);
        document.documentElement.setAttribute('data-bs-theme', next);
        
        toggle.querySelector('i').className = next === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
    });

    // Set initial dark mode icon
    const currentTheme = localStorage.getItem('pancakarya_theme') || 
                        (matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light');
    document.querySelector('.dark-toggle i').className = currentTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';

    // PWA Install prompt
    let deferredPrompt;
    const installBanner = document.getElementById('installBanner');
    const installBtn = document.getElementById('installBtn');
    const dismissBtn = document.getElementById('dismissInstall');

    // Check if already installed or dismissed
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const isDismissed = localStorage.getItem('pancakarya_pwa_dismissed') === '1';

    if (!isStandalone && !isDismissed) {
        // Android/Chrome/Edge
        window.addEventListener('beforeinstallprompt', e => {
            e.preventDefault();
            deferredPrompt = e;
            installBanner.classList.add('show');
        });

        // iOS Safari detection
        const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
        if (isIOS && !isStandalone) {
            installBanner.classList.add('show');
            installBtn.style.display = 'none';
            installBanner.querySelector('p').textContent = 'Ketuk ikon Share, lalu "Add to Home Screen"';
        }
    }

    installBtn?.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        const {outcome} = await deferredPrompt.userChoice;
        deferredPrompt = null;
        installBanner.classList.remove('show');
    });

    dismissBtn?.addEventListener('click', () => {
        localStorage.setItem('pancakarya_pwa_dismissed', '1');
        installBanner.classList.remove('show');
    });

    // Clear dismiss state when app is installed
    window.addEventListener('appinstalled', () => {
        localStorage.removeItem('pancakarya_pwa_dismissed');
        installBanner.classList.remove('show');
    });

    // Register service worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('<?= url('sw.js') ?>')
            .then(reg => console.log('SW registered:', reg.scope))
            .catch(err => console.error('SW registration failed:', err));
    }
    </script>
</body>
</html>
