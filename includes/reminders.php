<?php
function fw_notice_preferences(mysqli $conn,int $uid):array {
 $defaults=['debt_lead_days'=>3,'budget_popup'=>1,'debt_popup'=>0,'overdue_repeat'=>'none'];
 if(!fw_has_table($conn,'fw_notice_preferences'))return $defaults;
 return array_merge($defaults,fw_query($conn,'SELECT * FROM fw_notice_preferences WHERE user_id=?','i',[$uid])[0]??[]);
}
function fw_notice_popup(string $message):void {
 $_SESSION['fw_notice_dialog']??=[];
 if(count($_SESSION['fw_notice_dialog'])<5&&!in_array($message,$_SESSION['fw_notice_dialog'],true))$_SESSION['fw_notice_dialog'][]=$message;
}
function fw_reminder_message(string $title,string $note):string {
 $message=$title.($note!==''?' — '.$note:'');
 $characters=preg_split('//u',$message,-1,PREG_SPLIT_NO_EMPTY);
 return $characters!==false&&count($characters)>255?implode('',array_slice($characters,0,254)).'…':$message;
}
function fw_deliver_notice(mysqli $conn,int $uid,string $key,string $message,bool $popup):void {
 mysqli_begin_transaction($conn);
 try {
  fw_query($conn,'SELECT user_id FROM users WHERE user_id=? FOR UPDATE','i',[$uid]);
  if(fw_query($conn,'SELECT notification_id FROM fw_notice_events WHERE user_id=? AND event_key=?','is',[$uid,$key])){mysqli_commit($conn);return;}
  fw_query($conn,'INSERT INTO notifications(user_id,message,is_read) VALUES (?,?,0)','is',[$uid,$message]);$nid=(int)mysqli_insert_id($conn);
  fw_query($conn,'INSERT INTO fw_notice_events(user_id,event_key,notification_id) VALUES (?,?,?)','isi',[$uid,$key,$nid]);
  if(!mysqli_commit($conn))throw new RuntimeException('Notification commit failed.');
  if($popup)fw_notice_popup($message);
 }catch(Throwable $e){mysqli_rollback($conn);throw $e;}
}
function fw_personal_reminder_notifications(mysqli $conn,int $uid):void {
 if(!fw_has_table($conn,'fw_personal_reminders'))return;
 $due=fw_query($conn,"SELECT reminder_id FROM fw_personal_reminders WHERE user_id=? AND status='active' AND delivered_at IS NULL AND remind_at<=NOW() ORDER BY remind_at LIMIT 100",'i',[$uid]);
 foreach($due as $item){mysqli_begin_transaction($conn);try{
  fw_query($conn,'SELECT user_id FROM users WHERE user_id=? FOR UPDATE','i',[$uid]);
  $r=fw_query($conn,"SELECT * FROM fw_personal_reminders WHERE reminder_id=? AND user_id=? AND status='active' AND delivered_at IS NULL AND remind_at<=NOW() FOR UPDATE",'ii',[(int)$item['reminder_id'],$uid])[0]??null;
  if(!$r){mysqli_commit($conn);continue;}
  $message=fw_reminder_message($r['title'],$r['note']);
  fw_query($conn,'INSERT INTO notifications(user_id,message,is_read) VALUES (?,?,0)','is',[$uid,$message]);$nid=(int)mysqli_insert_id($conn);
  fw_query($conn,'UPDATE fw_personal_reminders SET delivered_at=NOW(),notification_id=? WHERE reminder_id=? AND user_id=?','iii',[$nid,(int)$r['reminder_id'],$uid]);
  if(!mysqli_commit($conn))throw new RuntimeException('Reminder commit failed.');if($r['show_popup'])fw_notice_popup($message);
 }catch(Throwable $e){mysqli_rollback($conn);throw $e;}}
}
function fw_configured_debt_notifications(mysqli $conn,int $uid):void {
 if(!fw_has_table($conn,'fw_debts'))return;
 $p=fw_notice_preferences($conn,$uid);$lead=max(0,min(365,(int)$p['debt_lead_days']));
 $rows=fw_query($conn,"SELECT d.* FROM fw_debts d WHERE user_id=? AND archived=0 AND next_due<=DATE_ADD(CURDATE(),INTERVAL ? DAY) AND opening_principal>COALESCE((SELECT SUM(principal) FROM fw_debt_payments p WHERE p.debt_id=d.debt_id AND p.user_id=d.user_id),0)",'ii',[$uid,$lead]);
 foreach($rows as $d){
  $overdue=$d['next_due']<date('Y-m-d');$kind=$overdue?'overdue':'upcoming';$repeat=$p['overdue_repeat'];$bucket='once';
  if($overdue&&$repeat!=='none'){$days=(int)(new DateTimeImmutable($d['next_due']))->diff(new DateTimeImmutable('today'))->days;$bucket=$repeat==='daily'?date('Y-m-d'):(string)intdiv(max(0,$days-1),7);}
  if($bucket==='once'&&fw_query($conn,'SELECT debt_id FROM fw_debt_alerts WHERE debt_id=? AND user_id=? AND due_on=? AND alert_kind=?','iiss',[(int)$d['debt_id'],$uid,$d['next_due'],$kind]))continue;
  $key='debt:'.$d['debt_id'].':'.$d['next_due'].':'.$kind.':'.$bucket;
  $message=$d['lender'].' repayment '.($overdue?'is overdue':'is due soon').'. Due '.date('M j, Y',strtotime($d['next_due'])).'.';
  fw_deliver_notice($conn,$uid,$key,$message,(bool)$p['debt_popup']);
 }
}
