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
            !fw_password_valid($plain_password)) {
            $message = 'Enter a valid name and email. Password must be 8 to 72 characters.';
        } elseif (!fw_auth_attempt($conn, $email)) {
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
                if (!$started) {
                    throw new RuntimeException('Transaction failed.');
                }
                fw_query($conn, 'SELECT user_id FROM users WHERE email=? FOR UPDATE', 's', [$email]);
                if (fw_query($conn, 'SELECT user_id FROM users WHERE email=?', 's', [$email])) {
                    throw new RuntimeException('Account creation failed.');
                }
                $hash = password_hash($plain_password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($conn, 'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)');
                if (!$stmt) {
                    throw new RuntimeException('Account creation failed.');
                }
                mysqli_stmt_bind_param($stmt, 'sss', $full_name, $email, $hash);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException('Account creation failed.');
                }
                $new_user_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare($conn, 'INSERT INTO categories (user_id, category_name, category_type, is_default) VALUES (?, ?, ?, TRUE)');
                if (!$stmt) {
                    throw new RuntimeException('Category setup failed.');
                }
                foreach ($defaults as [$name, $type]) {
                    mysqli_stmt_bind_param($stmt, 'iss', $new_user_id, $name, $type);
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new RuntimeException('Category setup failed.');
                    }
                }
                mysqli_stmt_close($stmt);
                $newRecovery = fw_recovery_code();
                fw_query($conn, 'INSERT INTO fw_account_security(user_id,recovery_hash) VALUES (?,?)', 'is', [$new_user_id, fw_recovery_hash($newRecovery)]);
                fw_audit($conn, $new_user_id, 'account.registered');
                if (!mysqli_commit($conn)) {
                    throw new RuntimeException('Unable to finish registration.');
                }
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $message = 'Registration successful! Save your recovery code before logging in.';
                $_SESSION['new_account_recovery'] = $newRecovery;
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $message = 'Registration failed. The email may already be registered.';
            }
        }
    }
}
?>

<?php
$authPage = ['title' => 'Create an account', 'section' => 'Create account', 'kicker' => 'GET STARTED', 'heading' => 'Create your account', 'intro' => 'Start with your everyday money. Build your plan from there.'];
require __DIR__ . '/../includes/auth_page_start.php';
?>
        <?php if ($message !== ''): ?>
          <div class="alert <?= strpos($message, 'Registration successful') === 0 ? 'alert-success' : 'alert-danger' ?>" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['new_account_recovery'])):$savedCode = $_SESSION['new_account_recovery'];
            unset($_SESSION['new_account_recovery']); ?><div class="alert alert-warning fw-security-message">Save this secret recovery code somewhere safe. It is shown once and is needed if you forget your password.<code class="fw-recovery-code"><?= htmlspecialchars($savedCode, ENT_QUOTES, 'UTF-8') ?></code></div><?php endif; ?>
        <form method="POST" autocomplete="on"><?php fw_form_tokens(); ?>
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
            <?php fw_password_field('fwRegisterPassword', 'password', ['placeholder' => 'Create a strong password', 'minlength' => '8', 'maxlength' => '72', 'autocomplete' => 'new-password', 'required' => true]); ?>
            <small class="d-block mt-2" style="color:var(--fw-muted)">Use 8–72 characters for your password.</small>
          </div>
          <button class="btn btn-primary fw-auth-submit w-100 mt-2" type="submit">Create account <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></button>
        </form>
        <p class="fw-auth-bottom">Already have an account? <a href="login.php" class="fw-bold text-decoration-none">Sign in <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></p>
<?php require __DIR__ . '/../includes/auth_page_end.php'; ?>
