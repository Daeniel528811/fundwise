<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";

$user_id = (int) $_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fw_csrf_valid()) {
        http_response_code(403);
        $error = 'Invalid request. Refresh the page and try again.';
    } else {
        $action = fw_text($_POST['action'] ?? null);
        $allocation_id = fw_positive_id($_POST['allocation_id'] ?? null);
        if ($action === 'delete') {
            if (!$allocation_id) {
                $error = 'Invalid allocation.';
            } else {
                $stmt = mysqli_prepare($conn, 'DELETE FROM savings_allocations WHERE allocation_id = ? AND user_id = ?');
                mysqli_stmt_bind_param($stmt, 'ii', $allocation_id, $user_id);
                $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0;
                mysqli_stmt_close($stmt);
                if ($ok) {
                    header('Location: allocations.php?success=deleted');
                    exit;
                }
                $error = 'Allocation not found or could not be deleted.';
            }
        } elseif ($action === 'add' || $action === 'update') {
            $name = fw_text($_POST['allocation_name'] ?? null);
            $percentage = fw_money($_POST['allocation_percentage'] ?? null);
            if ($name === '' || strlen($name) > 100) {
                $error = 'Please enter an allocation name of up to 100 characters.';
            } elseif ($percentage === null || $percentage > 100) {
                $error = 'Percentage must be greater than 0 and no more than 100.';
            } elseif ($action === 'update' && !$allocation_id) {
                $error = 'Invalid allocation.';
            } else {
                // Lock the user's record to serialize allocation changes for this user.
                // Without the lock, simultaneous requests could push the total over 100%.
                mysqli_begin_transaction($conn);
                try {
                    $lock = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE user_id = ? FOR UPDATE');
                    if (!$lock) throw new RuntimeException('Could not verify account.');
                    mysqli_stmt_bind_param($lock, 'i', $user_id);
                    if (!mysqli_stmt_execute($lock)) throw new RuntimeException('Could not verify account.');
                    $exists = mysqli_num_rows(mysqli_stmt_get_result($lock)) > 0;
                    mysqli_stmt_close($lock);
                    if (!$exists) throw new RuntimeException('User account not found.');

                    if ($action === 'update') {
                        $stmt = mysqli_prepare($conn, 'SELECT allocation_id FROM savings_allocations WHERE allocation_id = ? AND user_id = ?');
                        mysqli_stmt_bind_param($stmt, 'ii', $allocation_id, $user_id);
                        mysqli_stmt_execute($stmt);
                        $owns = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
                        mysqli_stmt_close($stmt);
                        if (!$owns) throw new RuntimeException('Allocation not found or not yours.');
                    }
                    $stmt = mysqli_prepare($conn, 'SELECT COALESCE(SUM(allocation_percentage), 0) AS total FROM savings_allocations WHERE user_id = ? AND allocation_id <> ?');
                    mysqli_stmt_bind_param($stmt, 'ii', $user_id, $allocation_id);
                    mysqli_stmt_execute($stmt);
                    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    $total = (float) ($row['total'] ?? 0);
                    mysqli_stmt_close($stmt);
                    if (round($total + $percentage, 2) > 100.00) {
                        throw new RuntimeException('Your total allocation cannot exceed 100%.');
                    }
                    if ($action === 'add') {
                        $stmt = mysqli_prepare($conn, 'INSERT INTO savings_allocations (user_id, allocation_name, allocation_percentage) VALUES (?, ?, ?)');
                        mysqli_stmt_bind_param($stmt, 'isd', $user_id, $name, $percentage);
                    } else {
                        $stmt = mysqli_prepare($conn, 'UPDATE savings_allocations SET allocation_name = ?, allocation_percentage = ? WHERE allocation_id = ? AND user_id = ?');
                        mysqli_stmt_bind_param($stmt, 'sdii', $name, $percentage, $allocation_id, $user_id);
                    }
                    if (!$stmt || !mysqli_stmt_execute($stmt)) throw new RuntimeException('Unable to save allocation.');
                    mysqli_stmt_close($stmt);
                    if (!mysqli_commit($conn)) throw new RuntimeException('Unable to save allocation.');
                    header('Location: allocations.php?success=' . ($action === 'add' ? 'added' : 'updated'));
                    exit;
                } catch (Throwable $e) {
                    mysqli_rollback($conn);
                    $error = $e->getMessage();
                }
            }
        }
    }
}

