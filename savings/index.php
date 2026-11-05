<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";

$user_id = (int) $_SESSION["user_id"];
$message = "";
$message_type = "success";

// CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Flash messages
if (isset($_SESSION["savings_message"])) {
    $message = $_SESSION["savings_message"];
    $message_type = $_SESSION["savings_message_type"] ?? "success";

    unset($_SESSION["savings_message"]);
    unset($_SESSION["savings_message_type"]);
}

// Validate dates
function validSavingsDate($date)
{
    return fw_valid_date($date);
}

// Handle submitted forms
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !is_string($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        die("Invalid request. Please refresh the page and try again.");
    }

    $action = fw_text($_POST["action"] ?? null);

    // CREATE OR UPDATE SAVINGS GOAL
    if ($action === "save_goal") {

        $goal_id = fw_positive_id($_POST["goal_id"] ?? null);
        $goal_name = is_string($_POST["goal_name"] ?? null) ? trim($_POST["goal_name"]) : "";
        $target_amount = fw_money($_POST["target_amount"] ?? null);
        $target_date = fw_text($_POST["target_date"] ?? null);

        if (
            $goal_name === "" ||
            strlen($goal_name) > 100 ||
            $target_amount === null ||
            !is_string($target_date) ||
            !validSavingsDate($target_date)
        ) {
            $message = "Please enter a valid goal name, target amount, and target date.";
            $message_type = "danger";
        } else {

            if ($goal_id > 0) {

                // Verify goal ownership
                $check_sql = "
                    SELECT goal_id
                    FROM savings_goals
                    WHERE goal_id = ?
                    AND user_id = ?
                ";

                $check_stmt = mysqli_prepare($conn, $check_sql);
                mysqli_stmt_bind_param($check_stmt, "ii", $goal_id, $user_id);
                mysqli_stmt_execute($check_stmt);

                $check_result = mysqli_stmt_get_result($check_stmt);
                $goal_exists = mysqli_num_rows($check_result) > 0;

                mysqli_stmt_close($check_stmt);

                if (!$goal_exists) {
                    $message = "Savings goal not found.";
                    $message_type = "danger";
                } else {

                    $sql = "
                        UPDATE savings_goals
                        SET goal_name = ?,
                            target_amount = ?,
                            target_date = ?
                        WHERE goal_id = ?
                        AND user_id = ?
                    ";

                    $stmt = mysqli_prepare($conn, $sql);

                    mysqli_stmt_bind_param(
                        $stmt,
                        "sdsii",
                        $goal_name,
                        $target_amount,
                        $target_date,
                        $goal_id,
                        $user_id
                    );

                    if (mysqli_stmt_execute($stmt)) {
                        $_SESSION["savings_message"] = "Savings goal updated successfully!";
                        $_SESSION["savings_message_type"] = "success";

                        mysqli_stmt_close($stmt);

                        header("Location: index.php");
                        exit();
                    } else {
                        $message = "Unable to update the savings goal.";
                        $message_type = "danger";
                    }

                    mysqli_stmt_close($stmt);
                }

            } else {

                $sql = "
                    INSERT INTO savings_goals
                    (user_id, goal_name, target_amount, target_date)
                    VALUES (?, ?, ?, ?)
                ";

                $stmt = mysqli_prepare($conn, $sql);

                mysqli_stmt_bind_param(
                    $stmt,
                    "isds",
                    $user_id,
                    $goal_name,
                    $target_amount,
                    $target_date
                );

                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION["savings_message"] = "Savings goal created successfully!";
                    $_SESSION["savings_message_type"] = "success";

                    mysqli_stmt_close($stmt);

                    header("Location: index.php");
                    exit();
                } else {
                    $message = "Unable to create the savings goal.";
                    $message_type = "danger";
                }

                mysqli_stmt_close($stmt);
            }
        }
    }

    // Archive instead of destroying financial history. Restore is available below.
    if(in_array($action,['delete_goal','restore_goal'],true)){
        $goal_id=fw_positive_id($_POST['goal_id']??null);
        if(fw_query($conn,'SELECT goal_id FROM savings_goals WHERE goal_id=? AND user_id=?','ii',[$goal_id,$user_id])){
            if($action==='restore_goal')fw_query($conn,'DELETE FROM fw_goal_archives WHERE goal_id=? AND user_id=?','ii',[$goal_id,$user_id]);
            else fw_query($conn,'INSERT IGNORE INTO fw_goal_archives(goal_id,user_id) VALUES (?,?)','ii',[$goal_id,$user_id]);
            fw_audit($conn,$user_id,$action==='restore_goal'?'goal.restored':'goal.archived',$goal_id);
            $_SESSION['savings_message']=$action==='restore_goal'?'Goal restored.':'Goal archived. Its money and contribution history are preserved.';
        }else $_SESSION['savings_message']='Goal not found.';
        header('Location: index.php');exit;
    }

    // ADD SAVINGS CONTRIBUTION OR WITHDRAWAL
    if ($action === "add_contribution") {

        $goal_id = fw_positive_id($_POST["goal_id"] ?? null);
        $contribution_type = fw_text($_POST["contribution_type"] ?? null);
        $amount = fw_money($_POST["amount"] ?? null);
        $contribution_date = fw_text($_POST["contribution_date"] ?? null);
        $notes = is_string($_POST["notes"] ?? null) ? trim($_POST["notes"]) : "";

        if (
            $goal_id <= 0 ||
            !in_array($contribution_type, ["deposit", "withdrawal"], true) ||
            $amount === null ||
            !is_string($contribution_date) ||
            !validSavingsDate($contribution_date) ||
            strlen($notes) > 255
        ) {
            $message = "Please complete the contribution form with valid information.";
            $message_type = "danger";
        } else {

            // Start transaction to protect balance calculations
            mysqli_begin_transaction($conn);

            try {

                // Lock the user's goal while checking its balance
                $goal_sql = "
                    SELECT goal_id
                    FROM savings_goals
                    WHERE goal_id = ?
                    AND user_id = ?
                    FOR UPDATE
                ";

                $goal_stmt = mysqli_prepare($conn, $goal_sql);

                mysqli_stmt_bind_param(
                    $goal_stmt,
                    "ii",
                    $goal_id,
                    $user_id
                );

                mysqli_stmt_execute($goal_stmt);

                $goal_result = mysqli_stmt_get_result($goal_stmt);
                $goal = mysqli_fetch_assoc($goal_result);

                mysqli_stmt_close($goal_stmt);

                if (!$goal) {
                    throw new Exception("Savings goal not found.");
                }

                // Calculate current savings balance
                $balance_sql = "
                    SELECT
                        COALESCE(
                            SUM(
                                CASE
                                    WHEN contribution_type = 'deposit'
                                    THEN amount
                                    WHEN contribution_type = 'withdrawal'
                                    THEN -amount
                                    ELSE 0
                                END
                            ),
                            0
                        ) AS savings_balance
                    FROM savings_contributions
                    WHERE goal_id = ?
                ";

                $balance_stmt = mysqli_prepare($conn, $balance_sql);

                mysqli_stmt_bind_param(
                    $balance_stmt,
                    "i",
                    $goal_id
                );

                mysqli_stmt_execute($balance_stmt);

                $balance_result = mysqli_stmt_get_result($balance_stmt);
                $balance_data = mysqli_fetch_assoc($balance_result);

                $current_balance = (float) $balance_data["savings_balance"];

                mysqli_stmt_close($balance_stmt);

                // Prevent withdrawing more than the available savings
                if (
                    $contribution_type === "withdrawal" &&
                    $amount > $current_balance
                ) {
                    throw new Exception(
                        "Insufficient savings balance. Available balance: " .
                        number_format($current_balance, 2)
                    );
                }

                // Insert contribution
                $insert_sql = "
                    INSERT INTO savings_contributions
                    (
                        goal_id,
                        contribution_type,
                        amount,
                        contribution_date,
                        notes
                    )
                    VALUES (?, ?, ?, ?, ?)
                ";

                $insert_stmt = mysqli_prepare($conn, $insert_sql);

                mysqli_stmt_bind_param(
                    $insert_stmt,
                    "isdss",
                    $goal_id,
                    $contribution_type,
                    $amount,
                    $contribution_date,
                    $notes
                );

                if (!mysqli_stmt_execute($insert_stmt)) {
                    throw new Exception("Unable to save the contribution.");
                }

                $contributionId=(int)mysqli_stmt_insert_id($insert_stmt);
                fw_revision($conn,$user_id,'savings_contribution',$contributionId,'created',null,['goal_id'=>$goal_id,'type'=>$contribution_type,'amount'=>number_format($amount,2,'.',''),'date'=>$contribution_date,'notes'=>$notes]);
                mysqli_stmt_close($insert_stmt);
                if(!mysqli_commit($conn))throw new RuntimeException('Unable to finish contribution.');

                $_SESSION["savings_message"] =
                    ($contribution_type === "deposit")
                    ? "Savings deposit recorded successfully!"
                    : "Savings withdrawal recorded successfully!";

                $_SESSION["savings_message_type"] = "success";

                header("Location: index.php?history=" . $goal_id);
                exit();

            } catch (Throwable $e) {

                mysqli_rollback($conn);

                error_log('Savings operation failed: '.$e->getMessage()); $message = 'Unable to save this contribution. Check the amount and available savings balance.';
                $message_type = "danger";
            }
        }
    }
}

