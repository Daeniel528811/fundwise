<?php
// Grouped navigation: same destinations and access as the previous menu.
$__path = $_SERVER['SCRIPT_NAME'] ?? '';
if (BASE_URL !== '' && strpos($__path, BASE_URL) === 0) {
    $__path = substr($__path, strlen(BASE_URL));
}
$__navSections = [
    'Main menu' => [
        ['Dashboard','/index.php','bi-grid-1x2','/index.php'],
        ['Transactions','/transactions/','bi-arrow-left-right','/transactions/index.php'],
        ['Categories','/categories/','bi-grid','/categories/index.php'],
        ['Budgets','/budgets/','bi-pie-chart','/budgets/index.php'],
    ],
    'Plan & grow' => [
        ['Savings Goals','/savings/','bi-bullseye','/savings/index.php'],
        ['Savings Challenges','/savings/challenges.php','bi-trophy','/savings/challenges.php'],
        ['Savings Allocation','/savings/allocations.php','bi-diagram-3','/savings/allocations.php'],
    ],
    'Financial intelligence' => [
        ['Money Lab','/insights/money-lab.php','bi-flask','/insights/money-lab.php'],
        ['Purchase Map','/insights/purchase-map.php','bi-signpost-split','/insights/purchase-map.php'],
        ['Real Earnings Lens','/insights/earnings.php','bi-briefcase','/insights/earnings.php'],
    ],
    'Insights & tools' => [
        ['Reports','/reports/','bi-bar-chart-line','/reports/index.php'],
        ['Currency Converter','/currency/','bi-arrow-left-right','/currency/index.php'],
        ['Notifications','/notifications/','bi-bell','/notifications/index.php'],
        ['Profile & Settings','/profile/','bi-gear','/profile/index.php'],
    ],
];
?>
<a class="fw-brand" href="<?= $__esc(BASE_URL) ?>/index.php" aria-label="Fundwise home">
    <span class="fw-brand-icon"><img src="<?= $__esc(BASE_URL) ?>/assets/fundwise-mark.png" alt="" loading="eager"></span>
    <span><span class="fw-brand-name">Fundwise<span style="color:#33d5df">.</span></span><span class="fw-brand-tag">YOUR MONEY, SIMPLIFIED</span></span>
</a>
<nav class="fw-nav" aria-label="Fundwise sections">
    <?php foreach ($__navSections as $__group => $__links): ?>
        <span class="fw-nav-label"><?= $__esc($__group) ?></span>
        <?php foreach ($__links as [$label, $href, $icon, $match]): ?>
            <?php $active = ($__path === $match) || ($label === 'Dashboard' && $__path === '/'); ?>
            <a href="<?= $__esc(BASE_URL . $href) ?>" class="fw-nav-link <?= $active ? 'active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                <i class="bi <?= $__esc($icon) ?>" aria-hidden="true"></i>
                <span class="fw-nav-text"><?= $__esc($label) ?></span>
                <?php if ($label === 'Notifications' && $__unread > 0): ?><span class="fw-badge"><?= $__unread > 99 ? '99+' : $__unread ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>
</nav>
<div class="fw-nav-bottom">
    <a class="fw-sidebar-profile" href="<?= $__esc(BASE_URL) ?>/profile/">
        <span class="fw-avatar" aria-hidden="true"><?= $__esc($__initial) ?></span>
        <span class="fw-side-details"><strong><?= $__esc($__name) ?></strong><small>Personal account</small></span>
        <i class="bi bi-three-dots" style="color:#8ea7c5" aria-hidden="true"></i>
    </a>
    <form method="post" action="<?= $__esc(BASE_URL . '/auth/logout.php') ?>">
        <input type="hidden" name="csrf_token" value="<?= $__esc($_SESSION['csrf_token']) ?>">
        <button type="submit" class="fw-nav-link fw-nav-logout text-start">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="fw-nav-text">Log out</span>
        </button>
    </form>
</div>
