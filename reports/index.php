<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";

$user_id = (int) $_SESSION["user_id"];

date_default_timezone_set("Asia/Manila");

$today = date("Y-m-d");
$period = fw_text($_GET["period"] ?? "monthly");

$start_date = "";
$end_date = $today;

// Determine report date range
switch ($period) {
    case "daily":
        $start_date = $today;
        $end_date = $today;
        break;

    case "weekly":
        $start_date = date("Y-m-d", strtotime("monday this week"));
        $end_date = date("Y-m-d", strtotime("sunday this week"));
        break;

    case "custom":
        $start_date = fw_text($_GET["start_date"] ?? date("Y-m-01"));
        $end_date = fw_text($_GET["end_date"] ?? $today);
        break;

    case "monthly":
    default:
        $period = "monthly";
        $start_date = date("Y-m-01");
        $end_date = date("Y-m-t");
        break;
}

// Validate dates
$date_error = "";

if (!fw_valid_date($start_date) || !fw_valid_date($end_date)) {
    $date_error = "Please select valid dates.";
} elseif ($start_date > $end_date) {
    $date_error = "The start date cannot be later than the end date.";
}

require_once __DIR__ . '/../includes/currency_display.php';
$currency_code = fw_user_currency_code($conn, $user_id);

$total_income = 0;
$total_expenses = 0;
$remaining_balance = 0;
$transactions = [];
$category_breakdown = [];

if ($date_error === "") {

    // Financial summary
    $summary_sql = "
        SELECT
            COALESCE(SUM(
                CASE WHEN transaction_type = 'income'
                THEN amount ELSE 0 END
            ), 0) AS total_income,

            COALESCE(SUM(
                CASE WHEN transaction_type = 'expense'
                THEN amount ELSE 0 END
            ), 0) AS total_expenses

        FROM transactions
        WHERE user_id = ?
          AND transaction_date BETWEEN ? AND ? AND transaction_date <= CURDATE()
    ";

    $stmt = mysqli_prepare($conn, $summary_sql);
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $start_date, $end_date);
    mysqli_stmt_execute($stmt);

    $summary = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $total_income = (float) $summary["total_income"];
    $total_expenses = (float) $summary["total_expenses"];
    $remaining_balance = $total_income - $total_expenses;

    // Transaction report
    $transaction_sql = "
        SELECT
            t.transaction_id,
            t.description,
            t.transaction_type,
            t.amount,
            t.transaction_date,
            c.category_name

        FROM transactions t

        LEFT JOIN categories c
            ON t.category_id = c.category_id

        WHERE t.user_id = ?
          AND t.transaction_date BETWEEN ? AND ? AND t.transaction_date <= CURDATE()

        ORDER BY t.transaction_date DESC, t.created_at DESC LIMIT 5001
    ";

    $stmt = mysqli_prepare($conn, $transaction_sql);
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $start_date, $end_date);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $transactions[] = $row;
    }

    mysqli_stmt_close($stmt);

    if (count($transactions) > 5000) {
        http_response_code(422);
        exit('This report exceeds 5,000 records. Select a smaller custom date range. No partial report was exported.');
    }

    // Expense breakdown by category
    $breakdown_sql = "
        SELECT
            c.category_name,
            COALESCE(SUM(t.amount), 0) AS total_amount

        FROM transactions t

        LEFT JOIN categories c
            ON t.category_id = c.category_id

        WHERE t.user_id = ?
          AND t.transaction_type = 'expense'
          AND t.transaction_date BETWEEN ? AND ? AND t.transaction_date <= CURDATE()

        GROUP BY t.category_id, c.category_name
        ORDER BY total_amount DESC
    ";

    $stmt = mysqli_prepare($conn, $breakdown_sql);
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $start_date, $end_date);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $category_breakdown[] = $row;
    }

    mysqli_stmt_close($stmt);
}

