<?php
// Sidebar navigation - extracted from header.php
$current_url = $_SERVER['REQUEST_URI'] ?? '';
?>
<aside class="sidebar">
    <a class="brand" href="<?= url('pages/dashboard/index.php') ?>">
        <span>KP</span>
        <strong>Koperasi<br>Pancakarya</strong>
    </a>
    <nav>
        <a href="<?= url('pages/dashboard/index.php') ?>">▦ Dashboard</a>
        <?php if (can('members.view')): ?><a href="<?= url('pages/members/index.php') ?>">♙ Anggota</a><?php endif; ?>
        <?php if (can('savings.view')): ?>
            <a href="<?= url('pages/savings/index.php') ?>">◉ Simpanan</a>
            <?php if (can('savings.post_interest')): ?><a href="<?= url('pages/savings/interest_posting.php') ?>" style="padding-left:2rem;font-size:0.9em">├ Posting Bunga</a><?php endif; ?>
            <a href="<?= url('pages/savings/interest_history.php') ?>" style="padding-left:2rem;font-size:0.9em">└ Riwayat Bunga</a>
        <?php endif; ?>
        <?php if (can('loans.view')): ?><a href="<?= url('pages/loans/index.php') ?>">◇ Pinjaman</a><?php endif; ?>
        <?php if (can('cashbank.view')): ?><a href="<?= url('pages/cashbank/accounts.php') ?>">💰 Kas & Bank</a><?php endif; ?>
        <?php if (can('loans.send_reminder')): ?><a href="<?= url('pages/loans/reminders.php') ?>">📧 Pengingat</a><?php endif; ?>
        <?php if (can('notifications.view')): ?><a href="<?= url('pages/notifications/index.php') ?>">🔔 Notifikasi</a><?php endif; ?>
        <?php if (can('reports.view')): ?><a href="<?= url('pages/reports/index.php') ?>">▤ Laporan</a><?php endif; ?>
        <?php if (can('shu.view')): ?><a href="<?= url('pages/shu/index.php') ?>">% SHU</a><?php endif; ?>
        <?php if (can('settings.manage')): ?><a href="<?= url('pages/settings/index.php') ?>">⚙ Pengaturan</a><?php endif; ?>
    </nav>
    <div class="sidebar-user">
        <small>Masuk sebagai</small>
        <strong><?= e(current_user()['name']) ?></strong>
        <form method="POST" action="<?= url('pages/auth/logout.php') ?>" style="display:inline;margin:0">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <button type="submit" style="border:0;background:none;color:inherit;text-decoration:underline;cursor:pointer;padding:0;font:inherit">Keluar</button>
        </form>
    </div>
</aside>
