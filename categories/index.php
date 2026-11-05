<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";

$user_id = (int) $_SESSION["user_id"];
$message = "";
$message_type = "success";
if (empty($_SESSION["categories_csrf_token"])) {
    $_SESSION["categories_csrf_token"] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION["categories_csrf_token"];

function categoryUsageCount(mysqli $conn, int $category_id, int $user_id): int {
    $stmt = mysqli_prepare($conn, "
        SELECT
            (SELECT COUNT(*) FROM transactions WHERE category_id = ? AND user_id = ?) +
            (SELECT COUNT(*) FROM budgets WHERE category_id = ? AND user_id = ?) AS usage_count
    ");
    mysqli_stmt_bind_param($stmt, 'iiii', $category_id, $user_id, $category_id, $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $used = (int) ($row['usage_count'] ?? 0);
    // Protect categories referenced by Money Lab after the optional SQL install.
    static $labTableExists = null;
    if ($labTableExists === null) {
        $exists = mysqli_prepare($conn, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='money_lab_experiments' LIMIT 1");
        $labTableExists = false;
        if ($exists) {
            mysqli_stmt_execute($exists);
            $labTableExists = mysqli_num_rows(mysqli_stmt_get_result($exists)) > 0;
            mysqli_stmt_close($exists);
        }
    }
    if ($labTableExists) {
        $labStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM money_lab_experiments WHERE category_id=? AND user_id=?');
        if ($labStmt) {
            mysqli_stmt_bind_param($labStmt, 'ii', $category_id, $user_id);
            mysqli_stmt_execute($labStmt);
            $labRow = mysqli_fetch_assoc(mysqli_stmt_get_result($labStmt));
            mysqli_stmt_close($labStmt);
            $used += (int)($labRow['total'] ?? 0);
        }
    }
    return $used;
}

function categoryDuplicate(mysqli $conn, int $user_id, string $name, string $type, int $ignore_id = 0): bool {
    $stmt = mysqli_prepare($conn, "
        SELECT category_id FROM categories
        WHERE user_id = ? AND category_name = ? AND category_type = ? AND category_id <> ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'issi', $user_id, $name, $type, $ignore_id);
    mysqli_stmt_execute($stmt);
    $duplicate = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
    return $duplicate;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fw_csrf_valid('categories_csrf_token')) {
        http_response_code(403);
        exit('Invalid form. Refresh the page and try again.');
    }

    if (isset($_POST['add_category']) || isset($_POST['edit_category'])) {
        $editing = isset($_POST['edit_category']);
        $category_id = $editing ? fw_positive_id($_POST['category_id'] ?? null) : 0;
        $name = fw_text($_POST['category_name'] ?? null);
        $type = fw_text($_POST['category_type'] ?? null);
        $old_category_type = null;

        if ($editing && $category_id > 0) {
            $stmt = mysqli_prepare($conn, 'SELECT category_type FROM categories WHERE category_id = ? AND user_id = ?');
            mysqli_stmt_bind_param($stmt, 'ii', $category_id, $user_id);
            mysqli_stmt_execute($stmt);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $old_category_type = $existing['category_type'] ?? null;
        }

        if ($name === '' || strlen($name) > 100 || !in_array($type, ['income', 'expense'], true)) {
            $message = 'Please enter a valid category name (up to 100 characters) and type.';
            $message_type = 'danger';
        } elseif ($editing && !$old_category_type) {
            $message = 'Category not found or you do not have permission to edit it.';
            $message_type = 'danger';
        } elseif ($editing && $old_category_type !== $type && categoryUsageCount($conn, $category_id, $user_id) > 0) {
            $message = 'This category is in use. You can rename it, but cannot change its income/expense type.';
            $message_type = 'danger';
        } elseif (categoryDuplicate($conn, $user_id, $name, $type, $category_id)) {
            $message = 'A category with this name and type already exists.';
            $message_type = 'danger';
        } else {
            if ($editing) {
                $stmt = mysqli_prepare($conn, '
                    UPDATE categories SET category_name = ?, category_type = ?
                    WHERE category_id = ? AND user_id = ?
                ');
                mysqli_stmt_bind_param($stmt, 'ssii', $name, $type, $category_id, $user_id);
            } else {
                $stmt = mysqli_prepare($conn, '
                    INSERT INTO categories (user_id, category_name, category_type, is_default)
                    VALUES (?, ?, ?, FALSE)
                ');
                mysqli_stmt_bind_param($stmt, 'iss', $user_id, $name, $type);
            }
            $saved = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $message = $saved ? ($editing ? 'Category updated successfully!' : 'Category added successfully!') : 'Unable to save category.';
            $message_type = $saved ? 'success' : 'danger';
        }
    } elseif (isset($_POST['delete_category'])) {
        $category_id = fw_positive_id($_POST['category_id'] ?? null);
        if ($category_id <= 0) {
            $message = 'Invalid category.';
            $message_type = 'danger';
        } elseif (categoryUsageCount($conn, $category_id, $user_id) > 0) {
            $message = 'This category is in use by transactions, budgets, or Money Lab experiments. Remove or reassign those records first.';
            $message_type = 'danger';
        } else {
            $stmt = mysqli_prepare($conn, 'DELETE FROM categories WHERE category_id = ? AND user_id = ?');
            mysqli_stmt_bind_param($stmt, 'ii', $category_id, $user_id);
            $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0;
            mysqli_stmt_close($stmt);
            $message = $ok ? 'Category deleted successfully!' : 'Category not found or could not be deleted.';
            $message_type = $ok ? 'success' : 'danger';
        }
    }
}

// GET CATEGORIES
$sql = "SELECT * FROM categories
        WHERE user_id = ?
        ORDER BY category_type, category_name";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$income_categories = [];
$expense_categories = [];

while ($category = mysqli_fetch_assoc($result)) {

    if ($category["category_type"] == "income") {
        $income_categories[] = $category;
    } else {
        $expense_categories[] = $category;
    }
}

mysqli_stmt_close($stmt);
?>

<?php
// Render category forms inline instead of inside Bootstrap modals. This avoids
// the backdrop blocking all clicks when a modal is trapped by a layout layer.
$edit_category = null;
$edit_id = fw_positive_id($_GET['edit'] ?? null);
if ($edit_id > 0) {
    $stmt = mysqli_prepare($conn, 'SELECT category_id, category_name, category_type FROM categories WHERE category_id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $edit_id, $user_id);
    mysqli_stmt_execute($stmt);
    $edit_category = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
}
$pageTitle = "Categories";
require_once __DIR__ . "/../includes/header.php";
?>
<div class="fi-page">
    <div class="fi-hero fi-hero-categories">
        <div><span class="fi-kicker"><i class="bi bi-grid-3x3-gap"></i> CATEGORIES</span><h1>Income & expense<br><span>categories.</span></h1><p>Manage the categories used in your transactions.</p></div>
        <div class="fi-hero-icon"><i class="bi bi-collection"></i></div>
    </div>
    <?php if ($message !== ''): ?><div class="alert alert-<?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?> mt-3" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <details class="fi-panel fi-details mt-4" id="addCategoryPanel" <?= $message_type==='danger' && !$edit_category?'open':'' ?>>
        <summary class="fi-details-summary"><span><i class="bi bi-plus-circle me-2"></i> Add a new category</span><i class="bi bi-chevron-down"></i></summary>
        <div class="fi-divider"></div>
        <form method="post" class="row g-3 align-items-end"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <div class="col-md-6"><label class="form-label" for="newCategoryName">Category name</label><input id="newCategoryName" type="text" name="category_name" maxlength="100" class="form-control" placeholder="e.g. Transportation" required></div>
            <div class="col-md-3"><label class="form-label" for="newCategoryType">Category type</label><select id="newCategoryType" name="category_type" class="form-select" required><option value="expense">Expense</option><option value="income">Income</option></select></div>
            <div class="col-md-3"><button type="submit" name="add_category" value="1" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i> Save category</button></div>
        </form>
    </details>

    <?php if ($edit_category): ?>
    <section class="fi-panel mt-3" id="editCategoryPanel" aria-labelledby="editCategoryTitle">
        <div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">EDIT CATEGORY</span><h2 id="editCategoryTitle"><?= htmlspecialchars($edit_category['category_name'], ENT_QUOTES, 'UTF-8') ?></h2></div><a class="btn btn-sm btn-outline-secondary" href="index.php">Cancel</a></div>
        <form method="post" class="row g-3 align-items-end"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="category_id" value="<?= (int)$edit_category['category_id'] ?>">
            <div class="col-md-6"><label class="form-label" for="editCategoryName">Category name</label><input id="editCategoryName" type="text" name="category_name" maxlength="100" class="form-control" value="<?= htmlspecialchars($edit_category['category_name'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="col-md-3"><label class="form-label" for="editCategoryType">Type</label><select id="editCategoryType" name="category_type" class="form-select"><option value="expense" <?= $edit_category['category_type']==='expense'?'selected':'' ?>>Expense</option><option value="income" <?= $edit_category['category_type']==='income'?'selected':'' ?>>Income</option></select></div>
            <div class="col-md-3"><button type="submit" name="edit_category" value="1" class="btn btn-primary w-100">Save changes</button></div>
        </form><p class="fi-subtext mb-0 mt-2">Categories used by transactions, budgets, or Money Lab can be renamed but cannot change type.</p>
    </section>
    <?php elseif ($edit_id > 0): ?><div class="alert alert-warning mt-3">Category not found or you do not have permission to edit it.</div><?php endif; ?>

    <div class="row g-4 mt-1">
        <?php foreach ([['Income categories',$income_categories,'bi-arrow-down-left-circle','success'], ['Expense categories',$expense_categories,'bi-arrow-up-right-circle','danger']] as [$heading,$items,$icon,$tint]): ?>
        <div class="col-lg-6"><section class="fi-panel h-100"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow"><?= count($items) ?> CATEGORIES</span><h2><i class="bi <?= $icon ?> me-2 text-<?= $tint ?>"></i><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h2></div></div>
            <?php if (!$items): ?><div class="fi-empty"><i class="bi bi-grid"></i><p>No categories added yet.</p></div><?php endif; ?>
            <?php foreach ($items as $category): ?>
            <div class="fi-category-row">
                <div class="fi-category-icon"><i class="bi <?= $icon ?>"></i></div>
                <div class="fi-category-name"><strong><?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= $category['is_default']?'Default category':'Custom category' ?></span></div>
                <div class="fi-category-actions"><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$category['category_id'] ?>#editCategoryPanel"><i class="bi bi-pencil me-1"></i> Edit</a>
                    <form method="post" onsubmit="return confirm('Delete this category? Categories in use by transactions, budgets, or Money Lab cannot be deleted.');"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="category_id" value="<?= (int)$category['category_id'] ?>"><button type="submit" name="delete_category" value="1" class="btn btn-sm btn-outline-danger" aria-label="Delete <?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-trash3"></i></button>
                    </form></div>
            </div>
            <?php endforeach; ?>
        </section></div>
        <?php endforeach; ?>
    </div>
    
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
