<?php
require_once __DIR__ . '/../includes/session.php';
$lastHumanActivity = $_SESSION['last_activity'] ?? null;
require_once __DIR__ . '/../includes/auth_check.php';
if (is_int($lastHumanActivity)) {
    $_SESSION['last_activity'] = $lastHumanActivity;
}
header('Content-Type: application/json; charset=UTF-8');
$uid = (int) $_SESSION['user_id'];
fw_budget_notifications($conn, $uid);
fw_debt_due_notifications($conn, $uid);
fw_personal_reminder_notifications($conn, $uid);
$budget = $_SESSION['fw_budget_dialog'] ?? [];
$messages = array_merge($budget, $_SESSION['fw_notice_dialog'] ?? []);
unset($_SESSION['fw_budget_dialog'],$_SESSION['fw_notice_dialog']);
echo json_encode(['messages' => array_slice(array_values(array_unique($messages)), 0, 5), 'kind' => $budget ? 'budget' : 'reminder'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
