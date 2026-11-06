<?php
// Internal helpers for the three Financial Intelligence pages.
// All user-supplied identifiers are verified through user_id-scoped queries.
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/currency_display.php';

$fi_user_id = (int) $_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function fi_post_csrf(): bool
{
    return fw_csrf_valid('csrf_token');
}
function fi_flash(string $key, string $text, string $kind = 'success'): void
{
    $_SESSION['fi_' . $key] = ['text' => $text, 'kind' => $kind];
}
function fi_take_flash(string $key): ?array
{
    $saved = $_SESSION['fi_' . $key] ?? null;
    unset($_SESSION['fi_' . $key]);
    return is_array($saved) ? $saved : null;
}
function fi_month($raw): string
{
    if (!is_string($raw) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $raw)) {
        return date('Y-m');
    }
    return $raw;
}

require_once __DIR__ . '/../includes/fi_tools.php';