// Load savings goals and their balances
$goals = [];

$goals_sql = "
    SELECT
        sg.goal_id,
        sg.goal_name,
        sg.target_amount,
        sg.target_date,
        sg.created_at,

        COALESCE(
            SUM(
                CASE
                    WHEN sc.contribution_type = 'deposit'
                    THEN sc.amount
                    WHEN sc.contribution_type = 'withdrawal'
                    THEN -sc.amount
                    ELSE 0
                END
            ),
            0
        ) AS savings_balance

    FROM savings_goals sg

    LEFT JOIN savings_contributions sc
        ON sg.goal_id = sc.goal_id

    WHERE sg.user_id = ?

    GROUP BY
        sg.goal_id,
        sg.goal_name,
        sg.target_amount,
        sg.target_date,
        sg.created_at

    ORDER BY sg.created_at DESC
";

$goals_stmt = mysqli_prepare($conn, $goals_sql);
mysqli_stmt_bind_param($goals_stmt, "i", $user_id);
mysqli_stmt_execute($goals_stmt);

$goals_result = mysqli_stmt_get_result($goals_stmt);

while ($row = mysqli_fetch_assoc($goals_result)) {
    $row["target_amount"] = (float) $row["target_amount"];
    $row["savings_balance"] = (float) $row["savings_balance"];

    $goals[] = $row;
}

