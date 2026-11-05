<?php
// Manual payment schedules. Cash transactions remain separate.
function fw_loan_ready(mysqli $conn):bool {
 foreach(['fw_loan_installments','fw_loan_allocations','fw_loan_notice_settings'] as $t)if(!fw_has_table($conn,$t))return false;
 return true;
}
function fw_loan_rows(mysqli $conn,int $uid,int $debt):array {
 return fw_query($conn,'SELECT i.*,COALESCE(SUM(p.principal),0) AS principal_paid,COALESCE(SUM(p.interest),0) AS interest_paid,COALESCE(SUM(p.fees),0) AS fees_paid FROM fw_loan_installments i LEFT JOIN fw_loan_allocations a ON a.installment_id=i.installment_id AND a.user_id=i.user_id AND a.debt_id=i.debt_id LEFT JOIN fw_debt_payments p ON p.payment_id=a.payment_id AND p.user_id=a.user_id AND p.debt_id=a.debt_id WHERE i.user_id=? AND i.debt_id=? GROUP BY i.installment_id ORDER BY i.due_on,i.sequence_no','ii',[$uid,$debt]);
}
function fw_loan_due(array $row):array {
 $due=[];foreach(['principal','interest','fees'] as $k)$due[$k]=max(0,(int)round((float)$row[$k]*100)-(int)round((float)($row[$k.'_paid']??0)*100));
 $due['total']=array_sum($due);return $due;
}
function fw_loan_state(array $row,string $today):string {
 $due=fw_loan_due($row);if($due['total']===0)return 'Paid';
 $partial=(float)$row['principal_paid']+(float)$row['interest_paid']+(float)$row['fees_paid']>0;
 if($row['due_on']<$today)return $partial?'Overdue · partial':'Overdue';
 if($partial)return 'Partially paid';return $row['due_on']===$today?'Due today':'Upcoming';
}
function fw_loan_month(string $first,int $offset):string {
 $a=new DateTimeImmutable($first);$month=$a->modify('first day of this month')->modify('+'.$offset.' months');
 return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$a->format('d'),(int)$month->format('t')))->format('Y-m-d');
}
function fw_loan_split(int $principal,int $count):array {
 if($count<1||$count>60||$principal<$count)throw new InvalidArgumentException('Choose 1–60 installments, each with at least 0.01 of principal.');
 $base=intdiv($principal,$count);$rows=array_fill(0,$count,$base);$rows[$count-1]+=$principal-$base*$count;return $rows;
}
function fw_loan_sync(mysqli $conn,int $uid,int $debt):void {
 $rows=fw_loan_rows($conn,$uid,$debt);if(!$rows)return;
 $date=null;$total=0;foreach($rows as $row){$due=fw_loan_due($row);if($due['total']>0){if($date===null)$date=$row['due_on'];if($row['due_on']===$date)$total+=$due['total'];}}
 fw_query($conn,'UPDATE fw_debts SET next_due=?,scheduled_amount=? WHERE user_id=? AND debt_id=?','ssii',[$date,number_format($total/100,2,'.',''),$uid,$debt]);
}
