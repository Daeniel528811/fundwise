<?php
require_once __DIR__ . '/../includes/auth_check.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Use the security form in Settings.');
}
$uid = (int) $_SESSION['user_id'];
$action = fw_text($_POST['action'] ?? null);
$current = $_POST['current_password'] ?? null;
$new = $_POST['new_password'] ?? null;
$confirm = $_POST['confirm_password'] ?? null;
$error = '';
if (!is_string($current) || !in_array($action, ['change_password', 'recovery_code'], true)) {
    $error = 'Invalid security request.';
} elseif ($action === 'change_password' && (!fw_password_valid($new) || !is_string($confirm) || $new !== $confirm)) {
    $error = 'Use matching new passwords of 8–72 characters.';
} elseif ($action === 'change_password' && $new === $current) {
    $error = 'Choose a new password different from your current one.';
}
if ($error === '') {
    if (!fw_auth_attempt($conn, 'settings:' . $uid)) {
        $error = 'Too many attempts. Try again in 15 minutes.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $row = fw_query($conn, 'SELECT password FROM users WHERE user_id=? FOR UPDATE', 'i', [$uid])[0] ?? null;
            if (!$row || !password_verify($current, $row['password'])) {
                throw new InvalidArgumentException('Your current password is incorrect.');
            }
            $code = fw_recovery_code();
            $hash = fw_recovery_hash($code);
            if ($action === 'change_password') {
                fw_query($conn, 'UPDATE users SET password=? WHERE user_id=?', 'si', [password_hash($new, PASSWORD_DEFAULT), $uid]);
                fw_query($conn, 'INSERT INTO fw_account_security(user_id,credential_version,recovery_hash) VALUES (?,1,?) ON DUPLICATE KEY UPDATE credential_version=credential_version+1,recovery_hash=VALUES(recovery_hash)', 'is', [$uid, $hash]);
            } else {
                fw_query($conn, 'INSERT INTO fw_account_security(user_id,recovery_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE recovery_hash=VALUES(recovery_hash)', 'is', [$uid, $hash]);
            }
            fw_audit($conn, $uid, $action === 'change_password' ? 'password.changed' : 'recovery_code.rotated');
            $version = fw_security_version($conn, $uid);
            if (!mysqli_commit($conn)) {
                throw new RuntimeException('Unable to save.');
            }
            session_regenerate_id(true);
            $_SESSION['credential_version'] = $version;
            $_SESSION['authenticated_at'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
            unset($_SESSION['notification_csrf']);
            $_SESSION['security_flash'] = ['ok' => true, 'text' => $action === 'change_password' ? 'Password changed. Other sessions have been signed out. Save your new recovery code below.' : 'A new recovery code was generated. The previous code no longer works.', 'code' => $code];
            header('Location: index.php#accountSecurity');
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to update account security. Please try again.';
        }
    }
}
$_SESSION['security_flash'] = ['ok' => false, 'text' => $error];
header('Location: index.php#accountSecurity');
exit;
