<?php
// Pure calculation and validation helpers shared by the Financial Intelligence pages.
function fi_v2_ready(mysqli $conn): bool
{
    foreach (['fw_lab_details', 'fw_lab_days', 'fw_purchase_scenarios', 'fw_work_session_details', 'fw_work_expense_kinds'] as $table) {
        if (!fw_has_table($conn, $table)) {
            return false;
        }
    }
    return true;
}
function fi_v2_notice(): void
{
    echo '<div class="alert alert-warning mt-3" role="alert"><strong>One-time update needed.</strong> Import <code>20261102_financial_intelligence_v2.sql</code> and <code>20261103_general_update.sql</code> into your existing database, then refresh. Existing financial records are kept.</div>';
}
function fi_cents($value): int
{
    return (int) round((float) $value * 100);
}
function fi_amount(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}
function fi_require_money($raw, string $label, bool $zero = true): int
{
    $value = fw_money($raw, $zero);
    if ($value === null) {
        throw new InvalidArgumentException($label . ' must be a valid ' . ($zero ? 'non-negative' : 'positive') . ' amount with up to two decimals.');
    }
    return fi_cents($value);
}
function fi_require_int($raw, int $min, int $max, string $label): int
{
    if ((!is_string($raw) && !is_int($raw)) || filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < $min || (int) $raw > $max) {
        throw new InvalidArgumentException($label . ' must be a whole number from ' . $min . ' to ' . $max . '.');
    }
    return (int) $raw;
}
function fi_checked($value): bool
{
    return is_string($value) && $value === '1';
}
function fi_lab_result(array $e, string $today): array
{
    $elapsed = $today < $e['start_date'] ? 0 : min((int) $e['duration_days'], (int) (new DateTimeImmutable($e['start_date']))->diff(new DateTimeImmutable($today))->days + 1);
    $target = (int) round(fi_cents($e['baseline_amount']) * (100 - (int) $e['reduction_percent']) / 100);
    $actual = fi_cents($e['actual_spending']);
    $complete = $e['end_date'] < $today;
    $covered = (int) ($e['confirmed_days'] ?? 0);
    $verified = !empty($e['baseline_confirmed']) && $covered >= $elapsed && $elapsed > 0;
    if ($actual > $target) {
        $status = 'Recorded target exceeded';
        $kind = 'danger';
    } elseif (!$verified) {
        $status = 'Incomplete records';
        $kind = 'warning';
    } elseif ($complete) {
        $status = 'Target reached · self-confirmed';
        $kind = 'success';
    } else {
        $status = 'Within recorded limit';
        $kind = 'success';
    }
    return ['elapsed' => $elapsed, 'target' => $target, 'actual' => $actual, 'complete' => $complete, 'verified' => $verified,
        'success' => $complete && $verified && $actual <= $target, 'status' => $status, 'kind' => $kind, 'difference' => fi_cents($e['baseline_amount']) - $actual];
}
function fi_purchase_inputs(array $post, string $today): array
{
    $title = fw_text($post['purchase_title'] ?? null);
    if ($title === '' || fw_text_length($title) > 80) {
        throw new InvalidArgumentException('Enter a purchase name of up to 80 characters.');
    }
    $date = fw_text($post['purchase_date'] ?? null);
    $horizon = fw_text($post['review_date'] ?? null);
    $maxDate = (new DateTimeImmutable($today))->modify('+366 days')->format('Y-m-d');
    if (!fw_valid_date($date) || $date < $today || $date > $maxDate || !fw_valid_date($horizon) || $horizon < $date || $horizon > $maxDate) {
        throw new InvalidArgumentException('Choose a purchase date from today through the next year, and a review date on or after it.');
    }
    $source = fw_text($post['purchase_source'] ?? null);
    if (!in_array($source, ['recorded', 'manual', 'goal'], true)) {
        throw new InvalidArgumentException('Choose a valid funding source.');
    }
    $names = $post['obligation_name'] ?? [];
    $amounts = $post['obligation_amount'] ?? [];
    $dates = $post['obligation_date'] ?? [];
    if (!is_array($names) || !is_array($amounts) || !is_array($dates) || count($names) !== count($amounts) || count($names) !== count($dates) || count($names) > 20) {
        throw new InvalidArgumentException('Use up to 20 complete commitment items.');
    }
    $names = array_values($names);
    $amounts = array_values($amounts);
    $dates = array_values($dates);
    $items = [];
    foreach ($names as $i => $raw) {
        $name = fw_text($raw);
        $amount = fw_text($amounts[$i]);
        $due = fw_text($dates[$i]);
        if ($name === '' && $amount === '' && $due === '') {
            continue;
        }
        if ($name === '' || fw_text_length($name) > 100 || !fw_valid_date($due) || $due > $horizon) {
            throw new InvalidArgumentException('Each commitment needs a name, positive amount, and due date on or before the review date. Include overdue unpaid obligations.');
        }
        $items[] = ['name' => $name, 'amount' => fi_require_money($amount, 'Commitment', false), 'date' => $due];
    }
    $amount = fi_require_money($post['purchase_amount'] ?? null, 'Purchase price', false);
    $cheaper = fw_text($post['cheaper_amount'] ?? null);
    $cheaper = $cheaper === '' ? null : fi_require_money($cheaper, 'Alternative price', false);
    if ($cheaper !== null && $cheaper > $amount) {
        throw new InvalidArgumentException('The cheaper alternative must cost no more than the planned purchase.');
    }
    $wait = fw_text($post['wait_date'] ?? null);
    if ($wait !== '' && (!fw_valid_date($wait) || $wait < $date || $wait > $maxDate)) {
        throw new InvalidArgumentException('The wait date must be on or after the purchase date, within the next year.');
    }
    return ['title' => $title, 'date' => $date, 'horizon' => $horizon, 'source' => $source, 'goal_id' => fw_positive_id($post['goal_id'] ?? null),
        'amount' => $amount, 'budget_id' => fw_positive_id($post['budget_id'] ?? null), 'manual' => $source === 'manual' ? fi_require_money($post['manual_balance'] ?? null, 'Confirmed cash balance') : 0,
        'reserve' => fi_require_money($post['reserve_amount'] ?? '0', 'Other protected funds'), 'obligations' => $items,
        'balance_checked' => fi_checked($post['balance_checked'] ?? null), 'commitments_checked' => fi_checked($post['commitments_checked'] ?? null),
        'monthly' => $source === 'goal' ? fi_require_money($post['monthly_save'] ?? '0', 'Monthly contribution') : 0,
        'cheaper' => $cheaper, 'wait' => $wait];
}
function fi_purchase_result(array $input, int $balance, ?array $goal = null, ?float $hourly = null, ?array $budget = null): array
{
    $committed = array_sum(array_column($input['obligations'], 'amount'));
    $available = $balance - $committed - $input['reserve'];
    $after = $available - $input['amount'];
    $assessed = $input['balance_checked'] && $input['commitments_checked'];
    $budgetAfter = $budget !== null ? $budget['limit'] - $budget['spent'] - $input['amount'] : null;
    $status = $after < 0 ? 'Shortfall in entered plan' : ($budgetAfter !== null && $budgetAfter < 0 ? 'Exceeds selected budget' : ($assessed ? 'Within entered plan' : 'More information needed'));
    $result = ['balance' => $balance, 'committed' => $committed, 'reserve' => $input['reserve'], 'available' => $available, 'after' => $after,
        'status' => $status, 'assessed' => $assessed, 'kind' => $after < 0 || ($budgetAfter !== null && $budgetAfter < 0) ? 'danger' : ($assessed ? 'success' : 'warning'), 'budget_after' => $budgetAfter, 'cheaper_after' => $input['cheaper'] === null ? null : $available - $input['cheaper'],
        'goal_before_months' => null, 'goal_after_months' => null, 'goal_replacement' => null, 'work_hours' => $hourly !== null && $hourly > 0 ? round(($input['amount'] / 100) / $hourly, 2) : null];
    if ($goal !== null && $available >= $input['amount']) {
        $result['goal_replacement'] = $input['amount'];
        if ($input['monthly'] > 0) {
            $left = max(0, fi_cents($goal['target']) - $balance);
            $result['goal_before_months'] = (int) ceil($left / $input['monthly']);
            $result['goal_after_months'] = (int) ceil(max(0, fi_cents($goal['target']) - ($balance - $input['amount'])) / $input['monthly']);
        }
    }
    return $result;
}
function fi_expense_kinds(): array
{
    return ['transport' => 'Transport & travel', 'supplies' => 'Supplies & equipment', 'workspace' => 'Workspace & digital', 'fees' => 'Fees & business services', 'other' => 'Other work costs'];
}
function fi_saved_expense_kinds(): array
{
    return ['food' => 'Work-related food', 'deductions' => 'Entered deductions'];
}
function fi_expense_kind_label(string $kind): string
{
    return (fi_expense_kinds() + fi_saved_expense_kinds())[$kind] ?? 'Other work costs';
}
function fi_expense_options(string $selected): array
{
    $options = fi_expense_kinds();
    $saved = fi_saved_expense_kinds();
    if (isset($saved[$selected])) {
        $options[$selected] = $saved[$selected] . ' (saved category)';
    }
    return $options;
}
function fi_validate_expense_kinds(array $kinds, array $previousItems = []): array
{
    $current = fi_expense_kinds();
    $saved = fi_saved_expense_kinds();
    $available = [];
    foreach ($previousItems as $item) {
        $kind = $item['cost_kind'] ?? '';
        if (is_string($kind) && isset($saved[$kind])) {
            $available[$kind] = ($available[$kind] ?? 0) + 1;
        }
    }
    foreach ($kinds as $kind) {
        if (!is_string($kind)) {
            throw new InvalidArgumentException('Choose a valid work-cost category.');
        }
        if (isset($current[$kind])) {
            continue;
        }
        if (isset($saved[$kind]) && ($available[$kind] ?? 0) > 0) {
            $available[$kind]--;
            continue;
        }
        throw new InvalidArgumentException('Choose one of the five work-cost categories. Older saved categories can only be retained on existing entries.');
    }
    return $kinds;
}
function fi_payment(array $details, ?array $transaction, float $gross): array
{
    if (!empty($details['income_transaction_id'])) {
        if (!$transaction || $transaction['transaction_type'] !== 'income') {
            return ['status' => 'Link needs review', 'received' => null, 'date' => null, 'linked' => false];
        }
        $amount = (float) $transaction['amount'];
        return ['status' => $amount > $gross ? 'Linked amount exceeds gross' : ($amount < $gross ? 'Partly received · linked' : 'Received · linked'), 'received' => $amount, 'date' => $transaction['transaction_date'], 'linked' => true];
    }
    $status = $details['payment_status'] ?? 'unknown';
    return ['status' => ['unknown' => 'Payment not specified', 'unpaid' => 'Not yet received', 'partial' => 'Partly received', 'paid' => 'Received'][$status] ?? 'Payment not specified',
        'received' => $status === 'unknown' ? null : (float) ($details['received_amount'] ?? 0), 'date' => $details['received_date'] ?? null, 'linked' => false];
}
