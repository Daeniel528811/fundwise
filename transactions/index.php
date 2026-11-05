<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";
require_once "../includes/currency_display.php";

// Generate CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$user_id = (int) $_SESSION["user_id"];
$message = "";
$message_type = "success";

// Display messages after redirect
if (isset($_SESSION["transaction_message"])) {
    $message = $_SESSION["transaction_message"];
    $message_type = $_SESSION["transaction_message_type"] ?? "success";

    unset($_SESSION["transaction_message"]);
    unset($_SESSION["transaction_message_type"]);
}

// Receipt upload settings
$upload_directory = "../uploads/receipts/";
$allowed_types = ["image/jpeg", "image/png"];
$max_file_size = 5 * 1024 * 1024;

// Function for uploading receipt images
function uploadReceipt($file, $upload_directory, $allowed_types, $max_file_size)
{
    if (!is_array($file) || !isset($file["error"]) || $file["error"] === UPLOAD_ERR_NO_FILE) {
        return ["path" => null, "error" => ""];
    }

    if (!is_int($file["error"]) || $file["error"] !== UPLOAD_ERR_OK ||
        !isset($file["tmp_name"], $file["size"]) || !is_string($file["tmp_name"]) ||
        !is_int($file["size"]) || $file["size"] <= 0 ||
        !is_uploaded_file($file["tmp_name"])) {
        return ["path" => null, "error" => "Receipt upload failed. Please try again."];
    }

    if ($file["size"] > $max_file_size) {
        return ["path" => null, "error" => "Receipt image must not exceed 5 MB."];
    }

    $file_info = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $file_info->file($file["tmp_name"]);

    if (!in_array($mime_type, $allowed_types, true) || @getimagesize($file["tmp_name"]) === false) {
        return ["path" => null, "error" => "Only JPG and PNG receipt images are allowed."];
    }

    $extension = ($mime_type === "image/png") ? "png" : "jpg";
    $new_filename = bin2hex(random_bytes(16)) . "." . $extension;

    if (!is_dir($upload_directory)) {
        if (!mkdir($upload_directory, 0755, true)) {
            return ["path" => null, "error" => "Unable to create the receipt folder."];
        }
    }

    if (!move_uploaded_file($file["tmp_name"], $upload_directory . $new_filename)) {
        return ["path" => null, "error" => "Unable to save the receipt image."];
    }

    return [
        "path" => "uploads/receipts/" . $new_filename,
        "error" => ""
    ];
}

