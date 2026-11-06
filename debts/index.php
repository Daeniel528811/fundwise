<?php
require_once __DIR__ . '/../insights/common.php';
require_once __DIR__ . '/../includes/loan_actions.php';
$uid = $fi_user_id;
$currency = fw_user_currency_code($conn, $uid);
$today = date('Y-m-d');
$error = '';
$ready = fw_has_table($conn, 'fw_debts');
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!fi_post_csrf()) {
            throw new InvalidArgumentException('Refresh this page and try again.');
        }
        $action = fw_text($_POST['action'] ?? null);
        mysqli_begin_transaction($conn);
        fw_query($conn, 'SELECT user_id FROM users WHERE user_id=? FOR UPDATE', 'i', [$uid]);
        if ($action === 'create') {
            $lender = fw_text($_POST['lender'] ?? null);
            $description = fw_text($_POST['description'] ?? null);
            $opened = fw_text($_POST['opened_on'] ?? null);
            $principal = fi_require_money($_POST['opening_principal'] ?? null, 'Outstanding principal', false);
            if ($lender === '' || fw_text_length($lender) > 100 || fw_text_length($description) > 255 || !fw_valid_date($opened) || $opened > $today) {
                throw new InvalidArgumentException('Enter a lender, a description up to 255 characters, and an opening date no later than today.');
            }
            fw_query($conn, 'INSERT INTO fw_debts(user_id,lender,description,opening_principal,opened_on) VALUES (?,?,?,?,?)', 'issss', [$uid, $lender, $description, fi_amount($principal), $opened]);
            $id = (int) mysqli_insert_id($conn);
            fw_revision($conn, $uid, 'debt', $id, 'created', null, ['lender' => $lender, 'principal' => fi_amount($principal), 'opened_on' => $opened]);
        } else {
            $id = fw_positive_id($_POST['debt_id'] ?? null);
            $d = fw_query($conn, 'SELECT * FROM fw_debts WHERE debt_id=? AND user_id=? FOR UPDATE', 'ii', [$id, $uid])[0] ?? null;
            if (!$d) {
                throw new InvalidArgumentException('Debt unavailable.');
            }
            if ((int) $d['version'] !== fi_require_int($_POST['version'] ?? null, 1, 2147483646, 'Version')) {
                throw new InvalidArgumentException('This debt changed in another tab. Reload before saving.');
            }
            $paid = fi_cents(fw_query($conn, 'SELECT COALESCE(SUM(principal),0) AS paid FROM fw_debt_payments WHERE debt_id=? AND user_id=?', 'ii', [$id, $uid])[0]['paid']);
            $remaining = fi_cents($d['opening_principal']) - $paid;
            if ($action === 'payment') {
                if ((int) $d['archived']) {
                    throw new InvalidArgumentException('Restore this debt before recording a payment.');
                }
                $principal = fi_require_money($_POST['principal'] ?? null, 'Principal');
                $interest = fi_require_money($_POST['interest'] ?? '0', 'Interest');
                $fees = fi_require_money($_POST['fees'] ?? '0', 'Fees');
                $date = fw_text($_POST['paid_on'] ?? null);
                $note = fw_text($_POST['note'] ?? null);
                if ($principal > $remaining || $principal + $interest + $fees <= 0 || !fw_valid_date($date) || $date < $d['opened_on'] || $date > $today || fw_text_length($note) > 255) {
                    throw new InvalidArgumentException('Use a positive total payment, principal no higher than the remaining debt, and a payment date between opening and today.');
                }
                $installmentId = fw_positive_id($_POST['installment_id'] ?? null);
                $plan = fw_loan_ready($conn) ? fw_loan_rows($conn, $uid, $id) : [];
                if ($plan) {
                    $selectedInstallment = null;
                    foreach ($plan as $entry) {
                        if ((int) $entry['installment_id'] === $installmentId) {
                            $selectedInstallment = $entry;
                        }
                    }
                    if (!$selectedInstallment) {
                        throw new InvalidArgumentException('Select an installment in this loan’s payment schedule.');
                    }
                    $due = fw_loan_due($selectedInstallment);
                    foreach (['principal' => $principal, 'interest' => $interest, 'fees' => $fees] as $key => $value) {
                        if ($value > $due[$key]) {
                            throw new InvalidArgumentException('The payment exceeds the remaining ' . $key . ' for this installment.');
                        }
                    }
                } elseif ($installmentId) {
                    throw new InvalidArgumentException('Installment unavailable.');
                }
                fw_query($conn, 'INSERT INTO fw_debt_payments(debt_id,user_id,paid_on,principal,interest,fees,note) VALUES (?,?,?,?,?,?,?)', 'iisssss', [$id, $uid, $date, fi_amount($principal), fi_amount($interest), fi_amount($fees), $note]);
                $newPaymentId = (int) mysqli_insert_id($conn);
                if ($installmentId) {
                    fw_query($conn, 'INSERT INTO fw_loan_allocations(payment_id,user_id,debt_id,installment_id) VALUES (?,?,?,?)', 'iiii', [$newPaymentId, $uid, $id, $installmentId]);
                }
                fw_revision($conn, $uid, 'debt_payment', $newPaymentId, 'created', null, ['debt_id' => $id, 'principal' => $principal, 'interest' => $interest, 'fees' => $fees, 'paid_on' => $date]);
                if (fi_checked($_POST['settles_due'] ?? null)) {
                    fw_query($conn, 'UPDATE fw_debts SET next_due=NULL,scheduled_amount=0 WHERE debt_id=? AND user_id=?', 'ii', [$id, $uid]);
                }
            } elseif (fw_loan_action($conn, $uid, $d, $action, $remaining)) {
            } elseif ($action === 'schedule') {
                if (fw_loan_ready($conn) && fw_loan_rows($conn, $uid, $id)) {
                    throw new InvalidArgumentException('Edit the installment schedule to change this loan’s next payment.');
                }
                $due = fw_text($_POST['next_due'] ?? null);
                $amount = fi_require_money($_POST['scheduled_amount'] ?? '0', 'Scheduled payment');
                if (($due !== '' && (!fw_valid_date($due) || $due < $d['opened_on'] || $amount <= 0)) || ($due === '' && $amount > 0)) {
                    throw new InvalidArgumentException('Enter both a due date and positive payment, or clear both.');
                }
                fw_query($conn, 'UPDATE fw_debts SET next_due=?,scheduled_amount=? WHERE debt_id=? AND user_id=?', 'ssii', [$due ?: null, fi_amount($amount), $id, $uid]);
                fw_revision($conn, $uid, 'debt', $id, 'schedule_changed', $d, ['next_due' => $due, 'scheduled_amount' => fi_amount($amount)]);
            } elseif ($action === 'void_payment') {
                $pid = fw_positive_id($_POST['payment_id'] ?? null);
                $p = fw_query($conn, 'SELECT * FROM fw_debt_payments WHERE payment_id=? AND debt_id=? AND user_id=?', 'iii', [$pid, $id, $uid])[0] ?? null;
                if (!$p) {
                    throw new InvalidArgumentException('Payment unavailable.');
                }
                if (fw_loan_ready($conn) && fw_loan_rows($conn, $uid, $id) && !fw_query($conn, 'SELECT payment_id FROM fw_loan_allocations WHERE payment_id=? AND debt_id=? AND user_id=?', 'iii', [$pid, $id, $uid])) {
                    throw new InvalidArgumentException('This payment predates the installment plan. Void any installment payments and clear the schedule before correcting this earlier payment.');
                }
                fw_revision($conn, $uid, 'debt_payment', $pid, 'voided', $p, null);
                if (fw_loan_ready($conn)) {
                    fw_query($conn, 'DELETE FROM fw_loan_allocations WHERE payment_id=? AND debt_id=? AND user_id=?', 'iii', [$pid, $id, $uid]);
                }
                fw_query($conn, 'DELETE FROM fw_debt_payments WHERE payment_id=? AND debt_id=? AND user_id=?', 'iii', [$pid, $id, $uid]);
            } elseif ($action === 'archive') {
                fw_query($conn, 'UPDATE fw_debts SET archived=? WHERE debt_id=? AND user_id=?', 'iii', [(int) !$d['archived'], $id, $uid]);
                fw_revision($conn, $uid, 'debt', $id, 'archive_changed', $d, ['archived' => (int) !$d['archived']]);
            } else {
                throw new InvalidArgumentException('Choose a valid debt action.');
            }
            if (fw_loan_ready($conn)) {
                fw_loan_sync($conn, $uid, $id);
            }
            fw_query($conn, 'UPDATE fw_debts SET version=version+1 WHERE debt_id=? AND user_id=?', 'ii', [$id, $uid]);
        }
        mysqli_commit($conn);
        header('Location: index.php?debt=' . $id . '&saved=1');
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save. Please try again.';
        error_log('Debt tracker: ' . $e->getMessage());
    }
}
$debts = $ready ? fw_query($conn, 'SELECT d.*,COALESCE((SELECT SUM(p.principal) FROM fw_debt_payments p WHERE p.debt_id=d.debt_id AND p.user_id=d.user_id),0) AS paid FROM fw_debts d WHERE d.user_id=? ORDER BY archived,next_due IS NULL,next_due,debt_id DESC', 'i', [$uid]) : [];
$selected = null;
$selectedId = fw_positive_id($_GET['debt'] ?? null);
foreach ($debts as $d) {
    if ((int) $d['debt_id'] === $selectedId) {
        $selected = $d;
    }
}
$payments = $selected ? fw_query($conn, 'SELECT * FROM fw_debt_payments WHERE debt_id=? AND user_id=? ORDER BY paid_on DESC,payment_id DESC', 'ii', [$selectedId, $uid]) : [];
$outstanding = 0;
foreach ($debts as $d) {
    if (!$d['archived']) {
        $outstanding += fi_cents($d['opening_principal']) - fi_cents($d['paid']);
    }
}
$pageTitle = 'Debts & Loans';
$pageHead = '<link rel="stylesheet" href="' . fw_h(BASE_URL) . '/css/intelligence.css?v=20261104-polish2">';
require __DIR__ . '/../includes/header.php';
function debt_fields(string $action, ?array $d = null): void
{?><input type="hidden" name="fw_request_token" value="<?= fw_h(fw_request_token()) ?>"><input type="hidden" name="csrf_token" value="<?= fw_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="<?= fw_h($action) ?>"><?php if ($d):?><input type="hidden" name="debt_id" value="<?= (int) $d['debt_id'] ?>"><input type="hidden" name="version" value="<?= (int) $d['version'] ?>"><?php endif;
}
?>
<div class="fi-page fi-v2 fw-general"><div class="fi-hero"><div><span class="fi-kicker">DEBT TRACKER</span><h1>Your debts &<br><span>loans, organized.</span></h1><p>Track money you owe, record repayments, and plan your next payment.</p></div><div class="fi-hero-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div></div>
<?php if ($error):?><p class="alert alert-danger mt-3"><?= fw_h($error) ?></p><?php endif;
if (isset($_GET['saved'])):?><p class="alert alert-success mt-3">Record saved. Review your payment schedule and remaining amounts.</p><?php endif;
if (!$ready):?><p class="alert alert-warning">Import 20261103_general_update.sql first.</p><?php else:?>
<?php $activeCount = 0;
    $paidPrincipal = 0;
    $nextDue = null;
    foreach ($debts as $item) {
        if (!$item['archived']) {
            $activeCount++;
            $paidPrincipal += (float) $item['paid'];
            if (((float) $item['opening_principal'] > (float) $item['paid'] || (float) $item['scheduled_amount'] > 0) && $item['next_due'] && (!$nextDue || $item['next_due'] < $nextDue)) {
                $nextDue = $item['next_due'];
            }
        }
    } ?>
