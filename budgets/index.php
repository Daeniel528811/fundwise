<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";

$user_id = (int) $_SESSION["user_id"];
$message = "";
$message_type = "success";

// CSRF protection
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Flash messages
if (isset($_SESSION["budget_message"])) {
    $message = $_SESSION["budget_message"];
    $message_type = $_SESSION["budget_message_type"] ?? "success";

    unset($_SESSION["budget_message"]);
    unset($_SESSION["budget_message_type"]);
}

// Validate a date
function validBudgetDate($date)
{
    return fw_valid_date($date);
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !is_string($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        die("Invalid request. Please refresh the page and try again.");
    }

    $action = fw_text($_POST["action"] ?? null);

    // ADD OR UPDATE BUDGET
    if ($action === "save") {

        $budget_id = fw_positive_id($_POST["budget_id"] ?? null);
        $category_id = fw_positive_id($_POST["category_id"] ?? null);
        $budget_limit = fw_money($_POST["budget_limit"] ?? null);
        $start_date = fw_text($_POST["start_date"] ?? null);
        $end_date = fw_text($_POST["end_date"] ?? null);

        if (
            $category_id <= 0 ||
            $budget_limit === null ||
            !validBudgetDate($start_date) ||
            !validBudgetDate($end_date) ||
            $start_date > $end_date
        ) {
            $message = "Please enter a valid category, budget amount, and date range.";
            $message_type = "danger";
        } else {

            // Check that the category belongs to this user
            // and is an expense category
            $category_sql = "
                SELECT category_id
                FROM categories
                WHERE category_id = ?
                AND user_id = ?
                AND category_type = 'expense'
            ";

            $category_stmt = mysqli_prepare($conn, $category_sql);
            mysqli_stmt_bind_param($category_stmt, "ii", $category_id, $user_id);
            mysqli_stmt_execute($category_stmt);
            $category_result = mysqli_stmt_get_result($category_stmt);

            $valid_category = mysqli_num_rows($category_result) > 0;

            mysqli_stmt_close($category_stmt);

            if (!$valid_category) {

                $message = "Please select one of your valid expense categories.";
                $message_type = "danger";

            } else {

                // Verify ownership before editing an existing budget.
                $valid_budget = true;
                if ($budget_id > 0) {
                    $owner_stmt = mysqli_prepare($conn, "
                        SELECT budget_id FROM budgets
                        WHERE budget_id = ? AND user_id = ?
                    ");
                    mysqli_stmt_bind_param($owner_stmt, "ii", $budget_id, $user_id);
                    mysqli_stmt_execute($owner_stmt);
                    $valid_budget = mysqli_num_rows(mysqli_stmt_get_result($owner_stmt)) > 0;
                    mysqli_stmt_close($owner_stmt);
                }

                // Serialize budget writes per owner before the overlap check.
                if(!mysqli_begin_transaction($conn))throw new RuntimeException('Unable to start budget update.');
                fw_query($conn,'SELECT user_id FROM users WHERE user_id=? FOR UPDATE','i',[$user_id]);
                // Prevent overlapping budgets for the same expense category.
                // Both start and end dates are inclusive.
                $overlap_stmt = mysqli_prepare($conn, "
                    SELECT budget_id FROM budgets
                    WHERE user_id = ?
                      AND category_id = ?
                      AND budget_id <> ?
                      AND start_date <= ?
                      AND end_date >= ?
                    LIMIT 1
                ");
                mysqli_stmt_bind_param(
                    $overlap_stmt, "iiiss",
                    $user_id, $category_id, $budget_id, $end_date, $start_date
                );
                mysqli_stmt_execute($overlap_stmt);
                $has_overlap = mysqli_num_rows(mysqli_stmt_get_result($overlap_stmt)) > 0;
                mysqli_stmt_close($overlap_stmt);

                if (!$valid_budget) {
                    $message = "Budget not found or you do not have permission to edit it.";
                    $message_type = "danger";
                } elseif ($has_overlap) {
                    $message = "This category already has a budget covering some of those dates. Choose a non-overlapping date range.";
                    $message_type = "danger";
                } else {

                if ($budget_id > 0) {

                    // Update only the current user's budget
                    $sql = "
                        UPDATE budgets
                        SET category_id = ?,
                            budget_limit = ?,
                            start_date = ?,
                            end_date = ?
                        WHERE budget_id = ?
                        AND user_id = ?
                    ";

                    $stmt = mysqli_prepare($conn, $sql);

                    mysqli_stmt_bind_param(
                        $stmt,
                        "idssii",
                        $category_id,
                        $budget_limit,
                        $start_date,
                        $end_date,
                        $budget_id,
                        $user_id
                    );

                } else {

                    // Create a new budget
                    $sql = "
                        INSERT INTO budgets
                        (
                            user_id,
                            category_id,
                            budget_limit,
                            start_date,
                            end_date
                        )
                        VALUES (?, ?, ?, ?, ?)
                    ";

                    $stmt = mysqli_prepare($conn, $sql);

                    mysqli_stmt_bind_param(
                        $stmt,
                        "iidss",
                        $user_id,
                        $category_id,
                        $budget_limit,
                        $start_date,
                        $end_date
                    );
                }

                if (mysqli_stmt_execute($stmt)) {

                    if(!mysqli_commit($conn))throw new RuntimeException('Unable to finish budget update.');
                    $_SESSION["budget_message"] =
                        ($budget_id > 0)
                        ? "Budget updated successfully!"
                        : "Budget created successfully!";

                    $_SESSION["budget_message_type"] = "success";

                    mysqli_stmt_close($stmt);

                    header("Location: index.php");
                    exit();

                } else {

                    $message = "Unable to save the budget. Please try again.";
                    $message_type = "danger";

                    mysqli_stmt_close($stmt);
                }
                } // End validated budget save.
                if($message!=='')mysqli_rollback($conn);
            }
        }
    }

    // DELETE BUDGET
    if ($action === "delete") {

        $budget_id = fw_positive_id($_POST["budget_id"] ?? null);

        $delete_sql = "
            DELETE FROM budgets
            WHERE budget_id = ?
            AND user_id = ?
        ";

        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "ii", $budget_id, $user_id);

        if (mysqli_stmt_execute($delete_stmt)) {

            if (mysqli_stmt_affected_rows($delete_stmt) > 0) {
                $_SESSION["budget_message"] = "Budget deleted successfully!";
                $_SESSION["budget_message_type"] = "success";
            } else {
                $_SESSION["budget_message"] = "Budget not found.";
                $_SESSION["budget_message_type"] = "danger";
            }

            mysqli_stmt_close($delete_stmt);

            header("Location: index.php");
            exit();

        } else {

            $message = "Unable to delete the budget.";
            $message_type = "danger";

            mysqli_stmt_close($delete_stmt);
        }
    }
}

// Get the user's expense categories
$categories = [];

$category_sql = "
    SELECT category_id, category_name
    FROM categories
    WHERE user_id = ?
    AND category_type = 'expense'
    ORDER BY category_name
";

$category_stmt = mysqli_prepare($conn, $category_sql);
mysqli_stmt_bind_param($category_stmt, "i", $user_id);
mysqli_stmt_execute($category_stmt);

$category_result = mysqli_stmt_get_result($category_stmt);

while ($row = mysqli_fetch_assoc($category_result)) {
    $categories[] = $row;
}

mysqli_stmt_close($category_stmt);

// Get budget being edited
$edit_budget = null;

if (isset($_GET["edit"])) {

    $edit_id = (int) $_GET["edit"];

    $edit_sql = "
        SELECT *
        FROM budgets
        WHERE budget_id = ?
        AND user_id = ?
    ";

    $edit_stmt = mysqli_prepare($conn, $edit_sql);
    mysqli_stmt_bind_param($edit_stmt, "ii", $edit_id, $user_id);
    mysqli_stmt_execute($edit_stmt);

    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $edit_budget = mysqli_fetch_assoc($edit_result);

    mysqli_stmt_close($edit_stmt);

    if (!$edit_budget) {
        $message = "Budget not found.";
        $message_type = "danger";
    }
}

// Get all budgets and calculate spending
$budget_sql = "
    SELECT
        b.budget_id,
        b.category_id,
        b.budget_limit,
        b.start_date,
        b.end_date,
        c.category_name,

        COALESCE(
            (
                SELECT SUM(t.amount)
                FROM transactions t
                WHERE t.user_id = b.user_id
                AND t.category_id = b.category_id
                AND t.transaction_type = 'expense'
                AND t.transaction_date BETWEEN b.start_date AND LEAST(b.end_date,CURDATE())
            ),
            0
        ) AS total_spent

    FROM budgets b

    INNER JOIN categories c
        ON b.category_id = c.category_id

    WHERE b.user_id = ?

    ORDER BY b.start_date DESC, b.budget_id DESC
";

$budget_stmt = mysqli_prepare($conn, $budget_sql);
mysqli_stmt_bind_param($budget_stmt, "i", $user_id);
mysqli_stmt_execute($budget_stmt);

$budget_result = mysqli_stmt_get_result($budget_stmt);

// Calculate overall budget summary
$total_budget_limit = 0;
$total_budget_spent = 0;

$budget_rows = [];

while ($budget = mysqli_fetch_assoc($budget_result)) {

    $budget["budget_limit"] = (float) $budget["budget_limit"];
    $budget["total_spent"] = (float) $budget["total_spent"];

    $budget_rows[] = $budget;

    $total_budget_limit += $budget["budget_limit"];
    $total_budget_spent += $budget["total_spent"];
}

$total_budget_remaining = $total_budget_limit - $total_budget_spent;

$overall_percentage = $total_budget_limit > 0
    ? ($total_budget_spent / $total_budget_limit) * 100
    : 0;

require_once "../includes/currency_display.php";
$currency = fw_user_currency($conn, $user_id);
?>

<?php
$pageTitle = "Budgets";
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

        .progress {
            height: 10px;
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

        .budget-card {
            border: 1px solid #eeeaf5;
            border-radius: 13px;
            padding: 18px;
            height: 100%;
        }

        .muted-text {
            color: #777;
            font-size: 13px;
        }
    </style>
<?php
$pageHead = ob_get_clean();
require_once __DIR__ . "/../includes/header.php";
?>
<div class="container-fluid px-4 py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">

        <div>
            <h2 class="page-title mb-1">Budget Management</h2>
            <p class="text-muted mb-0">
                Set spending limits and keep track of your expenses.
            </p>
        </div>

        <a href="#budgetForm" class="btn btn-purple">
            <i class="bi bi-plus-lg me-1"></i> Create Budget
        </a>

    </div>

    <?php if ($message !== ""): ?>

        <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($message); ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>

    <?php endif; ?>

    <!-- BUDGET SUMMARY -->

    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="summary-card">

                <h6><i class="bi bi-wallet me-2"></i>Total Budget Limits</h6>

                <h3 class="purple-color">
                    <?php echo $currency . number_format($total_budget_limit, 2); ?>
                </h3>

            </div>
        </div>

        <div class="col-md-4">
            <div class="summary-card">

                <h6><i class="bi bi-cart me-2"></i>Total Spending</h6>

                <h3 class="red-color">
                    <?php echo $currency . number_format($total_budget_spent, 2); ?>
                </h3>

            </div>
        </div>

        <div class="col-md-4">
            <div class="summary-card">

                <h6><i class="bi bi-piggy-bank me-2"></i>Budget Remaining</h6>

                <h3 class="<?php echo $total_budget_remaining < 0 ? "red-color" : "green-color"; ?>">
                    <?php echo $currency . number_format($total_budget_remaining, 2); ?>
                </h3>

            </div>
        </div>

    </div>

    <!-- CREATE OR EDIT BUDGET -->

    <div class="main-card p-4 mb-4" id="budgetForm">

        <h5 class="fw-bold mb-3">

            <?php echo $edit_budget ? "Edit Budget" : "Create New Budget"; ?>

        </h5>

        <?php if (count($categories) === 0): ?>

            <div class="alert alert-warning">
                You don't have any expense categories yet.
                <a href="../categories/index.php">Manage Categories</a>
            </div>

        <?php else: ?>

            <form method="POST"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">

                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                <input type="hidden" name="action" value="save">

                <input type="hidden" name="budget_id"
                       value="<?php echo (int) ($edit_budget["budget_id"] ?? 0); ?>">

                <div class="row g-3">

                    <div class="col-md-3">

                        <label class="form-label fw-semibold">
                            Expense Category *
                        </label>

                        <select name="category_id" class="form-select" required>

                            <option value="">Select Category</option>

                            <?php foreach ($categories as $category): ?>

                                <option value="<?php echo (int) $category["category_id"]; ?>"
                                    <?php echo (
                                        (int) ($edit_budget["category_id"] ?? 0)
                                        === (int) $category["category_id"]
                                    ) ? "selected" : ""; ?>>

                                    <?php echo htmlspecialchars($category["category_name"]); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-3">

                        <label class="form-label fw-semibold">
                            Budget Limit (PHP) *
                        </label>

                        <input type="number" name="budget_limit"
                               class="form-control" min="0.01" step="0.01"
                               placeholder="Example: 3000"
                               value="<?php echo htmlspecialchars($edit_budget["budget_limit"] ?? ""); ?>"
                               required>

                    </div>

                    <div class="col-md-3">

                        <label class="form-label fw-semibold">
                            Start Date *
                        </label>

                        <input type="date" name="start_date"
                               class="form-control"
                               value="<?php echo htmlspecialchars(
                                   $edit_budget["start_date"] ?? date("Y-m-01")
                               ); ?>"
                               required>

                    </div>

                    <div class="col-md-3">

                        <label class="form-label fw-semibold">
                            End Date *
                        </label>

                        <input type="date" name="end_date"
                               class="form-control"
                               value="<?php echo htmlspecialchars(
                                   $edit_budget["end_date"] ?? date("Y-m-t")
                               ); ?>"
                               required>

                    </div>

                    <div class="col-12 d-flex gap-2">

                        <button type="submit" class="btn btn-purple">

                            <i class="bi bi-check-circle me-1"></i>

                            <?php echo $edit_budget ? "Update Budget" : "Save Budget"; ?>

                        </button>

                        <?php if ($edit_budget): ?>

                            <a href="index.php" class="btn btn-outline-secondary">
                                Cancel Edit
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </form>

        <?php endif; ?>

    </div>

    <!-- OVERALL BUDGET PROGRESS -->

    <?php if (count($budget_rows) > 0): ?>

        <div class="main-card p-4 mb-4">

            <div class="d-flex justify-content-between align-items-center mb-2">

                <h5 class="fw-bold mb-0">Overall Budget Progress</h5>

                <span class="fw-semibold purple-color">
                    <?php echo number_format($overall_percentage, 1); ?>%
                </span>

            </div>

            <?php
                $overall_bar = min(100, max(0, $overall_percentage));
                $overall_color = $overall_percentage > 100 ? "bg-danger" : "bg-primary";
            ?>

            <div class="progress">
                <div class="progress-bar <?php echo $overall_color; ?>"
                     style="width: <?php echo $overall_bar; ?>%">
                </div>
            </div>

            <p class="muted-text mt-2 mb-0">
                Combined spending compared with the combined limits of your budgets.
            </p>

        </div>

    <?php endif; ?>

    <!-- BUDGET LIST -->

    <div class="main-card p-4">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <h5 class="fw-bold mb-0">Your Budgets</h5>

            <span class="text-muted small">
                <?php echo count($budget_rows); ?> budget(s)
            </span>

        </div>

        <?php if (count($budget_rows) > 0): ?>

            <div class="row g-3">

                <?php foreach ($budget_rows as $budget): ?>

                    <?php

                        $limit = $budget["budget_limit"];
                        $spent = $budget["total_spent"];
                        $remaining = $limit - $spent;

                        $percentage = $limit > 0
                            ? ($spent / $limit) * 100
                            : 0;

                        $bar_percentage = min(100, max(0, $percentage));

                        if ($percentage >= 100) {
                            $bar_color = "bg-danger";
                            $status = "Over Budget";
                            $status_color = "text-danger";
                        } elseif ($percentage >= 80) {
                            $bar_color = "bg-warning";
                            $status = "Near Limit";
                            $status_color = "text-warning";
                        } else {
                            $bar_color = "bg-success";
                            $status = "Within Budget";
                            $status_color = "text-success";
                        }

                    ?>

                    <div class="col-xl-4 col-md-6">

                        <div class="budget-card">

                            <div class="d-flex justify-content-between align-items-start mb-3">

                                <div>

                                    <h6 class="fw-bold mb-1">
                                        <?php echo htmlspecialchars($budget["category_name"]); ?>
                                    </h6>

                                    <span class="muted-text">
                                        <?php echo date("M d, Y", strtotime($budget["start_date"])); ?>
                                        -
                                        <?php echo date("M d, Y", strtotime($budget["end_date"])); ?>
                                    </span>

                                </div>

                                <span class="<?php echo $status_color; ?> small fw-semibold">
                                    <?php echo $status; ?>
                                </span>

                            </div>

                            <div class="d-flex justify-content-between mb-2">

                                <span class="muted-text">Spent</span>

                                <strong class="red-color">
                                    <?php echo $currency . number_format($spent, 2); ?>
                                </strong>

                            </div>

                            <div class="progress mb-2">

                                <div class="progress-bar <?php echo $bar_color; ?>"
                                     style="width: <?php echo $bar_percentage; ?>%">
                                </div>

                            </div>

                            <div class="d-flex justify-content-between mb-3">

                                <span class="muted-text">
                                    <?php echo number_format($percentage, 1); ?>% used
                                </span>

                                <span class="<?php echo $remaining < 0 ? "red-color" : "green-color"; ?> fw-semibold small">

                                    <?php if ($remaining < 0): ?>

                                        <?php echo $currency . number_format(abs($remaining), 2); ?> over

                                    <?php else: ?>

                                        <?php echo $currency . number_format($remaining, 2); ?> left

                                    <?php endif; ?>

                                </span>

                            </div>

                            <div class="d-flex gap-2">

                                <a href="index.php?edit=<?php echo (int) $budget["budget_id"]; ?>"
                                   class="btn btn-sm btn-outline-primary flex-fill">

                                    <i class="bi bi-pencil me-1"></i> Edit

                                </a>

                                <form method="POST" class="flex-fill"
                                      onsubmit="return confirm('Are you sure you want to delete this budget?');"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">

                                    <input type="hidden" name="csrf_token"
                                           value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                                    <input type="hidden" name="action" value="delete">

                                    <input type="hidden" name="budget_id"
                                           value="<?php echo (int) $budget["budget_id"]; ?>">

                                    <button type="submit"
                                            class="btn btn-sm btn-outline-danger w-100">

                                        <i class="bi bi-trash me-1"></i> Delete

                                    </button>

                                </form>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="text-center text-muted py-5">

                <i class="bi bi-pie-chart fs-1 d-block mb-3"></i>

                <h6>No budgets created yet.</h6>

                <p class="mb-0">
                    Create your first budget to start monitoring your expenses.
                </p>

            </div>

        <?php endif; ?>

    </div>

    <div class="text-center text-muted small mt-4">
        Fundwise Personal Finance Management System
    </div>

</div>



<?php require_once __DIR__ . "/../includes/footer.php"; ?>

<?php
mysqli_stmt_close($budget_stmt);
?>
