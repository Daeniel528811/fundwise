<?php
require_once "../includes/session.php";
require_once "../includes/db.php";
require_once "../includes/input.php";
require_once "../includes/reliability.php";
header("Cache-Control: private, no-store, max-age=0");

if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fw_csrf_valid()) {
        http_response_code(403);
        $message = 'Invalid form. Refresh and try again.';
    } else {
        $full_name = fw_text($_POST['full_name'] ?? null);
        $email = fw_text($_POST['email'] ?? null);
        $plain_password = $_POST['password'] ?? null;
        if ($full_name === '' || strlen($full_name) > 100 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 ||
            !is_string($plain_password) || strlen($plain_password) < 8 || strlen($plain_password) > 72 || str_contains($plain_password, "\0")) {
            $message = 'Enter a valid name and email. Password must be 8 to 72 characters.';
        } elseif (!fw_auth_attempt($conn,$email)) {
            http_response_code(429);
            $message = 'Too many attempts. Try again in 15 minutes.';
        } else {
            $defaults = [
                ['Salary', 'income'], ['Allowance', 'income'],
                ['Business', 'income'], ['Other Income', 'income'],
                ['Food', 'expense'], ['Transportation', 'expense'],
                ['Education', 'expense'], ['Bills', 'expense'],
                ['Shopping', 'expense'], ['Health', 'expense'],
                ['Entertainment', 'expense'], ['Other Expense', 'expense'],
            ];
            $started = mysqli_begin_transaction($conn);
            try {
                if (!$started) throw new RuntimeException('Transaction failed.');
                fw_query($conn,'SELECT user_id FROM users WHERE email=? FOR UPDATE','s',[$email]);
                if(fw_query($conn,'SELECT user_id FROM users WHERE email=?','s',[$email]))throw new RuntimeException('Account creation failed.');
                $hash = password_hash($plain_password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($conn, 'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)');
                if (!$stmt) throw new RuntimeException('Account creation failed.');
                mysqli_stmt_bind_param($stmt, 'sss', $full_name, $email, $hash);
                if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Account creation failed.');
                $new_user_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare($conn, 'INSERT INTO categories (user_id, category_name, category_type, is_default) VALUES (?, ?, ?, TRUE)');
                if (!$stmt) throw new RuntimeException('Category setup failed.');
                foreach ($defaults as [$name, $type]) {
                    mysqli_stmt_bind_param($stmt, 'iss', $new_user_id, $name, $type);
                    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Category setup failed.');
                }
                mysqli_stmt_close($stmt);
                $newRecovery=fw_recovery_code();
                fw_query($conn,'INSERT INTO fw_account_security(user_id,recovery_hash) VALUES (?,?)','is',[$new_user_id,fw_recovery_hash($newRecovery)]);
                fw_audit($conn,$new_user_id,'account.registered');
                if (!mysqli_commit($conn)) throw new RuntimeException('Unable to finish registration.');
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $message = 'Registration successful! Save your recovery code before logging in.';
                $_SESSION['new_account_recovery']=$newRecovery;
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $message = 'Registration failed. The email may already be registered.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#101c31">
  <title>Create an account | Fundwise</title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/css/app.css?v=reliability-20261102" rel="stylesheet">
<link href="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>/css/reliability.css?v=20261102" rel="stylesheet">
<link href="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>/css/refinements.css?v=20261104-polish2" rel="stylesheet">
<link href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/css/auth-refinement.css?v=20261104-auth1" rel="stylesheet">
</head>
<body>
  <main class="fw-auth fw-auth-refined">
    <aside class="fw-auth-side" aria-label="About Fundwise">
      <a class="fw-auth-brand" href="login.php">
        <span class="fw-brand-icon"><img src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png" alt=""></span>
        <span><strong>Fundwise</strong></span>
      </a>
      <div class="fw-auth-feature"><span class="fw-auth-eyebrow">YOUR MONEY, IN VIEW</span><h1>Track your money.<br><span>Plan ahead.</span></h1><p>Income, expenses, budgets, and savings in one place.</p><div class="fw-auth-pillars"><span><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Track daily</span><span><i class="bi bi-pie-chart" aria-hidden="true"></i>Plan spending</span><span><i class="bi bi-piggy-bank" aria-hidden="true"></i>Build savings</span></div></div>
      <div class="fw-auth-art" aria-hidden="true"><img src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png" alt=""></div>
    </aside>
    <section class="fw-auth-form-side" aria-label="Create account">
      <div class="fw-auth-form-wrap">
        <span class="fw-auth-kicker">GET STARTED</span><h2>Create your account</h2><p class="fw-auth-intro">Start with your everyday money. Build your plan from there.</p>
        <?php if ($message !== ''): ?>
          <div class="alert <?= strpos($message,'Registration successful') === 0 ? 'alert-success' : 'alert-danger' ?>" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if(isset($_SESSION['new_account_recovery'])):$savedCode=$_SESSION['new_account_recovery'];unset($_SESSION['new_account_recovery']);?><div class="alert alert-warning fw-security-message">Save this secret recovery code somewhere safe. It is shown once and is needed if you forget your password.<code class="fw-recovery-code"><?= htmlspecialchars($savedCode,ENT_QUOTES,'UTF-8') ?></code></div><?php endif;?>
        <form method="POST" autocomplete="on"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="fw-auth-field">
            <label for="fwRegisterName">Full name</label>
            <input id="fwRegisterName" type="text" name="full_name" class="form-control" placeholder="Your full name" autocomplete="name" maxlength="100" required>
          </div>
          <div class="fw-auth-field">
            <label for="fwRegisterEmail">Email address</label>
            <input id="fwRegisterEmail" type="email" name="email" class="form-control" placeholder="you@example.com" autocomplete="email" maxlength="100" required>
          </div>
          <div class="fw-auth-field">
            <label for="fwRegisterPassword">Password</label>
            <div class="fw-password-wrap">
              <input id="fwRegisterPassword" type="password" name="password" class="form-control" placeholder="Create a strong password" minlength="8" maxlength="72" autocomplete="new-password" required>
              <button type="button" class="fw-password-btn" data-fw-password-toggle="fwRegisterPassword" aria-label="Show password" title="Show password"><i class="bi bi-eye-slash" aria-hidden="true"></i></button>
            </div>
            <small class="d-block mt-2" style="color:var(--fw-muted)">Use 8–72 characters for your password.</small>
          </div>
          <button class="btn btn-primary fw-auth-submit w-100 mt-2" type="submit">Create account <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></button>
        </form>
        <p class="fw-auth-bottom">Already have an account? <a href="login.php" class="fw-bold text-decoration-none">Sign in <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></p>
      </div>
    </section>
  </main>
  <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/js/ui.js?v=20261104-auth1"></script>
</body>
</html>
