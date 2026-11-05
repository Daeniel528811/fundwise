<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/session.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/reliability.php';
$now=time();
$expired=isset($_SESSION['user_id']) && (($now-($_SESSION['last_activity']??$now)>1800)||($now-($_SESSION['authenticated_at']??$now)>43200));
if(isset($_SESSION['user_id']) && !$expired) {
    $expired=(int)($_SESSION['credential_version']??0)!==fw_security_version($conn,(int)$_SESSION['user_id']);
}
if($expired){$_SESSION=[];session_regenerate_id(true);}
if(!isset($_SESSION['user_id'])){header('Location: '.BASE_URL.'/auth/login.php'.($expired?'?expired=1':''));exit;}
if(random_int(1,100)===1){mysqli_query($conn,"DELETE FROM fw_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY) LIMIT 1000");mysqli_query($conn,"DELETE FROM fw_login_limits WHERE window_start < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) LIMIT 1000");}
$_SESSION['last_activity']=$now;$_SESSION['authenticated_at']??=$now;
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if($_SERVER['REQUEST_METHOD']==='POST' && !str_ends_with($_SERVER['SCRIPT_NAME']??'','/api/theme.php')) {
    $csrf=false;foreach(['csrf_token','profile_csrf','notification_csrf','categories_csrf_token'] as $key){if(fw_csrf_valid($key))$csrf=true;}
    if(!$csrf){http_response_code(403);exit('Invalid form token. Refresh this page and try again.');}
    if(!fw_claim_request($conn,(int)$_SESSION['user_id'],$_POST['fw_request_token']??null)){
        http_response_code(409);exit('This form was already submitted or has expired. Reload the page before submitting again.');
    }
    fw_audit($conn,(int)$_SESSION['user_id'],'request:'.substr(basename(dirname($_SERVER['SCRIPT_NAME']??'')).'/'.basename($_SERVER['SCRIPT_NAME']??'').':'.fw_text($_POST['action']??''),0,70));
}