mysqli_stmt_close($goals_stmt);

$archiveRows=fw_query($conn,'SELECT goal_id FROM fw_goal_archives WHERE user_id=?','i',[$user_id]);
$archivedIds=array_map(static fn($r)=>(int)$r['goal_id'],$archiveRows);
// Calculate total savings
$total_savings = 0;
$total_targets = 0;

foreach ($goals as $goal) {
    $total_savings += $goal["savings_balance"];
    $total_targets += $goal["target_amount"];
}

// Load goal being edited
$edit_goal = null;

if (isset($_GET["edit"])) {

    $edit_id = (int) $_GET["edit"];

    $edit_sql = "
        SELECT *
        FROM savings_goals
        WHERE goal_id = ?
        AND user_id = ?
    ";

    $edit_stmt = mysqli_prepare($conn, $edit_sql);
    mysqli_stmt_bind_param($edit_stmt, "ii", $edit_id, $user_id);
    mysqli_stmt_execute($edit_stmt);

    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $edit_goal = mysqli_fetch_assoc($edit_result);

    mysqli_stmt_close($edit_stmt);

    if (!$edit_goal) {
        $message = "Savings goal not found.";
        $message_type = "danger";
    }
}

// Load contribution history for selected goal
$history_goal = null;
$contributions = [];
$history_balance = 0;

