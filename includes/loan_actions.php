<?php
function fw_loan_action(mysqli $conn, int $uid, array $debt, string $action, int $remaining): bool
{
    if (!in_array($action, ['loan_plan', 'loan_clear', 'loan_edit', 'loan_preferences'], true)) {
        return false;
    }
    if (!fw_loan_ready($conn)) {
        throw new InvalidArgumentException('Import 20261104_update2.sql first.');
    }
    $id = (int) $debt['debt_id'];
    $rows = fw_loan_rows($conn, $uid, $id);
    if ((int) $debt['archived']) {
        throw new InvalidArgumentException('Restore this debt before changing its schedule.');
    }
    if ($action === 'loan_plan') {
        if ($rows) {
            throw new InvalidArgumentException('A payment schedule already exists. Edit its dates and charges, or clear an unpaid schedule first.');
        }
        if (!fi_checked($_POST['plan_reviewed'] ?? null)) {
            throw new InvalidArgumentException('Review the schedule terms before saving.');
        }
        $count = fi_require_int($_POST['installment_count'] ?? null, 1, 60, 'Installments');
        $first = fw_text($_POST['first_due'] ?? null);
        if (!fw_valid_date($first) || $first < $debt['opened_on'] || $first > (new DateTimeImmutable('today'))->modify('+5 years')->format('Y-m-d')) {
            throw new InvalidArgumentException('Choose a first due date between the opening date and five years from now.');
        }
        $interest = fi_require_money($_POST['installment_interest'] ?? '0', 'Interest per installment');
        $fees = fi_require_money($_POST['installment_fees'] ?? '0', 'Fees per installment');
        $principal = fw_loan_split($remaining, $count);
        foreach ($principal as $i => $part) {
            if ($part + $interest + $fees > 999999999999) {
                throw new InvalidArgumentException('The planned installment total is too large.');
            }
            fw_query($conn, 'INSERT INTO fw_loan_installments(user_id,debt_id,sequence_no,due_on,principal,interest,fees) VALUES (?,?,?,?,?,?,?)', 'iiissss', [$uid, $id, $i + 1, fw_loan_month($first, $i), fi_amount($part), fi_amount($interest), fi_amount($fees)]);
        }
        fw_revision($conn, $uid, 'debt', $id, 'installments_created', null, fw_loan_rows($conn, $uid, $id));
    } elseif ($action === 'loan_clear') {
        if (fw_query($conn, 'SELECT payment_id FROM fw_loan_allocations WHERE user_id=? AND debt_id=? LIMIT 1', 'ii', [$uid, $id])) {
            throw new InvalidArgumentException('A schedule with recorded payments cannot be cleared. Keep its history and edit unpaid dates or charges.');
        }
        fw_revision($conn, $uid, 'debt', $id, 'installments_cleared', $rows, null);
        fw_query($conn, 'DELETE FROM fw_loan_installments WHERE user_id=? AND debt_id=?', 'ii', [$uid, $id]);
        fw_query($conn, 'UPDATE fw_debts SET next_due=NULL,scheduled_amount=0 WHERE user_id=? AND debt_id=?', 'ii', [$uid, $id]);
    } elseif ($action === 'loan_edit') {
        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['installment_id'] === fw_positive_id($_POST['installment_id'] ?? null)) {
                $row = $r;
            }
        }
        if (!$row) {
            throw new InvalidArgumentException('Installment unavailable.');
        }
        $due = fw_text($_POST['due_on'] ?? null);
        $interest = fi_require_money($_POST['planned_interest'] ?? null, 'Planned interest');
        $fees = fi_require_money($_POST['planned_fees'] ?? null, 'Planned fees');
        if (!fw_valid_date($due) || $due < $debt['opened_on'] || $due > (new DateTimeImmutable('today'))->modify('+10 years')->format('Y-m-d') || $interest < fi_cents($row['interest_paid']) || $fees < fi_cents($row['fees_paid'])) {
            throw new InvalidArgumentException('Use a valid due date and charges no lower than amounts already paid.');
        }
        fw_query($conn, 'UPDATE fw_loan_installments SET due_on=?,interest=?,fees=? WHERE installment_id=? AND user_id=? AND debt_id=?', 'sssiii', [$due, fi_amount($interest), fi_amount($fees), (int) $row['installment_id'], $uid, $id]);
        fw_revision($conn, $uid, 'loan_installment', (int) $row['installment_id'], 'updated', $row, ['due_on' => $due, 'interest' => fi_amount($interest), 'fees' => fi_amount($fees)]);
    } else {
        $lead = fi_require_int($_POST['loan_lead_days'] ?? null, 0, 365, 'Reminder days');
        $repeat = fw_text($_POST['loan_repeat'] ?? null);
        if (!in_array($repeat, ['none', 'daily', 'weekly'], true)) {
            throw new InvalidArgumentException('Choose a reminder frequency.');
        }
        fw_query($conn, 'INSERT INTO fw_loan_notice_settings(debt_id,user_id,enabled,lead_days,show_popup,overdue_repeat) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),lead_days=VALUES(lead_days),show_popup=VALUES(show_popup),overdue_repeat=VALUES(overdue_repeat)', 'iiiiis', [$id, $uid, (int) fi_checked($_POST['loan_remind'] ?? null), $lead, (int) fi_checked($_POST['loan_popup'] ?? null), $repeat]);
        fw_revision($conn, $uid, 'debt', $id, 'reminder_preferences', null, ['lead_days' => $lead, 'repeat' => $repeat]);
    }
    fw_loan_sync($conn, $uid, $id);
    return true;
}
