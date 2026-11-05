<?php
// Shared chrome. Existing pages set $pageTitle/$pageHead before this include.
// Data access, authentication, and application behavior remain unchanged.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit();
}

$__uid = (int) $_SESSION['user_id'];
$__theme = 'light';
$__unread = 0;
$__name = $_SESSION['full_name'] ?? 'User';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$__stmt = mysqli_prepare($conn, 'SELECT full_name, theme FROM users WHERE user_id = ?');
if ($__stmt) {
    mysqli_stmt_bind_param($__stmt, 'i', $__uid);
    mysqli_stmt_execute($__stmt);
    $__row = mysqli_fetch_assoc(mysqli_stmt_get_result($__stmt));
    mysqli_stmt_close($__stmt);
    if ($__row) {
        $__name = $__row['full_name'];
        $__theme = ($__row['theme'] === 'dark') ? 'dark' : 'light';
    }
}
$__stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0');
if ($__stmt) {
    mysqli_stmt_bind_param($__stmt, 'i', $__uid);
    mysqli_stmt_execute($__stmt);
    $__row = mysqli_fetch_assoc(mysqli_stmt_get_result($__stmt));
    mysqli_stmt_close($__stmt);
    $__unread = (int) ($__row['total'] ?? 0);
}

$pageTitle = $pageTitle ?? 'Dashboard';
$pageHead = $pageHead ?? '';
$__initial = strtoupper(substr(trim((string) $__name), 0, 1));
$__initial = preg_match('/^[A-Z]$/', $__initial) ? $__initial : 'F';
$__today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('M d, Y');
$__esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $__esc($__theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#101c31">
    <meta name="csrf-token" content="<?= $__esc($_SESSION['csrf_token']) ?>">
    <meta name="base-url" content="<?= $__esc(BASE_URL) ?>">
    <title><?= $__esc($pageTitle) ?> | Fundwise</title>
    <link rel="icon" type="image/png" href="<?= $__esc(BASE_URL) ?>/assets/fundwise-mark.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?= $pageHead ?>
    <link href="<?= $__esc(BASE_URL) ?>/css/app.css?v=features-20261031" rel="stylesheet">
</head>
<body>
<a class="fw-skip" href="#fw-content">Skip to content</a>
<div class="fw-shell">
    <aside class="offcanvas-lg offcanvas-start fw-sidebar" tabindex="-1" id="fwSidebar" aria-label="Main navigation">
        <div class="offcanvas-header d-lg-none">
            <span class="fw-brand-name">Fundwise</span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#fwSidebar" aria-label="Close navigation"></button>
        </div>
        <div class="offcanvas-body fw-sidebar-body">
            <?php require __DIR__ . '/sidebar.php'; ?>
        </div>
    </aside>
    <div class="fw-body">
        <header class="fw-topbar">
            <button class="btn fw-icon-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#fwSidebar" aria-controls="fwSidebar" aria-label="Open menu">
                <i class="bi bi-list fs-5" aria-hidden="true"></i>
            </button>
            <div class="fw-topbar-context">
                <span class="fw-crumb">Workspace <i class="bi bi-chevron-right" aria-hidden="true"></i> Personal finance</span>
                <span class="fw-topbar-title"><?= $__esc($pageTitle) ?></span>
            </div>
            <div class="fw-topbar-actions">
                <span class="fw-topbar-date"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i><?= $__esc($__today) ?></span>
                <a href="<?= $__esc(BASE_URL) ?>/transactions/#transactionForm" class="btn btn-primary fw-add-btn" title="Go to transactions" aria-label="Add transaction on Transactions page">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i><span class="fw-quick-add-label">Add transaction</span>
                </a>
                <a href="<?= $__esc(BASE_URL) ?>/notifications/" class="btn fw-icon-btn position-relative" aria-label="Notifications<?= $__unread ? ' (' . $__unread . ' unread)' : '' ?>" title="Notifications">
                    <i class="bi bi-bell" aria-hidden="true"></i>
                    <?php if ($__unread > 0): ?><span class="fw-badge fw-bell-badge"><?= $__unread > 99 ? '99+' : $__unread ?></span><?php endif; ?>
                </a>
                <button class="btn fw-icon-btn" type="button" id="themeToggle" aria-label="Switch color theme" title="Toggle dark mode">
                    <i class="bi bi-moon-stars" id="themeIcon" aria-hidden="true"></i>
                </button>
                <a class="fw-topbar-profile" href="<?= $__esc(BASE_URL) ?>/profile/" aria-label="Open profile and settings" title="Profile & settings">
                    <span><span class="fw-topbar-profile-name d-block"><?= $__esc($__name) ?></span><span class="fw-topbar-profile-role d-block">My account</span></span>
                    <span class="fw-avatar" aria-hidden="true"><?= $__esc($__initial) ?></span>
                </a>
            </div>
        </header>
        <main class="fw-main" id="fw-content"><div class="fw-content">
