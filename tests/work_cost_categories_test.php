<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/input.php';
require_once __DIR__.'/../includes/fi_tools.php';
$checks=0;
function cost_expect(bool $ok,string $label):void{global $checks;if(!$ok){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}$checks++;}
$kinds=fi_expense_kinds();
cost_expect(count($kinds)===5,'exactly five current choices');
cost_expect(array_keys($kinds)===['transport','supplies','workspace','fees','other'],'stable current keys');
foreach(array_keys($kinds) as $key)cost_expect(fi_validate_expense_kinds([$key])===[$key],'accept current key '.$key);
cost_expect(count(fi_expense_options('other'))===5,'new rows have no old-only options');
cost_expect(count(fi_expense_options('food'))===6&&isset(fi_expense_options('food')['food']),'saved food has its original selection');
cost_expect(!isset(fi_expense_options('food')['deductions']),'saved category does not expose unrelated retired choice');
cost_expect(fi_expense_kind_label('food')==='Work-related food','food history stays accurately labeled');
cost_expect(fi_expense_kind_label('deductions')==='Entered deductions','deduction history stays accurately labeled');
cost_expect(fi_validate_expense_kinds(['food','deductions','workspace'],[['cost_kind'=>'food'],['cost_kind'=>'deductions']])===['food','deductions','workspace'],'editing can retain old kinds and add a current kind');
cost_expect(fi_validate_expense_kinds(['other'],[['cost_kind'=>'food']])===['other'],'explicit reclassification is allowed');
foreach([['food'],['deductions'],['unknown'],[['nested']]] as $bad){$rejected=false;try{fi_validate_expense_kinds($bad);}catch(InvalidArgumentException $e){$rejected=true;}cost_expect($rejected,'reject invalid or newly introduced retired category');}
$rejected=false;try{fi_validate_expense_kinds(['food','food'],[['cost_kind'=>'food']]);}catch(InvalidArgumentException $e){$rejected=true;}cost_expect($rejected,'cannot duplicate retired rows through an edit');
cost_expect(fi_expense_kind_label('unknown')==='Other work costs','safe unknown display fallback');
echo 'PASS: '.$checks.' work-cost category compatibility checks.'.PHP_EOL;
