<?php
// All authenticated and authentication pages share these secure session defaults.
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(static function (Throwable $e): void {
    error_log('Fundwise: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<p>Fundwise could not complete this request. Please try again. If this is a new update, confirm its database migration was imported.</p>';
});
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $https,
        'cookie_samesite' => 'Lax',
        'use_only_cookies' => true,
    ]);
}
