<?php
require_once __DIR__.'/input.php';
function fw_has_table(mysqli $conn,string $table):bool {
 static $cache=[];$key=spl_object_id($conn).':'.$table;
 return $cache[$key]??=count(fw_query($conn,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?','s',[$table]))>0;
}
function fw_label($raw):string {
 $label=fw_text($raw);if(fw_text_length($label)>60)throw new InvalidArgumentException('Spending labels can contain up to 60 characters.');
 return preg_replace('/\s+/u',' ',$label)??'';
}
function fw_work_time(array $post,?DateTimeImmutable $now=null):?array {
 $start=fw_text($post['started_at']??null);$end=fw_text($post['ended_at']??null);
 $breakRaw=$post['break_minutes']??'0';
 if($start===''&&$end==='')return null;
 $zone=new DateTimeZone('Asia/Manila');$now=$now??new DateTimeImmutable('now',$zone);
 $a=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$start,$zone);$b=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$end,$zone);
 if(!$a||!$b||$a->format('Y-m-d\TH:i')!==$start||$b->format('Y-m-d\TH:i')!==$end||$b<=$a||$b>$now)throw new InvalidArgumentException('Enter a valid start and end, with the end after the start and no later than now (Philippine time).');
 if(!is_scalar($breakRaw)||filter_var($breakRaw,FILTER_VALIDATE_INT)===false||(int)$breakRaw<0)throw new InvalidArgumentException('Break minutes must be a non-negative whole number.');
 $minutes=(int)(($b->getTimestamp()-$a->getTimestamp())/60);$break=(int)$breakRaw;
 if($minutes>1440||$break>=$minutes)throw new InvalidArgumentException('A session can last up to 24 hours and must include some working time.');
 return ['start'=>$a->format('Y-m-d H:i:s'),'end'=>$b->format('Y-m-d H:i:s'),'break'=>$break,'date'=>$a->format('Y-m-d'),'hours'=>round(($minutes-$break)/60,2)];
}
function fw_debt_due_notifications(mysqli $conn,int $uid):void {
 if(fw_has_table($conn,'fw_notice_preferences')){fw_configured_debt_notifications($conn,$uid);return;}
 if(!fw_has_table($conn,'fw_debts'))return;
 $rows=fw_query($conn,"SELECT d.* FROM fw_debts d WHERE user_id=? AND archived=0 AND next_due<=DATE_ADD(CURDATE(),INTERVAL 3 DAY) AND opening_principal>COALESCE((SELECT SUM(p.principal) FROM fw_debt_payments p WHERE p.debt_id=d.debt_id AND p.user_id=d.user_id),0)",'i',[$uid]);
 foreach($rows as $d){$kind=$d['next_due']<date('Y-m-d')?'overdue':'upcoming';mysqli_begin_transaction($conn);try{
  if(fw_insert_once($conn,'INSERT IGNORE INTO fw_debt_alerts(debt_id,user_id,due_on,alert_kind) VALUES (?,?,?,?)','iiss',[(int)$d['debt_id'],$uid,$d['next_due'],$kind]))fw_query($conn,'INSERT INTO notifications(user_id,message,is_read) VALUES (?,?,0)','is',[$uid,'Repayment '.$kind.': '.$d['lender'].' · due '.$d['next_due'].'. Review the scheduled payment in Debts & Repayments.']);
  mysqli_commit($conn);
 }catch(Throwable $e){mysqli_rollback($conn);throw $e;}}
}

require_once __DIR__.'/reminders.php';
