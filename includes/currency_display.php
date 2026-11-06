<?php
// Currency preference is a display label. Existing amounts are NOT converted.
function fw_user_currency_code(mysqli $conn, int $user_id): string
{
    $code = 'PHP';
    $stmt = mysqli_prepare($conn, 'SELECT preferred_currency FROM users WHERE user_id = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $candidate = strtoupper((string) ($data['preferred_currency'] ?? 'PHP'));
            if (preg_match('/^[A-Z]{3}$/D', $candidate)) {
                $code = $candidate;
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $code;
}

function fw_user_currency(mysqli $conn, int $user_id): string
{
    return fw_user_currency_code($conn, $user_id) . ' ';
}
