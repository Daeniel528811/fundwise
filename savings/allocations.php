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
$pageTitle = 'Savings Allocation';
ob_start();
?>
<style>
.fw-allocations .fw-allocation-summary{margin:24px 0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.fw-allocations .fw-allocation-summary .fi-stat{margin:0;min-height:128px}
.fw-allocations .fw-allocation-summary strong{font-variant-numeric:tabular-nums}
.fw-allocations .fw-allocation-chart{height:260px;width:min(100%,350px);position:relative;margin:0 auto}
.fw-allocations .fw-allocation-chart-copy{text-align:center;color:var(--fw-muted);font-size:11px;line-height:1.7;max-width:450px;margin:18px auto 0}
.fw-allocations .fw-allocation-list{margin-top:26px}
.fw-allocations .fw-allocation-list .fi-panel-heading{margin-bottom:12px}
.fw-allocations .fw-allocation-row{display:flex;gap:15px;align-items:center;border-bottom:1px solid var(--fw-border);padding:22px 0;min-width:0}
.fw-allocations .fw-allocation-row:last-child{border-bottom:0}
.fw-allocations .fw-allocation-icon{width:46px;height:46px;flex:none;display:flex;align-items:center;justify-content:center;border-radius:13px;background:var(--fw-inset-bg);color:var(--fw-accent);font-size:21px}
.fw-allocations .fw-allocation-copy{min-width:0;flex:1}
.fw-allocations .fw-allocation-name{font-size:14px;font-weight:800;line-height:1.5;color:var(--fw-heading);overflow-wrap:anywhere;display:block}
.fw-allocations .fw-allocation-caption{font-size:10px;color:var(--fw-muted);display:block;margin-top:3px}
.fw-allocations .fw-allocation-meter{height:5px;width:min(100%,250px);background:var(--fw-inset-bg);border-radius:20px;overflow:hidden;margin-top:10px}
.fw-allocations .fw-allocation-meter>span{display:block;height:100%;background:linear-gradient(90deg,#1787f2,#17bdcb);border-radius:20px}
.fw-allocations .fw-allocation-share{padding:8px 12px;border-radius:10px;background:var(--fw-inset-bg);color:var(--fw-accent);font-size:13px;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap;flex:none}
.fw-allocations .fw-allocation-actions{display:flex;align-items:center;gap:8px;flex:none}
.fw-allocations .fw-allocation-actions form{margin:0}
.fw-allocations .fw-allocation-actions .btn{min-height:37px;display:inline-flex;align-items:center;justify-content:center}
.fw-allocations .fw-allocation-actions .fw-allocation-delete{width:37px;padding:7px!important}
.fw-allocations .fw-allocation-available{border:1px dashed var(--fw-border);background:var(--fw-surface-alt);border-radius:13px;padding:15px 17px;margin-top:16px;display:flex;align-items:center;gap:12px;color:var(--fw-muted);font-size:12px;line-height:1.65}
.fw-allocations .fw-allocation-available>i{font-size:21px;color:var(--fw-accent);flex:none}
.fw-allocations .fw-allocation-available strong{color:var(--fw-heading)}
.fw-allocations .fw-allocation-form-note{font-size:11px;color:var(--fw-muted);line-height:1.7;margin:15px 0 0}
#editModal .form-label{display:block;font-size:12px;font-weight:700;color:var(--fw-heading)}
#editModal .form-control{background:var(--fw-input-bg);color:var(--fw-input-text);border-color:var(--fw-input-border);padding:11px 13px;border-radius:11px}
#editModal .modal-header,#editModal .modal-footer{padding:19px 24px;border-color:var(--fw-border)}
#editModal .modal-body{padding:22px 24px}
@media(max-width:767.98px){.fw-allocations .fw-allocation-summary{gap:12px;margin:20px 0}.fw-allocations .fw-allocation-summary .fi-stat{padding:18px 15px;min-height:120px}.fw-allocations .fw-allocation-row{gap:12px;padding:20px 0;flex-wrap:wrap}.fw-allocations .fw-allocation-icon{width:39px;height:39px;font-size:18px;border-radius:11px}.fw-allocations .fw-allocation-name{font-size:13px}.fw-allocations .fw-allocation-copy{flex:1;min-width:90px}.fw-allocations .fw-allocation-share{font-size:12px;padding:7px 9px}.fw-allocations .fw-allocation-actions{flex:1 0 calc(100% - 51px);justify-content:flex-end;margin-left:51px}.fw-allocations .fw-allocation-meter{max-width:100%}.fw-allocations .fw-allocation-chart{height:245px}.fw-allocations .fw-allocation-available{font-size:11px}}
</style>
<?php
$pageHead=ob_get_clean();
require_once __DIR__.'/../includes/header.php';
?>
<div class="fi-page fw-allocations">
 <section class="fi-hero" aria-labelledby="allocationTitle"><div><span class="fi-kicker"><i class="bi bi-diagram-3" aria-hidden="true"></i> PLAN &amp; GROW / SAVINGS PLAN</span><h1 id="allocationTitle">Your savings,<br><span>allocated.</span></h1><p>Decide how to divide your savings among the priorities that matter to you. Your plan can total up to 100%.</p></div><div class="fi-hero-icon"><i class="bi bi-pie-chart" aria-hidden="true"></i></div></section>
 <?php if($message):?><div class="alert alert-success mt-3" role="status"><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></div><?php endif;?>
 <?php if($error):?><div class="alert alert-danger mt-3" role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif;?>
 <section class="fw-allocation-summary" aria-label="Allocation summary"><div class="fi-stat fi-stat-featured"><span><i class="bi bi-pie-chart" aria-hidden="true"></i> Total allocated</span><strong><?= number_format($total_percentage,2) ?>%</strong><small>Across <?= count($allocations) ?> allocation<?= count($allocations)===1?'':'s' ?></small></div><div class="fi-stat"><span><i class="bi bi-plus-circle" aria-hidden="true"></i> Available to allocate</span><strong><?= number_format($remaining_percentage,2) ?>%</strong><small><?= $remaining_percentage>0?'Not yet allocated':'Your savings plan is fully allocated' ?></small></div></section>
 <div class="row g-4">
  <div class="col-lg-5"><section class="fi-panel h-100" id="allocationForm" aria-labelledby="addAllocationTitle"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">BUILD YOUR PLAN</span><h2 id="addAllocationTitle">Add an allocation</h2></div><span class="fi-icon-soft"><i class="bi bi-plus-lg" aria-hidden="true"></i></span></div>
   <form method="post"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="add">
    <div class="mb-3"><label class="form-label" for="allocationName">Allocation name</label><input type="text" name="allocation_name" id="allocationName" class="form-control" placeholder="e.g. Emergency fund" maxlength="100" required></div>
    <div class="mb-3"><label class="form-label" for="allocationPercentage">Share of your savings (%)</label><input type="number" name="allocation_percentage" id="allocationPercentage" class="form-control" placeholder="e.g. 20" min="0.01" max="100" step="0.01" required><div class="form-text"><?= number_format($remaining_percentage,2) ?>% is currently available. The combined plan cannot exceed 100%.</div></div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-2" aria-hidden="true"></i>Add allocation</button>
   </form><p class="fw-allocation-form-note">These percentages describe your plan. Adding or changing an allocation does not move money or change your savings balance.</p>
  </section></div>
  <div class="col-lg-7"><section class="fi-panel h-100" aria-labelledby="allocationChartTitle"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">YOUR SAVINGS MIX</span><h2 id="allocationChartTitle">Allocation breakdown</h2></div><span class="fi-icon-soft"><i class="bi bi-pie-chart" aria-hidden="true"></i></span></div>
   <?php if($allocations):?><div class="fw-allocation-chart"><canvas id="allocationChart" role="img" aria-label="Savings allocation percentages. Exact values are listed in Your savings allocations below."></canvas></div><p class="fw-allocation-chart-copy">Each segment is a planned share. Any unallocated percentage is included so the chart represents the full 100%.</p><?php else:?><div class="fi-empty"><i class="bi bi-pie-chart" aria-hidden="true"></i><h3>Give your savings a plan</h3><p>Add your first allocation to see how your priorities fit together.</p></div><?php endif;?>
  </section></div>
 </div>
 <section class="fi-panel fw-allocation-list" aria-labelledby="allocationListTitle"><div class="fi-panel-heading"><div><span class="fi-mini-eyebrow"><?= count($allocations) ?> ALLOCATION<?= count($allocations)===1?'':'S' ?></span><h2 id="allocationListTitle">Your savings allocations</h2></div><span class="fi-icon-soft"><i class="bi bi-diagram-3" aria-hidden="true"></i></span></div>
  <?php if(!$allocations):?><div class="fi-empty"><i class="bi bi-diagram-3" aria-hidden="true"></i><h3>No allocations yet</h3><p>Start with a priority such as an emergency fund, education, or travel.</p><a class="btn btn-outline-primary btn-sm mt-3" href="#allocationForm">Add your first allocation</a></div><?php endif;?>
  <div><?php foreach($allocations as $allocation):$share=(float)$allocation['allocation_percentage'];$name=(string)$allocation['allocation_name'];$id=(int)$allocation['allocation_id'];?>
   <article class="fw-allocation-row"><span class="fw-allocation-icon"><i class="bi bi-pie-chart" aria-hidden="true"></i></span><div class="fw-allocation-copy"><strong class="fw-allocation-name"><?= htmlspecialchars($name,ENT_QUOTES,'UTF-8') ?></strong><span class="fw-allocation-caption">Planned share of your savings</span><div class="fw-allocation-meter" aria-hidden="true"><span style="width:<?= number_format(min(100,max(0,$share)),2,'.','') ?>%"></span></div></div><span class="fw-allocation-share"><?= number_format($share,2) ?>%</span>
    <div class="fw-allocation-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal" data-allocation-id="<?= $id ?>" data-allocation-name="<?= htmlspecialchars($name,ENT_QUOTES,'UTF-8') ?>" data-allocation-percentage="<?= number_format($share,2,'.','') ?>" aria-label="Edit <?= htmlspecialchars($name,ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i> Edit</button>
     <form method="post" onsubmit="return confirm('Delete this allocation? Your saved money and transactions will not change.');"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="allocation_id" value="<?= $id ?>"><button type="submit" class="btn btn-sm btn-outline-danger fw-allocation-delete" aria-label="Delete <?= htmlspecialchars($name,ENT_QUOTES,'UTF-8') ?>" title="Delete allocation"><i class="bi bi-trash3" aria-hidden="true"></i></button></form>
    </div>
   </article><?php endforeach;?></div>
  <?php if($remaining_percentage>0):?><div class="fw-allocation-available"><i class="bi bi-plus-circle" aria-hidden="true"></i><span><strong><?= number_format($remaining_percentage,2) ?>% still available.</strong> You can assign it to another priority or leave it unallocated.</span></div><?php else:?><div class="fw-allocation-available"><i class="bi bi-check2-circle" aria-hidden="true"></i><span><strong>Your plan adds up to 100%.</strong> Edit an existing share if you want to make room for a new priority.</span></div><?php endif;?>
 </section>
</div>
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editAllocationTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>"><div class="modal-header"><div><span class="fi-mini-eyebrow">REFINE YOUR PLAN</span><h2 class="modal-title fs-5 mt-1" id="editAllocationTitle">Edit allocation</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close edit allocation"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="allocation_id" id="edit_id"><div class="mb-3"><label class="form-label" for="edit_name">Allocation name</label><input type="text" name="allocation_name" id="edit_name" class="form-control" maxlength="100" required></div><div><label class="form-label" for="edit_percentage">Share of your savings (%)</label><input type="number" name="allocation_percentage" id="edit_percentage" class="form-control" min="0.01" max="100" step="0.01" required><p class="form-text mb-0">The combined percentage of all allocations must stay at or below 100%.</p></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save changes</button></div></form></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
<script>
(() => {
 const labels=<?= json_encode(array_column($allocations,'allocation_name'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
 const values=<?= json_encode(array_map('floatval',array_column($allocations,'allocation_percentage'))) ?>;
 const remaining=<?= json_encode($remaining_percentage) ?>;
 if(remaining>0){labels.push('Unallocated');values.push(remaining);}
 const canvas=document.getElementById('allocationChart');
 if(canvas&&values.length)new Chart(canvas,{type:'doughnut',data:{labels,datasets:[{data:values,borderWidth:3}]},options:{responsive:true,maintainAspectRatio:false,cutout:'71%',plugins:{legend:{position:'bottom',labels:{padding:16,usePointStyle:true,boxWidth:8}},tooltip:{callbacks:{label:context=>context.label+': '+Number(context.parsed).toFixed(2)+'%'}}}}});
 document.getElementById('editModal').addEventListener('show.bs.modal',event=>{
  const button=event.relatedTarget;if(!button)return;
  document.getElementById('edit_id').value=button.dataset.allocationId;
  document.getElementById('edit_name').value=button.dataset.allocationName;
  document.getElementById('edit_percentage').value=button.dataset.allocationPercentage;
 });
})();
</script>
<?php require_once __DIR__.'/../includes/footer.php';?>