if (isset($_GET["history"])) {

    $history_id = (int) $_GET["history"];

    $history_goal_sql = "
        SELECT goal_id, goal_name, target_amount
        FROM savings_goals
        WHERE goal_id = ?
        AND user_id = ?
    ";

    $history_goal_stmt = mysqli_prepare($conn, $history_goal_sql);

    mysqli_stmt_bind_param(
        $history_goal_stmt,
        "ii",
        $history_id,
        $user_id
    );

    mysqli_stmt_execute($history_goal_stmt);

    $history_goal_result = mysqli_stmt_get_result($history_goal_stmt);
    $history_goal = mysqli_fetch_assoc($history_goal_result);

    mysqli_stmt_close($history_goal_stmt);

    if ($history_goal) {

        $history_sql = "
            SELECT
                contribution_id,
                contribution_type,
                amount,
                contribution_date,
                notes
            FROM savings_contributions
            WHERE goal_id = ?
            ORDER BY contribution_date DESC, contribution_id DESC
        ";

        $history_stmt = mysqli_prepare($conn, $history_sql);

        mysqli_stmt_bind_param(
            $history_stmt,
            "i",
            $history_id
        );

        mysqli_stmt_execute($history_stmt);

        $history_result = mysqli_stmt_get_result($history_stmt);

        while ($row = mysqli_fetch_assoc($history_result)) {
            $contributions[] = $row;
        }

        mysqli_stmt_close($history_stmt);
    } else {
        $message = "Savings goal not found.";
        $message_type = "danger";
    }
}

require_once "../includes/currency_display.php";
$currency = fw_user_currency($conn, $user_id);
?>