<div class="row g-3 my-3"><div class="col-12 col-md-4"><div class="fi-stat"><span>Outstanding principal</span><strong><?= fw_h(fw_format_money($outstanding / 100, $currency)) ?></strong><small><?= $activeCount ?> active debt<?= $activeCount === 1 ? '' : 's' ?></small></div></div><div class="col-6 col-md-4"><div class="fi-stat"><span>Principal repaid</span><strong><?= fw_h(fw_format_money($paidPrincipal, $currency)) ?></strong><small>Across active debts</small></div></div><div class="col-6 col-md-4"><div class="fi-stat"><span>Next payment</span><strong><?= $nextDue ? fw_h(date('M j', strtotime($nextDue))) : '—' ?></strong><small><?= $nextDue ? fw_h(date('Y', strtotime($nextDue))) : 'No payment scheduled' ?></small></div></div></div>
<details class="fw-help"><summary>How debt tracking works</summary><p>This is a manual debt register. Entries do not post Income or Expenses or change the dashboard’s recorded cash-flow balance. Principal reduces the debt; interest and fees are tracked separately. Use a checked cash balance in Purchase Map when your transaction history excludes loan cash movements.</p></details>
<div class="row g-4"><div class="col-lg-4"><section class="fi-panel"><h2 class="fi-section-title">Add a debt</h2><form method="post"><?php debt_fields('create'); ?><label class="form-label w-100">Lender / debt name<input class="form-control" name="lender" maxlength="100" required></label><label class="form-label w-100">Outstanding principal at start<input class="form-control" type="number" name="opening_principal" min="0.01" max="9999999999.99" step="0.01" required></label><label class="form-label w-100">Starting date<input class="form-control" type="date" name="opened_on" max="<?= $today ?>" value="<?= $today ?>" required></label><label class="form-label w-100">Description<textarea class="form-control" name="description" maxlength="255"></textarea></label><p class="form-text">Start with today’s outstanding principal. Record interest and fees from your actual statement.</p><button class="btn btn-primary" type="submit">Save debt</button></form></section></div>
<div class="col-lg-8"><section class="fi-panel"><h2 class="fi-section-title">Your debts</h2><?php if (!$debts):?><p>No debts recorded.</p><?php endif;
foreach ($debts as $d):$remaining = (float) $d['opening_principal'] - (float) $d['paid']; ?><a class="fw-debt-card" href="?debt=<?= (int) $d['debt_id'] ?>"><div><span class="fw-debt-name"><?= fw_h($d['lender']) ?></span><div class="fw-debt-meta"><?= $d['archived'] ? 'Archived' : ($remaining <= 0 ? ((float) $d['scheduled_amount'] > 0 ? 'Principal repaid · charges remain' : 'Repaid') : ($d['next_due'] ? 'Due ' . fw_h(date('M j, Y', strtotime($d['next_due']))) : 'No payment scheduled')) ?></div></div><div class="fw-debt-balance"><?= fw_h(fw_format_money($remaining, $currency)) ?><small>remaining principal <i class="bi bi-arrow-right" aria-hidden="true"></i></small></div></a><?php endforeach; ?></section>
<?php if ($selected):$d = $selected; ?><section class="fi-panel mt-3"><h2 class="fi-section-title"><?= fw_h($d['lender']) ?></h2><p><?= fw_h($d['description']) ?></p><?php $remaining = (float) $d['opening_principal'] - (float) $d['paid'];
    require __DIR__ . '/../includes/loan_schedule_view.php';
    $hasPlan = fw_loan_ready($conn) && fw_loan_rows($conn, $uid, (int) $d['debt_id']);
    if (!$hasPlan):?><details class="fi-disclosure" open><summary>Next scheduled payment</summary><form method="post"><?php debt_fields('schedule', $d); ?><div class="fi-form-grid"><label>Due date<input class="form-control" type="date" name="next_due" value="<?= fw_h($d['next_due'] ?? '') ?>"></label><label>Total due<input class="form-control" type="number" name="scheduled_amount" min="0" max="9999999999.99" step="0.01" value="<?= fw_h($d['scheduled_amount']) ?>"></label></div><p class="form-text">Choose how early to be reminded in <a href="../notifications/reminders.php">Reminder settings</a>.</p><button class="btn btn-outline-primary" type="submit">Save schedule</button></form></details>
