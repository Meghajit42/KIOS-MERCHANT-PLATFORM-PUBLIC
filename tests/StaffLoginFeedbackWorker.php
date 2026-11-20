<?php
declare(strict_types=1);
$case=$argv[1];$role=$argv[2]??'first_floor_kitchen';
putenv('APP_ENV=local');
foreach(['KIOS_CASHIER_PASSWORD','KIOS_GROUND_FLOOR_PASSWORD','KIOS_FIRST_FLOOR_KITCHEN_PASSWORD'] as $k)putenv($k.'=synthetic-fixture');
$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='POST';
session_name('KIOSSTAFF');session_id(bin2hex(random_bytes(16)));session_start();
$_SESSION['_cashier_csrf']='synthetic-csrf';if($case==='locked')$_SESSION['_cashier_locked_until']=time()+900;
session_write_close();
$_POST=['action'=>'login','staff_role'=>$role,'password'=>$case==='success'?'synthetic-fixture':'wrong-fixture','_csrf'=>$case==='csrf'?'stale-fixture':'synthetic-csrf'];
ob_start();register_shutdown_function(static function()use($case,$role):void{
$html=ob_get_clean();$status=http_response_code();$actual=$_SESSION['staff_role']??null;
if($case==='success'){$ok=$actual===$role&&$status===303;}
else {$message=match($case){'csrf'=>'Reload the staff sign-in page','locked'=>'Wait 15 minutes',default=>'Check the selected role'};
$ok=$actual===null&&$status===400&&str_contains($html,'value="first_floor_kitchen" selected')&&str_contains($html,$message)&&!str_contains($html,'value="wrong-fixture"');}
$_SESSION=[];session_destroy();echo $ok?'PASS':'FAIL';
});
require dirname(__DIR__).'/public/cashier.php';
