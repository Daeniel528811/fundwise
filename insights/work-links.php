<?php
require_once __DIR__ . '/common.php';
$uid = $fi_user_id;
$id = fw_positive_id($_GET['session'] ?? null);
$error = '';
$currency = fw_user_currency_code($conn, $uid);
$ready = fw_has_table($conn, 'fw_work_cost_links');
$session = fw_query($conn, 'SELECT s.*,d.income_transaction_id,d.work_source,COALESCE(d.record_version,0) AS record_version FROM work_sessions s LEFT JOIN fw_work_session_details d ON d.session_id=s.session_id AND d.user_id=s.user_id WHERE s.session_id=? AND s.user_id=?', 'ii', [$id, $uid])[0] ?? null;
if (!$session) {
    http_response_code(404);
    exit('Work session unavailable.');
}
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!fi_post_csrf()) {
            throw new InvalidArgumentException('Refresh the page and try again.');
        }
        mysqli_begin_transaction($conn);
        fw_query($conn, 'SELECT user_id FROM users WHERE user_id=? FOR UPDATE', 'i', [$uid]);
        $current = fw_query($conn, 'SELECT s.*,d.income_transaction_id,COALESCE(d.record_version,0) AS record_version FROM work_sessions s LEFT JOIN fw_work_session_details d ON d.session_id=s.session_id AND d.user_id=s.user_id WHERE s.session_id=? AND s.user_id=? FOR UPDATE', 'ii', [$id, $uid])[0] ?? null;
        if (!$current || (int) $current['record_version'] !== fi_require_int($_POST['version'] ?? null, 0, 2147483646, 'Version')) {
            throw new InvalidArgumentException('The session changed. Reload before linking.');
        }
        $action = fw_text($_POST['action'] ?? null);
        $kind = fw_text($_POST['kind'] ?? null);
        $expenseId = fw_positive_id($_POST['expense_id'] ?? null);
        if (!in_array($kind, ['income', 'expense'], true) || !in_array($action, ['create', 'link', 'unlink'], true)) {
            throw new InvalidArgumentException('Choose a valid link action.');
        }
        $item = $kind === 'expense' ? (fw_query($conn, 'SELECT * FROM work_session_expenses WHERE expense_id=? AND session_id=?', 'ii', [$expenseId, $id])[0] ?? null) : null;
        if ($kind === 'expense' && !$item) {
            throw new InvalidArgumentException('That cost is unavailable.');
        }
        if ($action === 'unlink') {
            if ($kind === 'expense') {
                fw_query($conn, 'DELETE FROM fw_work_cost_links WHERE expense_id=? AND user_id=?', 'ii', [$expenseId, $uid]);
            } else {
                fw_query($conn, "UPDATE fw_work_session_details SET income_transaction_id=NULL,payment_status='unknown',received_amount=0,received_date=NULL WHERE session_id=? AND user_id=?", 'ii', [$id, $uid]);
            }
        } else {
            if ($kind === 'income' && !empty($current['income_transaction_id'])) {
                throw new InvalidArgumentException('This session already has an income link. Unlink it first.');
            }
            if ($kind === 'expense' && fw_query($conn, 'SELECT expense_id FROM fw_work_cost_links WHERE expense_id=? AND user_id=?', 'ii', [$expenseId, $uid])) {
                throw new InvalidArgumentException('This cost is already linked.');
            }
            $transactionId = fw_positive_id($_POST['transaction_id'] ?? null);
            if ($action === 'create') {
                if (!fi_checked($_POST['reviewed'] ?? null)) {
                    throw new InvalidArgumentException('Confirm that this payment is real and has not already been recorded.');
                }
                $amount = $kind === 'expense' ? fi_cents($item['amount']) : fi_require_money($_POST['amount'] ?? null, 'Received income', false);
                if ($kind === 'income' && $amount > fi_cents($current['gross_earnings'])) {
                    throw new InvalidArgumentException('Received income cannot exceed this session’s gross earnings.');
                }
                $category = fw_positive_id($_POST['category_id'] ?? null);
                $date = fw_text($_POST['transaction_date'] ?? null);
                if (!fw_valid_date($date) || $date > date('Y-m-d')) {
                    throw new InvalidArgumentException('Enter the actual payment date, no later than today.');
                }
                if (!fw_query($conn, 'SELECT category_id FROM categories WHERE category_id=? AND user_id=? AND category_type=?', 'iis', [$category, $uid, $kind])) {
                    throw new InvalidArgumentException('Select one of your matching categories.');
                }
                $description = $kind === 'expense' ? $item['expense_description'] : 'Work payment: ' . ($session['work_source'] ?: 'Session ' . $id);
                fw_query($conn, 'INSERT INTO transactions(user_id,category_id,transaction_type,amount,description,transaction_date) VALUES (?,?,?,?,?,?)', 'iissss', [$uid, $category, $kind, fi_amount($amount), $description, $date]);
                $transactionId = (int) mysqli_insert_id($conn);
                $saved = fw_query($conn, 'SELECT * FROM transactions WHERE transaction_id=? AND user_id=?', 'ii', [$transactionId, $uid])[0];
                fw_revision($conn, $uid, 'transaction', $transactionId, 'created', null, $saved);
            }
            $t = fw_query($conn, 'SELECT * FROM transactions WHERE transaction_id=? AND user_id=? AND transaction_type=? AND transaction_date<=CURDATE() FOR UPDATE', 'iis', [$transactionId, $uid, $kind])[0] ?? null;
            if (!$t) {
                throw new InvalidArgumentException('Choose an existing transaction of the correct type belonging to your account.');
            }
            if ($kind === 'income') {
                if (fi_cents($t['amount']) > fi_cents($current['gross_earnings'])) {
                    throw new InvalidArgumentException('This payment exceeds session earnings. Combined payments must remain unlinked.');
                }
                if (fw_query($conn, 'SELECT session_id FROM fw_work_session_details WHERE income_transaction_id=?', 'i', [$transactionId])) {
                    throw new InvalidArgumentException('This income is already linked to a session.');
                }
                fw_query($conn, 'INSERT INTO fw_work_session_details(session_id,user_id,work_source,income_transaction_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE income_transaction_id=VALUES(income_transaction_id)', 'iisi', [$id, $uid, $session['work_source'] ?? '', $transactionId]);
            } else {
                if (fi_cents($t['amount']) !== fi_cents($item['amount'])) {
                    throw new InvalidArgumentException('The transaction amount must equal this cost item. Split a combined expense in Transactions first.');
                }
                if (fw_query($conn, 'SELECT expense_id FROM fw_work_cost_links WHERE transaction_id=?', 'i', [$transactionId])) {
                    throw new InvalidArgumentException('This expense is already linked to a cost item.');
                }
                fw_query($conn, 'INSERT INTO fw_work_cost_links(expense_id,user_id,session_id,transaction_id) VALUES (?,?,?,?)', 'iiii', [$expenseId, $uid, $id, $transactionId]);
            }
        }
        fw_query($conn, 'UPDATE fw_work_session_details SET record_version=record_version+1 WHERE session_id=? AND user_id=?', 'ii', [$id, $uid]);
        fw_revision($conn, $uid, 'work_session', $id, 'transaction_' . $action, null, ['kind' => $kind, 'expense_id' => $expenseId, 'transaction_id' => $transactionId ?? null]);
        mysqli_commit($conn);
        header('Location: work-links.php?session=' . $id . '&saved=1');
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to update transaction links. Please try again.';
        error_log('Work links: ' . $e->getMessage());
    }
}
$categories = fw_query($conn, 'SELECT * FROM categories WHERE user_id=? ORDER BY category_name', 'i', [$uid]);
$items = $ready ? fw_query($conn, 'SELECT e.*,l.transaction_id,t.amount AS linked_amount,t.transaction_type AS linked_type FROM work_session_expenses e LEFT JOIN fw_work_cost_links l ON l.expense_id=e.expense_id AND l.user_id=? LEFT JOIN transactions t ON t.transaction_id=l.transaction_id AND t.user_id=l.user_id WHERE e.session_id=? ORDER BY e.expense_id', 'ii', [$uid, $id]) : [];
$pageTitle = 'Work transaction links';
$pageHead = '<link rel="stylesheet" href="' . fw_h(BASE_URL) . '/css/intelligence.css?v=20261104-polish2">';
require __DIR__ . '/../includes/header.php';
function link_fields(array $s, string $kind, int $expenseId = 0): void
{?><input type="hidden" name="fw_request_token" value="<?= fw_h(fw_request_token()) ?>"><input type="hidden" name="csrf_token" value="<?= fw_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="version" value="<?= (int) $s['record_version'] ?>"><input type="hidden" name="kind" value="<?= fw_h($kind) ?>"><input type="hidden" name="expense_id" value="<?= $expenseId ?>"><?php }
?>
<div class="fi-page fi-v2 fw-general"><a href="earnings.php" class="fw-back-link"><i class="bi bi-arrow-left" aria-hidden="true"></i> Real Earnings Lens</a><div class="fi-panel"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">WORK PAYMENTS</span><h2>Connect your transactions</h2></div><span class="fi-icon-soft"><i class="bi bi-link-45deg" aria-hidden="true"></i></span></div><p><?= fw_h($session['work_source'] ?? 'Work session') ?> · <?= fw_h($session['session_date']) ?></p><p>Connect an existing payment or record a new one.</p><details class="fw-help"><summary>Avoid counting a payment twice</summary><p>Check Transactions before creating a new entry. Link gross receipts and separately paid costs. If a payout already excludes a fee, do not record that fee again as an additional cash expense. Combined payments can stay unlinked. Unlinking keeps the transaction.</p></details><?php if ($error):?><p class="alert alert-danger"><?= fw_h($error) ?></p><?php endif;
if (isset($_GET['saved'])):?><p class="alert alert-success">Transaction link updated.</p><?php endif;
if (!$ready):?><p class="alert alert-warning">Import 20261103_general_update.sql first.</p><?php else:
    $rows = array_merge([['expense_id' => 0, 'expense_description' => 'Received work income', 'amount' => $session['gross_earnings'], 'transaction_id' => $session['income_transaction_id']]], $items);
    foreach ($rows as $item):$kind = $item['expense_id'] ? 'expense' : 'income';
        $eid = (int) $item['expense_id'];
        $available = fw_query($conn, "SELECT t.* FROM transactions t WHERE t.user_id=? AND t.transaction_type=? AND t.transaction_date<=CURDATE() AND NOT EXISTS(SELECT 1 FROM fw_work_cost_links l WHERE l.transaction_id=t.transaction_id) AND NOT EXISTS(SELECT 1 FROM fw_work_session_details d WHERE d.income_transaction_id=t.transaction_id) ORDER BY t.transaction_date DESC,t.transaction_id DESC LIMIT 100", 'is', [$uid, $kind]); ?>
<section class="fw-link-card"><h2 class="h5"><?= fw_h($item['expense_description']) ?> · <?= fw_h(fw_format_money($item['amount'], $currency)) ?></h2>
<?php if ($item['transaction_id']):?><p>Linked transaction #<?= (int) $item['transaction_id'] ?>. <a href="<?= fw_h(BASE_URL) ?>/transactions/?edit=<?= (int) $item['transaction_id'] ?>">Review transaction</a></p><form method="post"><?php link_fields($session, $kind, $eid); ?><input type="hidden" name="action" value="unlink"><button class="btn btn-outline-danger" type="submit">Unlink transaction</button></form>
<?php else:?><details class="fi-disclosure"><summary>Link an existing <?= $kind ?></summary><form method="post"><?php link_fields($session, $kind, $eid); ?><input type="hidden" name="action" value="link"><label class="form-label">Existing transaction<select name="transaction_id" class="form-select" required><option value="">Choose a matching payment</option><?php foreach ($available as $t):?><option value="<?= (int) $t['transaction_id'] ?>"><?= fw_h($t['transaction_date'] . ' · ' . fw_format_money($t['amount'], $currency) . ' · ' . $t['description']) ?></option><?php endforeach; ?></select></label><button class="btn btn-outline-primary" type="submit">Link transaction</button></form></details>
<details class="fi-disclosure"><summary>Record a new <?= $kind === 'income' ? 'received payment' : 'paid expense' ?></summary><form method="post"><?php link_fields($session, $kind, $eid); ?><input type="hidden" name="action" value="create"><div class="fi-form-grid"><label>Category<select name="category_id" class="form-select" required><option value="">Select category</option><?php foreach ($categories as $c) {
    if ($c['category_type'] === $kind):?><option value="<?= (int) $c['category_id'] ?>"><?= fw_h($c['category_name']) ?></option><?php endif;
} ?></select></label><label>Payment date<input type="date" class="form-control" name="transaction_date" max="<?= date('Y-m-d') ?>" required></label><?php if ($kind === 'income'):?><label>Amount actually received<input type="number" class="form-control" name="amount" min="0.01" max="<?= fw_h($session['gross_earnings']) ?>" step="0.01" required></label><?php endif; ?></div><label class="fi-check mt-3"><input type="checkbox" name="reviewed" value="1" required><span>I checked Transactions. This payment happened and has not already been recorded.</span></label><button class="btn btn-primary" type="submit">Record and link <?= $kind ?></button></form></details><?php endif; ?></section><?php endforeach; endif; ?></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
