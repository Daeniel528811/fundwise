<?php
// Shared server-side input helpers. Never rely on browser validation alone.
function fw_text($value): string
{
    return is_string($value) ? trim($value) : "";
}

function fw_positive_id($value): int
{
    if (!is_string($value) && !is_int($value)) {
        return 0;
    }
    $number = filter_var($value, FILTER_VALIDATE_INT);
    return ($number !== false && $number > 0) ? (int) $number : 0;
}

// Monetary values are stored with two decimal places and must be finite.
// Returns null for malformed values, including arrays, exponents and negative numbers.
function fw_money($value, bool $allowZero = false): ?float
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }
    $raw = trim((string) $value);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $raw)) {
        return null;
    }
    $amount = (float) $raw;
    if (!is_finite($amount) || $amount > 9999999999.99) {
        return null;
    }
    if ($allowZero ? $amount < 0 : $amount <= 0) {
        return null;
    }
    return $amount;
}

function fw_valid_date($value): bool
{
    if (!is_string($value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function fw_csrf_valid(string $key = 'csrf_token'): bool
{
    $expected = $_SESSION[$key] ?? null;
    $submitted = $_POST['csrf_token'] ?? null;
    return is_string($expected) && $expected !== ''
        && is_string($submitted) && hash_equals($expected, $submitted);
}
