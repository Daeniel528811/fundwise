<?php
$overviewBudgets = fw_query($conn, "SELECT b.*,c.category_name,COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=b.user_id AND t.category_id=b.category_id AND t.transaction_type='expense' AND t.transaction_date BETWEEN b.start_date AND LEAST(b.end_date,CURDATE())),0) AS spent FROM budgets b JOIN categories c ON c.category_id=b.category_id AND c.user_id=b.user_id WHERE b.user_id=? AND CURDATE() BETWEEN b.start_date AND b.end_date ORDER BY spent/NULLIF(b.budget_limit,0) DESC LIMIT 3", 'i', [(int) $_SESSION['user_id']]);
$budgetCurrency = fw_query($conn, 'SELECT preferred_currency FROM users WHERE user_id=?', 'i', [(int) $_SESSION['user_id']])[0]['preferred_currency'] ?? 'PHP';
$bh = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<section class="fw-dashboard-panel fw-budget-overview" aria-labelledby="budgetOverview"><div class="fw-panel-header"><div><h2 id="budgetOverview">Budget overview</h2><p>Your active spending limits, at a glance.</p></div><a class="btn btn-outline-primary btn-sm" href="<?= $bh(BASE_URL) ?>/budgets/">View budgets</a></div><div class="fw-budget-grid">
<?php foreach ($overviewBudgets as $b):$limit = (float) $b['budget_limit'];
    $spent = (float) $b['spent'];
    $percent = $limit > 0 ? $spent / $limit * 100 : 0;
    $tone = $percent >= 100 ? 'danger' : ($percent >= 80 ? 'warning' : 'primary');
    $remaining = $limit - $spent; ?>
<article class="fw-budget-item"><h3><?= $bh($b['category_name']) ?></h3><span class="fw-budget-dates" title="<?= $bh($b['start_date'] . ' to ' . $b['end_date']) ?>"><?= $bh(date('M j', strtotime($b['start_date'])) . ' – ' . date('M j, Y', strtotime($b['end_date']))) ?></span><div class="fw-budget-amount"><?= $bh($budgetCurrency) ?> <?= number_format($spent, 2) ?> <span>/ <?= number_format($limit, 2) ?></span></div><div class="progress" role="progressbar" aria-label="<?= $bh($b['category_name']) ?> budget used" aria-valuenow="<?= min(100, max(0, round($percent))) ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-<?= $tone ?>" style="width:<?= min(100, max(0, $percent)) ?>%"></div></div><p class="fw-budget-state text-<?= $tone ?>"><i class="bi bi-<?= $remaining < 0 ? 'exclamation-circle' : ($remaining == 0 ? 'dash-circle' : 'check-circle') ?>" aria-hidden="true"></i><?= number_format(abs($remaining), 2) ?> <?= $remaining < 0 ? 'over budget' : ($remaining == 0 ? 'remaining · limit reached' : 'remaining') ?></p></article>
<?php endforeach;
if (!$overviewBudgets):?><div class="fw-goals-empty"><i class="bi bi-pie-chart" aria-hidden="true"></i>No active budgets yet. <a href="<?= $bh(BASE_URL) ?>/budgets/">Create a budget</a></div><?php endif; ?></div></section>