<?php endif;
    if (!$hasPlan && !$d['archived']):?><details class="fi-disclosure"><summary>Record a repayment</summary><form method="post"><?php debt_fields('payment', $d); ?><div class="fi-form-grid"><label>Paid on<input class="form-control" type="date" name="paid_on" min="<?= fw_h($d['opened_on']) ?>" max="<?= $today ?>" required></label><label>Principal paid<input class="form-control" type="number" name="principal" min="0" step="0.01" required></label><label>Interest paid<input class="form-control" type="number" name="interest" min="0" step="0.01" value="0" required></label><label>Fees paid<input class="form-control" type="number" name="fees" min="0" step="0.01" value="0" required></label><label class="fi-full">Note<input class="form-control" name="note" maxlength="255"></label></div><label class="fi-check"><input type="checkbox" name="settles_due" value="1"><span>This settles the scheduled payment. Clear it so I can set the next one.</span></label><button class="btn btn-primary" type="submit">Save repayment</button></form></details><?php endif; ?>
<h3 class="fi-section-title mt-4">Repayment history</h3><?php foreach ($payments as $p):?><div class="fw-ledger-entry"><strong><?= fw_h($p['paid_on']) ?> · <?= fw_h(fw_format_money((float) $p['principal'] + (float) $p['interest'] + (float) $p['fees'], $currency)) ?></strong><p class="mb-1">Principal <?= fw_h(fw_format_money($p['principal'], $currency)) ?> · Interest <?= fw_h(fw_format_money($p['interest'], $currency)) ?> · Fees <?= fw_h(fw_format_money($p['fees'], $currency)) ?></p><p style="overflow-wrap:anywhere"><?= fw_h($p['note']) ?></p><form method="post" onsubmit="return confirm('Void this mistaken payment? The principal will be restored. Review the payment schedule afterwards.');"><?php debt_fields('void_payment', $d); ?><input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>"><button class="btn btn-outline-danger btn-sm">Void mistaken payment</button></form></div><?php endforeach;
    if (!$payments):?><p>No repayments recorded.</p><?php endif; ?><form method="post" class="mt-3"><?php debt_fields('archive', $d); ?><button class="btn btn-outline-secondary"><?= $d['archived'] ? 'Restore debt' : 'Archive debt' ?></button><p class="form-text">Archiving keeps the history and pauses reminders. The balance remains owed.</p></form></section><?php endif; ?></div></div><?php endif; ?></div>
<script src="<?= fw_h(BASE_URL) ?>/js/loan-schedule.js?v=20261104-u2"></script><?php require __DIR__ . '/../includes/footer.php'; ?>
