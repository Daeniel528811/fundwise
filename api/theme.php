<?php
// Saves the user's theme preference (light or dark).
require_once __DIR__ . "/../includes/auth_check.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/input.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "message" => "Method not allowed."]);
    exit();
}

if (!fw_csrf_valid()) {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Invalid request."]);
    exit();
}

$theme = fw_text($_POST["theme"] ?? null);
if (!in_array($theme, ["light", "dark"], true)) {
    http_response_code(422);
    echo json_encode(["ok" => false, "message" => "Invalid theme."]);
    exit();
}

$user_id = (int) $_SESSION["user_id"];
$stmt = mysqli_prepare($conn, "UPDATE users SET theme = ? WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, "si", $theme, $user_id);
$saved = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$saved) {
    http_response_code(500);
    echo json_encode(["ok" => false, "message" => "Unable to save theme."]);
    exit();
}

echo json_encode(["ok" => true, "theme" => $theme]);
