<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";

$user_id = (int) $_SESSION["user_id"];

$success = "";
$error = "";

// Create CSRF token
if (empty($_SESSION["notification_csrf"])) {
    $_SESSION["notification_csrf"] = bin2hex(random_bytes(32));
}

fw_budget_notifications($conn,$user_id);

// Process notification actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) || !hash_equals($_SESSION["notification_csrf"], $token)) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {

        $notification_id = filter_var(
            $_POST["notification_id"] ?? "",
            FILTER_VALIDATE_INT
        );

        $action = fw_text($_POST["action"] ?? null);

        if (!$notification_id || !in_array($action, ["read", "delete"], true)) {
            $error = "Invalid notification action.";
        } else {

            if ($action === "read") {

                $sql = "
                    UPDATE notifications
                    SET is_read = 1
                    WHERE notification_id = ?
                      AND user_id = ?
                ";

                $message = "Notification marked as read.";

            } else {

                $sql = "
                    DELETE FROM notifications
                    WHERE notification_id = ?
                      AND user_id = ?
                ";

                $message = "Notification deleted.";
            }

            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param(
                $stmt,
                "ii",
                $notification_id,
                $user_id
            );

            if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
                $success = $message;
                $_SESSION["notification_csrf"] = bin2hex(random_bytes(32));
            } else {
                $error = "Notification not found or action could not be completed.";
            }

            mysqli_stmt_close($stmt);
        }
    }
}

// Get notifications
$sql = "
    SELECT notification_id, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$notifications = [];
$unread_count = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $notifications[] = $row;

    if ((int) $row["is_read"] === 0) {
        $unread_count++;
    }
}

mysqli_stmt_close($stmt);
?>

<?php
$pageTitle = "Notifications";
ob_start();
?>
<style>
        body {
            background: #f5f3ff;
            font-family: Arial, sans-serif;
        }

        .sidebar {
            background: #24134d;
            min-height: 100vh;
            padding: 25px 15px;
        }

        .sidebar h4 {
            color: white;
            margin-bottom: 30px;
        }

        .sidebar a {
            display: block;
            color: #ddd6fe;
            text-decoration: none;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 7px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #6d28d9;
            color: white;
        }

        .main-content {
            padding: 30px;
        }

        .notification-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 5px solid #8b5cf6;
            box-shadow: 0 3px 12px rgba(0,0,0,0.04);
        }

        .notification-card.unread {
            background: #faf5ff;
            border-left-color: #6d28d9;
        }

        .section-title {
            color: #2e1065;
            font-weight: bold;
        }

        .btn-purple {
            background: #6d28d9;
            color: white;
        }

        .btn-purple:hover {
            background: #5b21b6;
            color: white;
        }

        .notification-date {
            color: #777;
            font-size: 13px;
        }

        @media (max-width: 767px) {
            .sidebar {
                min-height: auto;
            }

            .main-content {
                padding: 18px;
            }
        }
    </style>
<?php
$pageHead = ob_get_clean();
require_once __DIR__ . "/../includes/header.php";
?>
            <div class="mb-4">
                <h2 class="section-title">Notifications</h2><a class="btn btn-outline-primary btn-sm mt-2" href="reminders.php"><i class="bi bi-sliders" aria-hidden="true"></i> Reminders & settings</a>
                <p class="text-muted">
                    
                </p>
            </div>

            <?php if ($success !== ""): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="section-title mb-0">Your Notifications</h5>

                <span class="badge bg-primary">
                    <?= $unread_count ?> Unread
                </span>
            </div>

            <?php if (empty($notifications)): ?>

                <div class="notification-card text-center">
                    <h5>No notifications yet</h5>
                    <p class="text-muted mb-0">
                        Your budget alerts and other updates will appear here.
                    </p>
                </div>

            <?php else: ?>

                <?php foreach ($notifications as $notification): ?>

                    <div class="notification-card <?= (int) $notification["is_read"] === 0 ? "unread" : "" ?>">

                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">

                            <div class="flex-grow-1">

                                <div class="mb-2">
                                    <?php if ((int) $notification["is_read"] === 0): ?>
                                        <span class="badge bg-primary">New</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Read</span>
                                    <?php endif; ?>
                                </div>

                                <p class="mb-2">
                                    <?= htmlspecialchars($notification["message"]) ?>
                                </p>

                                <div class="notification-date">
                                    <?= date("M d, Y h:i A", strtotime($notification["created_at"])) ?>
                                </div>

                            </div>

                            <div class="d-flex gap-2">

                                <?php if ((int) $notification["is_read"] === 0): ?>

                                    <form method="POST"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= htmlspecialchars($_SESSION["notification_csrf"]) ?>">

                                        <input type="hidden" name="notification_id"
                                               value="<?= (int) $notification["notification_id"] ?>">

                                        <input type="hidden" name="action" value="read">

                                        <button type="submit" class="btn btn-sm btn-purple">
                                            Mark as Read
                                        </button>
                                    </form>

                                <?php endif; ?>

                                <form method="POST"
                                      onsubmit="return confirm('Delete this notification?');"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">

                                    <input type="hidden" name="csrf_token"
                                           value="<?= htmlspecialchars($_SESSION["notification_csrf"]) ?>">

                                    <input type="hidden" name="notification_id"
                                           value="<?= (int) $notification["notification_id"] ?>">

                                    <input type="hidden" name="action" value="delete">

                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        Delete
                                    </button>
                                </form>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
