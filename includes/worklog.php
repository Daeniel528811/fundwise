<?php
if(!isset($fi_user_id)){http_response_code(404);exit;}
$currency=fi_currency($conn,$fi_user_id);$today=date('Y-m-d');
$ready=fi_table_exists($conn,'work_sessions')&&fi_table_exists($conn,'work_session_expenses')&&fi_v2_ready($conn);
$error='';$flash=fi_take_flash('earnings');$month=fi_month($_GET['month']??date('Y-m'));
$monthStart=$month.'-01';$monthEnd=(new DateTimeImmutable($monthStart))->format('Y-m-t');
$costKinds=fi_expense_kinds();$editing=null;$editId=fw_positive_id($_GET['edit']??null);
function fi_work_snapshot(mysqli $conn,int $uid,int $id): ?array {
    $row=fw_query($conn,'SELECT s.*,d.work_source,d.extra_minutes,d.payment_status,d.received_amount,d.received_date,d.income_transaction_id,COALESCE(d.record_version,0) AS record_version FROM work_sessions s LEFT JOIN fw_work_session_details d ON d.session_id=s.session_id AND d.user_id=s.user_id WHERE s.session_id=? AND s.user_id=?','ii',[$id,$uid])[0]??null;
    if(!$row)return null;
    $row['items']=fw_query($conn,'SELECT e.*,COALESCE(k.cost_kind,\'other\') AS cost_kind FROM work_session_expenses e JOIN work_sessions s ON s.session_id=e.session_id LEFT JOIN fw_work_expense_kinds k ON k.expense_id=e.expense_id AND k.user_id=s.user_id WHERE s.user_id=? AND e.session_id=? ORDER BY e.expense_id','ii',[$uid,$id]);
    return $row;
}
if($ready&&$_SERVER['REQUEST_METHOD']==='POST') {
 try {
    if(!fi_post_csrf())throw new InvalidArgumentException('Refresh this page and submit again.');
    $action=fw_text($_POST['action']??null);$id=fw_positive_id($_POST['session_id']??null);
    if(!in_array($action,['save','delete'],true))throw new InvalidArgumentException('Choose a valid work-log action.');
    mysqli_begin_transaction($conn);
    fw_query($conn,'SELECT user_id FROM users WHERE user_id=? FOR UPDATE','i',[$fi_user_id]);
    $before=$id>0?fi_work_snapshot($conn,$fi_user_id,$id):null;
    if(($id>0&&!$before)||($action==='delete'&&$id===0))throw new InvalidArgumentException('This work session is unavailable.');
    if($before && fi_require_int($_POST['record_version']??null,0,2147483646,'Record version')!==(int)$before['record_version'])throw new InvalidArgumentException('This session changed in another tab. Reload it before editing or deleting.');
    if($action==='delete') {
        fw_revision($conn,$fi_user_id,'work_session',$id,'deleted',$before,null);
        fw_query($conn,'DELETE k FROM fw_work_expense_kinds k JOIN work_session_expenses e ON e.expense_id=k.expense_id WHERE e.session_id=? AND k.user_id=?','ii',[$id,$fi_user_id]);
        fw_query($conn,'DELETE FROM work_session_expenses WHERE session_id=?','i',[$id]);
        fw_query($conn,'DELETE FROM fw_work_session_details WHERE session_id=? AND user_id=?','ii',[$id,$fi_user_id]);
        fw_query($conn,'DELETE FROM work_sessions WHERE session_id=? AND user_id=?','ii',[$id,$fi_user_id]);
        fw_audit($conn,$fi_user_id,'work_session.deleted',$id);
        if(!mysqli_commit($conn))throw new RuntimeException('Commit failed.');
        fi_flash('earnings','Session removed. Its revision history is kept; linked Transactions are unchanged.');header('Location: earnings.php?month='.urlencode($month));exit;
    }
    $workDate=fw_text($_POST['session_date']??null);$hours=fw_money($_POST['hours_worked']??null);$gross=fw_money($_POST['gross_earnings']??null,true);$notes=fw_text($_POST['notes']??null);$source=fw_text($_POST['work_source']??null);
    $extra=fi_require_int($_POST['extra_minutes']??'0',0,1439,'Additional work minutes');
    if(!fw_valid_date($workDate)||$workDate>$today||$hours===null||$hours>24||$gross===null||fw_text_length($notes)>255||$source===''||fw_text_length($source)>100)throw new InvalidArgumentException('Enter a past or current date, work source (up to 100 characters), 0.01–24 working hours, valid gross earnings, and notes up to 255 characters.');
    $costs=fw_expense_items($_POST['expense_description']??[],$_POST['expense_amount']??[]);
    $rawKinds=$_POST['expense_kind']??[];$rawDescriptions=$_POST['expense_description']??[];$kinds=[];
    if(!is_array($rawKinds)||count($rawKinds)!==count($rawDescriptions))throw new InvalidArgumentException('Choose a category for every work expense.');
    $rawKinds=array_values($rawKinds);$rawAmounts=array_values($_POST['expense_amount']??[]);
    foreach(array_values($rawDescriptions) as $i=>$description) {
        if(fw_text($description)===''&&fw_text($rawAmounts[$i])==='')continue;
        $kind=fw_text($rawKinds[$i]??null);if(!isset($costKinds[$kind]))throw new InvalidArgumentException('Choose a valid work-expense category.');$kinds[]=$kind;
    }
    $existing=fw_query($conn,'SELECT COALESCE(SUM(s.hours_worked),0) AS hours,COALESCE(SUM(d.extra_minutes),0) AS extra FROM work_sessions s LEFT JOIN fw_work_session_details d ON d.session_id=s.session_id AND d.user_id=s.user_id WHERE s.user_id=? AND s.session_date=? AND s.session_id<>?','isi',[$fi_user_id,$workDate,$id])[0];
    if((fi_cents($existing['hours'])+fi_cents($hours))*60+((int)$existing['extra']+$extra)*100>144000)throw new InvalidArgumentException('Combined working and additional time cannot exceed 24 hours on one date.');
    $linkId=fw_positive_id($_POST['income_transaction_id']??null);$payment=fw_text($_POST['payment_status']??'unknown');$received=0;$receivedDate=null;
    if($linkId>0) {
        $linked=fw_query($conn,"SELECT transaction_id,amount,transaction_date FROM transactions WHERE transaction_id=? AND user_id=? AND transaction_type='income' AND transaction_date<=? FOR UPDATE",'iis',[$linkId,$fi_user_id,$today])[0]??null;
        if(!$linked)throw new InvalidArgumentException('Select an existing income transaction belonging to your account.');
        if(fi_cents($linked['amount'])>fi_cents($gross))throw new InvalidArgumentException('The linked income exceeds this session’s gross. Link a payment for this session only; combined salary payments cannot be allocated here.');
        if(fw_query($conn,'SELECT session_id FROM fw_work_session_details WHERE income_transaction_id=? AND session_id<>? LIMIT 1','ii',[$linkId,$id]))throw new InvalidArgumentException('That income transaction is already linked to another work session.');
        $received=fi_cents($linked['amount']);$receivedDate=$linked['transaction_date'];$payment=$received===fi_cents($gross)?'paid':'partial';
    } else {
        if(!in_array($payment,['unknown','unpaid','partial','paid'],true))throw new InvalidArgumentException('Choose a valid payment status.');
        if($payment==='paid')$received=fi_cents($gross);
        elseif($payment==='partial'){$received=fi_require_money($_POST['received_amount']??null,'Amount received',false);if($received>=fi_cents($gross))throw new InvalidArgumentException('A partial payment must be less than gross earnings.');}
        if(in_array($payment,['paid','partial'],true)&&$received>0) {$receivedDate=fw_text($_POST['received_date']??null);if(!fw_valid_date($receivedDate)||$receivedDate>$today)throw new InvalidArgumentException('Enter the actual payment date, no later than today.');}
    }
    if($id>0)fw_query($conn,'UPDATE work_sessions SET session_date=?,hours_worked=?,gross_earnings=?,work_expenses=?,notes=? WHERE session_id=? AND user_id=?','sssssii',[$workDate,number_format($hours,2,'.',''),number_format($gross,2,'.',''),$costs['total'],$notes,$id,$fi_user_id]);
    else {fw_query($conn,'INSERT INTO work_sessions(user_id,session_date,hours_worked,gross_earnings,work_expenses,notes) VALUES (?,?,?,?,?,?)','isssss',[$fi_user_id,$workDate,number_format($hours,2,'.',''),number_format($gross,2,'.',''),$costs['total'],$notes]);$id=(int)mysqli_insert_id($conn);}
    fw_query($conn,'DELETE k FROM fw_work_expense_kinds k JOIN work_session_expenses e ON e.expense_id=k.expense_id WHERE e.session_id=? AND k.user_id=?','ii',[$id,$fi_user_id]);
    fw_query($conn,'DELETE FROM work_session_expenses WHERE session_id=?','i',[$id]);
    foreach($costs['items'] as $i=>$item){fw_query($conn,'INSERT INTO work_session_expenses(session_id,expense_description,amount) VALUES (?,?,?)','iss',[$id,$item['description'],$item['amount']]);$expenseId=(int)mysqli_insert_id($conn);fw_query($conn,'INSERT INTO fw_work_expense_kinds(expense_id,user_id,cost_kind) VALUES (?,?,?)','iis',[$expenseId,$fi_user_id,$kinds[$i]]);}
    fw_query($conn,'INSERT INTO fw_work_session_details(session_id,user_id,work_source,extra_minutes,payment_status,received_amount,received_date,income_transaction_id,record_version) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE work_source=VALUES(work_source),extra_minutes=VALUES(extra_minutes),payment_status=VALUES(payment_status),received_amount=VALUES(received_amount),received_date=VALUES(received_date),income_transaction_id=VALUES(income_transaction_id),record_version=VALUES(record_version)','iisisssii',[$id,$fi_user_id,$source,$extra,$payment,fi_amount($received),$receivedDate,$linkId?:null,(int)($before['record_version']??0)+1]);
    $after=fi_work_snapshot($conn,$fi_user_id,$id);
    if($after['session_date']!==$workDate)throw new RuntimeException('Work date changed.');
    fw_revision($conn,$fi_user_id,'work_session',$id,$before?'updated':'created',$before,$after);fw_audit($conn,$fi_user_id,$before?'work_session.updated':'work_session.created',$id);
    if(!mysqli_commit($conn))throw new RuntimeException('Commit failed.');
    fi_flash('earnings','Work session '.($before?'updated':'saved').' for '.date('M j, Y',strtotime($workDate)).'. Transactions were not posted or duplicated.');header('Location: earnings.php?month='.urlencode(substr($workDate,0,7)).'&work_date='.urlencode($workDate).'#session-'.$id);exit;
 }catch(Throwable $e){mysqli_rollback($conn);$error=$e instanceof InvalidArgumentException?$e->getMessage():'Unable to save this session. Please try again.';if(!($e instanceof InvalidArgumentException))error_log('Work log v2: '.$e->getMessage());}
}
if($ready&&$editId>0){$editing=fi_work_snapshot($conn,$fi_user_id,$editId);if(!$editing)$error='That work session is unavailable.';}
$posted=$_SERVER['REQUEST_METHOD']==='POST'&&fw_text($_POST['action']??null)==='save';
$form=$posted?$_POST:($editing??[]);
$formDate=fw_text($form['session_date']??($_GET['work_date']??$today));if(!fw_valid_date($formDate)||$formDate>$today)$formDate=$today;
if($posted){$expenseDescriptions=is_array($_POST['expense_description']??null)?array_slice(array_values($_POST['expense_description']),0,30):[''];$expenseAmounts=is_array($_POST['expense_amount']??null)?array_slice(array_values($_POST['expense_amount']),0,30):[''];$expenseKinds=is_array($_POST['expense_kind']??null)?array_slice(array_values($_POST['expense_kind']),0,30):['other'];}
elseif($editing){$expenseDescriptions=array_column($editing['items'],'expense_description');$expenseAmounts=array_column($editing['items'],'amount');$expenseKinds=array_column($editing['items'],'cost_kind');if(!$expenseDescriptions&&(float)$editing['work_expenses']>0){$expenseDescriptions=['Previously recorded work costs'];$expenseAmounts=[$editing['work_expenses']];$expenseKinds=['other'];}}
else{$expenseDescriptions=[''];$expenseAmounts=[''];$expenseKinds=['other'];}
$page=max(1,min(100000,fw_positive_id($_GET['page']??1)));$offset=($page-1)*20;
$sessions=[];$itemsBySession=[];$dayTotals=[];$sourceTotals=[];$categoryTotals=[];$incomeOptions=[];
$sessionCount=0;$totalHours=0;$totalExtra=0;$totalGross=0;$totalCosts=0;
if($ready){
    $totals=fi_query($conn,'SELECT COUNT(*) AS count,COALESCE(SUM(s.hours_worked),0) AS hours,COALESCE(SUM(d.extra_minutes),0) AS extra,COALESCE(SUM(s.gross_earnings),0) AS gross,COALESCE(SUM(s.work_expenses),0) AS costs FROM work_sessions s LEFT JOIN fw_work_session_details d ON d.session_id=s.session_id AND d.user_id=s.user_id WHERE s.user_id=? AND s.session_date BETWEEN ? AND ?','iss',[$fi_user_id,$monthStart,$monthEnd])[0];
    $sessionCount=(int)$totals['count'];$totalHours=(float)$totals['hours'];$totalExtra=(int)$totals['extra'];$totalGross=(float)$totals['gross'];$totalCosts=(float)$totals['costs'];
    foreach(fi_query($conn,'SELECT session_date,SUM(gross_earnings-work_expenses) AS net FROM work_sessions WHERE user_id=? AND session_date BETWEEN ? AND ? GROUP BY session_date ORDER BY session_date','iss',[$fi_user_id,$monthStart,$monthEnd]) as $day)$dayTotals[$day['session_date']]=(float)$day['net'];
    $sessions=fi_query($conn,"SELECT s.*,d.work_source,COALESCE(d.extra_minutes,0) AS extra_minutes,d.payment_status,d.received_amount,d.received_date,d.income_transaction_id,COALESCE(d.record_version,0) AS record_version,t.transaction_id AS linked_id,t.amount AS linked_amount,t.transaction_date AS linked_date,t.transaction_type AS linked_type FROM work_sessions s LEFT JOIN fw_work_session_details d ON d.session_id=s.session_id AND d.user_id=s.user_id LEFT JOIN transactions t ON t.transaction_id=d.income_transaction_id AND t.user_id=s.user_id AND t.transaction_type='income' WHERE s.user_id=? AND s.session_date BETWEEN ? AND ? ORDER BY s.session_date DESC,s.session_id DESC LIMIT 20 OFFSET {$offset}",'iss',[$fi_user_id,$monthStart,$monthEnd]);
    if($sessions){$ids=implode(',',array_map(static fn($s)=>(int)$s['session_id'],$sessions));foreach(fi_query($conn,"SELECT e.*,COALESCE(k.cost_kind,'other') AS cost_kind FROM work_session_expenses e JOIN work_sessions s ON s.session_id=e.session_id LEFT JOIN fw_work_expense_kinds k ON k.expense_id=e.expense_id AND k.user_id=s.user_id WHERE s.user_id=? AND e.session_id IN ($ids) ORDER BY e.expense_id",'i',[$fi_user_id]) as $item)$itemsBySession[(int)$item['session_id']][]=$item;}
    $sourceTotals=fi_query($conn,"SELECT COALESCE(NULLIF(d.work_source,''),'Unspecified (older sessions)') AS source,COUNT(*) AS sessions,SUM(s.hours_worked) AS hours,COALESCE(SUM(d.extra_minutes),0) AS extra,SUM(s.gross_earnings) AS gross,SUM(s.work_expenses) AS costs FROM work_sessions s LEFT JOIN fw_work_session_details d ON d.session_id=s.session_id AND d.user_id=s.user_id WHERE s.user_id=? AND s.session_date BETWEEN ? AND ? GROUP BY COALESCE(NULLIF(d.work_source,''),'Unspecified (older sessions)') ORDER BY (SUM(s.gross_earnings)-SUM(s.work_expenses))/NULLIF(SUM(s.hours_worked)+COALESCE(SUM(d.extra_minutes),0)/60,0) DESC,source",'iss',[$fi_user_id,$monthStart,$monthEnd]);
    $categoryTotals=fi_query($conn,"SELECT COALESCE(k.cost_kind,'other') AS cost_kind,SUM(e.amount) AS total FROM work_session_expenses e JOIN work_sessions s ON s.session_id=e.session_id LEFT JOIN fw_work_expense_kinds k ON k.expense_id=e.expense_id AND k.user_id=s.user_id WHERE s.user_id=? AND s.session_date BETWEEN ? AND ? GROUP BY COALESCE(k.cost_kind,'other') ORDER BY total DESC",'iss',[$fi_user_id,$monthStart,$monthEnd]);
    $selectedLink=fw_positive_id($form['income_transaction_id']??null);
    $incomeOptions=fi_query($conn,"SELECT t.transaction_id,t.amount,t.transaction_date,t.description FROM transactions t WHERE t.user_id=? AND t.transaction_type='income' AND t.transaction_date<=? AND (t.transaction_id=? OR NOT EXISTS(SELECT 1 FROM fw_work_session_details d WHERE d.income_transaction_id=t.transaction_id)) ORDER BY (t.transaction_id=?) DESC,t.transaction_date DESC,t.transaction_id DESC LIMIT 200",'isii',[$fi_user_id,$today,$selectedLink,$selectedLink]);
}
$totalNet=$totalGross-$totalCosts;$baseHourly=$totalHours>0?$totalNet/$totalHours:0;$effectiveHours=$totalHours+$totalExtra/60;$hourly=$effectiveHours>0?$totalNet/$effectiveHours:0;
$trendLabels=[];$trendValues=[];for($day=1;$day<=(int)substr($monthEnd,8,2);$day++){$key=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT);$trendLabels[]=date('M j',strtotime($key));$trendValues[]=$dayTotals[$key]??null;}