// Function for safely deleting an uploaded receipt
function deleteReceiptFile($receipt_path)
{
    if (empty($receipt_path)) {
        return;
    }

    $filename = basename((string) $receipt_path);
    if (!preg_match('/^[a-f0-9]{32}\.(?:png|jpg)$/D', $filename)) return;
    $full_path = "../uploads/receipts/" . $filename;

    if (is_file($full_path)) {
        unlink($full_path);
    }
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Verify CSRF token
    if (
        !fw_csrf_valid()
    ) {
        die("Invalid request. Please refresh the page and try again.");
    }

    $action = fw_text($_POST["action"] ?? null);

    // ADD OR UPDATE TRANSACTION
    if ($action === "save") {

        $transaction_id = fw_positive_id($_POST["transaction_id"] ?? null);
        $transaction_type = fw_text($_POST["transaction_type"] ?? null);
        $category_id = fw_positive_id($_POST["category_id"] ?? null);
        $amount = fw_money($_POST["amount"] ?? null);
        $description = fw_text($_POST["description"] ?? null);
        $transaction_date = fw_text($_POST["transaction_date"] ?? null);

        // Basic validation
        if (!in_array($transaction_type, ["income", "expense"], true)) {
            $message = "Please select a valid transaction type.";
            $message_type = "danger";
        } elseif ($category_id <= 0 || $amount === null || $description === "" || !fw_valid_date($transaction_date)) {
            $message = "Please complete all required fields and enter a valid amount.";
            $message_type = "danger";
        } elseif (strlen($description) > 255) {
            $message = "Description must not exceed 255 characters.";
            $message_type = "danger";
        } else {

            // Verify that the category belongs to this user
            // and matches the transaction type
            $category_sql = "
                SELECT category_id
                FROM categories
                WHERE category_id = ?
                AND user_id = ?
                AND category_type = ?
            ";

            $category_stmt = mysqli_prepare($conn, $category_sql);
            mysqli_stmt_bind_param(
                $category_stmt,
                "iis",
                $category_id,
                $user_id,
                $transaction_type
            );

            mysqli_stmt_execute($category_stmt);
            $category_result = mysqli_stmt_get_result($category_stmt);
            $valid_category = mysqli_num_rows($category_result) > 0;
            mysqli_stmt_close($category_stmt);

            if (!$valid_category) {
                $message = "Please select a valid category for this transaction.";
                $message_type = "danger";
            } else {

                $receipt_path = null;
                $old_receipt = null;

                // If editing, retrieve the existing transaction
                if ($transaction_id > 0) {
                    $old_sql = "
                        SELECT receipt_image
                        FROM transactions
                        WHERE transaction_id = ?
                        AND user_id = ?
                    ";

                    $old_stmt = mysqli_prepare($conn, $old_sql);
                    mysqli_stmt_bind_param($old_stmt, "ii", $transaction_id, $user_id);
                    mysqli_stmt_execute($old_stmt);
                    $old_result = mysqli_stmt_get_result($old_stmt);
                    $old_transaction = mysqli_fetch_assoc($old_result);
                    mysqli_stmt_close($old_stmt);

                    if (!$old_transaction) {
                        $message = "Transaction not found.";
                        $message_type = "danger";
                    } else {
                        $old_receipt = $old_transaction["receipt_image"];
                        $receipt_path = $old_receipt;
                    }
                }

                // Upload a new receipt if one was selected
                if ($message === "") {

                    if (
                        isset($_FILES["receipt_image"]) &&
                        is_array($_FILES["receipt_image"]) &&
                        ($_FILES["receipt_image"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
                    ) {
                        $upload_result = uploadReceipt(
                            $_FILES["receipt_image"],
                            $upload_directory,
                            $allowed_types,
                            $max_file_size
                        );

                        if ($upload_result["error"] !== "") {
                            $message = $upload_result["error"];
                            $message_type = "danger";
                        } else {
                            $receipt_path = $upload_result["path"];
                        }
                    }
                }

                // Save the transaction
                if ($message === "") {

                    if ($transaction_id > 0) {

                        $sql = "
                            UPDATE transactions
                            SET category_id = ?,
                                transaction_type = ?,
                                amount = ?,
                                description = ?,
                                transaction_date = ?,
                                receipt_image = ?
                            WHERE transaction_id = ?
                            AND user_id = ?
                        ";

                        $stmt = mysqli_prepare($conn, $sql);

                        mysqli_stmt_bind_param(
                            $stmt,
                            "isdsssii",
                            $category_id,
                            $transaction_type,
                            $amount,
                            $description,
                            $transaction_date,
                            $receipt_path,
                            $transaction_id,
                            $user_id
                        );

                    } else {

                        $sql = "
                            INSERT INTO transactions
                            (
                                user_id,
                                category_id,
                                transaction_type,
                                amount,
                                description,
                                transaction_date,
                                receipt_image
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ";

                        $stmt = mysqli_prepare($conn, $sql);

                        mysqli_stmt_bind_param(
                            $stmt,
                            "iisdsss",
                            $user_id,
                            $category_id,
                            $transaction_type,
                            $amount,
                            $description,
                            $transaction_date,
                            $receipt_path
                        );
                    }

                    if (mysqli_stmt_execute($stmt)) {

                        mysqli_stmt_close($stmt);

                        // Delete the previous receipt only after successful update
                        if (
                            $transaction_id > 0 &&
                            $receipt_path !== $old_receipt
                        ) {
                            deleteReceiptFile($old_receipt);
                        }

                        $_SESSION["transaction_message"] =
                            ($transaction_id > 0)
                            ? "Transaction updated successfully!"
                            : "Transaction added successfully!";

                        $_SESSION["transaction_message_type"] = "success";

                        header("Location: index.php");
                        exit();

                    } else {
                        if (!empty($receipt_path) && $receipt_path !== $old_receipt) {
                            deleteReceiptFile($receipt_path);
                        }

                        $message = "Unable to save transaction. Please try again.";
                        $message_type = "danger";
                        mysqli_stmt_close($stmt);
                    }
                }
            }
        }
    }

    // DELETE TRANSACTION
    if ($action === "delete") {

        $transaction_id = fw_positive_id($_POST["transaction_id"] ?? null);

        $find_sql = "
            SELECT receipt_image
            FROM transactions
            WHERE transaction_id = ?
            AND user_id = ?
        ";

        $find_stmt = mysqli_prepare($conn, $find_sql);
        mysqli_stmt_bind_param($find_stmt, "ii", $transaction_id, $user_id);
        mysqli_stmt_execute($find_stmt);
        $find_result = mysqli_stmt_get_result($find_stmt);
        $transaction = mysqli_fetch_assoc($find_result);
        mysqli_stmt_close($find_stmt);

        if ($transaction) {

            $delete_sql = "
                DELETE FROM transactions
                WHERE transaction_id = ?
                AND user_id = ?
            ";

            $delete_stmt = mysqli_prepare($conn, $delete_sql);
            mysqli_stmt_bind_param($delete_stmt, "ii", $transaction_id, $user_id);

            if (mysqli_stmt_execute($delete_stmt)) {

                deleteReceiptFile($transaction["receipt_image"]);

                $_SESSION["transaction_message"] = "Transaction deleted successfully!";
                $_SESSION["transaction_message_type"] = "success";

                mysqli_stmt_close($delete_stmt);

                header("Location: index.php");
                exit();

            } else {
                $message = "Unable to delete transaction.";
                $message_type = "danger";
                mysqli_stmt_close($delete_stmt);
            }

        } else {
            $message = "Transaction not found.";
            $message_type = "danger";
        }
    }
}

// Get user's categories
$categories = [];

$category_list_sql = "
    SELECT category_id, category_name, category_type
    FROM categories
    WHERE user_id = ?
    ORDER BY category_type, category_name
";

$category_list_stmt = mysqli_prepare($conn, $category_list_sql);
mysqli_stmt_bind_param($category_list_stmt, "i", $user_id);
mysqli_stmt_execute($category_list_stmt);
$category_list_result = mysqli_stmt_get_result($category_list_stmt);

while ($row = mysqli_fetch_assoc($category_list_result)) {
    $categories[] = $row;
}

mysqli_stmt_close($category_list_stmt);

// Get transaction being edited
$edit_transaction = null;

if (isset($_GET["edit"])) {
    $edit_id = (int) $_GET["edit"];

    $edit_sql = "
        SELECT *
        FROM transactions
        WHERE transaction_id = ?
        AND user_id = ?
    ";

    $edit_stmt = mysqli_prepare($conn, $edit_sql);
    mysqli_stmt_bind_param($edit_stmt, "ii", $edit_id, $user_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $edit_transaction = mysqli_fetch_assoc($edit_result);
    mysqli_stmt_close($edit_stmt);

    if (!$edit_transaction) {
        $message = "Transaction not found.";
        $message_type = "danger";
    }
}

// Read search and filter values
$search = substr(fw_text($_GET["search"] ?? null), 0, 100);
$filter_type = fw_text($_GET["type"] ?? null);
$filter_category = fw_positive_id($_GET["category"] ?? null);
$date_from = fw_text($_GET["date_from"] ?? null);
$date_to = fw_text($_GET["date_to"] ?? null);
$min_amount = fw_text($_GET["min_amount"] ?? null);
$max_amount = fw_text($_GET["max_amount"] ?? null);

if (($date_from !== '' && !fw_valid_date($date_from)) ||
    ($date_to !== '' && !fw_valid_date($date_to)) ||
    ($date_from !== '' && $date_to !== '' && $date_from > $date_to)) {
    $message = 'Please select a valid transaction date range.';
    $message_type = 'danger';
    $date_from = '';
    $date_to = '';
}
if (($min_amount !== '' && fw_money($min_amount, true) === null) ||
    ($max_amount !== '' && fw_money($max_amount, true) === null) ||
    ($min_amount !== '' && $max_amount !== '' && (float) $min_amount > (float) $max_amount)) {
    $message = 'Please select valid minimum and maximum amounts.';
    $message_type = 'danger';
    $min_amount = '';
    $max_amount = '';
}

// Build transaction query
$sql = "
    SELECT
        t.transaction_id,
        t.category_id,
        t.transaction_type,
        t.amount,
        t.description,
        t.transaction_date,
        t.receipt_image,
        c.category_name
    FROM transactions t
    INNER JOIN categories c
        ON t.category_id = c.category_id
    WHERE t.user_id = ?
";

$params = [$user_id];
$types = "i";

if (in_array($filter_type, ["income", "expense"], true)) {
    $sql .= " AND t.transaction_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if ($filter_category > 0) {
    $sql .= " AND t.category_id = ?";
    $params[] = $filter_category;
    $types .= "i";
}

if ($date_from !== "") {
    $sql .= " AND t.transaction_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to !== "") {
    $sql .= " AND t.transaction_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if ($min_amount !== "" && is_numeric($min_amount)) {
    $sql .= " AND t.amount >= ?";
    $params[] = (float) $min_amount;
    $types .= "d";
}

if ($max_amount !== "" && is_numeric($max_amount)) {
    $sql .= " AND t.amount <= ?";
    $params[] = (float) $max_amount;
    $types .= "d";
}

if ($search !== "") {
    $sql .= " AND (t.description LIKE ? OR c.category_name LIKE ?)";
    $search_value = "%" . $search . "%";
    $params[] = $search_value;
    $params[] = $search_value;
    $types .= "ss";
}

$sql .= " ORDER BY t.transaction_date DESC, t.transaction_id DESC";

$transaction_stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($transaction_stmt, $types, ...$params);
mysqli_stmt_execute($transaction_stmt);
$transaction_result = mysqli_stmt_get_result($transaction_stmt);

// Calculate totals for the current user's transactions
$totals_sql = "
    SELECT
        COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) AS total_expenses
    FROM transactions
    WHERE user_id = ?
";

$totals_stmt = mysqli_prepare($conn, $totals_sql);
mysqli_stmt_bind_param($totals_stmt, "i", $user_id);
mysqli_stmt_execute($totals_stmt);
$totals_result = mysqli_stmt_get_result($totals_stmt);
$totals = mysqli_fetch_assoc($totals_result);

$total_income = (float) $totals["total_income"];
$total_expenses = (float) $totals["total_expenses"];
$remaining_balance = $total_income - $total_expenses;

$currency = fw_user_currency($conn, $user_id);
?>

<?php
$pageTitle = "Transactions";
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
            font-weight: 700;
            color: #38245f;
        }

        .summary-card {
            border: none;
            border-radius: 16px;
            padding: 22px;
            height: 100%;
            box-shadow: 0 4px 15px rgba(40, 20, 80, 0.06);
        }

        .summary-card h6 {
            color: #777;
            margin-bottom: 10px;
        }

        .summary-card h3 {
            font-weight: 700;
            margin: 0;
        }

        .income-color {
            color: #15966b;
        }

        .expense-color {
            color: #dc5265;
        }

        .balance-color {
            color: #6c4ab6;
        }

        .main-card {
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(40, 20, 80, 0.06);
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

        .table th {
            color: #6b6280;
            font-size: 13px;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .badge-income {
            background: #e2f7ed;
            color: #16845c;
        }

        .badge-expense {
            background: #fde8eb;
            color: #c43e53;
        }

        .receipt-link {
            color: #6c4ab6;
            text-decoration: none;
        }

        .receipt-link:hover {
            text-decoration: underline;
        }

        .section-label {
            color: #6c4ab6;
            font-weight: 700;
        }
    </style>
<?php
$pageHead = ob_get_clean();
require_once __DIR__ . "/../includes/header.php";
?>
<div class="container-fluid px-4 py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="page-title mb-1">Income & Expenses</h2>
            <p class="text-muted mb-0">Manage and monitor your financial transactions.</p>
        </div>

        <a href="#transactionForm" class="btn btn-purple">
            <i class="bi bi-plus-lg me-1"></i> Add Transaction
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
            <div class="summary-card bg-white">
                <h6><i class="bi bi-arrow-down-circle me-2"></i>Total Income</h6>
                <h3 class="income-color">
                    <?php echo $currency . number_format($total_income, 2); ?>
                </h3>
            </div>
        </div>

        <div class="col-md-4">
            <div class="summary-card bg-white">
                <h6><i class="bi bi-arrow-up-circle me-2"></i>Total Expenses</h6>
                <h3 class="expense-color">
                    <?php echo $currency . number_format($total_expenses, 2); ?>
                </h3>
            </div>
        </div>

        <div class="col-md-4">
            <div class="summary-card bg-white">
                <h6><i class="bi bi-wallet me-2"></i>Remaining Balance</h6>
                <h3 class="balance-color">
                    <?php echo $currency . number_format($remaining_balance, 2); ?>
                </h3>
            </div>
        </div>

    </div>

    <!-- TRANSACTION FORM -->
    <div class="main-card p-4 mb-4" id="transactionForm">

        <h5 class="fw-bold mb-3">
            <?php echo $edit_transaction ? "Edit Transaction" : "Add New Transaction"; ?>
        </h5>

        <?php if (count($categories) === 0): ?>

            <div class="alert alert-warning">
                You don't have any categories yet.
                Please create categories before adding transactions.
                <a href="../categories/index.php">Manage Categories</a>
            </div>

        <?php else: ?>

            <form method="POST" enctype="multipart/form-data">

                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                <input type="hidden" name="action" value="save">

                <input type="hidden" name="transaction_id"
                       value="<?php echo (int) ($edit_transaction["transaction_id"] ?? 0); ?>">

                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Transaction Type *</label>

                        <select name="transaction_type" id="transaction_type"
                                class="form-select" required>

                            <option value="income"
                                <?php echo (($edit_transaction["transaction_type"] ?? "") === "income") ? "selected" : ""; ?>>
                                Income
                            </option>

                            <option value="expense"
                                <?php echo (($edit_transaction["transaction_type"] ?? "expense") === "expense") ? "selected" : ""; ?>>
                                Expense
                            </option>

                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Category *</label>

                        <select name="category_id" id="category_id"
                                class="form-select" required>

                            <option value="">Select Category</option>

                            <?php foreach ($categories as $category): ?>

                                <option
                                    value="<?php echo (int) $category["category_id"]; ?>"
                                    data-type="<?php echo htmlspecialchars($category["category_type"]); ?>"
                                    <?php echo (
                                        (int) ($edit_transaction["category_id"] ?? 0)
                                        === (int) $category["category_id"]
                                    ) ? "selected" : ""; ?>
                                >
                                    <?php echo htmlspecialchars($category["category_name"]); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Amount (PHP) *</label>

                        <input type="number" name="amount" class="form-control"
                               min="0.01" step="0.01" placeholder="0.00"
                               value="<?php echo htmlspecialchars($edit_transaction["amount"] ?? ""); ?>"
                               required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Transaction Date *</label>

                        <input type="date" name="transaction_date"
                               class="form-control"
                               value="<?php echo htmlspecialchars(
                                   $edit_transaction["transaction_date"] ?? date("Y-m-d")
                               ); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Description *</label>

                        <input type="text" name="description" class="form-control"
                               maxlength="255"
                               placeholder="Example: Monthly allowance or grocery shopping"
                               value="<?php echo htmlspecialchars($edit_transaction["description"] ?? ""); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Receipt Image (Optional)</label>

                        <input type="file" name="receipt_image"
                               class="form-control"
                               accept=".jpg,.jpeg,.png,image/jpeg,image/png">

                        <small class="text-muted">JPG or PNG only. Maximum size: 5 MB.</small>

                        <?php if (!empty($edit_transaction["receipt_image"])): ?>
                            <div class="mt-2">
                                Current receipt:
                                <a class="receipt-link"
                                   href="receipt.php?id=<?php echo (int) $edit_transaction['transaction_id']; ?>"
                                   target="_blank" rel="noopener noreferrer">
                                    View Receipt
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 d-flex gap-2">

                        <button type="submit" class="btn btn-purple">
                            <i class="bi bi-check-circle me-1"></i>
                            <?php echo $edit_transaction ? "Update Transaction" : "Save Transaction"; ?>
                        </button>

                        <?php if ($edit_transaction): ?>
                            <a href="index.php" class="btn btn-outline-secondary">
                                Cancel Edit
                            </a>
                        <?php endif; ?>

                    </div>

                </div>
            </form>

        <?php endif; ?>

    </div>

    <!-- SEARCH AND FILTERS -->
    <div class="main-card p-4 mb-4">

        <h5 class="fw-bold mb-3">
            <i class="bi bi-funnel me-2"></i>Search and Filter Transactions
        </h5>

        <form method="GET">

            <div class="row g-3">

                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control"
                           placeholder="Description or category"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Type</label>

                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="income" <?php echo $filter_type === "income" ? "selected" : ""; ?>>
                            Income
                        </option>
                        <option value="expense" <?php echo $filter_type === "expense" ? "selected" : ""; ?>>
                            Expense
                        </option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Category</label>

                    <select name="category" class="form-select">
                        <option value="0">All Categories</option>

                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int) $category["category_id"]; ?>"
                                <?php echo $filter_category === (int) $category["category_id"] ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($category["category_name"]); ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control"
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control"
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Minimum Amount</label>
                    <input type="number" name="min_amount" min="0" step="0.01"
                           class="form-control" placeholder="Minimum"
                           value="<?php echo htmlspecialchars($min_amount); ?>">
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Maximum Amount</label>
                    <input type="number" name="max_amount" min="0" step="0.01"
                           class="form-control" placeholder="Maximum"
                           value="<?php echo htmlspecialchars($max_amount); ?>">
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-purple">
                        <i class="bi bi-search me-1"></i> Apply Filters
                    </button>

                    <a href="index.php" class="btn btn-outline-secondary">
                        Reset Filters
                    </a>
                </div>

            </div>
        </form>

    </div>

    <!-- TRANSACTION HISTORY -->
    <div class="main-card p-4">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">Transaction History</h5>
            <span class="text-muted small">
                <?php echo mysqli_num_rows($transaction_result); ?> record(s)
            </span>
        </div>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Receipt</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (mysqli_num_rows($transaction_result) > 0): ?>

                        <?php while ($transaction = mysqli_fetch_assoc($transaction_result)): ?>

                            <tr>

                                <td>
                                    <?php echo date("M d, Y", strtotime($transaction["transaction_date"])); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($transaction["description"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($transaction["category_name"]); ?>
                                </td>

                                <td>
                                    <?php if ($transaction["transaction_type"] === "income"): ?>
                                        <span class="badge badge-income">Income</span>
                                    <?php else: ?>
                                        <span class="badge badge-expense">Expense</span>
                                    <?php endif; ?>
                                </td>

                                <td class="<?php echo $transaction["transaction_type"] === "income" ? "income-color" : "expense-color"; ?> fw-bold">

                                    <?php echo $currency . number_format($transaction["amount"], 2); ?>

                                </td>

                                <td>
                                    <?php if (!empty($transaction["receipt_image"])): ?>

                                        <a class="receipt-link"
                                           href="receipt.php?id=<?php echo (int) $transaction['transaction_id']; ?>"
                                           target="_blank" rel="noopener noreferrer">
                                            <i class="bi bi-image"></i> View
                                        </a>

                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="d-flex gap-2">

                                        <a href="index.php?edit=<?php echo (int) $transaction["transaction_id"]; ?>"
                                           class="btn btn-sm btn-outline-primary"
                                           title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <form method="POST"
                                              onsubmit="return confirm('Are you sure you want to delete this transaction?');">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                                            <input type="hidden" name="action" value="delete">

                                            <input type="hidden" name="transaction_id"
                                                   value="<?php echo (int) $transaction["transaction_id"]; ?>">

                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>

                                        </form>

                                    </div>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-receipt fs-2 d-block mb-2"></i>
                                No transactions found.
                                Add your first income or expense above.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>
            </table>

        </div>
    </div>

    <div class="text-center text-muted small mt-4">
        Fundwise Personal Finance Management System
    </div>

</div>



<script>
// Display only categories that match the selected transaction type
const transactionType = document.getElementById("transaction_type");
const categorySelect = document.getElementById("category_id");

function filterCategories() {
    const selectedType = transactionType.value;
    let selectedCategoryStillVisible = false;

    Array.from(categorySelect.options).forEach(option => {
        if (option.value === "") {
            option.hidden = false;
            return;
        }

        const matches = option.dataset.type === selectedType;
        option.hidden = !matches;

        if (matches && option.selected) {
            selectedCategoryStillVisible = true;
        }
    });

    if (!selectedCategoryStillVisible) {
        categorySelect.value = "";
    }
}

transactionType.addEventListener("change", filterCategories);
filterCategories();
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>

<?php
mysqli_stmt_close($transaction_stmt);
mysqli_stmt_close($totals_stmt);
?>
