<?php
require_once __DIR__ . '/common.php';
$currency = fi_currency($conn, $fi_user_id);
$ready = fi_table_exists($conn, 'work_sessions');
$error = '';
$flash = fi_take_flash('earnings');
$month = fi_month($_GET['month'] ?? date('Y-m'));
$monthStart = $month . '-01';
$monthEnd = (new DateTimeImmutable($monthStart))->format('Y-m-t');

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fi_post_csrf()) {
        http_response_code(403);
        $error = 'Invalid form token. Refresh the page and try again.';
    } else {
        $action = fw_text($_POST['action'] ?? null);
        if ($action === 'save') {
            $date = fw_text($_POST['session_date'] ?? null);
            $rawHours = fw_text($_POST['hours_worked'] ?? null);
            $hours = fw_money($rawHours);
            $gross = fw_money($_POST['gross_earnings'] ?? null, true);
            $expense = fw_money($_POST['work_expenses'] ?? null, true);
            $notes = fw_text($_POST['notes'] ?? null);
            if (!fw_valid_date($date) || $date > date('Y-m-d') || $hours === null || $hours > 24
                || $gross === null || $expense === null || strlen($notes) > 255) {
                $error = 'Enter a valid date (today or earlier), hours between 0.01 and 24, and valid earnings and costs.';
            } else {
                $ok = fi_exec($conn, 'INSERT INTO work_sessions (user_id,session_date,hours_worked,gross_earnings,work_expenses,notes) VALUES (?,?,?,?,?,?)',
                    'isddds', [$fi_user_id,$date,$hours,$gross,$expense,$notes]);
                if ($ok) { fi_flash('earnings','Work session recorded. Your main income and expense totals are unchanged.'); header('Location: earnings.php?month='.urlencode(substr($date,0,7))); exit; }
                $error = 'Unable to record your work session.';
            }
        } elseif ($action === 'delete') {
            $id = fw_positive_id($_POST['session_id'] ?? null);
            if ($id > 0 && fi_exec($conn, 'DELETE FROM work_sessions WHERE session_id=? AND user_id=?','ii',[$id,$fi_user_id])) {
                fi_flash('earnings','Work session removed. Your main transaction records are unchanged.');
                header('Location: earnings.php?month='.urlencode($month)); exit;
            }
            $error = 'Could not remove that session.';
        }
    }
}
$sessions = [];
$totalHours = 0.0; $totalGross=0.0; $totalCosts=0.0; $dayTotals = [];
$sessionCount = 0;
if ($ready) {
    // Aggregate ALL matching sessions; the history table displays only the 100 newest.
    $totals = fi_query($conn, "SELECT COUNT(*) AS session_count,
       COALESCE(SUM(hours_worked),0) AS hours,
       COALESCE(SUM(gross_earnings),0) AS gross,
       COALESCE(SUM(work_expenses),0) AS costs
       FROM work_sessions WHERE user_id=? AND session_date BETWEEN ? AND ?",
       'iss', [$fi_user_id,$monthStart,$monthEnd]);
    $sessionCount = (int)($totals[0]['session_count'] ?? 0);
    $totalHours = (float)($totals[0]['hours'] ?? 0);
    $totalGross = (float)($totals[0]['gross'] ?? 0);
    $totalCosts = (float)($totals[0]['costs'] ?? 0);
    $trend = fi_query($conn, 'SELECT session_date,
       COALESCE(SUM(gross_earnings-work_expenses),0) AS net
       FROM work_sessions WHERE user_id=? AND session_date BETWEEN ? AND ?
       GROUP BY session_date ORDER BY session_date ASC',
       'iss', [$fi_user_id,$monthStart,$monthEnd]);
    foreach ($trend as $day) $dayTotals[$day['session_date']] = (float)$day['net'];
    $sessions = fi_query($conn, 'SELECT session_id,session_date,hours_worked,gross_earnings,work_expenses,notes
       FROM work_sessions WHERE user_id=? AND session_date BETWEEN ? AND ?
       ORDER BY session_date DESC, session_id DESC LIMIT 100',
       'iss', [$fi_user_id,$monthStart,$monthEnd]);
}
$totalNet = $totalGross - $totalCosts;
$hourly = $totalHours > 0 ? $totalNet/$totalHours : 0;
$chartMax = max(1, ...array_map('abs', array_values($dayTotals) ?: [0]));
$pageTitle='Real Earnings Lens';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="fi-page">
    <div class="fi-hero fi-hero-earnings"><div><span class="fi-kicker"><i class="bi bi-briefcase"></i> FINANCIAL INTELLIGENCE / WORK PROFITABILITY</span><h1>Earned money.<br><span>Actual take-home.</span></h1><p>Log your work hours, gross pay, and work-related costs. Find out how much you really make per session and per hour.</p></div><div class="fi-hero-icon"><i class="bi bi-graph-up-arrow"></i></div></div>
    <?php if (!$ready): fi_db_notice(); endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger mt-3" role="alert"><?= fi_h($error) ?></div><?php endif; ?>
    <?php if ($flash): ?><div class="alert alert-<?= fi_h($flash['kind']) ?> mt-3" role="status"><?= fi_h($flash['text']) ?></div><?php endif; ?>
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mt-4 mb-2"><div><span class="fi-mini-eyebrow">PERIOD OVERVIEW</span><h2 class="fi-section-title">Work performance</h2></div><form method="get" class="d-flex align-items-center gap-2"><label for="month" class="form-label mb-0">Month</label><input name="month" id="month" type="month" class="form-control" value="<?= fi_h($month) ?>" style="max-width:190px" onchange="this.form.submit()"><button type="submit" class="btn btn-outline-primary">Apply</button></form></div>
    <div class="row g-3">
        <div class="col-6 col-xl-3"><div class="fi-stat"><span><i class="bi bi-cash-stack"></i> Gross earnings</span><strong><?= fi_h(fi_cash($totalGross,$currency)) ?></strong><small>Before work costs</small></div></div>
        <div class="col-6 col-xl-3"><div class="fi-stat"><span><i class="bi bi-fuel-pump"></i> Work expenses</span><strong><?= fi_h(fi_cash($totalCosts,$currency)) ?></strong><small>Fuel, fares, supplies, etc.</small></div></div>
        <div class="col-6 col-xl-3"><div class="fi-stat fi-stat-featured"><span><i class="bi bi-wallet2"></i> Net work earnings</span><strong><?= fi_h(fi_cash($totalNet,$currency)) ?></strong><small>Gross minus work expenses</small></div></div>
        <div class="col-6 col-xl-3"><div class="fi-stat"><span><i class="bi bi-clock"></i> Net per hour</span><strong><?= fi_h(fi_cash($hourly,$currency)) ?></strong><small><?= number_format($totalHours,2) ?> hours logged</small></div></div>
    </div>
    <div class="row g-4 mt-1 fi-columns"><div class="col-lg-4"><section class="fi-panel fi-sticky" aria-labelledby="newSession"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">WORK LOG</span><h2 id="newSession">Record work session</h2></div><span class="fi-icon-soft"><i class="bi bi-plus-lg"></i></span></div>
        <form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= fi_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="save">
            <div class="mb-3"><label for="session_date" class="form-label">Work date</label><input type="date" id="session_date" name="session_date" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= fi_h($_POST['session_date'] ?? date('Y-m-d')) ?>" required></div>
            <div class="mb-3"><label for="hours_worked" class="form-label">Hours worked</label><input type="number" id="hours_worked" name="hours_worked" class="form-control" min="0.01" max="24" step="0.01" placeholder="e.g. 8" required></div>
            <div class="mb-3"><label for="gross_earnings" class="form-label">Gross earnings (<?= fi_h($currency) ?>)</label><input type="number" id="gross_earnings" name="gross_earnings" class="form-control" min="0" max="9999999999.99" step="0.01" placeholder="e.g. 1200" required></div>
            <div class="mb-3"><label for="work_expenses" class="form-label">Work-related expenses (<?= fi_h($currency) ?>)</label><input type="number" id="work_expenses" name="work_expenses" class="form-control" min="0" max="9999999999.99" step="0.01" value="0" required><div class="form-text">Include only costs related to this work session, such as fuel or supplies.</div></div>
            <div class="mb-3"><label for="notes" class="form-label">Notes (optional)</label><textarea id="notes" name="notes" class="form-control" rows="2" maxlength="255" placeholder="e.g. Delivery shift, fuel and parking"></textarea></div>
            <button type="submit" class="btn btn-primary w-100" <?= $ready?'':'disabled' ?>><i class="bi bi-check2-circle me-2"></i> Save work session</button>
        </form>
        <div class="fi-note mt-3"><i class="bi bi-shield-check"></i><span>Work sessions are analytics only. They do <strong>not</strong> add income or expenses to Transactions, so nothing is double-counted.</span></div>
    </section></div>
    <div class="col-lg-8"><section class="fi-panel h-100"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">EARNINGS TREND</span><h2>Net earnings by workday</h2></div><span class="fi-icon-soft"><i class="bi bi-bar-chart"></i></span></div>
        <?php if (!$dayTotals): ?><div class="fi-empty"><i class="bi bi-graph-up-arrow"></i><h3>No work sessions for this month</h3><p>Log your first session to see your real earnings and a monthly comparison.</p></div><?php else: ?>
            <div class="fi-chart" aria-label="Net work earnings by date"><?php foreach ($dayTotals as $date=>$net): ?>
                <div class="fi-bar-col" title="<?= fi_h($date) ?>: <?= fi_h(fi_cash($net,$currency)) ?>"><div class="fi-bar-track"><div class="fi-bar-fill <?= $net<0?'fi-bar-negative':'' ?>" style="height:<?= round(abs($net)/$chartMax*100,1) ?>%"></div></div><span><?= fi_h(date('M j', strtotime($date))) ?></span></div>
            <?php endforeach; ?></div>
            <div class="fi-note mt-3"><i class="bi bi-info-circle"></i><span>Each bar represents net earnings (gross minus expenses) recorded for that date. The bar height shows absolute net magnitude; red bars indicate a negative net day.</span></div>
        <?php endif; ?>
        <div class="fi-divider"></div><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">SESSION HISTORY</span><h2>Your work log</h2></div><span class="fi-tag"><?= count($sessions) ?> of <?= $sessionCount ?> shown</span></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Date</th><th>Hours</th><th>Gross</th><th>Costs</th><th>Net</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($sessions as $s): ?><tr><td><strong><?= fi_h(date('M j, Y', strtotime($s['session_date']))) ?></strong><?php if ($s['notes']!==''): ?><small class="d-block text-muted"><?= fi_h($s['notes']) ?></small><?php endif; ?></td><td><?= number_format((float)$s['hours_worked'],2) ?></td><td><?= fi_h(fi_cash($s['gross_earnings'],$currency)) ?></td><td><?= fi_h(fi_cash($s['work_expenses'],$currency)) ?></td><td class="fw-semibold"><?= fi_h(fi_cash((float)$s['gross_earnings']-(float)$s['work_expenses'],$currency)) ?></td><td><form method="post" onsubmit="return confirm('Delete this work session? Transaction records are not affected.');"><input type="hidden" name="csrf_token" value="<?= fi_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="session_id" value="<?= (int)$s['session_id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger" title="Delete session"><i class="bi bi-trash3"></i></button></form></td></tr><?php endforeach; ?>
        <?php if (!$sessions): ?><tr><td colspan="6" class="text-center text-muted py-4">No sessions for this period.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if ($sessionCount > count($sessions)): ?><div class="fi-note mt-3">Showing the <?= count($sessions) ?> most recent sessions. Summary totals and the trend chart include all <?= $sessionCount ?> sessions for this month.</div><?php endif; ?>
    </section></div></div>
    <div class="fi-footnote"><i class="bi bi-info-circle"></i> Real Earnings Lens is a separate work log, not an account balance. To reflect actual income and expenses on the Fundwise Dashboard, record those transactions separately.</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
