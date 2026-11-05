<?php
require_once __DIR__ . '/common.php';
$currency = fi_currency($conn, $fi_user_id);
$ready = fi_table_exists($conn, 'money_lab_experiments');
$error = '';
$flash = fi_take_flash('lab');

$categories = fi_query($conn, "SELECT category_id, category_name FROM categories
    WHERE user_id = ? AND category_type='expense' ORDER BY category_name", 'i', [$fi_user_id]);

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fi_post_csrf()) {
        http_response_code(403);
        $error = 'Invalid form token. Refresh the page and try again.';
    } else {
        $action = fw_text($_POST['action'] ?? null);
        if ($action === 'create') {
            $title = fw_text($_POST['experiment_title'] ?? null);
            $category_id = fw_positive_id($_POST['category_id'] ?? null);
            $duration = filter_var($_POST['duration'] ?? null, FILTER_VALIDATE_INT);
            $reduction = filter_var($_POST['reduction_percent'] ?? null, FILTER_VALIDATE_INT);
            $manual = fw_money($_POST['manual_baseline'] ?? null);
            $category_ids = array_map(static fn($c)=>(int)$c['category_id'], $categories);
            if ($title === '' || strlen($title) > 120 || !in_array($category_id, $category_ids, true)
                || !in_array($duration, [7,14,30], true) || $reduction === false || $reduction < 5 || $reduction > 80) {
                $error = 'Enter a title, your expense category, a valid duration and a reduction between 5% and 80%.';
            } else {
                $start = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
                $end = $start->modify('+' . ($duration-1) . ' days');
                $previousStart = $start->modify('-' . $duration . ' days');
                $previousEnd = $start->modify('-1 day');
                $history = fi_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM transactions
                    WHERE user_id=? AND category_id=? AND transaction_type='expense'
                    AND transaction_date BETWEEN ? AND ?", 'iiss', [
                        $fi_user_id, $category_id, $previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')]);
                $historical = round((float)($history[0]['total'] ?? 0), 2);
                $baseline = $historical > 0 ? $historical : $manual;
                $source = $historical > 0 ? 'history' : 'manual';
                if ($baseline === null || $baseline <= 0) {
                    $error = 'No spending was found in the previous ' . $duration . ' days. Enter a positive manual baseline to start.';
                } else {
                    $ok = fi_exec($conn, "INSERT INTO money_lab_experiments
                      (user_id,category_id,experiment_title,start_date,end_date,duration_days,reduction_percent,baseline_amount,baseline_source)
                      VALUES (?,?,?,?,?,?,?,?,?)", 'iisssiids', [
                        $fi_user_id,$category_id,$title,$start->format('Y-m-d'),$end->format('Y-m-d'),$duration,$reduction,$baseline,$source]);
                    if ($ok) {
                        fi_flash('lab', 'Your experiment is live! Fundwise will track matching expenses from your Transactions page.');
                        header('Location: money-lab.php'); exit;
                    }
                    $error = 'Unable to save the experiment. Please try again.';
                }
            }
        } elseif ($action === 'delete') {
            $id = fw_positive_id($_POST['experiment_id'] ?? null);
            if ($id <= 0) {
                $error = 'Select a valid experiment.';
            } else {
                $ok = fi_exec($conn, 'DELETE FROM money_lab_experiments WHERE experiment_id=? AND user_id=?','ii', [$id,$fi_user_id]);
                if ($ok) {
                    fi_flash('lab', 'Experiment removed. Your actual transaction records were not changed.');
                    header('Location: money-lab.php'); exit;
                }
                $error = 'Could not delete the experiment.';
            }
        }
    }
}
$page=max(1,min(100000,(int)($_GET['page']??1))); $offset=($page-1)*20;
$experiments = [];
if ($ready) {
    $experiments = fi_query($conn, "
        SELECT e.*, c.category_name,
               COALESCE((SELECT SUM(t.amount) FROM transactions t
                  WHERE t.user_id=e.user_id AND t.category_id=e.category_id
                    AND t.transaction_type='expense'
                    AND t.transaction_date BETWEEN e.start_date AND LEAST(e.end_date,CURDATE())),0) AS actual_spending
        FROM money_lab_experiments e
        INNER JOIN categories c ON c.category_id=e.category_id AND c.user_id=e.user_id
        WHERE e.user_id=? ORDER BY e.start_date DESC, e.experiment_id DESC LIMIT 20 OFFSET {$offset}
    ", 'i', [$fi_user_id]);
}
$today = date('Y-m-d');
$counts=$ready?fi_query($conn,"SELECT COUNT(*) AS total, COALESCE(SUM(e.end_date>=CURDATE()),0) AS active, COALESCE(SUM(e.end_date<CURDATE()),0) AS finished, COALESCE(SUM(e.end_date<CURDATE() AND COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=e.user_id AND t.category_id=e.category_id AND t.transaction_type='expense' AND t.transaction_date BETWEEN e.start_date AND LEAST(e.end_date,CURDATE())),0)<=e.baseline_amount*(1-e.reduction_percent/100)),0) AS successful FROM money_lab_experiments e WHERE e.user_id=?",'i',[$fi_user_id])[0]:[];
$activeCount=(int)($counts['active']??0);$finishedCount=(int)($counts['finished']??0);$successfulCount=(int)($counts['successful']??0);
$pageTitle = 'Money Lab';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="fi-page">
    <div class="fi-hero fi-hero-lab">
        <div><span class="fi-kicker"><i class="bi bi-flask"></i> FINANCIAL INTELLIGENCE / HABIT EXPERIMENTS</span>
            <h1>Small experiments.<br><span>Smarter money habits.</span></h1>
            <p>Turn a spending goal into a 7-, 14-, or 30-day experiment. Compare your previous spending with what you actually record during the challenge.</p></div>
        <div class="fi-hero-icon"><i class="bi bi-flask"></i></div>
    </div>
    <?php if (!$ready): fi_db_notice(); endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger mt-3" role="alert"><?= fi_h($error) ?></div><?php endif; ?>
    <?php if ($flash): ?><div class="alert alert-<?= fi_h($flash['kind']) ?> mt-3" role="status"><?= fi_h($flash['text']) ?></div><?php endif; ?>
    <div class="row g-3 my-2">
        <div class="col-md-4"><div class="fi-stat"><span><i class="bi bi-lightning-charge"></i> Active experiments</span><strong><?= $activeCount ?></strong><small>Ongoing habit tests</small></div></div>
        <div class="col-md-4"><div class="fi-stat"><span><i class="bi bi-check2-circle"></i> Completed experiments</span><strong><?= $finishedCount ?></strong><small>Experiments past their end date</small></div></div>
        <div class="col-md-4"><div class="fi-stat"><span><i class="bi bi-stars"></i> Targets achieved</span><strong><?= $successfulCount ?></strong><small>Completed within the target</small></div></div>
    </div>
    <div class="row g-4 mt-1 fi-columns">
        <div class="col-lg-4">
            <section class="fi-panel fi-sticky" aria-labelledby="newExperiment">
                <div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">CREATE NEW</span><h2 id="newExperiment">Start an experiment</h2></div><span class="fi-icon-soft"><i class="bi bi-plus-lg"></i></span></div>
                <form method="post" autocomplete="off"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= fi_h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3"><label class="form-label" for="experiment_title">Experiment title</label><input id="experiment_title" name="experiment_title" class="form-control" placeholder="e.g. Less takeout this week" maxlength="120" required value="<?= fi_h($_POST['experiment_title'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label" for="category_id">Expense category</label><select id="category_id" name="category_id" class="form-select" required><option value="">Choose a category</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['category_id'] ?>" <?= (int)($_POST['category_id'] ?? 0)===(int)$c['category_id']?'selected':'' ?>><?= fi_h($c['category_name']) ?></option><?php endforeach ?></select></div>
                    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label" for="duration">Duration</label><select name="duration" id="duration" class="form-select"><option value="7">7 days</option><option value="14">14 days</option><option value="30">30 days</option></select></div><div class="col-6"><label class="form-label" for="reduction_percent">Spend less by</label><select id="reduction_percent" name="reduction_percent" class="form-select"><option value="10">10%</option><option value="20" selected>20%</option><option value="25">25%</option><option value="30">30%</option><option value="40">40%</option><option value="50">50%</option></select></div></div>
                    <div class="mb-3"><label class="form-label" for="manual_baseline">Manual baseline (optional, <?= fi_h($currency) ?>)</label><input type="number" step="0.01" min="0.01" max="9999999999.99" id="manual_baseline" name="manual_baseline" class="form-control" placeholder="Use if no previous transactions"><div class="form-text">Fundwise uses your actual spending from the previous matching period when available. This value is only used if that total is zero.</div></div>
                    <button type="submit" class="btn btn-primary w-100" <?= (!$ready || !$categories)?'disabled':'' ?>><i class="bi bi-flask me-2"></i> Start money experiment</button>
                </form>
                <div class="fi-note mt-3"><i class="bi bi-info-circle"></i><span>Starting an experiment does not modify your budget or savings balances. You still record expenses normally.</span></div>
            </section>
        </div>
        <div class="col-lg-8">
            <div class="fi-panel">
                <div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">YOUR EXPERIMENTS</span><h2>Progress & outcomes</h2></div><span class="fi-icon-soft"><i class="bi bi-graph-up-arrow"></i></span></div>
                <?php if (!$experiments): ?>
                    <div class="fi-empty"><i class="bi bi-flask"></i><h3>Ready for your first experiment?</h3><p>Choose an expense category and a reduction goal. Your experiments will appear here with live progress from Transactions.</p></div>
                <?php endif; ?>
                <?php foreach ($experiments as $e):
                    $baseline = (float)$e['baseline_amount'];
                    $target = round($baseline*(1-(int)$e['reduction_percent']/100),2);
                    $actual = (float)$e['actual_spending'];
                    $completed = $e['end_date'] < $today;
                    $success = $completed && $actual <= $target;
                    $daysElapsed = max(0, min((int)$e['duration_days'], (new DateTimeImmutable($e['start_date']))->diff(new DateTimeImmutable('today'))->days + 1));
                    if ($today < $e['start_date']) $daysElapsed = 0;
                    $paceTarget = $target * $daysElapsed / max(1, (int)$e['duration_days']);
                    $onTrack = $actual <= $paceTarget;
                    $status = $completed ? ($success?'Target reached':'Target missed') : ($onTrack?'On track':'Above target pace');
                    $pill = $completed ? ($success?'success':'danger') : ($onTrack?'success':'warning');
                    $percent = $target > 0 ? min(100, max(0, $actual/$target*100)) : ($actual>0?100:0);
                ?>
                <article class="fi-experiment">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2"><div><span class="fi-mini-eyebrow"><?= fi_h($e['category_name']) ?> · <?= (int)$e['duration_days'] ?> DAYS · <?= (int)$e['reduction_percent'] ?>% REDUCTION</span><h3><?= fi_h($e['experiment_title']) ?></h3><small class="text-muted"><?= fi_h(date('M j',strtotime($e['start_date']))) ?> – <?= fi_h(date('M j, Y',strtotime($e['end_date']))) ?> · <?= $e['baseline_source']==='manual'?'Manual baseline':'Previous-period baseline' ?></small></div><span class="fi-pill fi-pill-<?= $pill ?>"><?= fi_h($status) ?></span></div>
                    <div class="fi-exp-numbers"><span>Previously: <strong><?= fi_h(fi_cash($baseline,$currency)) ?></strong></span><span>Target: <strong><?= fi_h(fi_cash($target,$currency)) ?></strong></span><span>Actual: <strong><?= fi_h(fi_cash($actual,$currency)) ?></strong></span></div>
                    <div class="fi-meter"><span style="width:<?= round($percent,1) ?>%" class="<?= $percent>=100?'fi-meter-full':'' ?>"></span></div>
                    <div class="d-flex justify-content-between align-items-center gap-2 mt-2 flex-wrap"><span class="fi-subtext"><?= $completed ? ($success?'Spent '.fi_h(fi_cash($target-$actual,$currency)).' less than your target.':'Spent '.fi_h(fi_cash($actual-$target,$currency)).' over your target.') : 'Target pace so far: '.fi_h(fi_cash($paceTarget,$currency)).' · Day '.$daysElapsed.' of '.(int)$e['duration_days'] ?></span><form method="post" onsubmit="return confirm('Remove this experiment? Actual transactions will not be deleted.');"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="csrf_token" value="<?= fi_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="experiment_id" value="<?= (int)$e['experiment_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash3 me-1"></i> Remove</button></form></div>
                </article>
                <?php endforeach; ?>
                <div class="fi-note mt-3"><i class="bi bi-shield-check"></i><span>Spending below a target is not automatically saved money. Your Savings Goals balance only changes when you record a contribution.</span></div>
            </div>
        </div>
    </div>
</div>
<nav class="fw-pagination" aria-label="Experiment history pages"><?php if($page>1):?><a class="btn btn-outline-primary" href="?page=<?= $page-1 ?>">Previous</a><?php endif;?> <span>Page <?= $page ?></span> <?php if($page*20<(int)($counts['total']??0)):?><a class="btn btn-outline-primary" href="?page=<?= $page+1 ?>">Next</a><?php endif;?></nav>
<div class="fi-note mt-3">Results are comparisons, not proof that an experiment caused a change. Missing records or edits to past transactions can change outcomes. The baseline is kept from the start of the experiment.</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
