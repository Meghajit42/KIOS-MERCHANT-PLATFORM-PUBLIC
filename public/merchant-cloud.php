<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\{Database,DatabaseConfiguration,Environment,StaffAccountService};
use Kios\Domain\{Money,OrderType,PreparationTaskStatus};
use Kios\MerchantOperations\{StaffSessionIdentity,TenantRestaurant,TenantOperations,TenantFinance,MerchantProvisioner,MerchantProvisioningView,OwnerOnboarding,OwnerSetupView,RestaurantSetup,RestaurantSetupView};
use Kios\MerchantOperations\{BrandProfile,BrandProfileView};

// Dedicated protected cloud adapter. The original local endpoint remains unchanged.
if (!\Kios\MerchantOperations\CloudDemo::allowed($_SERVER)) { http_response_code(404); exit; }
ob_start([\Kios\MerchantOperations\CloudDemo::class,'presentation']);
$bodyLimit=(Environment::get('KIOS_MERCHANT_BRANDING_ENABLED','0')==='1'&&($_POST['action']??'')==='brand-upload')?1572864:65536;
if ((int)($_SERVER['CONTENT_LENGTH']??0)>$bodyLimit) { if(str_starts_with(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/','/api/'))ejson(413,[]);http_response_code(413);exit; }
session_name('KIOSMERCHANTCLOUD');
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_set_cookie_params(['path'=>'/','httponly'=>true,'samesite'=>'Strict',
    'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
function eh(string $s):string {return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function ejson(int $status,array $payload):never {
    if(str_starts_with(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/','/api/')){
        if(ob_get_level()>0)ob_end_clean();$id=bin2hex(random_bytes(16));
        $payload=['api_version'=>'v1','request_id'=>$id,'data'=>null,'error'=>['code'=>match($status){400=>'INVALID_REQUEST',401=>'AUTHENTICATION_REQUIRED',403=>'ACCESS_DENIED',404=>'NOT_FOUND',405=>'METHOD_NOT_ALLOWED',413=>'REQUEST_TOO_LARGE',default=>'UNAVAILABLE'}],'pagination'=>null];
        header('X-Request-Id: '.$id);header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    }
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    if(($_SERVER['REQUEST_METHOD']??'')!=='HEAD')echo json_encode($payload,JSON_THROW_ON_ERROR);exit;
}
function eid(string $field,int $minimum=1):int {
    $value=$_POST[$field] ?? '';
    if (!is_string($value) || !ctype_digit($value) || strlen($value)>15 || (int)$value<$minimum) {
        throw new InvalidArgumentException('Invalid identifier.');
    }
    return (int)$value;
}
$_SESSION['_merchant_csrf'] ??= bin2hex(random_bytes(32));
try {
    $db=Database::connect();
    $rateKey=Environment::get('KIOS_MERCHANT_AUTH_RATE_KEY','') ?? '';
    if (strlen($rateKey)<32) {
        throw new RuntimeException('Authentication configuration incomplete.');
    }
    $provider=new StaffSessionIdentity($db,static fn():array=>$_SESSION,$rateKey);
    if(str_starts_with(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/','/api/')){
        ob_end_clean();header('Content-Type: application/json; charset=utf-8');
        $result=(new \Kios\MerchantOperations\PrivateApi($db,$provider))->request($_SERVER['REQUEST_METHOD']??'',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),$_GET,$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_AUTHORIZATION']??'',$_SERVER['HTTP_IDEMPOTENCY_KEY']??'',(int)($_SERVER['CONTENT_LENGTH']??0));
        http_response_code($result['status']);foreach($result['headers']as$k=>$v)header($k.': '.$v);
        if(($_SERVER['REQUEST_METHOD']??'')!=='HEAD')echo json_encode($result['body'],JSON_THROW_ON_ERROR);exit;
    }
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method,['GET','POST'],true))ejson(405,['error'=>'Method denied']);
    if ($method==='POST') {
        $csrf=$_POST['_csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['_merchant_csrf'],$csrf)) {
            ejson(400,['error'=>'Reload and retry']);
        }
        $action=$_POST['action'] ?? '';
        if (!is_string($action) || !\Kios\MerchantOperations\CloudDemo::actionAllowed($action)) ejson(403,['error'=>'Operation unavailable in private demonstration']);

        if ($action==='login') {
            $outlet=eid('login_outlet');
            $q=$db->prepare("SELECT o.outlet_code,EXISTS(SELECT 1 FROM merchant_legacy_outlets l WHERE l.legacy_outlet_id=o.outlet_id) mapped FROM outlets o WHERE o.outlet_id=? AND o.active=1 AND (EXISTS(SELECT 1 FROM merchant_legacy_outlets l WHERE l.legacy_outlet_id=o.outlet_id) OR (?=1 AND EXISTS(SELECT 1 FROM merchant_auth_links l JOIN merchant_platform_memberships p ON p.user_id=l.user_id AND p.active=1 JOIN merchant_staff u ON u.user_id=p.user_id AND u.active=1 WHERE l.legacy_outlet_id=o.outlet_id AND l.active=1)))");
            $q->execute([$outlet,\Kios\MerchantOperations\Entitlements::enabled()?1:0]);$loginScope=$q->fetch(PDO::FETCH_ASSOC);$code=$loginScope['outlet_code']??false;
            if (!is_string($code))ejson(401,['error'=>'Sign-in rejected']);
            $accounts=new StaffAccountService($db,$code,$rateKey);
            $identity=$accounts->authenticate(is_string($_POST['username']??null)?$_POST['username']:'',
                $_POST['password']??null,$_SERVER['REMOTE_ADDR']);
            if ($identity===null)ejson(401,['error'=>'Sign-in rejected']);
            $q=$db->prepare('SELECT user_id FROM merchant_auth_links WHERE account_id=? AND legacy_outlet_id=? AND active=1');
            $q->execute([$identity['account_id'],$outlet]);
            $linkedUser=$q->fetchColumn();if($linkedUser===false)ejson(401,['error'=>'Sign-in rejected']);
            if(!(int)$loginScope['mapped']){$q=$db->prepare("SELECT p.user_id FROM merchant_platform_memberships p JOIN merchant_staff s ON s.user_id=p.user_id AND s.active=1 JOIN merchant_role_permissions r ON r.role_code=p.role_code AND r.permission_code='manage_platform' WHERE p.user_id=? AND p.active=1 AND p.role_code='platform_administrator'");$q->execute([$linkedUser]);if(!$q->fetchColumn())ejson(401,['error'=>'Sign-in rejected']);}
            session_regenerate_id(true);$_SESSION=[];
            StaffAccountService::bind($_SESSION,$identity);
            $_SESSION['_merchant_login_outlet']=$outlet;
            $_SESSION['_merchant_csrf']=bin2hex(random_bytes(32));
            header('Location: /merchant.php',true,303);exit;
        }
        if(in_array($action,['usage-price','usage-event','usage-save'],true)){
            $svc=new \Kios\MerchantOperations\UsageBilling($db,$provider);
            foreach(['merchant','request','reason','confirm']as$key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Invalid estimate form.');
            $data=$_POST['data']??null;if(!is_array($data))throw new InvalidArgumentException('Invalid estimate fields.');
            if($action==='usage-price'){
                if(!is_string($data['choice']??null)||!preg_match('/^([a-z][a-z0-9_-]{0,31}):([1-9][0-9]{0,5})$/D',$data['choice'],$choice))throw new InvalidArgumentException('Select a plan version.');
                unset($data['choice']);$data['plan']=$choice[1];$data['plan_version']=$choice[2];
                if($_POST['merchant']!=='')throw new InvalidArgumentException('Pricing is platform-scoped.');
                $r=$svc->price($_POST['request'],$data,$_POST['reason'],$_POST['confirm']);
            }else $r=$action==='usage-event'?$svc->event($_POST['merchant'],$_POST['request'],$data,$_POST['reason'],$_POST['confirm']):$svc->save($_POST['merchant'],$_POST['request'],$data,$_POST['reason'],$_POST['confirm']);
            ejson(200,['ok'=>true,'data'=>$r]);
        }
        if ($action==='logout') {
            $_SESSION=[];session_regenerate_id(true);
            $_SESSION['_merchant_csrf']=bin2hex(random_bytes(32));
            header('Location: /merchant.php',true,303);exit;
        }
        if ($action==='change-password') {
            $loginOutlet=$_SESSION['_merchant_login_outlet']??null;
            if (!is_int($loginOutlet))ejson(401,['error'=>'Authentication required']);
            $q=$db->prepare('SELECT outlet_code FROM outlets WHERE outlet_id=? AND active=1');
            $q->execute([$loginOutlet]);$code=$q->fetchColumn();
            if (!is_string($code))ejson(401,['error'=>'Authentication required']);
            $accounts=new StaffAccountService($db,$code,$rateKey);
            if ($accounts->identity($_SESSION)===null)ejson(401,['error'=>'Authentication required']);
            $identity=$accounts->changePassword($_SESSION,$_POST['current_password']??null,
                $_POST['new_password']??null,$_POST['confirm_password']??null);
            session_regenerate_id(true);StaffAccountService::bind($_SESSION,$identity);
            $_SESSION['_merchant_csrf']=bin2hex(random_bytes(32));
            header('Location: /merchant.php',true,303);exit;
        }
        if ($provider->currentUserId()===null)ejson(401,['error'=>'Authentication required']);
        if(in_array($action,['integration-save','integration-review'],true)){
            if(!\Kios\MerchantOperations\IntegratedSetup::enabled())ejson(404,['error'=>'Not available']);
            $keys=['_csrf','action','merchant','outlet','expected','request','platform_setup','reason','confirm'];if($action==='integration-save')$keys=array_merge($keys,['profile','modules']);
            if(array_diff(array_keys($_POST),$keys)||$_FILES!==[])throw new InvalidArgumentException('Use only the current outlet configuration form.');
            foreach(['merchant','outlet','expected','request','platform_setup','reason','confirm']as$k)if(!is_string($_POST[$k]??null))throw new InvalidArgumentException('Invalid outlet setup field.');
            if(!in_array($_POST['platform_setup'],['0','1'],true))throw new InvalidArgumentException('Select the authorized setup mode.');$platform=$_POST['platform_setup']==='1';
            $svc=new \Kios\MerchantOperations\IntegratedSetup($db,$provider);
            $data=$action==='integration-save'?['profile'=>$_POST['profile']??null,'modules'=>$_POST['modules']??[]]:[];
            try{$svc->act($_POST['merchant'],$_POST['outlet'],$_POST['expected'],$_POST['request'],substr($action,12),$data,$_POST['reason'],$_POST['confirm'],$platform);}
            catch(DomainException|InvalidArgumentException $error){$state=$svc->view($_POST['merchant'],$_POST['outlet'],$platform);http_response_code($error instanceof DomainException?403:400);echo \Kios\MerchantOperations\IntegratedSetupView::render($state,$_SESSION['_merchant_csrf'],'wizard',$error->getMessage());exit;}
            header('Location: /merchant.php?outlet_wizard=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).($platform?'&platform_setup=1':'').'&saved=1',true,303);exit;
        }
        if(str_starts_with($action,'platform-')){
            $console=new \Kios\MerchantOperations\PlatformAdministration($db,$provider);
            // Authenticate before parsing editable administrative fields.
            (new MerchantProvisioner($db,$provider))->assertAdministrator();
            if(!\Kios\MerchantOperations\PlatformAccess::enabled())ejson(404,['error'=>'Not available']);
            if($_FILES!==[])throw new InvalidArgumentException('No uploads.');
            try{
                if($action==='platform-create'){
                    $keys=['_csrf','action','request','confirm','data'];if(array_diff(array_keys($_POST),$keys)||array_diff($keys,array_keys($_POST))||($_POST['confirm']??'')!=='yes'||!is_string($_POST['request'])||!is_array($_POST['data']))throw new InvalidArgumentException('Confirm the exact synthetic provisioning form.');
                    $result=$console->provision($_POST['request'],$_POST['data']);$merchant=$result['receipt']['merchant_id'];
                }elseif($action==='platform-owner-activate'){
                    $keys=['_csrf','action','request','confirm','merchant','version','password','confirmation'];if(array_diff(array_keys($_POST),$keys)||array_diff($keys,array_keys($_POST)))throw new InvalidArgumentException('Use the attended activation form.');foreach($keys as$k)if(!is_string($_POST[$k]))throw new InvalidArgumentException('Invalid activation field.');if($_POST['confirm']!=='yes'||!ctype_digit($_POST['version'])||strlen($_POST['version'])>8)throw new InvalidArgumentException('Confirm activation with its current version.');
                    $console->activateOwner($_POST['merchant'],(int)$_POST['version'],$_POST['request'],$_POST['password'],$_POST['confirmation']);$merchant=$_POST['merchant'];
                }elseif($action==='platform-admin'){
                    $keys=['_csrf','action','request','confirm','merchant','expected','op','reason','data'];if(array_diff(array_keys($_POST),$keys)||array_diff($keys,array_keys($_POST)))throw new InvalidArgumentException('Use the exact administration form.');foreach(array_diff($keys,['data'])as$k)if(!is_string($_POST[$k]))throw new InvalidArgumentException('Invalid administration field.');if(!is_array($_POST['data']))throw new InvalidArgumentException('Invalid administration data.');
                    $console->act($_POST['merchant'],$_POST['expected'],$_POST['request'],$_POST['op'],$_POST['data'],$_POST['reason'],$_POST['confirm']);$merchant=$_POST['merchant'];
                }else throw new InvalidArgumentException('Unknown platform operation.');
                header('Location: /merchant.php?platform=detail&merchant='.rawurlencode($merchant).'&saved=1',true,303);exit;
            }catch(DomainException|InvalidArgumentException $error){
                http_response_code($error instanceof DomainException?403:400);
                echo \Kios\MerchantOperations\PlatformAdministrationView::render('create',[],$_SESSION['_merchant_csrf'],false,$error->getMessage());exit;
            }
        }
        if(str_starts_with($action,'plans-')){
 if(!\Kios\MerchantOperations\Entitlements::enabled())ejson(404,['error'=>'Not available']);if($_FILES!==[])throw new InvalidArgumentException('No uploads.');$plans=new \Kios\MerchantOperations\Entitlements($db,$provider);
 if($action==='plans-save'){$keys=['_csrf','action','request','plan_id','version','name','description','features','limits','active','metadata'];if(array_diff(array_keys($_POST),$keys))throw new InvalidArgumentException('Unexpected plan fields.');foreach(['request','plan_id','version','name','description','active','metadata']as$k)if(!is_string($_POST[$k]??null))throw new InvalidArgumentException('Invalid plan field.');if(!is_array($_POST['features']??[])||!is_array($_POST['limits']??null))throw new InvalidArgumentException('Invalid configuration.');$plans->plan($_POST['request'],$_POST['plan_id'],$_POST['version'],$_POST['name'],$_POST['description'],$_POST['features']??[],$_POST['limits'],$_POST['active'],$_POST['metadata']);}
 elseif($action==='plans-lifecycle'){$keys=['_csrf','action','request','merchant','expected','assignment_expected','transition','choice','effective','expires','trial_end','grace_days','renewable','reason'];if(array_diff(array_keys($_POST),$keys)||array_diff($keys,array_keys($_POST)))throw new InvalidArgumentException('Unexpected lifecycle fields.');foreach($keys as$k)if(!is_string($_POST[$k]))throw new InvalidArgumentException('Invalid lifecycle field.');$data=array_intersect_key($_POST,array_flip(['choice','effective','expires','trial_end','grace_days','renewable','reason']));$plans->lifecycle($_POST['request'],$_POST['merchant'],$_POST['expected'],$_POST['assignment_expected'],$_POST['transition'],$data);}
 elseif($action==='plans-assign'){$keys=['_csrf','action','request','merchant','expected','choice','effective','expires','reason'];if(array_diff(array_keys($_POST),$keys)||array_diff($keys,array_keys($_POST)))throw new InvalidArgumentException('Unexpected assignment fields.');foreach($keys as$k)if(!is_string($_POST[$k]))throw new InvalidArgumentException('Invalid assignment field.');$choice=explode(':',$_POST['choice'],2);if(count($choice)!==2)throw new InvalidArgumentException('Select a plan.');$plans->assign($_POST['request'],$_POST['merchant'],$_POST['expected'],$choice[0],$choice[1],$_POST['effective'],$_POST['expires'],$_POST['reason']);}
 else throw new InvalidArgumentException('Unknown plan operation.');header('Location: /merchant.php?plans=1&saved=1',true,303);exit;
 }
 \Kios\MerchantOperations\EntitlementRoutes::check($db,$provider,'POST',[], $_POST);
 if($action==='billing-save'){
 if(Environment::get('KIOS_BILL_DRAFTS_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
 $keys=['_csrf','action','merchant','outlet','request','service','version','source_hash','mode','people','discount','reason','allocation','cancel'];if(array_diff(array_keys($_POST),$keys)||$_FILES!==[])throw new InvalidArgumentException('Use only billing fields.');
 foreach(['merchant','outlet','request','service','version','source_hash','mode','people','discount','reason']as$key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Invalid billing field.');
 $n=\Kios\MerchantOperations\BillDrafts::number($_POST['people'],1,6);$allocation=$_POST['allocation']??[];if(!is_array($allocation))throw new InvalidArgumentException('Invalid allocation.');foreach($allocation as &$qs){if(!is_array($qs)||!array_is_list($qs)||count($qs)!==6)throw new InvalidArgumentException('Invalid portions.');foreach(array_slice($qs,$n)as$q)if($q!=='0')throw new InvalidArgumentException('Hidden portions must be zero.');$qs=array_slice($qs,0,$n);}unset($qs);if($_POST['mode']==='equal')$allocation=[];
 if(isset($_POST['cancel'])&&$_POST['cancel']!=='1')throw new InvalidArgumentException('Invalid cancel.');
 (new \Kios\MerchantOperations\BillDrafts($db,$provider))->save($_POST['merchant'],$_POST['outlet'],$_POST['request'],$_POST['service'],$_POST['version'],$_POST['source_hash'],$_POST['mode'],$_POST['people'],$_POST['discount'],$_POST['reason'],$allocation,isset($_POST['cancel']));header('Location: /merchant.php?billing=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).'&service='.rawurlencode($_POST['service']).'&saved=1',true,303);exit;
 }
 if(str_starts_with($action,'pos-')){
            if(Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            $keys=['_csrf','action','merchant','outlet','request'];$keys=array_merge($keys,match($action){'pos-save'=>['service','version','table','lines_json'],'pos-submit','pos-served','pos-cancel'=>['service','version'],'pos-task'=>['task','expected'],default=>throw new InvalidArgumentException('Unknown service action.')});
            if(array_diff(array_keys($_POST),$keys)||array_diff($keys,array_keys($_POST))||$_FILES!==[])throw new InvalidArgumentException('Use only the current service form.');foreach($keys as $key)if(!is_string($_POST[$key]))throw new InvalidArgumentException('Invalid service field.');
            $service=new \Kios\MerchantOperations\StaffPos($db,$provider);$m=$_POST['merchant'];$o=$_POST['outlet'];
            try{
                if($action==='pos-task'){$service->advance($m,$o,eid('task'),$_POST['expected']);header('Location: /merchant.php?service-kitchen=1&merchant='.rawurlencode($m).'&outlet='.rawurlencode($o).'&saved=1',true,303);exit;}
                $result=match($action){
                    'pos-save'=>$service->save($m,$o,$_POST['request'],$_POST['service'],$_POST['version'],$_POST['table'],json_decode($_POST['lines_json'],true,32,JSON_THROW_ON_ERROR)),
                    'pos-submit'=>$service->submit($m,$o,$_POST['request'],$_POST['service'],$_POST['version']),
                    'pos-served','pos-cancel'=>$service->finish($m,$o,$_POST['request'],$_POST['service'],$_POST['version'],$action==='pos-cancel'),
                };header('Location: /merchant.php?pos=1&merchant='.rawurlencode($m).'&outlet='.rawurlencode($o).'&service='.rawurlencode($result['service']).'&saved=1',true,303);exit;
            }catch(DomainException|InvalidArgumentException $error){
                if($action==='pos-task')throw $error;$state=$service->load($m,$o);http_response_code(400);echo \Kios\MerchantOperations\StaffPosView::render($state,$_SESSION['_merchant_csrf'],'',$error->getMessage(),true);exit;
            }
        }
        if(str_starts_with($action,'seating-')){
            if(Environment::get('KIOS_MERCHANT_SEATING_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            foreach(['merchant','outlet','request'] as $key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Invalid seating scope.');
            if($_FILES!==[])throw new InvalidArgumentException('Uploads are not accepted.');
            $service=new \Kios\MerchantOperations\Seating($db,$provider);$data=array_diff_key($_POST,array_fill_keys(['_csrf','action','merchant','outlet','request'],true));
            try{$service->act($_POST['merchant'],$_POST['outlet'],$_POST['request'],substr($action,8),$data);header('Location: /merchant.php?seating=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).'&saved=1',true,303);exit;}
            catch(\Kios\MerchantOperations\SeatingAccessDenied $error){ejson(403,['error'=>'Seating access denied']);}
            catch(DomainException|InvalidArgumentException $error){$state=$service->load($_POST['merchant'],$_POST['outlet']);http_response_code(400);echo \Kios\MerchantOperations\SeatingView::render($state,$_SESSION['_merchant_csrf'],$error->getMessage(),true);exit;}
        }
        if(str_starts_with($action,'inventory-')){
            if(Environment::get('KIOS_MERCHANT_INVENTORY_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            foreach(['merchant','outlet','request'] as $key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Invalid inventory scope.');
            $service=new \Kios\MerchantOperations\Inventory($db,$provider);$data=array_diff_key($_POST,array_fill_keys(['_csrf','action','merchant','outlet','request'],true));
            if($action==='inventory-recipe'&&is_array($data['lines']??null))$data['lines']=array_values(array_filter($data['lines'],fn($line)=>!is_array($line)||($line['quantity']??'')!=='0'));
            try{$service->act($_POST['merchant'],$_POST['outlet'],$_POST['request'],substr($action,10),$data);header('Location: /merchant.php?inventory=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).'&saved=1',true,303);exit;}
            catch(DomainException|InvalidArgumentException $error){$state=$service->load($_POST['merchant'],$_POST['outlet']);http_response_code(400);echo \Kios\MerchantOperations\InventoryView::render($state,$_SESSION['_merchant_csrf'],$error->getMessage(),true);exit;}
        }
        if(in_array($action,['design-publish','design-restore'],true)){
            if(Environment::get('KIOS_MERCHANT_PUBLICATION_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            $keys=['_csrf','action','merchant','outlet','revision','request'];if($action==='design-restore')$keys[]='version_id';
            if(array_diff(array_keys($_POST),$keys)||array_diff($keys,array_keys($_POST))||$_FILES!==[])throw new InvalidArgumentException('Only current design controls are accepted.');
            foreach(['merchant','outlet','request'] as $key)if(!is_string($_POST[$key]))throw new InvalidArgumentException('Invalid design scope.');
            $restore=$action==='design-restore'?$_POST['version_id']:null;if($restore!==null&&!is_string($restore))throw new InvalidArgumentException('Invalid version.');
            (new BrandProfile($db,$provider))->publish($_POST['merchant'],$_POST['outlet'],eid('revision'),$_POST['request'],$restore);
            header('Location: /merchant.php?design=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']),true,303);exit;
        }
        if(in_array($action,['layout-save','layout-defaults'],true)){
            if(Environment::get('KIOS_MERCHANT_LAYOUT_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            foreach(['merchant','outlet','request'] as $field)if(!is_string($_POST[$field]??null))throw new InvalidArgumentException('Invalid layout identifiers.');
            $service=new BrandProfile($db,$provider);
            $fields=array_diff_key($_POST,array_fill_keys(['_csrf','action','merchant','outlet','version','request'],true));
            $config=\Kios\MerchantOperations\LayoutConfiguration::validate($fields);
            if($action==='layout-defaults')$config=\Kios\MerchantOperations\LayoutConfiguration::inherited($service->load($_POST['merchant'],$_POST['outlet'])['config']);
            $service->layout($_POST['merchant'],$_POST['outlet'],eid('version'),$_POST['request'],$config);
            header('Location: /merchant.php?layout=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).'&saved=1',true,303);exit;
        }
        if(is_string($action)&&str_starts_with($action,'brand-')){
            if(Environment::get('KIOS_MERCHANT_BRANDING_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            if(!is_string($_POST['merchant']??null)||!is_string($_POST['outlet']??null)||!is_string($_POST['request']??null))throw new InvalidArgumentException('Invalid brand identifiers.');
            $service=new BrandProfile($db,$provider);$ui=($_POST['_brand_ui']??'')==='1';$op=substr($action,6);
            $data=array_diff_key($_POST,array_fill_keys(['_csrf','_brand_ui','action','merchant','outlet','version','request'],true));$upload=null;
            try{
                if($op==='preview'){
                    if($_FILES!==[])throw new InvalidArgumentException('Use the separate logo upload form.');
                    $draft=BrandProfile::configuration($data);$state=$service->load($_POST['merchant'],$_POST['outlet']);
                    $menu=(new RestaurantSetup($db,$provider))->load($_POST['merchant'],$_POST['outlet']);$nonce=bin2hex(random_bytes(16));
                    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-$nonce'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
                    header('Content-Type: text/html; charset=utf-8');echo BrandProfileView::render($state,$menu,$_SESSION['_merchant_csrf'],$nonce,'desktop',$draft);exit;
                }
                if($op==='upload'){
                    if(array_keys($_FILES)!==['logo'])throw new InvalidArgumentException('Choose one logo image.');$f=$_FILES['logo'];
                    if(!is_int($f['error']??null)||$f['error']!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||!is_int($f['size']??null)||$f['size']>1048576)throw new InvalidArgumentException('Upload a JPG, PNG or WebP file up to 1 MiB.');
                    $upload=file_get_contents($f['tmp_name'],false,null,0,1048577);if(!is_string($upload))throw new InvalidArgumentException('Unable to read upload.');
                }elseif($_FILES!==[])throw new InvalidArgumentException('Unexpected upload.');
                $result=$service->act($_POST['merchant'],$_POST['outlet'],eid('version'),$_POST['request'],$op,$data,$upload);
                if(!$ui)ejson(200,['ok'=>true,'data'=>$result]);
                header('Location: /merchant.php?brand=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).'&saved=1',true,303);exit;
            }catch(DomainException|InvalidArgumentException $error){
                if(!$ui)throw $error;
                $state=$service->load($_POST['merchant'],$_POST['outlet']);$menu=(new RestaurantSetup($db,$provider))->load($_POST['merchant'],$_POST['outlet']);$nonce=bin2hex(random_bytes(16));
                header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-$nonce'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
                http_response_code(400);header('Content-Type: text/html; charset=utf-8');echo BrandProfileView::render($state,$menu,$_SESSION['_merchant_csrf'],$nonce,'desktop',null,$error->getMessage());exit;
            }
        }
        if(is_string($action)&&str_starts_with($action,'restaurant-')){
            if(Environment::get('KIOS_MERCHANT_RESTAURANT_SETUP_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            if(!is_string($_POST['merchant']??null)||!is_string($_POST['outlet']??null)||!is_string($_POST['request']??null))throw new InvalidArgumentException('Invalid restaurant identifiers.');
            $service=new RestaurantSetup($db,$provider);$ui=($_POST['_restaurant_ui']??'')==='1';$op=substr($action,11);
            $data=array_diff_key($_POST,array_fill_keys(['_csrf','_restaurant_ui','action','merchant','outlet','version','request'],true));
            if($op==='product'&&$ui&&is_array($data['variants']??null))$data['variants']=array_values(array_filter($data['variants'],fn($v)=>!is_array($v)||($v['label']??'')!==''||($v['id']??'0')!=='0'));
            if($op==='staff-access'&&!isset($data['stations']))$data['stations']=[];
            try{
                $result=$service->act($_POST['merchant'],$_POST['outlet'],eid('version'),$_POST['request'],$op,$data);
                if(!$ui)ejson(200,['ok'=>true,'data'=>$result]);
                $section=match($op){'station'=>'kitchen','staff-create','staff-activate','staff-access'=>'staff','availability','archive'=>'pricing',default=>'menu'};
                header('Location: /merchant.php?restaurant=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).'&section='.$section.'&saved=1',true,303);exit;
            }catch(DomainException|InvalidArgumentException $error){
                if(!$ui)throw $error;$state=$service->load($_POST['merchant'],$_POST['outlet']);http_response_code(400);
                header('Content-Type: text/html; charset=utf-8');echo RestaurantSetupView::render($state,$_SESSION['_merchant_csrf'],'menu',$error->getMessage());exit;
            }
        }
        if(is_string($action)&&str_starts_with($action,'setup-')){
            if(Environment::get('KIOS_MERCHANT_OWNER_SETUP_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            if(!is_string($_POST['merchant']??null)||!is_string($_POST['outlet']??null)||!is_string($_POST['request']??null))throw new InvalidArgumentException('Invalid setup identifiers.');
            $setup=new OwnerOnboarding($db,$provider);$ui=($_POST['_setup_ui']??'')==='1';$review=$action==='setup-review';
            $fields=array_diff_key($_POST,array_fill_keys(['_csrf','_setup_ui','action','merchant','outlet','version','request'],true));
            try{
                $operation=match($action){'setup-save-business'=>'save_business','setup-save-outlet'=>'save_outlet','setup-submit'=>'submit','setup-reopen'=>'reopen','setup-review'=>'review',default=>throw new DomainException('Action denied')};
                $result=$setup->act($_POST['merchant'],$_POST['outlet'],eid('version'),$_POST['request'],$operation,$fields);
                if($ui){header('Location: /merchant.php?'.($review?'setup-review':'setup').'=1&merchant='.rawurlencode($_POST['merchant']).'&notice=saved',true,303);exit;}
                ejson(200,['ok'=>true,'data'=>$result]);
            }catch(DomainException|InvalidArgumentException $error){
                if(!$ui)throw $error;$state=$setup->load($_POST['merchant'],$review);
                http_response_code(400);header('Content-Type: text/html; charset=utf-8');echo OwnerSetupView::render($state,$_SESSION['_merchant_csrf'],$review,$error->getMessage());exit;
            }
        }
        if(is_string($action) && str_starts_with($action,'onboarding-')) {
            if(Environment::get('KIOS_MERCHANT_PROVISIONING_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            $provisioning=new MerchantProvisioner($db,$provider);$provisioning->assertAdministrator();
        if(\Kios\MerchantOperations\PlatformAccess::enabled()){if(isset($_GET['receipt'])){if(!is_string($_GET['receipt']))throw new InvalidArgumentException('Invalid merchant.');header('Location: /merchant.php?platform=detail&merchant='.rawurlencode($_GET['receipt']),true,303);}else header('Location: /merchant.php?platform=create',true,303);exit;}
            $ui=($_POST['_ui']??'')==='1';
            try {
                if($action==='onboarding-create'){
                    $fields=array_diff_key($_POST,array_fill_keys(['_csrf','_ui','action','request'],true));
                    $result=$provisioning->provision(is_string($_POST['request']??null)?$_POST['request']:'',$fields);
                }elseif($action==='onboarding-activate'){
                    if(array_diff(array_keys($_POST),['_csrf','_ui','action','request','merchant_id','version','password','confirmation']))throw new InvalidArgumentException('Unexpected activation fields.');
                    if(!is_string($_POST['merchant_id']??null)||!is_string($_POST['request']??null))throw new InvalidArgumentException('Invalid activation identifiers.');
                    $result=$provisioning->activate($_POST['merchant_id'],eid('version'),$_POST['request'],
                        is_string($_POST['password']??null)?$_POST['password']:'',is_string($_POST['confirmation']??null)?$_POST['confirmation']:'');
                }else{throw new DomainException('Action denied');}
                if($ui){header('Location: /merchant.php?onboarding=1&receipt='.rawurlencode($result['receipt']['merchant_id']),true,303);exit;}
                ejson(200,['ok'=>true,'data'=>$result]);
            }catch(DomainException|InvalidArgumentException $error){
                if(!$ui)throw $error;
                http_response_code(400);header('Content-Type: text/html; charset=utf-8');
                echo MerchantProvisioningView::render($_SESSION['_merchant_csrf'],null,$error->getMessage());exit;
            }
        }
        $merchant=$_POST['merchant']??'';$outlet=$_POST['outlet']??'';
        if (!is_string($merchant)||!is_string($outlet))throw new InvalidArgumentException('Invalid scope.');
        $restaurant=new TenantRestaurant($db,$provider);
        $operations=new TenantOperations($db,$provider);
        $finance=new TenantFinance($db,$provider);
        $data=match($action) {
            'financial-preview'=>$finance->preview($merchant,$outlet),
            'financial-history'=>$finance->history($merchant,$outlet),
            'financial-closing'=>$finance->closing($merchant,$outlet,eid('closing')),
            'financial-end-day'=>$finance->endDay($merchant,$outlet,(string)($_POST['business_date']??''),
                eid('version'),(string)($_POST['request']??''),[
                    'acknowledge'=>$_POST['acknowledge']??'',
                    'acknowledgement'=>$_POST['acknowledgement']??'',
                ]),
            'catalogue'=>$restaurant->catalogue($merchant,$outlet,(string)($_POST['category']??'')),
            'price'=>$restaurant->setPrice($merchant,$outlet,eid('product'),eid('variant'),
                Money::fromPaise(eid('price',0)),eid('version')),
            'checkout'=>$restaurant->checkout($merchant,$outlet,
                json_decode((string)($_POST['lines']??''),true,64,JSON_THROW_ON_ERROR),
                OrderType::from((string)($_POST['type']??'')),(string)($_POST['intent']??''),session_id())->toArray(),
            'order'=>$restaurant->order($merchant,$outlet,eid('order'))?->toArray(),
            'pay'=>$operations->confirmCash($merchant,$outlet,eid('order'))->toArray(),
            'queue'=>$operations->queue($merchant,$outlet,eid('station')),
            'kot'=>$operations->kot($merchant,$outlet,eid('task')),
            'advance'=>$operations->advance($merchant,$outlet,eid('task'),PreparationTaskStatus::from((string)($_POST['expected']??''))),
            'collect'=>$operations->collect($merchant,$outlet,eid('order'))->toArray(),
            'prints'=>$operations->printJobs($merchant,$outlet),
            'deliver'=>$operations->deliverVirtual($merchant,$outlet,eid('job'))->value,
            default=>throw new DomainException('Action denied'),
        };
        ejson(200,['ok'=>true,'data'=>$data]);
    }
    header('Content-Type: text/html; charset=utf-8');
    if($_SERVER['REQUEST_METHOD']==='GET'){
 if(isset($_GET['outlet_directory'])||isset($_GET['outlet_wizard'])||isset($_GET['assistant'])||isset($_GET['outlet_home'])){
    if(!\Kios\MerchantOperations\IntegratedSetup::enabled())ejson(404,['error'=>'Not available']);
    foreach(['merchant','outlet','platform_setup']as$k)if(isset($_GET[$k])&&!is_string($_GET[$k]))throw new InvalidArgumentException('Invalid outlet selector.');
    if(isset($_GET['platform_setup'])&&!in_array($_GET['platform_setup'],['0','1'],true))throw new InvalidArgumentException('Invalid setup mode.');
    $platform=($_GET['platform_setup']??'0')==='1';$svc=new \Kios\MerchantOperations\IntegratedSetup($db,$provider);
    $m=$_GET['merchant']??'';
    if(isset($_GET['outlet_directory']))echo \Kios\MerchantOperations\IntegratedSetupView::directory($svc->outlets($m,$platform),$_SESSION['_merchant_csrf']);
    else echo \Kios\MerchantOperations\IntegratedSetupView::render($svc->view($m,$_GET['outlet']??'',$platform),$_SESSION['_merchant_csrf'],isset($_GET['outlet_wizard'])?'wizard':(isset($_GET['outlet_home'])?'home':'assistant'),'',isset($_GET['saved']));
    exit;
 }
 if(isset($_GET['usage'])||isset($_GET['usage_platform'])){
    $platform=isset($_GET['usage_platform']);$svc=new \Kios\MerchantOperations\UsageBilling($db,$provider);
    foreach(['merchant','outlet','period','cycle','support','page','export']as$key)if(isset($_GET[$key])&&!is_string($_GET[$key]))throw new InvalidArgumentException('Invalid estimate filter.');
    $m=$_GET['merchant']??'';
    if($platform&&$m===''){echo \Kios\MerchantOperations\UsageBillingView::render($svc->catalogue(),$_SESSION['_merchant_csrf'],true,true);exit;}
    $support=$_GET['support']??'0';if(!in_array($support,['0','1'],true))throw new InvalidArgumentException('Invalid support selection.');
    $state=$svc->load($m,$_GET['outlet']??'all',$_GET['period']??gmdate('Y-m'),$_GET['cycle']??'MONTHLY',$support==='1',$platform,$_GET['page']??'1');
    if(isset($_GET['export'])){if($_GET['export']!=='json')throw new InvalidArgumentException('JSON export only.');ob_end_clean();header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="kios-demonstration-estimate.json"');echo \Kios\MerchantOperations\UsageBilling::report($state);exit;}
    echo \Kios\MerchantOperations\UsageBillingView::render($state,$_SESSION['_merchant_csrf'],$platform);exit;
 }
 if(isset($_GET['platform'])){
  $section=$_GET['platform'];if(!is_string($section)||!in_array($section,['overview','merchants','detail','outlets','subscriptions','audit','create'],true))throw new InvalidArgumentException('Invalid platform section.');
  $console=new \Kios\MerchantOperations\PlatformAdministration($db,$provider);(new MerchantProvisioner($db,$provider))->assertAdministrator();if(!\Kios\MerchantOperations\PlatformAccess::enabled())ejson(404,['error'=>'Not available']);
  foreach(['q','status','page','merchant']as$k)if(isset($_GET[$k])&&!is_string($_GET[$k]))throw new InvalidArgumentException('Invalid platform filter.');
  $state=match($section){'overview'=>$console->overview(),'merchants'=>$console->directory($_GET['q']??'',$_GET['status']??'',$_GET['page']??'1'),'outlets'=>$console->outlets($_GET['q']??'',$_GET['status']??'',$_GET['page']??'1'),'detail'=>$console->detail($_GET['merchant']??''),'subscriptions'=>(new \Kios\MerchantOperations\Entitlements($db,$provider))->lifecyclePlatform(),'audit'=>$console->auditPage($_GET['page']??'1'),'create'=>[]};
  echo \Kios\MerchantOperations\PlatformAdministrationView::render($section,$state,$_SESSION['_merchant_csrf'],isset($_GET['saved']));exit;
 }
 if(isset($_GET['plans'])||isset($_GET['subscription'])){if(!\Kios\MerchantOperations\Entitlements::enabled())ejson(404,['error'=>'Not available']);$e=new \Kios\MerchantOperations\Entitlements($db,$provider);$platform=isset($_GET['plans']);if($platform){$selected=$_GET['plan_id']??'';if(!is_string($selected))throw new InvalidArgumentException('Invalid plan.');$state=$e->platform();}else{foreach(['merchant','outlet']as$k)if(!is_string($_GET[$k]??null))throw new InvalidArgumentException('Select a tenant.');$selected='';$state=$e->overview($_GET['merchant'],$_GET['outlet']);$GLOBALS['kios_feature_flags']=$e->availability($_GET['merchant'],$_GET['outlet']);}echo \Kios\MerchantOperations\SubscriptionView::render($state,$_SESSION['_merchant_csrf'],$platform,$selected,isset($_GET['saved']));exit;}
 \Kios\MerchantOperations\EntitlementRoutes::check($db,$provider,'GET',$_GET,[]);
 }
 if(isset($_GET['billing'])){foreach(['merchant','outlet']as$key)if(!is_string($_GET[$key]??null))throw new InvalidArgumentException('Invalid billing scope.');$service=$_GET['service']??'';if(!is_string($service))throw new InvalidArgumentException('Invalid service.');$state=(new \Kios\MerchantOperations\BillDrafts($db,$provider))->load($_GET['merchant'],$_GET['outlet'],$service);echo \Kios\MerchantOperations\BillDraftsView::render($state,$_SESSION['_merchant_csrf'],isset($_GET['saved'])?'Nonfinancial draft saved.':'');exit;}
 if(isset($_GET['analytics'])){
        if(Environment::get('KIOS_OPERATIONAL_ANALYTICS_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
        foreach(['merchant','outlet']as $key)if(!is_string($_GET[$key]??null))throw new InvalidArgumentException('Select an authorized report scope.');
        $from=$_GET['from']??gmdate('Y-m-d',time()-6*86400);$to=$_GET['to']??gmdate('Y-m-d');$station=$_GET['station']??'';$page=$_GET['page']??'1';foreach([$from,$to,$station,$page]as $v)if(!is_string($v))throw new InvalidArgumentException('Invalid report filter.');if(!ctype_digit($page))throw new InvalidArgumentException('Invalid report page.');
        $data=(new \Kios\MerchantOperations\OperationalAnalytics($db,$provider))->load($_GET['merchant'],$_GET['outlet'],$from,$to,$station,(int)$page);
        if(isset($_GET['export'])){if($_GET['export']!=='csv')throw new InvalidArgumentException('CSV export only.');ob_end_clean();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="kios-operational-analytics.csv"');echo \Kios\MerchantOperations\OperationalAnalytics::csv($data);exit;}
        echo \Kios\MerchantOperations\OperationalAnalyticsView::render($data);exit;
    }
    if(isset($_GET['pos'])||isset($_GET['service-kitchen'])){
        if(Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);foreach(['merchant','outlet']as $key)if(!is_string($_GET[$key]??null))throw new InvalidArgumentException('Invalid service scope.');$service=new \Kios\MerchantOperations\StaffPos($db,$provider);
        if(isset($_GET['service-kitchen'])){echo \Kios\MerchantOperations\StaffPosView::kitchen($service->kitchen($_GET['merchant'],$_GET['outlet']),$_SESSION['_merchant_csrf'],isset($_GET['saved'])?'Preparation updated.':'');exit;}
        $id=$_GET['service']??'';if(!is_string($id))throw new InvalidArgumentException('Invalid service reference.');echo \Kios\MerchantOperations\StaffPosView::render($service->load($_GET['merchant'],$_GET['outlet']),$_SESSION['_merchant_csrf'],$id,isset($_GET['saved'])?'Service changes saved.':'');exit;
    }
    if(isset($_GET['seating'])){
        if(Environment::get('KIOS_MERCHANT_SEATING_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
        foreach(['merchant','outlet'] as $key)if(!is_string($_GET[$key]??null))throw new InvalidArgumentException('Invalid seating scope.');
        $state=(new \Kios\MerchantOperations\Seating($db,$provider))->load($_GET['merchant'],$_GET['outlet']);
        echo \Kios\MerchantOperations\SeatingView::render($state,$_SESSION['_merchant_csrf'],isset($_GET['saved'])?'Seating change saved.':'');exit;
    }
    if(isset($_GET['inventory'])){
        if(Environment::get('KIOS_MERCHANT_INVENTORY_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
        foreach(['merchant','outlet'] as $key)if(!is_string($_GET[$key]??null))throw new InvalidArgumentException('Invalid inventory scope.');
        $state=(new \Kios\MerchantOperations\Inventory($db,$provider))->load($_GET['merchant'],$_GET['outlet']);
        echo \Kios\MerchantOperations\InventoryView::render($state,$_SESSION['_merchant_csrf'],isset($_GET['saved'])?'Inventory change saved.':'');exit;
    }
    if(isset($_GET['design'])){
        if(Environment::get('KIOS_MERCHANT_PUBLICATION_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
        foreach(['merchant','outlet'] as $key)if(!is_string($_GET[$key]??null))throw new InvalidArgumentException('Invalid design scope.');
        $selected=$_GET['version_id']??null;if($selected!==null&&!is_string($selected))throw new InvalidArgumentException('Invalid version.');
        $service=new BrandProfile($db,$provider);$state=$service->designs($_GET['merchant'],$_GET['outlet'],$selected);$brand=$service->load($_GET['merchant'],$_GET['outlet']);
        $menu=(new RestaurantSetup($db,$provider))->load($_GET['merchant'],$_GET['outlet']);$nonce=bin2hex(random_bytes(16));
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-$nonce'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        echo \Kios\MerchantOperations\DesignPublicationView::render($state,$brand,$menu,$_SESSION['_merchant_csrf'],$nonce);exit;
    }
    if(isset($_GET['layout'])){
        if(Environment::get('KIOS_MERCHANT_LAYOUT_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
        foreach(['merchant','outlet'] as $field)if(!is_string($_GET[$field]??null))throw new InvalidArgumentException('Invalid layout scope.');
        $service=new BrandProfile($db,$provider);$brand=$service->load($_GET['merchant'],$_GET['outlet']);$layout=$service->layout($_GET['merchant'],$_GET['outlet']);
        $menu=(new RestaurantSetup($db,$provider))->load($_GET['merchant'],$_GET['outlet']);$nonce=bin2hex(random_bytes(16));
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-$nonce'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        echo \Kios\MerchantOperations\LayoutStudioView::render($brand,$layout,$menu,$_SESSION['_merchant_csrf'],$nonce);exit;
    }
    if(isset($_GET['brand'])||isset($_GET['brand_preview'])||isset($_GET['brand_image'])){
        if(Environment::get('KIOS_MERCHANT_BRANDING_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
        if(!is_string($_GET['merchant']??null)||!is_string($_GET['outlet']??null))throw new InvalidArgumentException('Invalid brand scope.');
        $service=new BrandProfile($db,$provider);
        if(isset($_GET['brand_image'])){
            if(!is_string($_GET['brand_image']))throw new InvalidArgumentException('Invalid image identifier.');
            $image=$service->image($_GET['merchant'],$_GET['outlet'],$_GET['brand_image']);
            header('Content-Type: image/png');header('Content-Disposition: inline; filename="'.$image['filename'].'"');
            header('Cross-Origin-Resource-Policy: same-origin');header('Content-Length: '.strlen($image['safe_png']));echo $image['safe_png'];exit;
        }
        $size=$_GET['size']??'desktop';if(!is_string($size)||!in_array($size,['desktop','tablet','mobile'],true))throw new InvalidArgumentException('Invalid preview size.');
        $state=$service->load($_GET['merchant'],$_GET['outlet']);$menu=(new RestaurantSetup($db,$provider))->load($_GET['merchant'],$_GET['outlet']);$nonce=bin2hex(random_bytes(16));
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-$nonce'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        echo BrandProfileView::render($state,$menu,$_SESSION['_merchant_csrf'],$nonce,$size,null,($_GET['saved']??'')==='1'?'Brand changes saved.':'',isset($_GET['brand_preview']));exit;
    }
    if(($_GET['restaurant']??'')==='1'){
        if(Environment::get('KIOS_MERCHANT_RESTAURANT_SETUP_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
        if(!is_string($_GET['merchant']??null)||!is_string($_GET['outlet']??null))throw new InvalidArgumentException('Invalid restaurant identifiers.');
        $section=$_GET['section']??'menu';if(!is_string($section)||!in_array($section,['menu','pricing','kitchen','staff','preview'],true))throw new InvalidArgumentException('Invalid setup section.');
        $state=(new RestaurantSetup($db,$provider))->load($_GET['merchant'],$_GET['outlet']);
        echo RestaurantSetupView::render($state,$_SESSION['_merchant_csrf'],$section,'',($_GET['saved']??'')==='1'?'Configuration saved.':'');exit;
    }
    if(($_GET['setup']??'')==='1'||($_GET['setup-review']??'')==='1'){
        if(Environment::get('KIOS_MERCHANT_OWNER_SETUP_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
        if(!is_string($_GET['merchant']??null))throw new InvalidArgumentException('Invalid setup identifier.');
        $review=($_GET['setup-review']??'')==='1';$state=(new OwnerOnboarding($db,$provider))->load($_GET['merchant'],$review);
        echo OwnerSetupView::render($state,$_SESSION['_merchant_csrf'],$review,'',($_GET['notice']??'')==='saved'?'Your changes were saved.':'');exit;
    }
    if(($_GET['onboarding']??'')==='1'){
        if(Environment::get('KIOS_MERCHANT_PROVISIONING_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
        $provisioning=new MerchantProvisioner($db,$provider);$provisioning->assertAdministrator();
        if(\Kios\MerchantOperations\PlatformAccess::enabled()){if(isset($_GET['receipt'])){if(!is_string($_GET['receipt']))throw new InvalidArgumentException('Invalid merchant.');header('Location: /merchant.php?platform=detail&merchant='.rawurlencode($_GET['receipt']),true,303);}else header('Location: /merchant.php?platform=create',true,303);exit;}
        if(isset($_GET['receipt'])&&!is_string($_GET['receipt']))throw new InvalidArgumentException('Invalid confirmation identifier.');
        $receipt=isset($_GET['receipt'])?$provisioning->receipt($_GET['receipt']):null;
        echo MerchantProvisioningView::render($_SESSION['_merchant_csrf'],$receipt);exit;
    }
    $user=$provider->currentUserId();
    if($user!==null&&Environment::get('KIOS_MERCHANT_OWNER_SETUP_ENABLED','0')==='1'){
        $home=(new OwnerOnboarding($db,$provider))->home();
        if($home!==null){echo \Kios\MerchantOperations\CloudDemo::dashboard($db,$provider,$home,$_SESSION['_merchant_csrf']);exit;}
    }
    if($user!==null&&Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')==='1'){
        $homes=(new \Kios\MerchantOperations\StaffPos($db,$provider))->homes();
        if(count($homes)===1){$home=$homes[0];header('Location: /merchant.php?'.($home['role_code']==='kitchen'?'service-kitchen':'pos').'=1&merchant='.rawurlencode($home['merchant_id']).'&outlet='.rawurlencode($home['outlet_id']),true,303);exit;}
        if(count($homes)>1){echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Choose outlet · KIOS</title><link rel="stylesheet" href="/assets/cloud-demo.css"><body><main><h1>Choose authorized outlet</h1>';foreach($homes as $home)echo '<p><a href="/merchant.php?'.($home['role_code']==='kitchen'?'service-kitchen':'pos').'=1&amp;merchant='.eh($home['merchant_id']).'&amp;outlet='.eh($home['outlet_id']).'">'.eh($home['name']).'</a></p>';echo '</main></body></html>';exit;}
    }
    if($user!==null&&Environment::get('KIOS_MERCHANT_SEATING_ENABLED','0')==='1'){
        $seatingHome=(new \Kios\MerchantOperations\Seating($db,$provider))->home();
        if($seatingHome!==null){header('Location: /merchant.php?seating=1&merchant='.rawurlencode($seatingHome['merchant_id']).'&outlet='.rawurlencode($seatingHome['outlet_id']),true,303);exit;}
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/cloud-demo.css"><title>KIOS private merchant demonstration</title><h1>KIOS Merchant</h1><p>Private synthetic demonstration · Customer ordering closed</p>';
    $pending=false;
    if ($user===null && is_int($_SESSION['_merchant_login_outlet']??null)) {
        $q=$db->prepare('SELECT outlet_code FROM outlets WHERE outlet_id=?');
        $q->execute([$_SESSION['_merchant_login_outlet']]);$code=$q->fetchColumn();
        if (is_string($code)) {
            $native=(new StaffAccountService($db,$code,$rateKey))->identity($_SESSION);
            $pending=$native!==null && (int)$native['must_change']===1;
        }
    }
    if ($pending) {
        echo '<h2>Change password required</h2><form method="post"><input type="hidden" name="action" value="change-password">';
        echo '<input type="hidden" name="_csrf" value="'.eh($_SESSION['_merchant_csrf']).'">';
        foreach(['current_password','new_password','confirm_password'] as $field)echo '<label>'.eh($field).'<input type="password" name="'.eh($field).'" required></label>';
        echo '<button>Change password</button></form>';
    } elseif ($user===null) {
        echo '<form method="post"><input type="hidden" name="_csrf" value="'.eh($_SESSION['_merchant_csrf']).'"><input type="hidden" name="action" value="login">';
        echo '<label>Outlet number <input name="login_outlet" required></label><label>Username <input name="username" autocomplete="username" required></label>';
        echo '<label>Password <input name="password" type="password" autocomplete="current-password" required></label><button>Sign in</button></form>';
    } else {
        if(\Kios\MerchantOperations\Entitlements::enabled()){try{(new \Kios\MerchantOperations\Entitlements($db,$provider))->platform();echo '<p><a href="/merchant.php?plans=1">Platform Plan Manager</a></p>';if(\Kios\MerchantOperations\PlatformAccess::enabled())echo '<p><a href="/merchant.php?platform=overview">Platform administration</a></p>';}catch(DomainException){}}
        echo '<h2>Authorized dashboard</h2><p>Authenticated operator '.eh($user).'</p><form method="post">';
        echo '<input type="hidden" name="_csrf" value="'.eh($_SESSION['_merchant_csrf']).'"><input type="hidden" name="action" value="logout"><button>Sign out</button></form>';
    }
} catch (DomainException $error) {
    ejson(403,['error'=>'Operation denied']);
} catch (InvalidArgumentException|ValueError|JsonException|TypeError $error) {
    ejson(400,['error'=>'Invalid request']);
} catch (Throwable $error) {
    ejson(503,['error'=>'Service unavailable']);
}
