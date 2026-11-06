<?php
// Login and registration supply only their page-specific copy.
if (!isset($authPage)) {
    http_response_code(404);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#101c31">
  <title><?= fw_h($authPage['title']) ?> | Fundwise</title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/css/app.css?v=reliability-20261102" rel="stylesheet">
<link href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/css/reliability.css?v=20261102" rel="stylesheet">
<link href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/css/refinements.css?v=20261104-polish2" rel="stylesheet">
<link href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/css/auth-refinement.css?v=20261104-auth1" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/css/controls.css?v=20261105-u3">
</head>
<body class="fw-controls-v3">
  <main class="fw-auth fw-auth-refined">
    <aside class="fw-auth-side" aria-label="About Fundwise">
      <a class="fw-auth-brand" href="login.php">
        <span class="fw-brand-icon"><img src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png" alt=""></span>
        <span><strong>Fundwise</strong></span>
      </a>
      <div class="fw-auth-feature"><span class="fw-auth-eyebrow">YOUR MONEY, IN VIEW</span><h1>Track your money.<br><span>Plan ahead.</span></h1><p>Income, expenses, budgets, and savings in one place.</p><div class="fw-auth-pillars"><span><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Track daily</span><span><i class="bi bi-pie-chart" aria-hidden="true"></i>Plan spending</span><span><i class="bi bi-piggy-bank" aria-hidden="true"></i>Build savings</span></div></div>
      <div class="fw-auth-art" aria-hidden="true"><img src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png" alt=""></div>
    </aside>
    <section class="fw-auth-form-side" aria-label="<?= fw_h($authPage['section'] ?? $authPage['title']) ?>">
      <div class="fw-auth-form-wrap">
        <span class="fw-auth-kicker"><?= fw_h($authPage['kicker']) ?></span><h2><?= fw_h($authPage['heading']) ?></h2><p class="fw-auth-intro"><?= fw_h($authPage['intro']) ?></p>
