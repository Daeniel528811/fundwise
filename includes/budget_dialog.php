<?php
$budget=$_SESSION['fw_budget_dialog']??[];$messages=array_slice(array_values(array_unique(array_merge($budget,$_SESSION['fw_notice_dialog']??[]))),0,5);
unset($_SESSION['fw_budget_dialog'],$_SESSION['fw_notice_dialog']);
if($messages):?>
<script type="application/json" id="fwBudgetDialogPayload"><?= json_encode(['messages'=>$messages,'kind'=>$budget?'budget':'reminder'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script>(()=>{const data=JSON.parse(document.getElementById('fwBudgetDialogPayload').textContent);if(window.fwShowNotices)window.fwShowNotices(data.messages,data.kind);})();</script>
<?php endif; ?>
