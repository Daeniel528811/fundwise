<?php
require_once __DIR__ . '/../insights/common.php';
$uid = $fi_user_id;
$ready = fw_has_table($conn, 'fw_personal_reminders');
$error = '';
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
function reminder_integer($raw, int $min, int $max, string $label): int
{
    return fi_require_int($raw, $min, $max, $label);
}
function reminder_date($raw): string
{
    $value = fw_text($raw);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format('Y-m-d\TH:i') !== $value || $date->getTimestamp() < time() - 60 || $date > (new DateTimeImmutable())->modify('+5 years')) {
        throw new InvalidArgumentException('Choose a reminder time from now through the next five years, in Philippine time.');
    }
    return $date->format('Y-m-d H:i:s');
}
if ($ready && $posted) {
    try {
        if (!fi_post_csrf()) {
            throw new InvalidArgumentException('Refresh this page and try again.');
        }
        $action = fw_text($_POST['action'] ?? null);
        mysqli_begin_transaction($conn);
        fw_query($conn, 'SELECT user_id FROM users WHERE user_id=? FOR UPDATE', 'i', [$uid]);
        if ($action === 'preferences') {
            $lead = reminder_integer($_POST['debt_lead_days'] ?? null, 0, 365, 'Reminder days');
            $repeat = fw_text($_POST['overdue_repeat'] ?? null);
            if (!in_array($repeat, ['none', 'daily', 'weekly'], true)) {
                throw new InvalidArgumentException('Choose an overdue reminder frequency.');
            }
            fw_query($conn, 'INSERT INTO fw_notice_preferences(user_id,debt_lead_days,budget_popup,debt_popup,overdue_repeat) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE debt_lead_days=VALUES(debt_lead_days),budget_popup=VALUES(budget_popup),debt_popup=VALUES(debt_popup),overdue_repeat=VALUES(overdue_repeat)', 'iiiis', [$uid, $lead, (int) fi_checked($_POST['budget_popup'] ?? null), (int) fi_checked($_POST['debt_popup'] ?? null), $repeat]);
            fw_audit($conn, $uid, 'reminders.preferences_saved');
        } else {
            $id = fw_positive_id($_POST['reminder_id'] ?? null);
            $before = $id ? fw_query($conn, 'SELECT * FROM fw_personal_reminders WHERE reminder_id=? AND user_id=? FOR UPDATE', 'ii', [$id, $uid])[0] ?? null : null;
            if ($id && (!$before || (int) $before['revision'] !== reminder_integer($_POST['revision'] ?? null, 1, 2147483646, 'Reminder version'))) {
                throw new InvalidArgumentException('This reminder changed or is unavailable. Reload before saving.');
            }
            if ($action === 'save') {
                $title = fw_text($_POST['title'] ?? null);
                $note = fw_text($_POST['note'] ?? null);
                $at = reminder_date($_POST['remind_at'] ?? null);
                $popup = (int) fi_checked($_POST['show_popup'] ?? null);
                if ($title === '' || fw_text_length($title) > 120 || fw_text_length($note) > 255) {
                    throw new InvalidArgumentException('Enter a title up to 120 characters and a note up to 255 characters.');
                }
                if ($id) {
                    fw_query($conn, "UPDATE fw_personal_reminders SET title=?,note=?,remind_at=?,show_popup=?,status='active',delivered_at=NULL,notification_id=NULL,revision=revision+1 WHERE reminder_id=? AND user_id=?", 'sssiii', [$title, $note, $at, $popup, $id, $uid]);
                } else {
                    fw_query($conn, 'INSERT INTO fw_personal_reminders(user_id,title,note,remind_at,show_popup) VALUES (?,?,?,?,?)', 'isssi', [$uid, $title, $note, $at, $popup]);
                    $id = (int) mysqli_insert_id($conn);
                }
            } elseif ($before && $action === 'snooze') {
                $at = reminder_date($_POST['snooze_until'] ?? null);
                fw_query($conn, "UPDATE fw_personal_reminders SET remind_at=?,delivered_at=NULL,notification_id=NULL,status='active',revision=revision+1 WHERE reminder_id=? AND user_id=?", 'sii', [$at, $id, $uid]);
            } elseif ($before && $action === 'done') {
                fw_query($conn, "UPDATE fw_personal_reminders SET status='done',revision=revision+1 WHERE reminder_id=? AND user_id=?", 'ii', [$id, $uid]);
            } elseif ($before && $action === 'delete') {
                fw_query($conn, 'DELETE FROM fw_personal_reminders WHERE reminder_id=? AND user_id=?', 'ii', [$id, $uid]);
            } else {
                throw new InvalidArgumentException('Choose a valid reminder action.');
            }
            if (!empty($before['notification_id'])) {
                fw_query($conn, 'UPDATE notifications SET is_read=1 WHERE notification_id=? AND user_id=?', 'ii', [(int) $before['notification_id'], $uid]);
            }
            fw_revision($conn, $uid, 'personal_reminder', $id, $action, $before, $action === 'delete' ? null : (fw_query($conn, 'SELECT * FROM fw_personal_reminders WHERE reminder_id=? AND user_id=?', 'ii', [$id, $uid])[0] ?? null));
        }
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Reminder commit failed.');
        }
        header('Location: reminders.php?saved=1');
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save reminders. Please try again.';
        error_log('Reminders: ' . $e->getMessage());
    }
}
if ($ready) {
    fw_personal_reminder_notifications($conn, $uid);
}
$preferences = fw_notice_preferences($conn, $uid);
$reminders = $ready ? fw_query($conn, 'SELECT * FROM fw_personal_reminders WHERE user_id=? ORDER BY status,remind_at DESC LIMIT 100', 'i', [$uid]) : [];
$editId = fw_positive_id($_GET['edit'] ?? null);
$editing = $editId && $ready ? (fw_query($conn, 'SELECT * FROM fw_personal_reminders WHERE reminder_id=? AND user_id=?', 'ii', [$editId, $uid])[0] ?? null) : null;
$form = $posted && ($_POST['action'] ?? '') === 'save' ? $_POST : ($editing ?? []);
$pageTitle = 'Reminders';
$pageHead = '<link rel="stylesheet" href="' . fw_h(BASE_URL) . '/css/intelligence.css?v=20261104-polish2">';
require __DIR__ . '/../includes/header.php';
function reminder_fields(string $action, ?array $r = null): void
{?><input type="hidden" name="fw_request_token" value="<?= fw_h(fw_request_token()) ?>"><input type="hidden" name="csrf_token" value="<?= fw_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="<?= fw_h($action) ?>"><?php if ($r):?><input type="hidden" name="reminder_id" value="<?= (int) $r['reminder_id'] ?>"><input type="hidden" name="revision" value="<?= (int) $r['revision'] ?>"><?php endif;
}
?>
<div class="fi-page fi-v2 fw-general"><a class="fw-back-link" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Notifications</a><div class="fi-hero"><div><span class="fi-kicker">REMINDER SETTINGS</span><h1>Your reminders,<br><span>on your schedule.</span></h1><p>Choose when to be reminded and which updates need your attention.</p></div><div class="fi-hero-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></div></div>
<?php if ($error):?><p class="alert alert-danger mt-3" role="alert"><?= fw_h($error) ?></p><?php endif;
if (isset($_GET['saved'])):?><p class="alert alert-success mt-3" role="status">Reminder settings saved.</p><?php endif;
if (!$ready):?><p class="alert alert-warning mt-3">Import 20261104_interface_reminders.sql to enable reminders.</p><?php else:?>
<p class="fw-inline-note my-3"><i class="bi bi-info-circle" aria-hidden="true"></i> Reminders appear when you open Fundwise. All times use Philippine time.</p><div class="row g-4"><div class="col-lg-5"><section class="fi-panel"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">ALERT PREFERENCES</span><h2>Choose what pops up</h2></div><span class="fi-icon-soft"><i class="bi bi-sliders" aria-hidden="true"></i></span></div><form method="post"><?php reminder_fields('preferences'); ?><label class="form-label w-100">Remind me before a debt is due<input type="number" class="form-control" name="debt_lead_days" min="0" max="365" value="<?= (int) $preferences['debt_lead_days'] ?>" required><small class="form-text">Days before the due date. Use 0 for the due date itself.</small></label><label class="form-label w-100">Repeat overdue reminders<select class="form-select" name="overdue_repeat"><?php foreach (['none' => 'Once only', 'daily' => 'Every day', 'weekly' => 'Every week'] as $key => $label):?><option value="<?= $key ?>" <?= $preferences['overdue_repeat'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><label class="fi-check"><input type="checkbox" name="budget_popup" value="1" <?= $preferences['budget_popup'] ? 'checked' : '' ?>><span>Show a popup when a budget is exceeded</span></label><label class="fi-check"><input type="checkbox" name="debt_popup" value="1" <?= $preferences['debt_popup'] ? 'checked' : '' ?>><span>Show debt reminders as popups</span></label><button class="btn btn-primary" type="submit">Save preferences</button></form></section></div><div class="col-lg-7"><section class="fi-panel" id="reminderForm"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">PERSONAL REMINDER</span><h2><?= $editing ? 'Edit reminder' : 'Add a reminder' ?></h2></div><span class="fi-icon-soft"><i class="bi bi-plus-lg" aria-hidden="true"></i></span></div><form method="post"><?php reminder_fields('save', $editing); ?><div class="fi-form-grid"><label class="fi-full">Title<input class="form-control" name="title" maxlength="120" value="<?= fw_h($form['title'] ?? '') ?>" placeholder="Review this month’s spending" required></label><label class="fi-full">Date and time<input type="datetime-local" class="form-control" name="remind_at" value="<?= fw_h(isset($form['remind_at']) ? date('Y-m-d\TH:i', strtotime($form['remind_at'])) : '') ?>" required></label><label class="fi-full">Note (optional)<textarea class="form-control" name="note" maxlength="255" rows="2"><?= fw_h($form['note'] ?? '') ?></textarea></label></div><label class="fi-check"><input type="checkbox" name="show_popup" value="1" <?= !empty($form['show_popup']) ? 'checked' : '' ?>><span>Show this reminder as a popup</span></label><div class="d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Save reminder' ?></button><?php if ($editing):?><a class="btn btn-outline-primary" href="reminders.php">Cancel</a><?php endif; ?></div></form></section></div></div>
<section class="fi-panel mt-4"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">YOUR SCHEDULE</span><h2>Personal reminders</h2></div><span class="fi-tag"><?= count($reminders) ?> shown</span></div><?php if (!$reminders):?><div class="fi-empty"><i class="bi bi-calendar2-plus" aria-hidden="true"></i><h3>No reminders yet</h3><p>Add a date and time above to get started.</p></div><?php endif;
foreach ($reminders as $r):?><article class="fw-reminder-entry"><div class="d-flex justify-content-between align-items-start flex-wrap gap-2"><div><h3><?= fw_h($r['title']) ?></h3><p><?= fw_h(date('M j, Y · g:i A', strtotime($r['remind_at']))) ?> · <?= $r['status'] === 'done' ? 'Done' : ($r['delivered_at'] ? 'Delivered' : 'Scheduled') ?><?= $r['show_popup'] ? ' · Popup enabled' : '' ?></p><?php if ($r['note'] !== ''):?><p><?= fw_h($r['note']) ?></p><?php endif; ?></div><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-primary btn-sm" href="?edit=<?= (int) $r['reminder_id'] ?>#reminderForm">Edit</a><?php if ($r['status'] !== 'done'):?><form method="post"><?php reminder_fields('done', $r); ?><button class="btn btn-outline-primary btn-sm">Mark done</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Delete this reminder?');"><?php reminder_fields('delete', $r); ?><button class="btn btn-outline-danger btn-sm">Delete</button></form></div></div><?php if ($r['status'] !== 'done'):?><details class="fi-disclosure"><summary>Snooze until another time</summary><form method="post" class="d-flex gap-2 flex-wrap align-items-end"><?php reminder_fields('snooze', $r); ?><label class="form-label mb-0">New reminder time<input type="datetime-local" class="form-control" name="snooze_until" required></label><button class="btn btn-outline-primary">Snooze</button></form></details><?php endif; ?></article><?php endforeach; ?><p class="form-text mt-3 mb-0">Showing up to 100 recent reminders. Changes to reminder timing do not change debt due dates.</p></section><?php endif; ?></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
