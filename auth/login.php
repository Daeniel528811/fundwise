<?php
require_once "../includes/session.php";
require_once "../includes/db.php";
require_once "../includes/input.php";
require_once "../includes/reliability.php";
header("Cache-Control: private, no-store, max-age=0");

if (isset($_SESSION["user_id"])) {
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fw_csrf_valid()) {
        http_response_code(403);
        $message = "Invalid form. Please refresh and try again.";
    } else {
        $email = fw_text($_POST['email'] ?? null);
        $password = $_POST['password'] ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password)) {
            $message = "Invalid email or password.";
        } elseif (!fw_auth_attempt($conn,$email)) {
            http_response_code(429);
            $message = 'Too many attempts. Try again in 15 minutes.';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, password FROM users WHERE email = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if ($account && password_verify($password, $account['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $account['user_id'];
                $_SESSION['credential_version']=fw_security_version($conn,(int)$account['user_id']);
                $_SESSION['authenticated_at']=time();$_SESSION['last_activity']=time();
                fw_auth_success($conn,$email);fw_audit($conn,(int)$account['user_id'],'login.success');
                $_SESSION['full_name'] = $account['full_name'];
                $_SESSION['email'] = $account['email'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header("Location: " . BASE_URL . "/index.php");
                exit();
            }
            $message = "Invalid email or password.";
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
  <title>Sign in | Fundwise</title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/css/app.css?v=reliability-20261102" rel="stylesheet">
<link href="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>/css/reliability.css?v=20261102" rel="stylesheet">
<link href="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>/css/refinements.css?v=20261104-polish2" rel="stylesheet">
</head>
<body>
  <main class="fw-auth">
    <aside class="fw-auth-side" aria-label="About Fundwise">
      <a class="fw-auth-brand" href="login.php">
        <span class="fw-brand-icon"><img src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png" alt=""></span>
        <span><strong>Fundwise</strong></span>
      </a>
      <div class="fw-auth-feature"><h1>Track your money.<br><span>Plan ahead.</span></h1><p>Income, expenses, budgets, and savings in one place.</p></div>
      <div class="fw-auth-art" aria-hidden="true"><img src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png" alt=""></div>
    </aside>
    <section class="fw-auth-form-side" aria-label="Sign in">
      <div class="fw-auth-form-wrap">
        <h2>Sign in to Fundwise</h2>
        <?php if ($message !== ''): ?>
          <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-circle me-2" aria-hidden="true"></i><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if(isset($_GET['expired'])):?><div class="alert alert-info">Your session expired or your credentials changed. Please sign in again.</div><?php endif;?>
<?php if(isset($_SESSION['recovery_notice'])):$savedCode=$_SESSION['recovery_notice'];unset($_SESSION['recovery_notice']);?><div class="alert alert-success fw-security-message">Password reset. Save this replacement recovery code now; it is shown once.<code class="fw-recovery-code"><?= htmlspecialchars($savedCode,ENT_QUOTES,'UTF-8') ?></code></div><?php endif;?>
<form method="POST" autocomplete="on"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="fw-auth-field">
            <label for="fwLoginEmail">Email address</label>
            <input id="fwLoginEmail" type="email" name="email" class="form-control" placeholder="you@example.com" autocomplete="username" required autofocus>
          </div>
          <div class="fw-auth-field">
            <label for="fwLoginPassword">Password</label>
            <div class="fw-password-wrap">
              <input id="fwLoginPassword" type="password" name="password" class="form-control" placeholder="Enter your password" autocomplete="current-password" required>
              <button type="button" class="fw-password-btn" data-fw-password-toggle="fwLoginPassword" aria-label="Show password" title="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
          </div>
          <button class="btn btn-primary fw-auth-submit w-100 mt-2" type="submit">Sign in <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></button>
        </form>
        <a class="fw-security-link" href="recover.php">Forgot password? Use your recovery code</a>
        <p class="fw-auth-bottom">New to Fundwise? <a href="register.php" class="fw-bold text-decoration-none">Create an account <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></p>
      </div>
    </section>
  </main>
  <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/js/ui.js?v=20261104-polish2"></script>
</body>
</html>
