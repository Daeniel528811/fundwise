<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";

$user_id = (int) $_SESSION['user_id'];
$id = fw_positive_id($_GET['id'] ?? null);
if ($id <= 0) {
    http_response_code(404);
    exit('Receipt not found.');
}
$stmt = mysqli_prepare($conn, 'SELECT receipt_image FROM transactions WHERE user_id = ? AND transaction_id = ?');
mysqli_stmt_bind_param($stmt, 'ii', $user_id, $id);
mysqli_stmt_execute($stmt);
$data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$data || empty($data['receipt_image'])) {
    http_response_code(404);
    exit('Receipt not found.');
}
$relative = (string) $data['receipt_image'];
if (!preg_match('~^uploads/receipts/([a-f0-9]{32}\.(?:jpg|png))$~D', $relative, $match)) {
    http_response_code(404);
    exit('Receipt not found.');
}
$file = __DIR__ . '/../uploads/receipts/' . $match[1];
if (!is_file($file)) {
    http_response_code(404);
    exit('Receipt not found.');
}
$mime = str_ends_with($match[1], '.png') ? 'image/png' : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="receipt.' . (str_ends_with($match[1], '.png') ? 'png' : 'jpg') . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
