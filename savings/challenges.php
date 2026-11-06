<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";

$user_id = (int) $_SESSION["user_id"];
require_once "../includes/currency_display.php";
$currency = fw_user_currency($conn, $user_id);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];
$message = "";
$error = "";

function validChallengeDate($value)
{
    return fw_valid_date($value);
}

function getChallengeStatus($current, $target, $end_date, $start_date = null)
{
    if ($start_date !== null && $start_date > date('Y-m-d')) {
        return 'scheduled';
    }
    if ($current >= $target) {
        return "completed";
    }

    if ($end_date < date("Y-m-d")) {
        return "failed";
    }

    return "active";
}

// Goal-linked progress is derived from dated contributions, never a second balance.
if (fw_has_table($conn, 'fw_challenge_goals')) {
    $linked = fw_query($conn, "SELECT c.*,l.goal_id FROM savings_challenges c JOIN fw_challenge_goals l ON l.challenge_id=c.challenge_id AND l.user_id=c.user_id WHERE c.user_id=?", 'i', [$user_id]);
    foreach ($linked as $lc) {
        $saved = fw_query($conn, "SELECT COALESCE(SUM(CASE WHEN contribution_type='deposit' THEN amount ELSE -amount END),0) AS amount FROM savings_contributions WHERE goal_id=? AND contribution_date BETWEEN ? AND LEAST(?,CURDATE())", 'iss', [(int) $lc['goal_id'], $lc['start_date'], $lc['end_date']])[0]['amount'];
        $current = max(0, (float) $saved);
        $status = getChallengeStatus($current, (float) $lc['target_amount'], $lc['end_date'], $lc['start_date']);
        fw_query($conn, 'UPDATE savings_challenges SET current_amount=?,status=? WHERE challenge_id=? AND user_id=?', 'ssii', [(string) $current, $status, (int) $lc['challenge_id'], $user_id]);
    }
}
// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !is_string($_POST["csrf_token"]) ||
        !hash_equals($csrf_token, $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {

        $action = is_string($_POST["action"] ?? null) ? $_POST["action"] : "";

        // CREATE CHALLENGE
        if ($action === "create") {
            $name = (is_string($_POST["challenge_name"] ?? null) ? trim($_POST["challenge_name"]) : "");
            $target = fw_money($_POST["target_amount"] ?? null);
            $start_date = (is_string($_POST["start_date"] ?? null) ? $_POST["start_date"] : "");
            $end_date = (is_string($_POST["end_date"] ?? null) ? $_POST["end_date"] : "");

            if (($name === "" || strlen($name) > 100) || $target === null || !validChallengeDate($start_date) || !validChallengeDate($end_date)) {
                $error = "Please complete all fields with valid values.";
            } elseif ($end_date < $start_date) {
                $error = "The end date cannot be earlier than the start date.";
            } else {
                $current = 0;
                $status = getChallengeStatus($current, $target, $end_date, $start_date);

                $stmt = mysqli_prepare($conn, "
                    INSERT INTO savings_challenges
                    (user_id, challenge_name, target_amount, start_date, end_date, current_amount, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    "isdssds",
                    $user_id,
                    $name,
                    $target,
                    $start_date,
                    $end_date,
                    $current,
                    $status
                );

                if (mysqli_stmt_execute($stmt)) {
                    header("Location: challenges.php?success=created");
                    exit();
                } else {
                    $error = "Unable to create the challenge.";
                }

                mysqli_stmt_close($stmt);
            }
        }

        // UPDATE CHALLENGE PROGRESS
        if ($action === "progress" && fw_has_table($conn, 'fw_challenge_goals') && fw_query($conn, 'SELECT challenge_id FROM fw_challenge_goals WHERE challenge_id=? AND user_id=?', 'ii', [fw_positive_id($_POST['challenge_id'] ?? null), $user_id])) {
            $error = 'This challenge uses goal contributions. Add or correct a dated contribution in Savings Goals to update its progress.';
            $action = '';
        }
        if ($action === "progress") {
            $challenge_id = (filter_var($_POST["challenge_id"] ?? null, FILTER_VALIDATE_INT) ?: 0);
            $current = fw_money($_POST["current_amount"] ?? null, true);

            $stmt = mysqli_prepare($conn, "
                SELECT target_amount, end_date, start_date
                FROM savings_challenges
                WHERE challenge_id = ? AND user_id = ?
            ");

            mysqli_stmt_bind_param($stmt, "ii", $challenge_id, $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $challenge = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if (!$challenge) {
                $error = "Challenge not found.";
            } elseif ($challenge['start_date'] > date('Y-m-d') || $challenge['end_date'] < date('Y-m-d')) {
                $error = 'Progress can only be updated during the challenge dates.';
            } elseif ($current === null) {
                $error = "Progress amount cannot be negative.";
            } elseif ($current > (float) $challenge["target_amount"]) {
                $error = "Progress cannot exceed the target amount.";
            } else {
                $status = getChallengeStatus(
                    $current,
                    (float) $challenge["target_amount"],
                    $challenge["end_date"],
                    $challenge["start_date"]
                );

                $stmt = mysqli_prepare($conn, "
                    UPDATE savings_challenges
                    SET current_amount = ?, status = ?
                    WHERE challenge_id = ? AND user_id = ?
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    "dsii",
                    $current,
                    $status,
                    $challenge_id,
                    $user_id
                );

                if (mysqli_stmt_execute($stmt)) {
                    header("Location: challenges.php?success=progress");
                    exit();
                } else {
                    $error = "Unable to update challenge progress.";
                }

                mysqli_stmt_close($stmt);
            }
        }

        // EDIT CHALLENGE DETAILS
        if ($action === "edit" && fw_has_table($conn, 'fw_challenge_goals')) {
            $cl = fw_query($conn, 'SELECT goal_id FROM fw_challenge_goals WHERE challenge_id=? AND user_id=?', 'ii', [fw_positive_id($_POST['challenge_id'] ?? null), $user_id])[0] ?? null;
            if ($cl && fw_query($conn, 'SELECT c.challenge_id FROM savings_challenges c JOIN fw_challenge_goals l ON l.challenge_id=c.challenge_id AND l.user_id=c.user_id WHERE c.user_id=? AND l.goal_id=? AND c.challenge_id<>? AND c.start_date<=? AND c.end_date>=?', 'iiiss', [$user_id, (int) $cl['goal_id'], fw_positive_id($_POST['challenge_id'] ?? null), fw_text($_POST['end_date'] ?? null), fw_text($_POST['start_date'] ?? null)])) {
                $error = 'Those dates overlap another challenge linked to the same goal.';
                $action = '';
            }
        }
        if ($action === "edit") {
            $challenge_id = (filter_var($_POST["challenge_id"] ?? null, FILTER_VALIDATE_INT) ?: 0);
            $name = (is_string($_POST["challenge_name"] ?? null) ? trim($_POST["challenge_name"]) : "");
            $target = fw_money($_POST["target_amount"] ?? null);
            $start_date = (is_string($_POST["start_date"] ?? null) ? $_POST["start_date"] : "");
            $end_date = (is_string($_POST["end_date"] ?? null) ? $_POST["end_date"] : "");

            $stmt = mysqli_prepare($conn, "
                SELECT current_amount
                FROM savings_challenges
                WHERE challenge_id = ? AND user_id = ?
            ");

            mysqli_stmt_bind_param($stmt, "ii", $challenge_id, $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $existing = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if (!$existing) {
                $error = "Challenge not found.";
            } elseif (($name === "" || strlen($name) > 100) || $target === null || !validChallengeDate($start_date) || !validChallengeDate($end_date)) {
                $error = "Please complete all fields with valid values.";
            } elseif ($end_date < $start_date) {
                $error = "The end date cannot be earlier than the start date.";
            } elseif ((float) $existing["current_amount"] > $target) {
                $error = "The new target cannot be lower than the current progress.";
            } else {
                $current = (float) $existing["current_amount"];
                $status = getChallengeStatus($current, $target, $end_date, $start_date);

                $stmt = mysqli_prepare($conn, "
                    UPDATE savings_challenges
                    SET challenge_name = ?, target_amount = ?,
                        start_date = ?, end_date = ?, status = ?
                    WHERE challenge_id = ? AND user_id = ?
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    "sdsssii",
                    $name,
                    $target,
                    $start_date,
                    $end_date,
                    $status,
                    $challenge_id,
                    $user_id
                );

                if (mysqli_stmt_execute($stmt)) {
                    header("Location: challenges.php?success=updated");
                    exit();
                } else {
                    $error = "Unable to update the challenge.";
                }

                mysqli_stmt_close($stmt);
            }
        }

        // DELETE CHALLENGE
        if ($action === "delete") {
            $challenge_id = (filter_var($_POST["challenge_id"] ?? null, FILTER_VALIDATE_INT) ?: 0);

            $stmt = mysqli_prepare($conn, "
                DELETE FROM savings_challenges
                WHERE challenge_id = ? AND user_id = ?
            ");

            mysqli_stmt_bind_param($stmt, "ii", $challenge_id, $user_id);

            if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
                header("Location: challenges.php?success=deleted");
                exit();
            } else {
                $error = "Unable to delete the challenge.";
            }

            mysqli_stmt_close($stmt);
        }
    }
}

// Success messages
if (isset($_GET["success"])) {
    $success_messages = [
        "created" => "Challenge created successfully!",
        "progress" => "Challenge progress updated!",
        "updated" => "Challenge updated successfully!",
        "deleted" => "Challenge deleted successfully!"
    ];

    $message = $success_messages[fw_text($_GET["success"])] ?? "";
}

// Retrieve user's challenges
$stmt = mysqli_prepare($conn, "
    SELECT *
    FROM savings_challenges
    WHERE user_id = ?
    ORDER BY created_at DESC
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$challenges = [];

while ($row = mysqli_fetch_assoc($result)) {
    // Refresh status based on current progress and deadline
    $calculated_status = getChallengeStatus(
        (float) $row["current_amount"],
        (float) $row["target_amount"],
        $row["end_date"],
        $row["start_date"]
    );

    if ($row["status"] !== $calculated_status) {
        $update = mysqli_prepare($conn, "
            UPDATE savings_challenges
            SET status = ?
            WHERE challenge_id = ? AND user_id = ?
        ");

        mysqli_stmt_bind_param(
            $update,
            "sii",
            $calculated_status,
            $row["challenge_id"],
            $user_id
        );

        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);

        $row["status"] = $calculated_status;
    }

    $challenges[] = $row;
}

mysqli_stmt_close($stmt);

// Summary counts
$total_challenges = count($challenges);
$active_count = 0;
$completed_count = 0;
$total_progress = 0;

foreach ($challenges as $challenge) {
    if (in_array($challenge["status"], ["active", "scheduled"], true)) {
        $active_count++;
    } elseif ($challenge["status"] === "completed") {
        $completed_count++;
    }

    $total_progress += (float) $challenge["current_amount"];
}
?>

<?php
$pageTitle = "Savings Challenges";
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

        .summary-card,
        .challenge-card,
        .form-card {
            background: white;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
        }

        .summary-number {
            font-size: 27px;
            font-weight: bold;
            color: #5b21b6;
        }

        .progress {
            height: 12px;
            border-radius: 20px;
        }

        .progress-bar {
            background: #7c3aed;
        }

        .btn-purple {
            background: #6d28d9;
            color: white;
        }

        .btn-purple:hover {
            background: #5b21b6;
            color: white;
        }

        .section-title {
            color: #2e1065;
            font-weight: bold;
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

<?php
?>
            <div class="fi-page fw-unified-heading"><section class="fi-hero"><div><span class="fi-kicker">SAVINGS CHALLENGES</span><h1>Build a routine.<br><span>Grow your savings.</span></h1><p>Practice a saving habit during a set period and connect it to a goal.</p></div><div class="fw-hero-end"><div class="fi-hero-icon"><i class="bi bi-trophy" aria-hidden="true"></i></div><a class="btn btn-light fw-hero-action" href="challenge-link.php"><i class="bi bi-link-45deg" aria-hidden="true"></i> Link a saving routine</a></div></section></div>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- SUMMARY -->
            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <div class="summary-card">
                        <p class="text-muted mb-1">Total Challenges</p>
                        <div class="summary-number">
                            <?= $total_challenges ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="summary-card">
                        <p class="text-muted mb-1">Active / Upcoming Challenges</p>
                        <div class="summary-number">
                            <?= $active_count ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="summary-card">
                        <p class="text-muted mb-1">Completed Challenges</p>
                        <div class="summary-number">
                            <?= $completed_count ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- CREATE CHALLENGE -->
            <div class="form-card mb-4">
                <h5 class="section-title mb-3">Create a New Challenge</h5>

                <form method="POST"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Challenge Name</label>
                            <input type="text" name="challenge_name"
                                   class="form-control"
                                   placeholder="Example: 30-Day Savings Challenge"
                                   maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Target Amount (<?= htmlspecialchars(trim($currency)) ?>)</label>
                            <input type="number" name="target_amount"
                                   class="form-control"
                                   min="0.01" step="0.01" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date"
                                   class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date"
                                   class="form-control" required>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-purple">
                                Create Challenge
                            </button>
                        </div>

                    </div>
                </form>
            </div>

            <!-- CHALLENGE LIST -->
            <h5 class="section-title mb-3">My Challenges</h5>

            <?php if (empty($challenges)): ?>

                <div class="challenge-card text-center">
                    <h5>No challenges yet</h5>
                    <p class="text-muted mb-0">
                        Create your first savings challenge above.
                    </p>
                </div>

            <?php else: ?>

                <div class="row g-3">

                    <?php foreach ($challenges as $challenge): ?>

                        <?php
                        $target = (float) $challenge["target_amount"];
                        $current = (float) $challenge["current_amount"];

                        $percentage = $target > 0
                            ? min(100, ($current / $target) * 100)
                            : 0;

                        $status_class = "secondary";

                        if ($challenge["status"] === "active") {
                            $status_class = "primary";
                        } elseif ($challenge["status"] === "completed") {
                            $status_class = "success";
                        } elseif ($challenge["status"] === "failed") {
                            $status_class = "danger";
                        }
                        ?>

                        <div class="col-lg-6">

                            <div class="challenge-card h-100">

                                <div class="d-flex justify-content-between align-items-start mb-3">

                                    <div>
                                        <h5 class="mb-1">
                                            <?= htmlspecialchars($challenge["challenge_name"]) ?>
                                        </h5>

                                        <small class="text-muted">
                                            <?= htmlspecialchars($challenge["start_date"]) ?>
                                            to
                                            <?= htmlspecialchars($challenge["end_date"]) ?>
                                        </small>
                                    </div>

                                    <span class="badge bg-<?= $status_class ?>">
                                        <?= ucfirst($challenge["status"]) ?>
                                    </span>

                                </div>

                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Progress</span>
                                    <strong>
                                        <?= number_format($percentage, 1) ?>%
                                    </strong>
                                </div>

                                <div class="progress mb-3">
                                    <div class="progress-bar"
                                         role="progressbar"
                                         style="width: <?= min(100, $percentage) ?>%">
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between mb-3">
                                    <div>
                                        <small class="text-muted">Current Amount</small>
                                        <h5><?= htmlspecialchars($currency) ?><?= number_format($current, 2) ?></h5>
                                    </div>

                                    <div class="text-end">
                                        <small class="text-muted">Target Amount</small>
                                        <h5><?= htmlspecialchars($currency) ?><?= number_format($target, 2) ?></h5>
                                    </div>
                                </div>

                                <p class="text-muted">
                                    Remaining:
                                    <strong>
                                        <?= htmlspecialchars($currency) ?><?= number_format(max(0, $target - $current), 2) ?>
                                    </strong>
                                </p>

                                <hr>

                                <!-- UPDATE PROGRESS -->
                                <?php $goalLink = fw_has_table($conn, 'fw_challenge_goals') ? (fw_query($conn, 'SELECT l.*,g.goal_name FROM fw_challenge_goals l JOIN savings_goals g ON g.goal_id=l.goal_id AND g.user_id=l.user_id WHERE l.challenge_id=? AND l.user_id=?', 'ii', [(int) $challenge['challenge_id'], $user_id])[0] ?? null) : null;
                        if ($goalLink):?>
                                <p><strong><?= htmlspecialchars($goalLink['behavior'], ENT_QUOTES, 'UTF-8') ?></strong></p><p class="text-muted">Progress comes from dated contributions to <?= htmlspecialchars($goalLink['goal_name'], ENT_QUOTES, 'UTF-8') ?> during this challenge. It is already included in your goal balance.</p><a class="btn btn-outline-primary mb-3" href="index.php">Record a goal contribution</a>
                                <?php else:?>
                                <form method="POST" class="mb-3"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="progress">
                                    <input type="hidden" name="challenge_id"
                                           value="<?= (int) $challenge["challenge_id"] ?>">

                                    <label class="form-label">
                                        Update Current Amount
                                    </label>

                                    <div class="input-group">
                                        <span class="input-group-text"><?= htmlspecialchars(trim($currency)) ?></span>

                                        <input type="number"
                                               name="current_amount"
                                               class="form-control"
                                               min="0" step="0.01"
                                               max="<?= $target ?>"
                                               value="<?= htmlspecialchars((string) $current) ?>"
                                               required>

                                        <button type="submit"
                                                class="btn btn-purple">
                                            Update
                                        </button>
                                    </div>

                                    <small class="text-muted">
                                        Enter your new total saved amount for this challenge.
                                    </small>
                                </form>
                                <?php endif; ?>

                                <!-- EDIT DETAILS -->
                                <details class="mb-3">
                                    <summary class="text-primary" style="cursor:pointer;">
                                        Edit Challenge Details
                                    </summary>

                                    <form method="POST" class="mt-3"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="challenge_id"
                                               value="<?= (int) $challenge["challenge_id"] ?>">

                                        <label class="form-label">Challenge Name</label>
                                        <input type="text" name="challenge_name"
                                               class="form-control mb-2"
                                               maxlength="100"
                                               value="<?= htmlspecialchars($challenge["challenge_name"]) ?>"
                                               required>

                                        <label class="form-label">Target Amount</label>
                                        <input type="number" name="target_amount"
                                               class="form-control mb-2"
                                               min="0.01" step="0.01"
                                               value="<?= htmlspecialchars((string) $target) ?>"
                                               required>

                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date"
                                               class="form-control mb-2"
                                               value="<?= htmlspecialchars($challenge["start_date"]) ?>"
                                               required>

                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date"
                                               class="form-control mb-3"
                                               value="<?= htmlspecialchars($challenge["end_date"]) ?>"
                                               required>

                                        <button type="submit" class="btn btn-purple btn-sm">
                                            Save Changes
                                        </button>
                                    </form>
                                </details>

                                <!-- DELETE -->
                                <form method="POST"
                                      onsubmit="return confirm('Are you sure you want to delete this challenge?');"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(), ENT_QUOTES, 'UTF-8') ?>">

                                    <input type="hidden" name="csrf_token"
                                           value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="challenge_id"
                                           value="<?= (int) $challenge["challenge_id"] ?>">

                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                        Delete Challenge
                                    </button>
                                </form>

                            </div>
                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
