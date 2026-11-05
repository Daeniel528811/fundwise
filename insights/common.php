<?php
// Internal helpers for the three Financial Intelligence pages.
// All user-supplied identifiers are verified through user_id-scoped queries.
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/input.php';

$fi_user_id = (int) $_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function fi_h($value): string {
    return htmlspecialchars(is_scalar($value)?(string)$value:'', ENT_QUOTES, 'UTF-8');
}
function fi_cash($value, string $code = 'PHP'): string {
    return $code . ' ' . number_format((float)$value, 2);
}
function fi_currency(mysqli $conn, int $user_id): string {
    $stmt = mysqli_prepare($conn, 'SELECT preferred_currency FROM users WHERE user_id = ?');
    if (!$stmt) return 'PHP';
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
    mysqli_stmt_close($stmt);
    $code = strtoupper((string)($row['preferred_currency'] ?? 'PHP'));
    return preg_match('/^[A-Z]{3}$/D', $code) ? $code : 'PHP';
}
function fi_query(mysqli $conn, string $sql, string $types = '', array $values = []): array {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare query.');
    if ($types !== '') {
        if (!mysqli_stmt_bind_param($stmt, $types, ...$values)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Unable to bind query parameters.');
        }
    }
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Unable to execute query.');
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}
function fi_exec(mysqli $conn, string $sql, string $types, array $values): bool {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    if (!mysqli_stmt_bind_param($stmt, $types, ...$values)) {
        mysqli_stmt_close($stmt);
        return false;
    }
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}
function fi_table_exists(mysqli $conn, string $name): bool {
    $rows = fi_query($conn, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', 's', [$name]);
    return count($rows) > 0;
}
function fi_post_csrf(): bool {
    return fw_csrf_valid('csrf_token');
}
function fi_flash(string $key, string $text, string $kind = 'success'): void {
    $_SESSION['fi_' . $key] = ['text' => $text, 'kind' => $kind];
}
function fi_take_flash(string $key): ?array {
    $saved = $_SESSION['fi_' . $key] ?? null;
    unset($_SESSION['fi_' . $key]);
    return is_array($saved) ? $saved : null;
}
function fi_month($raw): string {
    if (!is_string($raw) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $raw)) return date('Y-m');
    return $raw;
}
function fi_db_notice(): void {
    echo '<div class="alert alert-warning mt-4"><strong>One-time setup needed.</strong> Import <code>database/financial_intelligence.sql</code> and <code>database/20261102_upgrade.sql</code> into your existing InfinityFree database using phpMyAdmin, then refresh. This only adds two tables; it does not change your current records.</div>';
}

require_once __DIR__ . '/../includes/fi_tools.php';
