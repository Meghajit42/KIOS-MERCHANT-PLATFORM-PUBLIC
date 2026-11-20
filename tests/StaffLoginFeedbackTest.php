<?php
declare(strict_types=1);
return ['staff login retains each role and preserves CSRF and lockout protections'=>static function(string $root):void{
foreach([['success','cashier'],['success','ground_floor'],['success','first_floor_kitchen'],['wrong','first_floor_kitchen'],['csrf','first_floor_kitchen'],['locked','first_floor_kitchen']] as [$case,$role]){
$p=proc_open([PHP_BINARY,'-n',$root.'/tests/StaffLoginFeedbackWorker.php',$case,$role],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
if($code!==0||trim($out)!=='PASS'||$err!=='')throw new RuntimeException('staff login regression failed: '.$case.'/'.$role);}
}];
