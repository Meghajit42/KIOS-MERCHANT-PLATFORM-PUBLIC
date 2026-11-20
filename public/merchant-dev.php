<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\{Database,DatabaseConfiguration,Environment,StaffAccountService};
use Kios\Domain\{Money,OrderType,PreparationTaskStatus};
use Kios\MerchantOperations\{StaffSessionIdentity,TenantRestaurant,TenantOperations,TenantFinance,MerchantProvisioner,MerchantProvisioningView,OwnerOnboarding,OwnerSetupView,RestaurantSetup,RestaurantSetupView};
use Kios\MerchantOperations\{BrandProfile,BrandProfileView};

// This internal integration target fails closed before opening a DB or session.
if (Environment::get('KIOS_MERCHANT_DEV_ENABLED','0') !== '1'
    || Environment::get('APP_ENV','local') !== 'local'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)) {
    http_response_code(404);
    exit;
}
$bodyLimit=(Environment::get('KIOS_MERCHANT_BRANDING_ENABLED','0')==='1'&&($_POST['action']??'')==='brand-upload')?1572864:65536;
if ((int)($_SERVER['CONTENT_LENGTH']??0)>$bodyLimit) { http_response_code(413);exit; }
session_name('KIOSMERCHANTDEV');
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_set_cookie_params(['path'=>'/merchant-dev.php','httponly'=>true,'samesite'=>'Strict',
    'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
function eh(string $s):string {return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function ejson(int $status,array $payload):never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload,JSON_THROW_ON_ERROR);exit;
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
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method,['GET','POST'],true))ejson(405,['error'=>'Method denied']);
    if ($method==='POST') {
        $csrf=$_POST['_csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['_merchant_csrf'],$csrf)) {
            ejson(400,['error'=>'Reload and retry']);
        }
        $action=$_POST['action'] ?? '';
        if ($action==='login') {
            $outlet=eid('login_outlet');
            $q=$db->prepare('SELECT o.outlet_code FROM outlets o JOIN merchant_legacy_outlets l ON l.legacy_outlet_id=o.outlet_id WHERE o.outlet_id=? AND o.active=1');
            $q->execute([$outlet]);$code=$q->fetchColumn();
            if (!is_string($code))ejson(401,['error'=>'Sign-in rejected']);
            $accounts=new StaffAccountService($db,$code,$rateKey);
            $identity=$accounts->authenticate(is_string($_POST['username']??null)?$_POST['username']:'',
                $_POST['password']??null,$_SERVER['REMOTE_ADDR']);
            if ($identity===null)ejson(401,['error'=>'Sign-in rejected']);
            $q=$db->prepare('SELECT user_id FROM merchant_auth_links WHERE account_id=? AND legacy_outlet_id=? AND active=1');
            $q->execute([$identity['account_id'],$outlet]);
            if ($q->fetchColumn()===false)ejson(401,['error'=>'Sign-in rejected']);
            session_regenerate_id(true);$_SESSION=[];
            StaffAccountService::bind($_SESSION,$identity);
            $_SESSION['_merchant_login_outlet']=$outlet;
            $_SESSION['_merchant_csrf']=bin2hex(random_bytes(32));
            header('Location: /merchant-dev.php',true,303);exit;
        }
        if ($action==='logout') {
            $_SESSION=[];session_regenerate_id(true);
            $_SESSION['_merchant_csrf']=bin2hex(random_bytes(32));
            header('Location: /merchant-dev.php',true,303);exit;
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
            header('Location: /merchant-dev.php',true,303);exit;
        }
        if ($provider->currentUserId()===null)ejson(401,['error'=>'Authentication required']);
        if(in_array($action,['design-publish','design-restore'],true)){
            if(Environment::get('KIOS_MERCHANT_PUBLICATION_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            $keys=['_csrf','action','merchant','outlet','revision','request'];if($action==='design-restore')$keys[]='version_id';
            if(array_diff(array_keys($_POST),$keys)||array_diff($keys,array_keys($_POST))||$_FILES!==[])throw new InvalidArgumentException('Only current design controls are accepted.');
            foreach(['merchant','outlet','request'] as $key)if(!is_string($_POST[$key]))throw new InvalidArgumentException('Invalid design scope.');
            $restore=$action==='design-restore'?$_POST['version_id']:null;if($restore!==null&&!is_string($restore))throw new InvalidArgumentException('Invalid version.');
            (new BrandProfile($db,$provider))->publish($_POST['merchant'],$_POST['outlet'],eid('revision'),$_POST['request'],$restore);
            header('Location: /merchant-dev.php?design=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']),true,303);exit;
        }
        if(in_array($action,['layout-save','layout-defaults'],true)){
            if(Environment::get('KIOS_MERCHANT_LAYOUT_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            foreach(['merchant','outlet','request'] as $field)if(!is_string($_POST[$field]??null))throw new InvalidArgumentException('Invalid layout identifiers.');
            $service=new BrandProfile($db,$provider);
            $fields=array_diff_key($_POST,array_fill_keys(['_csrf','action','merchant','outlet','version','request'],true));
            $config=\Kios\MerchantOperations\LayoutConfiguration::validate($fields);
            if($action==='layout-defaults')$config=\Kios\MerchantOperations\LayoutConfiguration::inherited($service->load($_POST['merchant'],$_POST['outlet'])['config']);
            $service->layout($_POST['merchant'],$_POST['outlet'],eid('version'),$_POST['request'],$config);
            header('Location: /merchant-dev.php?layout=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).'&saved=1',true,303);exit;
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
                header('Location: /merchant-dev.php?brand=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).'&saved=1',true,303);exit;
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
                header('Location: /merchant-dev.php?restaurant=1&merchant='.rawurlencode($_POST['merchant']).'&outlet='.rawurlencode($_POST['outlet']).'&section='.$section.'&saved=1',true,303);exit;
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
                if($ui){header('Location: /merchant-dev.php?'.($review?'setup-review':'setup').'=1&merchant='.rawurlencode($_POST['merchant']).'&notice=saved',true,303);exit;}
                ejson(200,['ok'=>true,'data'=>$result]);
            }catch(DomainException|InvalidArgumentException $error){
                if(!$ui)throw $error;$state=$setup->load($_POST['merchant'],$review);
                http_response_code(400);header('Content-Type: text/html; charset=utf-8');echo OwnerSetupView::render($state,$_SESSION['_merchant_csrf'],$review,$error->getMessage());exit;
            }
        }
        if(is_string($action) && str_starts_with($action,'onboarding-')) {
            if(Environment::get('KIOS_MERCHANT_PROVISIONING_ENABLED','0')!=='1')ejson(404,['error'=>'Not available']);
            $provisioning=new MerchantProvisioner($db,$provider);$provisioning->assertAdministrator();
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
                if($ui){header('Location: /merchant-dev.php?onboarding=1&receipt='.rawurlencode($result['receipt']['merchant_id']),true,303);exit;}
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
        if(isset($_GET['receipt'])&&!is_string($_GET['receipt']))throw new InvalidArgumentException('Invalid confirmation identifier.');
        $receipt=isset($_GET['receipt'])?$provisioning->receipt($_GET['receipt']):null;
        echo MerchantProvisioningView::render($_SESSION['_merchant_csrf'],$receipt);exit;
    }
    $user=$provider->currentUserId();
    if($user!==null&&Environment::get('KIOS_MERCHANT_OWNER_SETUP_ENABLED','0')==='1'){
        $home=(new OwnerOnboarding($db,$provider))->home();
        if($home!==null){header('Location: /merchant-dev.php?setup=1&merchant='.rawurlencode($home['merchant_id']),true,303);exit;}
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Merchant development</title><h1>Merchant development</h1>';
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
