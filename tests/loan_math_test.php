<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/loans.php';
function loan_expect(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}}
loan_expect(fw_loan_split(1000000,3)===[333333,333333,333334],'principal cent conservation');
loan_expect(fw_loan_month('2027-01-31',1)==='2027-02-28'&&fw_loan_month('2027-01-31',2)==='2027-03-31','short month without drift');
loan_expect(fw_loan_month('2028-01-31',1)==='2028-02-29','leap-year due date');
$r=['principal'=>'100.00','principal_paid'=>'100.00','interest'=>'5.00','interest_paid'=>'2.00','fees'=>'1.00','fees_paid'=>'1.00','due_on'=>'2026-11-03'];
loan_expect(fw_loan_due($r)['total']===300&&fw_loan_state($r,'2026-11-05')==='Overdue · partial','unpaid charges remain owed');
$r['interest_paid']='5.00';loan_expect(fw_loan_state($r,'2026-11-05')==='Paid','paid only when all components paid');
foreach([[100,0],[100,61],[1,3]] as [$amount,$count]){$rejected=false;try{fw_loan_split($amount,$count);}catch(InvalidArgumentException $e){$rejected=true;}loan_expect($rejected,'invalid schedule count');}
echo 'PASS: loan rounding, due dates and unpaid-charge states.'.PHP_EOL;
