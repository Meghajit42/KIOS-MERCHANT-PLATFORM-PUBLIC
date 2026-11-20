<?php
declare(strict_types=1);namespace Kios\MerchantOperations;
use PDO;use InvalidArgumentException;use Kios\MerchantFoundation\AuthenticatedUserProvider;
final class EntitlementRoutes {
 public static function feature(array $q):?string {
  if(isset($q['billing']))return 'billing';if(isset($q['analytics']))return 'analytics';if(isset($q['pos']))return 'pos';if(isset($q['service-kitchen']))return 'kitchen';if(isset($q['seating']))return 'floor';if(isset($q['inventory']))return 'inventory';foreach(['brand','brand_preview','brand_image','layout','design']as $k)if(isset($q[$k]))return 'branding';
  if(isset($q['restaurant']))return match($q['section']??'menu'){'staff'=>'staff','kitchen'=>'kitchen',default=>'menu'};return null;
 }
 public static function check(PDO $db,AuthenticatedUserProvider $auth,string $method,array $get,array $post):void{
  if(!Entitlements::enabled())return;$e=new Entitlements($db,$auth);
  if($method==='GET'){$f=self::feature($get);$d=$get;$op=in_array($f,['pos','kitchen','floor'],true)?'drain-read':'read';}
  else{$a=$post['action']??'';$f=match(true){str_starts_with($a,'billing-')=>'billing',str_starts_with($a,'pos-')=>$a==='pos-task'?'kitchen':'pos',str_starts_with($a,'inventory-')=>'inventory',str_starts_with($a,'seating-')=>in_array($a,['seating-reserve','seating-transition','seating-checkin','seating-assign'],true)?'reservations':'floor',str_starts_with($a,'restaurant-')=>str_starts_with($a,'restaurant-staff')?'staff':($a==='restaurant-station'?'kitchen':'menu'),str_starts_with($a,'brand-'),str_starts_with($a,'layout-'),str_starts_with($a,'design-')=>'branding',in_array($a,['queue','kot','prints'],true)=>'kitchen',in_array($a,['catalogue','order'],true)=>'menu',default=>null};$d=$post;$op=(in_array($a,['pos-task','pos-served','pos-cancel'],true)||($a==='seating-transition'&&in_array($post['status']??'',['COMPLETED','CANCELLED','NO_SHOW'],true))||($a==='seating-status'&&($post['state']??'')==='AVAILABLE'))?'drain':'new';}
  if($f===null)return;foreach(['merchant','outlet']as$k)if(!is_string($d[$k]??null))throw new InvalidArgumentException('Select a valid subscription scope.');$o=$d['outlet'];if($o==='all'&&$f==='analytics'){$q=$db->prepare('SELECT outlet_id FROM merchant_outlets WHERE merchant_id=? AND active=1 ORDER BY outlet_id LIMIT 1');$q->execute([$d['merchant']]);$o=(string)$q->fetchColumn();$e->requireFeature($d['merchant'],$o,'multi_outlet','read');}$e->requireFeature($d['merchant'],$o,$f,$op);$GLOBALS['kios_feature_flags']=$e->availability($d['merchant'],$o);
 }
}
