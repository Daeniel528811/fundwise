<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/reliability.php';
function expect($test,$label){if(!$test){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);}}
$r=fw_expense_items(['Fuel','Meals'],['90.25','109.75']);expect($r['total']==='200.00'&&count($r['items'])===2,'itemized total');
expect(fw_expense_items([],[])['total']==='0.00','empty list');
expect(fw_expense_items([''],[''])['total']==='0.00','blank initial row');
expect(fw_expense_items(['Small cost','Small cost'],['0.10','0.20'])['total']==='0.30','cent precision');
expect(fw_text_length(str_repeat('é',255))===255,'Unicode character length');
expect(count(fw_expense_items([str_repeat('é',160)],['1.00'])['items'])===1,'Unicode expense description');
foreach([[[''],['10']],[['Cost'],['-1']],[['Cost'],['1.001']],[['Cost'],['1e3']],[[['nested']],['10']],['not an array',[]],[array_fill(0,31,'Cost'),array_fill(0,31,'1')],[['Cost'],[]]] as [$descriptions,$amounts]){
 $rejected=false;try{fw_expense_items($descriptions,$amounts);}catch(InvalidArgumentException $e){$rejected=true;}expect($rejected,'malformed expense rejected');
}
expect(!fw_password_valid("bad\0password"),'null byte password');expect(!fw_password_valid('short'),'short password');expect(fw_password_valid('valid-local-test-password'),'valid password');
$code=fw_recovery_code();expect(strlen($code)===48&&fw_recovery_hash($code)===fw_recovery_hash(strtolower($code)),'recovery normalization');
echo 'PASS: expense, password, and recovery-code validation.'.PHP_EOL;