// SUCCESS MESSAGES
if (isset($_GET["success"])) {

    if ($_GET["success"] === "added") {
        $message = "Allocation added successfully.";
    } elseif ($_GET["success"] === "updated") {
        $message = "Allocation updated successfully.";
    } elseif ($_GET["success"] === "deleted") {
        $message = "Allocation deleted successfully.";
    }
}


// GET USER ALLOCATIONS
$stmt = mysqli_prepare($conn, "
    SELECT *
    FROM savings_allocations
    WHERE user_id = ?
    ORDER BY allocation_id DESC
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$allocations = [];
$total_percentage = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $allocations[] = $row;
    $total_percentage += (float) $row["allocation_percentage"];
}

mysqli_stmt_close($stmt);

$remaining_percentage = max(0, 100 - $total_percentage);
?>

<?php
$pageTitle = "Savings Allocation";
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

        .content-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
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

        .chart-container {
            max-width: 380px;
            margin: auto;
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
            <h2 class="section-title">Savings Allocation</h2>

            <p class="text-muted">
                Plan how you want to divide your savings among your priorities.
            </p>


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
            <div class="row mb-4">

                <div class="col-md-6 mb-3">
                    <div class="content-card">
                        <p class="text-muted mb-1">Total Allocated</p>

                        <h3 class="section-title">
                            <?= number_format($total_percentage, 2) ?>%
                        </h3>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="content-card">
                        <p class="text-muted mb-1">Unallocated Percentage</p>

                        <h3 class="section-title">
                            <?= number_format($remaining_percentage, 2) ?>%
                        </h3>
                    </div>
                </div>

            </div>


            <div class="row">

                <!-- ADD ALLOCATION -->
                <div class="col-lg-5">

                    <div class="content-card">

                        <h5 class="section-title mb-3">
                            Add Savings Allocation
                        </h5>

                        <form method="POST">

                            <input type="hidden" name="csrf_token"
                                value="<?= htmlspecialchars($csrf_token) ?>">

                            <input type="hidden" name="action" value="add">

                            <div class="mb-3">
                                <label class="form-label">
                                    Allocation Name
                                </label>

                                <input
                                    type="text"
                                    name="allocation_name"
                                    class="form-control"
                                    placeholder="Example: Emergency Fund"
                                    maxlength="100"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Percentage (%)
                                </label>

                                <input
                                    type="number"
                                    name="allocation_percentage"
                                    class="form-control"
                                    min="0.01"
                                    max="100"
                                    step="0.01"
                                    placeholder="Example: 50"
                                    required
                                >
                            </div>

                            <button type="submit" class="btn btn-purple w-100">
                                Add Allocation
                            </button>

                        </form>

                    </div>

                </div>


                <!-- CHART -->
                <div class="col-lg-7">

                    <div class="content-card">

                        <h5 class="section-title mb-3">
                            Allocation Breakdown
                        </h5>

                        <div class="chart-container">
                            <canvas id="allocationChart"></canvas>
                        </div>

                        <?php if (count($allocations) === 0): ?>
                            <p class="text-center text-muted mt-3">
                                Add an allocation to display your chart.
                            </p>
                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <!-- ALLOCATION LIST -->
            <div class="content-card">

                <h5 class="section-title mb-3">
                    Your Savings Allocations
                </h5>

                <?php if (count($allocations) > 0): ?>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead>
                                <tr>
                                    <th>Allocation</th>
                                    <th>Percentage</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($allocations as $allocation): ?>

                                    <tr>

                                        <td>
                                            <?= htmlspecialchars($allocation["allocation_name"]) ?>
                                        </td>

                                        <td>
                                            <?= number_format(
                                                $allocation["allocation_percentage"],
                                                2
                                            ) ?>%
                                        </td>

                                        <td>

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-warning"
                                                onclick='editAllocation(
                                                    <?= (int) $allocation["allocation_id"] ?>,
                                                    <?= htmlspecialchars(json_encode($allocation["allocation_name"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>,
                                                    <?= json_encode((float) $allocation["allocation_percentage"]) ?>
                                                )'
                                            >
                                                Edit
                                            </button>

                                            <form method="POST"
                                                class="d-inline"
                                                onsubmit="return confirm('Are you sure you want to delete this allocation?');">

                                                <input type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($csrf_token) ?>">

                                                <input type="hidden"
                                                    name="action"
                                                    value="delete">

                                                <input type="hidden"
                                                    name="allocation_id"
                                                    value="<?= (int) $allocation["allocation_id"] ?>">

                                                <button type="submit"
                                                    class="btn btn-sm btn-danger">
                                                    Delete
                                                </button>

                                            </form>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <p class="text-muted">
                        You have not created any savings allocations yet.
                    </p>

                <?php endif; ?>

            </div>


            <!-- EDIT MODAL -->
            <div class="modal fade" id="editModal" tabindex="-1">

                <div class="modal-dialog">

                    <div class="modal-content">

                        <form method="POST">

                            <div class="modal-header">
                                <h5 class="modal-title">
                                    Edit Allocation
                                </h5>

                                <button type="button"
                                    class="btn-close"
                                    data-bs-dismiss="modal">
                                </button>
                            </div>

                            <div class="modal-body">

                                <input type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars($csrf_token) ?>">

                                <input type="hidden"
                                    name="action"
                                    value="update">

                                <input type="hidden"
                                    name="allocation_id"
                                    id="edit_id">

                                <div class="mb-3">
                                    <label class="form-label">
                                        Allocation Name
                                    </label>

                                    <input type="text"
                                        name="allocation_name"
                                        id="edit_name"
                                        class="form-control"
                                        maxlength="100"
                                        required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">
                                        Percentage (%)
                                    </label>

                                    <input type="number"
                                        name="allocation_percentage"
                                        id="edit_percentage"
                                        class="form-control"
                                        min="0.01"
                                        max="100"
                                        step="0.01"
                                        required>
                                </div>

                            </div>

                            <div class="modal-footer">

                                <button type="button"
                                    class="btn btn-secondary"
                                    data-bs-dismiss="modal">
                                    Cancel
                                </button>

                                <button type="submit"
                                    class="btn btn-purple">
                                    Save Changes
                                </button>

                            </div>

                        </form>

                    </div>

                </div>

            </div>

        




<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>

// Allocation chart data from PHP
const allocationLabels = <?= json_encode(
    array_column($allocations, "allocation_name"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;

const allocationValues = <?= json_encode(
    array_map(
        "floatval",
        array_column($allocations, "allocation_percentage")
    )
) ?>;

// Include remaining percentage in chart
if (<?= json_encode($remaining_percentage) ?> > 0) {
    allocationLabels.push("Unallocated");
    allocationValues.push(<?= json_encode($remaining_percentage) ?>);
}

// Create circular chart
const chartCanvas = document.getElementById("allocationChart");

if (allocationValues.length > 0) {

    new Chart(chartCanvas, {
        type: "doughnut",

        data: {
            labels: allocationLabels,

            datasets: [{
                data: allocationValues,
                borderWidth: 2
            }]
        },

        options: {
            responsive: true,

            plugins: {
                legend: {
                    position: "bottom"
                },

                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ": " +
                                context.parsed + "%";
                        }
                    }
                }
            }
        }
    });

}


// Edit allocation
function editAllocation(id, name, percentage) {

    document.getElementById("edit_id").value = id;
    document.getElementById("edit_name").value = name;
    document.getElementById("edit_percentage").value = percentage;

    const modal = new bootstrap.Modal(
        document.getElementById("editModal")
    );

    modal.show();
}

</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
