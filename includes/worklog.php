<?php
if(!isset($fi_user_id)){http_response_code(404);exit;}
// Loaded only after the authenticated Financial Intelligence bootstrap.
$currency=fi_currency($conn,$fi_user_id);
$ready=fi_table_exists($conn,'work_sessions')&&fi_table_exists($conn,'work_session_expenses');
$error='';$flash=fi_take_flash('earnings');
$month=fi_month($_GET['month']??date('Y-m'));
$monthStart=$month.'-01';$monthEnd=(new DateTimeImmutable($monthStart))->format('Y-m-t');
$formDate=fw_text($_POST['session_date']??($_GET['work_date']??date('Y-m-d')));
if(!fw_valid_date($formDate)||$formDate>date('Y-m-d'))$formDate=date('Y-m-d');
$expenseDescriptions=is_array($_POST['expense_description']??null)?array_slice(array_values($_POST['expense_description']),0,30):[''];
$expenseAmounts=is_array($_POST['expense_amount']??null)?array_slice(array_values($_POST['expense_amount']),0,30):[''];
if($ready&&$_SERVER['REQUEST_METHOD']==='POST'){
 $action=fw_text($_POST['action']??null);
 if(!fi_post_csrf()){$error='Invalid form. Refresh and try again.';http_response_code(403);}
 elseif($action==='save'){
  $workDate=fw_text($_POST['session_date']??null);$hours=fw_money($_POST['hours_worked']??null);$gross=fw_money($_POST['gross_earnings']??null,true);$notes=fw_text($_POST['notes']??null);
  try{
   if(!fw_valid_date($workDate)||$workDate>date('Y-m-d')||$hours===null||$hours>24||$gross===null||fw_text_length($notes)>255)throw new InvalidArgumentException('Enter a date no later than today, 0.01–24 hours, valid gross earnings, and notes up to 255 characters.');
   $costs=fw_expense_items($_POST['expense_description']??[],$_POST['expense_amount']??[]);
   if(!mysqli_begin_transaction($conn))throw new RuntimeException('Unable to begin.');
   fw_query($conn,'SELECT user_id FROM users WHERE user_id=? FOR UPDATE','i',[$fi_user_id]);
   $existing=fw_query($conn,'SELECT COALESCE(SUM(hours_worked),0) AS hours FROM work_sessions WHERE user_id=? AND session_date=?','is',[$fi_user_id,$workDate])[0];
   if((int)round((float)$existing['hours']*100)+(int)round($hours*100)>2400)throw new InvalidArgumentException('Combined work sessions cannot exceed 24 hours on one date.');
   fw_query($conn,'INSERT INTO work_sessions(user_id,session_date,hours_worked,gross_earnings,work_expenses,notes) VALUES (?,?,?,?,?,?)','isssss',[$fi_user_id,$workDate,number_format($hours,2,'.',''),number_format($gross,2,'.',''),$costs['total'],$notes]);
   $newId=mysqli_insert_id($conn);
   foreach($costs['items'] as $item)fw_query($conn,'INSERT INTO work_session_expenses(session_id,expense_description,amount) VALUES (?,?,?)','iss',[$newId,$item['description'],$item['amount']]);
   $stored=fw_query($conn,'SELECT session_date FROM work_sessions WHERE session_id=? AND user_id=?','ii',[$newId,$fi_user_id])[0]['session_date'];
   if($stored!==$workDate)throw new RuntimeException('Selected date was not preserved.');
   fw_audit($conn,$fi_user_id,'work_session.created',$newId);
   if(!mysqli_commit($conn))throw new RuntimeException('Unable to commit.');
   fi_flash('earnings','Work session saved for '.date('M j, Y',strtotime($workDate)).'. Your selected work date is preserved.');
   header('Location: earnings.php?month='.urlencode(substr($workDate,0,7)).'&work_date='.urlencode($workDate));exit;
  }catch(Throwable $e){mysqli_rollback($conn);$error=$e instanceof InvalidArgumentException?$e->getMessage():'Unable to record this session. Please try again.';if(!($e instanceof InvalidArgumentException))error_log('Work log: '.$e->getMessage());}
 }elseif($action==='delete'){
  $id=fw_positive_id($_POST['session_id']??null);mysqli_begin_transaction($conn);
  try{
   if(!fw_query($conn,'SELECT session_id FROM work_sessions WHERE session_id=? AND user_id=? FOR UPDATE','ii',[$id,$fi_user_id]))throw new RuntimeException('Not found.');
   fw_query($conn,'DELETE FROM work_session_expenses WHERE session_id=?','i',[$id]);fw_query($conn,'DELETE FROM work_sessions WHERE session_id=? AND user_id=?','ii',[$id,$fi_user_id]);fw_audit($conn,$fi_user_id,'work_session.deleted',$id);
   if(!mysqli_commit($conn))throw new RuntimeException('Unable to commit.');fi_flash('earnings','Work session removed. Transactions are unchanged.');header('Location: earnings.php?month='.urlencode($month));exit;
  }catch(Throwable $e){mysqli_rollback($conn);$error='Unable to remove this session.';}
 }
}
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*20;
$sessions=[];$itemsBySession=[];$dayTotals=[];$sessionCount=0;$totalHours=0;$totalGross=0;$totalCosts=0;
if($ready){
 $totals=fi_query($conn,'SELECT COUNT(*) AS count,COALESCE(SUM(hours_worked),0) AS hours,COALESCE(SUM(gross_earnings),0) AS gross,COALESCE(SUM(work_expenses),0) AS costs FROM work_sessions WHERE user_id=? AND session_date BETWEEN ? AND ?','iss',[$fi_user_id,$monthStart,$monthEnd])[0];
 $sessionCount=(int)$totals['count'];$totalHours=(float)$totals['hours'];$totalGross=(float)$totals['gross'];$totalCosts=(float)$totals['costs'];
 foreach(fi_query($conn,'SELECT session_date,SUM(gross_earnings-work_expenses) AS net FROM work_sessions WHERE user_id=? AND session_date BETWEEN ? AND ? GROUP BY session_date ORDER BY session_date','iss',[$fi_user_id,$monthStart,$monthEnd]) as $day)$dayTotals[$day['session_date']]=(float)$day['net'];
 $sessions=fi_query($conn,"SELECT * FROM work_sessions WHERE user_id=? AND session_date BETWEEN ? AND ? ORDER BY session_date DESC,session_id DESC LIMIT 20 OFFSET {$offset}",'iss',[$fi_user_id,$monthStart,$monthEnd]);
 if($sessions){$ids=implode(',',array_map(static fn($s)=>(int)$s['session_id'],$sessions));foreach(fi_query($conn,"SELECT e.* FROM work_session_expenses e JOIN work_sessions s ON s.session_id=e.session_id WHERE s.user_id=? AND e.session_id IN ($ids) ORDER BY e.expense_id",'i',[$fi_user_id]) as $item)$itemsBySession[(int)$item['session_id']][]=$item;}
}
$totalNet=$totalGross-$totalCosts;$hourly=$totalHours>0?$totalNet/$totalHours:0;
$trendLabels=[];$trendValues=[];for($day=1;$day<=(int)substr($monthEnd,8,2);$day++){$key=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT);$trendLabels[]=date('M j',strtotime($key));$trendValues[]=$dayTotals[$key]??null;}