// Printable PDF report, no Composer or PHP Zip extension required.
if (isset($_GET["export"]) && $_GET["export"] === "pdf" && $date_error === "") {
    require __DIR__ . "/pdf_export.php";
}

// Formatted XLSX download; uses the same date filter and per-user financial data.
if (isset($_GET["export"]) && $_GET["export"] === "xlsx" && $date_error === "") {
    require __DIR__ . "/xlsx_export.php";
}

// CSV EXPORT
if (isset($_GET["export"]) && $_GET["export"] === "csv" && $date_error === "") {

    header("Content-Type: text/csv; charset=UTF-8");
    header(
        "Content-Disposition: attachment; filename=fundwise_report_" .
        $start_date . "_to_" . $end_date . ".csv"
    );

    $output = fopen("php://output", "w");

    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ["FUNDWISE FINANCIAL REPORT"]);
    fputcsv($output, ["Currency", $currency_code]);
    fputcsv($output, ["Start Date", $start_date]);
    fputcsv($output, ["End Date", $end_date]);
    fputcsv($output, []);
    fputcsv($output, ["Total Income", number_format($total_income, 2, ".", "")]);
    fputcsv($output, ["Total Expenses", number_format($total_expenses, 2, ".", "")]);
    fputcsv($output, ["Remaining Balance", number_format($remaining_balance, 2, ".", "")]);
    fputcsv($output, []);

    fputcsv($output, [
        "Transaction ID",
        "Date",
        "Description",
        "Category",
        "Type",
        "Currency",
        "Amount"
    ]);

    foreach ($transactions as $transaction) {
        $description = (string) ($transaction["description"] ?? "");
        $category = (string) ($transaction["category_name"] ?? "Uncategorized");

        if (preg_match('/^[\s]*[=+\-@]/', $description)) {
            $description = "'" . $description;
        }

        if (preg_match('/^[\s]*[=+\-@]/', $category)) {
            $category = "'" . $category;
        }

        fputcsv($output, [
            $transaction["transaction_id"],
            $transaction["transaction_date"],
            $description,
            $category,
            ucfirst($transaction["transaction_type"]),
            $currency_code,
            number_format((float) $transaction["amount"], 2, ".", "")
        ]);
    }

    fclose($output);
    exit();
}
?>