<?php
$pageTitle = "Savings";
ob_start();
?>
<style>

        body {
            background: #f5f3fb;
            font-family: Arial, sans-serif;
            color: #29243a;
        }

        .navbar {
            background: #6c4ab6;
        }

        .navbar-brand,
        .navbar .nav-link {
            color: white !important;
        }

        .page-title {
            color: #38245f;
            font-weight: 700;
        }

        .main-card,
        .summary-card {
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(40, 20, 80, 0.06);
        }

        .summary-card {
            padding: 22px;
            height: 100%;
        }

        .summary-card h6 {
            color: #777;
            margin-bottom: 10px;
        }

        .summary-card h3 {
            font-weight: 700;
            margin: 0;
        }

        .purple-color {
            color: #6c4ab6;
        }

        .green-color {
            color: #15966b;
        }

        .red-color {
            color: #dc5265;
        }

        .btn-purple {
            background: #6c4ab6;
            color: white;
            border: none;
        }

        .btn-purple:hover {
            background: #563795;
            color: white;
        }

        .form-control,
        .form-select {
            border-radius: 9px;
            padding: 10px 12px;
        }

        .goal-card {
            background: white;
            border: 1px solid #eeeaf5;
            border-radius: 15px;
            padding: 20px;
            height: 100%;
        }

        .progress {
            height: 11px;
            border-radius: 20px;
            background: #eeeaf5;
        }

        .progress-bar {
            border-radius: 20px;
        }

        .table th {
            color: #6b6280;
            font-size: 13px;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .muted-text {
            color: #777;
            font-size: 13px;
        }

        .deposit-badge {
            background: #e2f7ed;
            color: #16845c;
        }

        .withdrawal-badge {
            background: #fde8eb;
            color: #c43e53;
        }

    </style>
<?php
$pageHead = ob_get_clean();
require_once __DIR__ . "/../includes/header.php";
?>
<div class="container-fluid px-4 py-4">

    <!-- PAGE HEADER -->

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">

        <div>

            <h2 class="page-title mb-1">Savings Goals</h2>

            <p class="text-muted mb-0">
                Set financial goals and track your savings progress.
            </p>

        </div>

        <a href="#goalForm" class="btn btn-purple">

            <i class="bi bi-plus-lg me-1"></i>
            Create Savings Goal

        </a>

    </div>

    <?php if ($message !== ""): ?>

        <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show">

            <?php echo htmlspecialchars($message); ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>

    <!-- SUMMARY CARDS -->

    <div class="row g-3 mb-4">

        <div class="col-md-4">

            <div class="summary-card">

                <h6>
                    <i class="bi bi-piggy-bank me-2"></i>
                    Total Savings
                </h6>

                <h3 class="green-color">
                    <?php echo $currency . number_format($total_savings, 2); ?>
                </h3>

            </div>

        </div>

        <div class="col-md-4">

            <div class="summary-card">

                <h6>
                    <i class="bi bi-bullseye me-2"></i>
                    Combined Goal Targets
                </h6>

                <h3 class="purple-color">
                    <?php echo $currency . number_format($total_targets, 2); ?>
                </h3>

            </div>

        </div>

        <div class="col-md-4">

            <div class="summary-card">

                <h6>
                    <i class="bi bi-flag me-2"></i>
                    Savings Goals
                </h6>

                <h3 class="purple-color">
                    <?php echo count($goals); ?>
                </h3>

            </div>

        </div>

    </div>

    <!-- CREATE OR EDIT GOAL -->

    <div class="main-card p-4 mb-4" id="goalForm">

        <h5 class="fw-bold mb-3">

            <?php echo $edit_goal ? "Edit Savings Goal" : "Create New Savings Goal"; ?>

        </h5>

        <form method="POST"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">

            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

            <input type="hidden" name="action" value="save_goal">

            <input type="hidden" name="goal_id"
                   value="<?php echo (int) ($edit_goal["goal_id"] ?? 0); ?>">

            <div class="row g-3">

                <div class="col-md-4">

                    <label class="form-label fw-semibold">
                        Goal Name *
                    </label>

                    <input type="text" name="goal_name"
                           class="form-control" maxlength="100"
                           placeholder="Example: Emergency Fund"
                           value="<?php echo htmlspecialchars($edit_goal["goal_name"] ?? ""); ?>"
                           required>

                </div>

                <div class="col-md-4">

                    <label class="form-label fw-semibold">
                        Target Amount (PHP) *
                    </label>

                    <input type="number" name="target_amount"
                           class="form-control" min="0.01" step="0.01"
                           placeholder="Example: 20000"
                           value="<?php echo htmlspecialchars($edit_goal["target_amount"] ?? ""); ?>"
                           required>

                </div>

                <div class="col-md-4">

                    <label class="form-label fw-semibold">
                        Target Date *
                    </label>

                    <input type="date" name="target_date"
                           class="form-control"
                           value="<?php echo htmlspecialchars(
                               $edit_goal["target_date"] ?? date("Y-m-d", strtotime("+6 months"))
                           ); ?>"
                           required>

                </div>

                <div class="col-12 d-flex gap-2">

                    <button type="submit" class="btn btn-purple">

                        <i class="bi bi-check-circle me-1"></i>

                        <?php echo $edit_goal ? "Update Goal" : "Save Goal"; ?>

                    </button>

                    <?php if ($edit_goal): ?>

                        <a href="index.php" class="btn btn-outline-secondary">
                            Cancel Edit
                        </a>

                    <?php endif; ?>

                </div>

            </div>

        </form>

    </div>

    <!-- RECORD CONTRIBUTION -->

    <?php if (count($goals) > 0): ?>

        <div class="main-card p-4 mb-4">

            <h5 class="fw-bold mb-3">
                <i class="bi bi-cash-coin me-2"></i>
                Record Savings Contribution
            </h5>

            <form method="POST"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">

                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                <input type="hidden" name="action" value="add_contribution">

                <div class="row g-3">

                    <div class="col-md-3">

                        <label class="form-label fw-semibold">
                            Savings Goal *
                        </label>

                        <select name="goal_id" class="form-select" required>

                            <option value="">Select Goal</option>

                            <?php foreach ($goals as $goal): ?>

                                <option value="<?php echo (int) $goal["goal_id"]; ?>"
                                    <?php echo (
                                        isset($_GET["history"]) &&
                                        (int) $_GET["history"] === (int) $goal["goal_id"]
                                    ) ? "selected" : ""; ?>>

                                    <?php echo htmlspecialchars($goal["goal_name"]); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-2">

                        <label class="form-label fw-semibold">
                            Type *
                        </label>

                        <select name="contribution_type" class="form-select" required>

                            <option value="deposit">Deposit</option>
                            <option value="withdrawal">Withdrawal</option>

                        </select>

                    </div>

                    <div class="col-md-2">

                        <label class="form-label fw-semibold">
                            Amount (PHP) *
                        </label>

                        <input type="number" name="amount"
                               class="form-control" min="0.01"
                               step="0.01" placeholder="0.00" required>

                    </div>

                    <div class="col-md-2">

                        <label class="form-label fw-semibold">
                            Date *
                        </label>

                        <input type="date" name="contribution_date"
                               class="form-control"
                               value="<?php echo date("Y-m-d"); ?>"
                               required>

                    </div>

                    <div class="col-md-3">

                        <label class="form-label fw-semibold">
                            Notes
                        </label>

                        <input type="text" name="notes"
                               class="form-control" maxlength="255"
                               placeholder="Optional notes">

                    </div>

                    <div class="col-12">

                        <button type="submit" class="btn btn-purple">

                            <i class="bi bi-plus-circle me-1"></i>
                            Save Contribution

                        </button>

                    </div>

                </div>

            </form>

        </div>

    <?php endif; ?>

    <!-- SAVINGS GOAL CARDS -->

    <div class="main-card p-4 mb-4">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <h5 class="fw-bold mb-0">Your Savings Goals</h5>

            <span class="text-muted small">
                <?php echo count($goals); ?> goal(s)
            </span>

        </div>

        <?php if (count($goals) > 0): ?>

            <div class="row g-3">

                <?php foreach ($goals as $goal): ?>

                    <?php

                        $target = $goal["target_amount"];
                        $balance = $goal["savings_balance"];

                        $remaining = $target - $balance;

                        $percentage = $target > 0
                            ? ($balance / $target) * 100
                            : 0;

                        $bar_percentage = min(100, max(0, $percentage));

                        $goal_completed = $balance >= $target;

                    ?>

                    <div class="col-xl-4 col-md-6">

                        <div class="goal-card">

                            <div class="d-flex justify-content-between align-items-start mb-3">

                                <div>

                                    <h5 class="fw-bold mb-1">
                                        <?php echo htmlspecialchars($goal["goal_name"]); ?>
                                    </h5>

                                    <span class="muted-text">

                                        <i class="bi bi-calendar-event me-1"></i>

                                        Target:
                                        <?php echo date("M d, Y", strtotime($goal["target_date"])); ?>

                                    </span>

                                </div>

                                <?php if(in_array((int)$goal['goal_id'],$archivedIds,true)): ?><span class="badge bg-secondary">Archived</span><?php elseif ($goal_completed): ?>

                                    <span class="badge bg-success">
                                        Completed
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-primary">
                                        In Progress
                                    </span>

                                <?php endif; ?>

                            </div>

                            <div class="mb-2">

                                <span class="muted-text">
                                    Current Savings
                                </span>

                                <h3 class="green-color fw-bold mb-0">

                                    <?php echo $currency . number_format($balance, 2); ?>

                                </h3>

                            </div>

                            <div class="d-flex justify-content-between mb-2">

                                <span class="muted-text">
                                    Target Amount
                                </span>

                                <strong>
                                    <?php echo $currency . number_format($target, 2); ?>
                                </strong>

                            </div>

                            <div class="progress mb-2">

                                <div class="progress-bar <?php echo $goal_completed ? "bg-success" : "bg-primary"; ?>"
                                     style="width: <?php echo $bar_percentage; ?>%">

                                </div>

                            </div>

                            <div class="d-flex justify-content-between mb-3">

                                <span class="muted-text">
                                    <?php echo number_format($percentage, 1); ?>% saved
                                </span>

                                <span class="<?php echo $remaining <= 0 ? "green-color" : "purple-color"; ?> fw-semibold small">

                                    <?php if ($remaining <= 0): ?>

                                        Goal reached!

                                    <?php else: ?>

                                        <?php echo $currency . number_format($remaining, 2); ?> to go

                                    <?php endif; ?>

                                </span>

                            </div>

                            <div class="d-flex flex-wrap gap-2">

                                <a href="index.php?history=<?php echo (int) $goal["goal_id"]; ?>"
                                   class="btn btn-sm btn-outline-success flex-fill">

                                    <i class="bi bi-clock-history me-1"></i>
                                    History

                                </a>

                                <a href="index.php?edit=<?php echo (int) $goal["goal_id"]; ?>"
                                   class="btn btn-sm btn-outline-primary flex-fill">

                                    <i class="bi bi-pencil me-1"></i>
                                    Edit

                                </a>

                                <form method="POST"
                                      onsubmit="return confirm('Archive this goal? Its balance and contribution history will be preserved.');"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">

                                    <input type="hidden" name="csrf_token"
                                           value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                                    <input type="hidden" name="action" value="<?= in_array((int)$goal['goal_id'],$archivedIds,true)?'restore_goal':'delete_goal' ?>">

                                    <input type="hidden" name="goal_id"
                                           value="<?php echo (int) $goal["goal_id"]; ?>">

                                    <button type="submit"
                                            class="btn btn-sm btn-outline-danger">

                                        <i class="bi bi-archive" aria-hidden="true"></i> <?= in_array((int)$goal['goal_id'],$archivedIds,true)?'Restore':'Archive' ?>

                                    </button>

                                </form>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="text-center text-muted py-5">

                <i class="bi bi-piggy-bank fs-1 d-block mb-3"></i>

                <h6>No savings goals yet.</h6>

                <p class="mb-0">
                    Create your first savings goal to start tracking your progress.
                </p>

            </div>

        <?php endif; ?>

    </div>

    <!-- CONTRIBUTION HISTORY -->

    <?php if ($history_goal): ?>

        <div class="main-card p-4 mb-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">

                <div>

                    <h5 class="fw-bold mb-1">
                        Contribution History
                    </h5>

                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($history_goal["goal_name"]); ?>
                    </p>

                </div>

                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    Close History
                </a>

            </div>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead>

                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Notes</th>
                            <th>Amount</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (count($contributions) > 0): ?>

                            <?php foreach ($contributions as $contribution): ?>

                                <tr>

                                    <td>
                                        <?php echo date("M d, Y", strtotime($contribution["contribution_date"])); ?>
                                    </td>

                                    <td>

                                        <?php if ($contribution["contribution_type"] === "deposit"): ?>

                                            <span class="badge deposit-badge">
                                                Deposit
                                            </span>

                                        <?php else: ?>

                                            <span class="badge withdrawal-badge">
                                                Withdrawal
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($contribution["notes"] ?: "No notes"); ?>
                                    </td>

                                    <td class="fw-bold <?php echo $contribution["contribution_type"] === "deposit" ? "green-color" : "red-color"; ?>">

                                        <?php echo (
                                            $contribution["contribution_type"] === "deposit"
                                            ? "+"
                                            : "-"
                                        ); ?>

                                        <?php echo $currency . number_format((float) $contribution["amount"], 2); ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="4" class="text-center text-muted py-4">
                                    No contributions recorded for this goal yet.
                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>

    <div class="text-center text-muted small mt-4">
        Fundwise Personal Finance Management System
    </div>

</div>



<?php require_once __DIR__ . "/../includes/footer.php"; ?>
