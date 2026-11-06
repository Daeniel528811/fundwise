<?php
require_once __DIR__ . '/input.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/general.php';
function fw_text_length(string $value): int
{
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
}

function fw_query(mysqli $conn, string $sql, string $types = '', array $args = []): array
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException('Database operation could not be prepared.');
    }
    try {
        if ($types !== '' && !mysqli_stmt_bind_param($stmt, $types, ...$args)) {
            throw new RuntimeException('Database parameters rejected.');
        }
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Database operation failed.');
        }
        $result = mysqli_stmt_get_result($stmt);
        return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    } finally {
        mysqli_stmt_close($stmt);
    }
}
function fw_security_version(mysqli $conn, int $uid): int
{
    $rows = fw_query($conn, 'SELECT credential_version FROM fw_account_security WHERE user_id=?', 'i', [$uid]);
    return (int) ($rows[0]['credential_version'] ?? 0);
}
function fw_audit(mysqli $conn, int $uid, string $event, ?int $entity = null): void
{
    fw_query($conn, 'INSERT INTO fw_audit_log(user_id,event_name,entity_id) VALUES (?,?,?)', 'isi', [$uid, $event, $entity]);
}
function fw_revision(mysqli $conn, int $uid, string $entity, int $id, string $event, ?array $before, ?array $after): void
{
    $encode = static fn ($value) => $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    fw_query($conn, 'INSERT INTO fw_record_history(user_id,entity_type,entity_id,event_name,before_data,after_data) VALUES (?,?,?,?,?,?)', 'isisss', [$uid, $entity, $id, $event, $encode($before), $encode($after)]);
}
function fw_execute_transaction_change(mysqli $conn, mysqli_stmt $stmt, int $uid, int $id, ?array $before, bool $deleting = false): bool
{
    if (!mysqli_begin_transaction($conn)) {
        return false;
    }
    try {
        fw_query($conn, 'SELECT user_id FROM users WHERE user_id=? FOR UPDATE', 'i', [$uid]);
        if ($id > 0) {
            $before = fw_query($conn, 'SELECT * FROM transactions WHERE transaction_id=? AND user_id=? FOR UPDATE', 'ii', [$id, $uid])[0] ?? null;
            if (!$before) {
                throw new RuntimeException('Transaction is unavailable.');
            }
            if (fw_has_table($conn, 'fw_spending_labels')) {
                $before['spending_label'] = fw_query($conn, 'SELECT label FROM fw_spending_labels WHERE transaction_id=? AND user_id=?', 'ii', [$id, $uid])[0]['label'] ?? '';
            }
        }
        if ($id > 0 && fw_has_table($conn, 'fw_work_cost_links') && fw_query($conn, 'SELECT expense_id FROM fw_work_cost_links WHERE transaction_id=? AND user_id=?', 'ii', [$id, $uid])) {
            throw new RuntimeException('Unlink this work expense in Real Earnings Lens before changing it.');
        }
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Transaction write failed.');
        }
        $savedId = $id > 0 ? $id : (int) mysqli_stmt_insert_id($stmt);
        if (fw_has_table($conn, 'fw_spending_labels')) {
            if ($deleting) {
                fw_query($conn, 'DELETE FROM fw_spending_labels WHERE transaction_id=? AND user_id=?', 'ii', [$savedId, $uid]);
            } elseif (array_key_exists('spending_label', $_POST)) {
                $label = fw_label($_POST['spending_label']);
                if (($_POST['transaction_type'] ?? '') !== 'expense') {
                    $label = '';
                }
                fw_query($conn, 'DELETE FROM fw_spending_labels WHERE transaction_id=? AND user_id=?', 'ii', [$savedId, $uid]);
                if ($label !== '') {
                    fw_query($conn, 'INSERT INTO fw_spending_labels(transaction_id,user_id,label) VALUES (?,?,?)', 'iis', [$savedId, $uid, $label]);
                }
            }
        }
        $after = $deleting ? null : (fw_query($conn, 'SELECT * FROM transactions WHERE transaction_id=? AND user_id=?', 'ii', [$savedId, $uid])[0] ?? null);
        if ($after && fw_has_table($conn, 'fw_spending_labels')) {
            $after['spending_label'] = fw_query($conn, 'SELECT label FROM fw_spending_labels WHERE transaction_id=? AND user_id=?', 'ii', [$savedId, $uid])[0]['label'] ?? '';
        }
        fw_revision($conn, $uid, 'transaction', $savedId, $deleting ? 'deleted' : ($before ? 'updated' : 'created'), $before, $after);
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Transaction commit failed.');
        }
        return true;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('Financial record change failed: ' . $e->getMessage());
        return false;
    }
}
function fw_recovery_code(): string
{
    return strtoupper(bin2hex(random_bytes(24)));
}
function fw_recovery_hash(string $code): string
{
    return hash('sha256', strtoupper(preg_replace('/[\s-]+/', '', $code)));
}
function fw_password_valid($value): bool
{
    return is_string($value) && strlen($value) >= 8 && strlen($value) <= 72 && !str_contains($value, "\0");
}
// Reserve login/recovery attempts atomically. No raw email or IP is retained.
function fw_auth_attempt(mysqli $conn, string $email): bool
{
    $subjects = [hash('sha256', 'email:' . strtolower(trim($email))) => 10, hash('sha256', 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => 100];
    mysqli_begin_transaction($conn);
    try {
        $allowed = true;
        foreach ($subjects as $key => $maximum) {
            fw_query($conn, 'INSERT IGNORE INTO fw_login_limits(subject_key,attempts,window_start) VALUES (?,0,UTC_TIMESTAMP())', 's', [$key]);
            $r = fw_query($conn, 'SELECT attempts,window_start, window_start <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) AS expired FROM fw_login_limits WHERE subject_key=? FOR UPDATE', 's', [$key])[0];
            $count = (int) $r['expired'] ? 0 : (int) $r['attempts'];
            if ($count >= $maximum) {
                $allowed = false;
            }
            fw_query($conn, 'UPDATE fw_login_limits SET attempts=?,window_start=IF(?,UTC_TIMESTAMP(),window_start) WHERE subject_key=?', 'iis', [$count + 1, (int) $r['expired'], $key]);
        }
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Unable to reserve attempt.');
        }
        return $allowed;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}
function fw_auth_success(mysqli $conn, string $email): void
{
    fw_query($conn, 'DELETE FROM fw_login_limits WHERE subject_key=?', 's', [hash('sha256', 'email:' . strtolower(trim($email)))]);
}
function fw_request_token(): string
{
    $nonce = bin2hex(random_bytes(24));
    return $nonce . '.' . hash_hmac('sha256', $nonce, $_SESSION['csrf_token'] ?? '');
}
function fw_insert_once(mysqli $conn, string $sql, string $types, array $args): bool
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare unique write.');
    }
    try {
        if (!mysqli_stmt_bind_param($stmt, $types, ...$args) || !mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Unable to reserve unique write.');
        }
        return mysqli_stmt_affected_rows($stmt) === 1;
    } finally {
        mysqli_stmt_close($stmt);
    }
}
function fw_claim_request(mysqli $conn, int $uid, $token): bool
{
    if (!is_string($token) || !preg_match('/^([a-f0-9]{48})\.([a-f0-9]{64})$/D', $token, $m) || !hash_equals(hash_hmac('sha256', $m[1], $_SESSION['csrf_token'] ?? ''), $m[2])) {
        return false;
    }
    return fw_insert_once($conn, 'INSERT IGNORE INTO fw_requests(user_id,request_key) VALUES (?,?)', 'is', [$uid, hash('sha256', $m[1])]);
}
function fw_budget_notifications(mysqli $conn, int $uid): void
{
    $budgets = fw_query($conn, "SELECT b.*,c.category_name,COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=b.user_id AND t.category_id=b.category_id AND t.transaction_type='expense' AND t.transaction_date BETWEEN b.start_date AND LEAST(b.end_date,CURDATE())),0) AS spent FROM budgets b JOIN categories c ON c.category_id=b.category_id AND c.user_id=b.user_id WHERE b.user_id=? AND CURDATE() BETWEEN b.start_date AND b.end_date", 'i', [$uid]);
    $noticePreferences = fw_notice_preferences($conn, $uid);
    foreach ($budgets as $b) {
        $limit = (float) $b['budget_limit'];
        $spent = (float) $b['spent'];
        if ($limit <= 0 || $spent < $limit * .8) {
            continue;
        }
        $threshold = $spent > $limit ? 101 : ($spent == $limit ? 100 : 80);
        mysqli_begin_transaction($conn);
        try {
            fw_query($conn, 'SELECT user_id FROM users WHERE user_id=? FOR UPDATE', 'i', [$uid]);
            $newAlert = fw_insert_once($conn, 'INSERT IGNORE INTO fw_budget_alerts(user_id,budget_id,period_start,period_end,threshold_value) VALUES (?,?,?,?,?)', 'iissi', [$uid, (int) $b['budget_id'], $b['start_date'], $b['end_date'], $threshold]);
            if ($newAlert) {
                $state = $threshold === 80 ? 'is at least 80% used' : ($spent > $limit ? 'has exceeded its limit' : 'has reached its limit');
                $message = $b['category_name'] . ' budget ' . $state . '. Recorded spending: ' . number_format($spent, 2) . ' / ' . number_format($limit, 2) . '. Period: ' . $b['start_date'] . ' to ' . $b['end_date'] . '.';
                if (!fw_query($conn, 'SELECT notification_id FROM notifications WHERE user_id=? AND message=? LIMIT 1', 'is', [$uid, $message])) {
                    fw_query($conn, 'INSERT INTO notifications(user_id,message,is_read) VALUES (?,?,0)', 'is', [$uid, $message]);
                } else {
                    $newAlert = false;
                }
            }
            if (!mysqli_commit($conn)) {
                throw new RuntimeException('Unable to save alert.');
            }
            if ($newAlert && $threshold === 101 && !empty($noticePreferences['budget_popup'])) {
                $_SESSION['fw_budget_dialog'][] = $message;
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            throw $e;
        }
    }
}
function fw_currency_locked(mysqli $conn, int $uid): bool
{
    foreach (['transactions', 'savings_goals', 'budgets', 'savings_challenges', 'work_sessions', 'money_lab_experiments', 'fw_debts'] as $table) {
        $rows = fw_query($conn, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', 's', [$table]);
        if ($rows && fw_query($conn, "SELECT 1 FROM `$table` WHERE user_id=? LIMIT 1", 'i', [$uid])) {
            return true;
        }
    }
    return false;
}
function fw_expense_items($descriptions, $amounts): array
{
    if (!is_array($descriptions) || !is_array($amounts) || count($descriptions) !== count($amounts) || count($amounts) > 30) {
        throw new InvalidArgumentException('Use up to 30 expense items.');
    }
    $items = [];
    $cents = 0;
    $amounts = array_values($amounts);
    foreach (array_values($descriptions) as $i => $raw) {
        $description = fw_text($raw);
        $value = $amounts[$i];
        if ($description === '' && fw_text($value) === '') {
            continue;
        }
        $amount = fw_money($value);
        if ($description === '' || fw_text_length($description) > 160 || $amount === null) {
            throw new InvalidArgumentException('Every expense needs a description (up to 160 characters) and a positive amount with up to two decimals.');
        }
        $cents += (int) round($amount * 100);
        if ($cents > 999999999999) {
            throw new InvalidArgumentException('Expense total is too large.');
        }
        $items[] = ['description' => $description, 'amount' => number_format($amount, 2, '.', '')];
    }
    return ['items' => $items, 'total' => number_format($cents / 100, 2, '.', '')];
}
