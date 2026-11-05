<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$count=0;
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as $file){$rel=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));if(str_starts_with($rel,'.git/')||str_starts_with($rel,'vendor/')||str_starts_with($rel,'node_modules/')||$file->getExtension()!=='php')continue;
 exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()).' 2>&1',$output,$code);if($code!==0){fwrite(STDERR,implode(PHP_EOL,$output).PHP_EOL);exit(1);}$output=[];$count++;}
foreach(['validation_test.php','reliability_test.php','loan_math_test.php','work_cost_categories_test.php'] as $test){passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tests/'.$test),$code);if($code!==0)exit($code);}
echo 'PASS: PHP syntax for '.$count.' files and pure validation/calculation suites.'.PHP_EOL;