<?php
$pageTitle = "Reports";
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
        .report-card {
            background: white;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
        }

        .summary-number {
            font-size: 25px;
            font-weight: bold;
            color: #5b21b6;
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

        .income-text {
            color: #15803d;
            font-weight: bold;
        }

        .expense-text {
            color: #dc2626;
            font-weight: bold;
        }

        .table th {
            background: #f3e8ff;
            color: #4c1d95;
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
                <h2 class="section-title">Reports</h2>
                <p class="text-muted">
                    Review your income and expenses for a selected period.
                </p>
            </div>

            <!-- Date Filter -->
            <div class="report-card mb-4">

                <h5 class="section-title mb-3">Select Report Period</h5>

                <form method="GET">
                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label">Report Period</label>

                            <select name="period" id="period"
                                    class="form-select"
                                    onchange="toggleCustomDates()">

                                <option value="daily" <?= $period === "daily" ? "selected" : "" ?>>Daily</option>
                                <option value="weekly" <?= $period === "weekly" ? "selected" : "" ?>>Weekly</option>
                                <option value="monthly" <?= $period === "monthly" ? "selected" : "" ?>>Monthly</option>
                                <option value="custom" <?= $period === "custom" ? "selected" : "" ?>>Custom Date Range</option>

                            </select>
                        </div>

                        <div class="col-md-3 custom-date">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date"
                                   class="form-control"
                                   value="<?= htmlspecialchars($start_date) ?>">
                        </div>

                        <div class="col-md-3 custom-date">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date"
                                   class="form-control"
                                   value="<?= htmlspecialchars($end_date) ?>">
                        </div>

                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-purple w-100">
                                Generate
                            </button>
                        </div>

                    </div>
                </form>

                <p class="text-muted small mt-3 mb-0">
                    Selected period:
                    <?= htmlspecialchars($start_date) ?>
                    to
                    <?= htmlspecialchars($end_date) ?>
                </p>

            </div>

            <?php if ($date_error !== ""): ?>

                <div class="alert alert-danger">
                    <?= htmlspecialchars($date_error) ?>
                </div>

            <?php else: ?>

                <!-- Summary Cards -->
                <div class="row g-3 mb-4">

                    <div class="col-md-4">
                        <div class="summary-card">
                            <p class="text-muted mb-1">Total Income</p>
                            <div class="summary-number income-text">
                                <?= fw_format_money($total_income, $currency_code) ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="summary-card">
                            <p class="text-muted mb-1">Total Expenses</p>
                            <div class="summary-number expense-text">
                                <?= fw_format_money($total_expenses, $currency_code) ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="summary-card">
                            <p class="text-muted mb-1">Remaining Balance</p>
                            <div class="summary-number">
                                <?= fw_format_money($remaining_balance, $currency_code) ?>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Category Breakdown -->
                <div class="report-card mb-4">

                    <h5 class="section-title mb-3">
                        Expense Breakdown by Category
                    </h5>

                    <?php if (empty($category_breakdown)): ?>

                        <p class="text-muted mb-0">
                            No expenses recorded for this period.
                        </p>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Total Expenses</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($category_breakdown as $category): ?>

                                        <?php
                                        $percentage = $total_expenses > 0
                                            ? ((float) $category["total_amount"] / $total_expenses) * 100
                                            : 0;
                                        ?>

                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($category["category_name"] ?? "Uncategorized") ?>
                                            </td>

                                            <td>
                                                <?= fw_format_money($category["total_amount"], $currency_code) ?>
                                            </td>

                                            <td>
                                                <?= number_format($percentage, 1) ?>%
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php endif; ?>

                </div>

                <!-- Transaction Report -->
                <div class="report-card">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                        <div>
                            <h5 class="section-title mb-1">
                                Transaction Report
                            </h5>

                            <p class="text-muted small mb-0">
                                <?= count($transactions) ?> transaction(s) found.
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <a href="?period=<?= urlencode($period) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&export=pdf"
                               class="btn btn-purple fw-export-action">
                                Export PDF (.pdf)
                            </a>
                            <a href="?period=<?= urlencode($period) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&export=xlsx"
                               class="btn btn-purple fw-export-action">
                                Export Excel (.xlsx)
                            </a>
                            <a href="?period=<?= urlencode($period) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&export=csv"
                               class="btn btn-purple fw-export-action">
                                Export CSV (.csv)
                            </a>
                        </div>

                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">

                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (empty($transactions)): ?>

                                    <tr>
                                        <td colspan="5" class="text-center text-muted">
                                            No transactions found for this period.
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($transactions as $transaction): ?>

                                        <tr>
                                            <td>
                                                <?= date("M d, Y", strtotime($transaction["transaction_date"])) ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($transaction["description"] ?: "No description") ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($transaction["category_name"] ?? "Uncategorized") ?>
                                            </td>

                                            <td>
                                                <?= ucfirst(htmlspecialchars($transaction["transaction_type"])) ?>
                                            </td>

                                            <td class="<?= $transaction["transaction_type"] === "income" ? "income-text" : "expense-text" ?>">
                                                <?= $transaction["transaction_type"] === "income" ? "+" : "-" ?>
                                                <?= fw_format_money($transaction["amount"], $currency_code) ?>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>
                        </table>
                    </div>

                </div>

            <?php endif; ?>

        

<script>
function toggleCustomDates() {
    const period = document.getElementById("period").value;
    const customFields = document.querySelectorAll(".custom-date");

    customFields.forEach(function(field) {
        field.style.display = period === "custom" ? "block" : "none";
    });
}

toggleCustomDates();
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
