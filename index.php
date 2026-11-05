<?php
require_once "includes/auth_check.php";
require_once "includes/db.php";

$user_id = (int) $_SESSION["user_id"];



// GET USER INFORMATION
$stmt = mysqli_prepare($conn, "
    SELECT full_name, preferred_currency
    FROM users
    WHERE user_id = ?
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$full_name = $user["full_name"] ?? "User";
$currency = strtoupper($user["preferred_currency"] ?? "PHP");

$allowedCurrencies = [
    "PHP", "USD", "EUR", "GBP",
    "JPY", "AUD", "CAD", "SGD"
];

if (!in_array($currency, $allowedCurrencies, true)) {
    $currency = "PHP";
}


// HELPER FOR CURRENCY DISPLAY
function formatMoney($amount, $currency) {
    return $currency . " " . number_format((float) $amount, 2);
}


// GET TOTAL INCOME
$stmt = mysqli_prepare($conn, "
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE user_id = ? AND transaction_type = 'income'
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$totalIncome = (float) mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
)["total"];

mysqli_stmt_close($stmt);


// GET TOTAL EXPENSES
$stmt = mysqli_prepare($conn, "
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE user_id = ? AND transaction_type = 'expense'
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$totalExpenses = (float) mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
)["total"];

mysqli_stmt_close($stmt);


// REMAINING BALANCE
$remainingBalance = $totalIncome - $totalExpenses;


// GET ACTUAL SAVINGS BALANCE
$stmt = mysqli_prepare($conn, "
    SELECT COALESCE(
        SUM(
            CASE
                WHEN contribution_type = 'deposit' THEN amount
                WHEN contribution_type = 'withdrawal' THEN -amount
                ELSE 0
            END
        ), 0
    ) AS total
    FROM savings_contributions sc
    INNER JOIN savings_goals sg
        ON sc.goal_id = sg.goal_id
    WHERE sg.user_id = ?
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$savingsBalance = (float) mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
)["total"];

mysqli_stmt_close($stmt);


// GET UNREAD NOTIFICATIONS
$stmt = mysqli_prepare($conn, "
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE user_id = ? AND is_read = 0
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$unreadNotifications = (int) mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
)["total"];

mysqli_stmt_close($stmt);


// GET RECENT NOTIFICATIONS
$stmt = mysqli_prepare($conn, "
    SELECT message, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$notificationResult = mysqli_stmt_get_result($stmt);

$recentNotifications = [];

while ($row = mysqli_fetch_assoc($notificationResult)) {
    $recentNotifications[] = $row;
}

mysqli_stmt_close($stmt);


// GET SAVINGS GOALS
$stmt = mysqli_prepare($conn, "
    SELECT
        sg.goal_id,
        sg.goal_name,
        sg.target_amount,
        sg.target_date,

        COALESCE(
            SUM(
                CASE
                    WHEN sc.contribution_type = 'deposit'
                    THEN sc.amount
                    WHEN sc.contribution_type = 'withdrawal'
                    THEN -sc.amount
                    ELSE 0
                END
            ), 0
        ) AS current_amount

    FROM savings_goals sg

    LEFT JOIN savings_contributions sc
        ON sg.goal_id = sc.goal_id

    WHERE sg.user_id = ?

    GROUP BY
        sg.goal_id,
        sg.goal_name,
        sg.target_amount,
        sg.target_date

    ORDER BY sg.created_at DESC

    LIMIT 5
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$goalResult = mysqli_stmt_get_result($stmt);

$savingsGoals = [];

while ($row = mysqli_fetch_assoc($goalResult)) {
    $savingsGoals[] = $row;
}

mysqli_stmt_close($stmt);


// GET EXPENSE BREAKDOWN FOR CURRENT MONTH
$stmt = mysqli_prepare($conn, "
    SELECT
        c.category_name,
        SUM(t.amount) AS total

    FROM transactions t

    LEFT JOIN categories c
        ON t.category_id = c.category_id

    WHERE
        t.user_id = ?
        AND t.transaction_type = 'expense'
        AND YEAR(t.transaction_date) = YEAR(CURDATE())
        AND MONTH(t.transaction_date) = MONTH(CURDATE())

    GROUP BY t.category_id, c.category_name

    ORDER BY total DESC
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$expenseResult = mysqli_stmt_get_result($stmt);

$expenseLabels = [];
$expenseValues = [];

while ($row = mysqli_fetch_assoc($expenseResult)) {
    $expenseLabels[] = $row["category_name"] ?? "Uncategorized";
    $expenseValues[] = (float) $row["total"];
}

mysqli_stmt_close($stmt);


// GET MONTHLY INCOME AND EXPENSES FOR LAST SIX MONTHS
$monthlyLabels = [];
$monthlyIncome = [];
$monthlyExpenses = [];

for ($i = 5; $i >= 0; $i--) {

    $date = new DateTime("first day of this month");
    $date->modify("-" . $i . " months");

    $monthStart = $date->format("Y-m-01");
    $monthEnd = $date->format("Y-m-t");

    $monthlyLabels[] = $date->format("M Y");

    $stmt = mysqli_prepare($conn, "
        SELECT
            COALESCE(
                SUM(
                    CASE
                        WHEN transaction_type = 'income'
                        THEN amount
                        ELSE 0
                    END
                ), 0
            ) AS income,

            COALESCE(
                SUM(
                    CASE
                        WHEN transaction_type = 'expense'
                        THEN amount
                        ELSE 0
                    END
                ), 0
            ) AS expenses

        FROM transactions

        WHERE user_id = ?
        AND transaction_date BETWEEN ? AND ?
    ");

    mysqli_stmt_bind_param(
        $stmt,
        "iss",
        $user_id,
        $monthStart,
        $monthEnd
    );

    mysqli_stmt_execute($stmt);

    $monthlyRow = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    $monthlyIncome[] = (float) $monthlyRow["income"];
    $monthlyExpenses[] = (float) $monthlyRow["expenses"];

    mysqli_stmt_close($stmt);
}


// GET RECENT TRANSACTIONS
$stmt = mysqli_prepare($conn, "
    SELECT
        t.transaction_id,
        t.transaction_type,
        t.amount,
        t.description,
        t.transaction_date,
        c.category_name

    FROM transactions t

    LEFT JOIN categories c
        ON t.category_id = c.category_id

    WHERE t.user_id = ?

    ORDER BY t.transaction_date DESC, t.transaction_id DESC

    LIMIT 5
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$transactionResult = mysqli_stmt_get_result($stmt);

$recentTransactions = [];

while ($row = mysqli_fetch_assoc($transactionResult)) {
    $recentTransactions[] = $row;
}

mysqli_stmt_close($stmt);


// GET SAVINGS ALLOCATIONS
$stmt = mysqli_prepare($conn, "
    SELECT allocation_name, allocation_percentage
    FROM savings_allocations
    WHERE user_id = ?
    ORDER BY allocation_name ASC
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$allocationResult = mysqli_stmt_get_result($stmt);

$savingsAllocationLabels = [];
$savingsAllocationValues = [];

while ($row = mysqli_fetch_assoc($allocationResult)) {
    $savingsAllocationLabels[] = $row["allocation_name"];
    $savingsAllocationValues[] = (float) $row["allocation_percentage"];
}

mysqli_stmt_close($stmt);


// CALCULATE UNALLOCATED PERCENTAGE
$totalAllocation = array_sum($savingsAllocationValues);

$unallocatedPercentage = max(0, 100 - $totalAllocation);

if ($unallocatedPercentage > 0) {
    $savingsAllocationLabels[] = "Unallocated";
    $savingsAllocationValues[] = $unallocatedPercentage;
}

// SAFETY CHECK: Ensure chart variables always exist before JavaScript uses them.
$monthlyLabels = $monthlyLabels ?? [];
$monthlyIncome = $monthlyIncome ?? [];
$monthlyExpenses = $monthlyExpenses ?? [];

$expenseLabels = $expenseLabels ?? [];
$expenseValues = $expenseValues ?? [];

$savingsAllocationLabels = $savingsAllocationLabels ?? [];
$savingsAllocationValues = $savingsAllocationValues ?? [];

?>

<?php
$pageTitle = "Dashboard";
ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>

        body {
            background: #f5f3ff;
            font-family: Arial, sans-serif;
        }

        .main-content {
            padding: 30px;
        }

        .section-title {
            color: #2e1065;
            font-weight: bold;
        }

        .summary-card,
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            height: 100%;
        }

        .summary-card h3 {
            font-weight: bold;
            color: #5b21b6;
            overflow-wrap: anywhere;
        }

        .summary-label {
            color: #6b7280;
            font-size: 14px;
        }

        .chart-container {
            position: relative;
            max-width: 100%;
            height: 300px;
        }

        .progress {
            height: 10px;
            background: #ede9fe;
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

        .table th {
            color: #5b21b6;
        }

        .dashboard-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .main-content {
            flex: 1;
            min-width: 0;
        }

        @media (max-width: 767px) {
            .main-content {
                padding: 18px;
            }

            .chart-container {
                height: 260px;
            }
        }

    </style>
<?php
$pageHead = ob_get_clean();
require_once __DIR__ . "/includes/header.php";
?>
<div class="fw-dashboard">
    <section class="fw-hero" aria-label="Financial workspace">
        <div class="fw-hero-copy">
            <div class="fw-hero-eyebrow">Your financial command center</div>
            <h1>Welcome back, <?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?> <span aria-hidden="true">✦</span></h1>
            <p>Build better money habits, stay on top of your spending, and make steady progress toward what matters.</p>
            <div class="fw-hero-actions">
                <a href="transactions/#transactionForm" class="fw-hero-btn"><i class="bi bi-plus-lg" aria-hidden="true"></i> New transaction</a>
                <a href="reports/" class="fw-hero-btn alt"><i class="bi bi-bar-chart-line" aria-hidden="true"></i> View reports</a>
            </div>
        </div>
        <div class="fw-hero-art" aria-hidden="true"><img src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/fundwise-mark.png" alt=""></div>
    </section>

    <div class="fw-section-heading">
        <div><h2>Financial overview</h2><p>Your money at a glance, across all recorded transactions.</p></div>
        <span class="fw-date-pill"><i class="bi bi-calendar3" aria-hidden="true"></i><?= htmlspecialchars((new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('F d, Y'), ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <section class="row g-3 mb-4" aria-label="Financial totals">
        <div class="col-sm-6 col-xl-3"><div class="fw-stat">
            <div class="fw-stat-top"><span class="fw-stat-icon income"><i class="bi bi-arrow-down-left" aria-hidden="true"></i></span><span class="fw-stat-tag">Money in</span></div>
            <div class="fw-stat-label">Total income</div><h3><?= htmlspecialchars(formatMoney($totalIncome, $currency), ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="fw-stat-foot">Recorded income transactions</p>
        </div></div>
        <div class="col-sm-6 col-xl-3"><div class="fw-stat">
            <div class="fw-stat-top"><span class="fw-stat-icon expense"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span><span class="fw-stat-tag">Money out</span></div>
            <div class="fw-stat-label">Total expenses</div><h3><?= htmlspecialchars(formatMoney($totalExpenses, $currency), ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="fw-stat-foot">Recorded expense transactions</p>
        </div></div>
        <div class="col-sm-6 col-xl-3"><div class="fw-stat focus">
            <div class="fw-stat-top"><span class="fw-stat-icon balance"><i class="bi bi-wallet2" aria-hidden="true"></i></span><span class="fw-stat-tag">Net balance</span></div>
            <div class="fw-stat-label">Remaining balance</div><h3><?= htmlspecialchars(formatMoney($remainingBalance, $currency), ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="fw-stat-foot">Income minus expenses</p>
        </div></div>
        <div class="col-sm-6 col-xl-3"><div class="fw-stat">
            <div class="fw-stat-top"><span class="fw-stat-icon savings"><i class="bi bi-piggy-bank" aria-hidden="true"></i></span><span class="fw-stat-tag">Saved money</span></div>
            <div class="fw-stat-label">Savings balance</div><h3><?= htmlspecialchars(formatMoney($savingsBalance, $currency), ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="fw-stat-foot">Savings deposits minus withdrawals</p>
        </div></div>
    </section>

    <div class="fw-section-heading mt-2">
        <div><h2>Money insights</h2><p>Track how your money moves and where you plan to put it.</p></div>
    </div>
    <section class="row g-4 mb-4" aria-label="Financial charts and savings goals">
        <div class="col-xl-7">
            <div class="fw-dashboard-panel">
                <div class="fw-panel-header">
                    <div><h3>Income vs. expenses</h3><p class="fw-panel-subtitle">Your financial activity over the last six months</p></div>
                    <span class="fw-panel-icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
                </div>
                <div class="chart-container fw-chart-tall"><canvas id="monthlyChart" aria-label="Income and expenses over the past six months" role="img"></canvas></div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="fw-dashboard-panel">
                <div class="fw-panel-header">
                    <div><h3>Spending breakdown</h3><p class="fw-panel-subtitle">Expense distribution for this month</p></div>
                    <span class="fw-panel-icon"><i class="bi bi-pie-chart" aria-hidden="true"></i></span>
                </div>
                <div class="chart-container fw-chart-tall"><canvas id="expenseChart" aria-label="Expense distribution by category this month" role="img"></canvas></div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="fw-dashboard-panel">
                <div class="fw-panel-header">
                    <div><h3>Savings allocation</h3><p class="fw-panel-subtitle">How you plan to distribute your savings</p></div>
                    <span class="fw-panel-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                </div>
                <div class="chart-container"><canvas id="savingsAllocationChart" aria-label="Planned savings allocation by percentage" role="img"></canvas></div>
                <div class="fw-panel-footer"><a href="savings/allocations.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Manage allocations</a></div>
            </div>
        </div>
        <div class="col-xl-7">
            <div class="fw-dashboard-panel">
                <div class="fw-panel-header">
                    <div><h3>Your savings goals</h3><p class="fw-panel-subtitle">Every contribution is a step forward</p></div>
                    <a href="savings/" class="btn btn-outline-primary btn-sm">View all <i class="bi bi-arrow-up-right ms-1" aria-hidden="true"></i></a>
                </div>
                <?php if (count($savingsGoals) > 0): ?>
                    <?php foreach ($savingsGoals as $goal): ?>
                        <?php $target=(float)$goal['target_amount']; $current=(float)$goal['current_amount']; $progress=$target>0?min(100,max(0,($current/$target)*100)):0; ?>
                        <div class="fw-goal-entry">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <span class="fw-goal-title"><?= htmlspecialchars($goal['goal_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="fw-goal-amount"><?= htmlspecialchars(formatMoney($current,$currency), ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars(formatMoney($target,$currency), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="progress mt-2" aria-label="<?= number_format($progress,1) ?>% completed">
                                <div class="progress-bar" role="progressbar" aria-valuenow="<?= number_format($progress,1,'.','') ?>" aria-valuemin="0" aria-valuemax="100" style="width:<?= number_format($progress,1,'.','') ?>%"></div>
                            </div>
                            <div class="fw-goal-meta"><span><?= number_format($progress,1) ?>% complete</span>
                            <?php if (!empty($goal['target_date'])): ?><span><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Target: <?= htmlspecialchars($goal['target_date'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="fw-goals-empty"><i class="bi bi-bullseye" aria-hidden="true"></i>No savings goals yet. Set your first goal and start tracking progress.<div class="mt-3"><a href="savings/" class="btn btn-primary btn-sm">Create a savings goal</a></div></div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="fw-section-heading fi-dashboard-heading"><div><h2>Financial intelligence</h2><p>Go beyond tracking. Explore the impact of your decisions and grow better money habits.</p></div></div>
    <div class="row g-3 fi-dashboard-links">
        <div class="col-md-4"><a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/insights/purchase-map.php" class="fi-dashboard-link"><span class="fi-icon-soft"><i class="bi bi-signpost-split"></i></span><span><strong>Purchase Map</strong><small>Understand a purchase before you commit</small></span><i class="bi bi-arrow-up-right fi-link-arrow"></i></a></div>
        <div class="col-md-4"><a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/insights/money-lab.php" class="fi-dashboard-link"><span class="fi-icon-soft"><i class="bi bi-flask"></i></span><span><strong>Money Lab</strong><small>Test new habits with real spending data</small></span><i class="bi bi-arrow-up-right fi-link-arrow"></i></a></div>
        <div class="col-md-4"><a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/insights/earnings.php" class="fi-dashboard-link"><span class="fi-icon-soft"><i class="bi bi-briefcase"></i></span><span><strong>Real Earnings Lens</strong><small>See what your work really brings home</small></span><i class="bi bi-arrow-up-right fi-link-arrow"></i></a></div>
    </div>

    <div class="fw-section-heading"><div><h2>Latest activity</h2><p>Recent records and updates in your workspace.</p></div></div>
    <section class="row g-4" aria-label="Recent activity">
        <div class="col-xl-8">
            <div class="fw-dashboard-panel fw-dashboard-transactions">
                <div class="fw-panel-header">
                    <div><h3>Recent transactions</h3><p class="fw-panel-subtitle">Your five latest financial entries</p></div>
                    <a href="transactions/" class="btn btn-outline-primary btn-sm">View all <i class="bi bi-arrow-up-right ms-1" aria-hidden="true"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        <?php if (count($recentTransactions) > 0): ?>
                            <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td><?= htmlspecialchars($transaction['transaction_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars($transaction['description'] ?: 'No description', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($transaction['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge <?= $transaction['transaction_type']==='income'?'bg-success':'bg-danger' ?>"><?= $transaction['transaction_type']==='income'?'Income':'Expense' ?></span></td>
                                <td class="text-end fw-semibold"><?= htmlspecialchars(formatMoney($transaction['amount'],$currency), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="fw-empty-state"><i class="bi bi-receipt" aria-hidden="true"></i>No transactions recorded yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="fw-dashboard-panel">
                <div class="fw-panel-header">
                    <div><h3>Notifications</h3><p class="fw-panel-subtitle">Recent activity reminders</p></div>
                    <a href="notifications/" class="btn btn-outline-primary btn-sm">View all</a>
                </div>
                <?php if (count($recentNotifications) > 0): ?>
                    <?php foreach ($recentNotifications as $notification): ?>
                    <div class="fw-activity-entry">
                        <span class="fw-activity-dot"><i class="bi bi-bell" aria-hidden="true"></i></span>
                        <div><p><?= htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8') ?></p><small><?= date('M d, Y h:i A',strtotime($notification['created_at'])) ?></small></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="fw-goals-empty"><i class="bi bi-bell" aria-hidden="true"></i>No notifications yet. You're all caught up.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<script>

// SPENDING BREAKDOWN CHART

const expenseLabels = <?= json_encode($expenseLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const expenseValues = <?= json_encode($expenseValues) ?>;

new Chart(document.getElementById("expenseChart"), {

    type: "doughnut",

    data: {

        labels: expenseLabels,

        datasets: [{

            data: expenseValues,

            borderWidth: 2

        }]

    },

    options: {

        responsive: true,

        maintainAspectRatio: false,

        plugins: {

            legend: {
                position: "bottom"
            },

            tooltip: {

                callbacks: {

                    label: function(context) {

                        return context.label + ": " +
                            <?= json_encode($currency) ?> + " " +
                            Number(context.parsed).toLocaleString(
                                "en-US",
                                {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }
                            );

                    }

                }

            }

        }

    }

});


// SAVINGS ALLOCATION CHART

const savingsAllocationLabels =
    <?= json_encode($savingsAllocationLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const savingsAllocationValues =
    <?= json_encode($savingsAllocationValues) ?>;

const allocationCanvas =
    document.getElementById("savingsAllocationChart");

if (savingsAllocationValues.length > 0) {

    new Chart(allocationCanvas, {

        type: "doughnut",

        data: {

            labels: savingsAllocationLabels,

            datasets: [{

                data: savingsAllocationValues,

                borderWidth: 2

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

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

} else {

    allocationCanvas.parentElement.innerHTML =
        '<p class="text-muted text-center mt-5">' +
        'No savings allocations yet. Add your first allocation.' +
        '</p>';

}


// MONTHLY INCOME VS EXPENSES CHART

const monthlyLabels = <?= json_encode($monthlyLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const monthlyIncome = <?= json_encode($monthlyIncome, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const monthlyExpenses = <?= json_encode($monthlyExpenses, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

new Chart(document.getElementById("monthlyChart"), {

    type: "bar",

    data: {

        labels: monthlyLabels,

        datasets: [

            {

                label: "Income",

                data: monthlyIncome,

                borderWidth: 1

            },

            {

                label: "Expenses",

                data: monthlyExpenses,

                borderWidth: 1

            }

        ]

    },

    options: {

        responsive: true,

        maintainAspectRatio: false,

        scales: {

            y: {

                beginAtZero: true

            }

        },

        plugins: {

            legend: {
                position: "bottom"
            }

        }

    }

});

</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
