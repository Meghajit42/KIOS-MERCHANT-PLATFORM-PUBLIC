<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';adminRequireLogin();
use Kios\Infrastructure\{Database,ProductImageHistory,MenuImageService,MenuRepository};
use Kios\Application\GopalMenuImageCatalog;
$history=new ProductImageHistory(Database::connect());$text=$_SERVER['REQUEST_METHOD']==='POST'?adminPost('product_id'):($_GET['product_id']??'');if(!is_string($text)||!ctype_digit($text)){http_response_code(404);exit('Product not found.');}$id=(int)$text;
try{$product=$history->product($id);}catch(DomainException){http_response_code(404);exit('Product not found.');}
foreach($_SESSION['_catalogue_image_previews']??[] as $key=>$entry)if(($entry['expires']??0)<time())unset($_SESSION['_catalogue_image_previews'][$key]);
$error='';$preview=null;
if($_SERVER['REQUEST_METHOD']==='POST')try{
 adminRequireCsrf();$action=adminPost('action');$v=adminPost('version');if(!ctype_digit($v))throw new DomainException('Invalid image version.');
 if($action==='preview'){
  if((int)$v!==(int)$product['version'])throw new DomainException('Product changed in another tab. Reload before uploading.');
  if(count($_SESSION['_catalogue_image_previews']??[])>=5)throw new DomainException('Cancel an existing preview or wait for it to expire.');
  $file=$_FILES['photograph']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null))throw new DomainException('Choose a JPG, PNG or WebP photograph up to 8 MB.');
  $info=@getimagesize($file['tmp_name']);if(!$info||$info[0]<320||$info[1]<240)throw new DomainException('Use a photograph at least 320 pixels wide and 240 pixels high.');
  $image=(new MenuImageService())->store($file['tmp_name'],$file['name'],$product['sku']);$key=bin2hex(random_bytes(24));$preview=['id'=>$id,'version'=>(int)$v,'image_ref'=>$image['reference'],'image_width'=>$image['width'],'image_height'=>$image['height'],'expires'=>time()+1800,'key'=>$key];$_SESSION['_catalogue_image_previews'][$key]=$preview;
 }elseif($action==='confirm'||$action==='cancel'){
  $key=adminPost('preview_key');$entry=$_SESSION['_catalogue_image_previews'][$key]??null;if(!is_array($entry)||$entry['id']!==$id||$entry['version']!==(int)$v)throw new DomainException('Preview expired or already used. Upload again.');
  if($action==='confirm')$history->replace($id,(int)$v,$entry);unset($_SESSION['_catalogue_image_previews'][$key]);adminFlash($action==='confirm'?'Product photograph saved. Merchant content approval remains your decision.':'Replacement cancelled; current image unchanged.');header('Location: /admin/catalogue-image.php?product_id='.$id,true,303);exit;
 }elseif($action==='restore'){$history->replace($id,(int)$v,[],true);adminFlash('Previous image mapping restored.');header('Location: /admin/catalogue-image.php?product_id='.$id,true,303);exit;}else throw new DomainException('Choose an available image action.');
}catch(Throwable $e){http_response_code($e instanceof DomainException?400:500);$error=adminFail($e);}
adminStart('Replace product image');echo '<link rel="stylesheet" href="/assets/catalogue-review.css"><h2>ID '.adminH($id).' — '.adminH($product['name']).'</h2><p><a href="/admin/catalogue-review.php?product_id='.$id.'">Back to owner decisions</a></p>';
if($error!=='')echo '<p role="alert">'.adminH($error).'</p>';
$domain=(new MenuRepository(Database::connect()))->findBySku(KIOS_ADMIN_OUTLET,$product['sku']);echo '<section class="admin-panel image-owner-panel"><h3>Current photograph</h3>';if($domain)echo GopalMenuImageCatalog::render($domain);echo '<p>Availability '.($product['available']?'ON':'OFF').' · Routing '.adminH($product['routing_status']).'. Image actions do not change these settings.</p>';
$q=Database::connect()->prepare('SELECT label,price_paise,unit FROM product_variants WHERE product_id=? ORDER BY display_order,variant_id');$q->execute([$id]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $v)echo '<p>'.adminH($v['label']).' · '.adminH($v['unit']).' · INR '.number_format($v['price_paise']/100,2).'</p>';echo '</section>';
$hidden='<input type="hidden" name="product_id" value="'.$id.'"><input type="hidden" name="version" value="'.adminH($product['version']).'">';
if($preview){echo '<section class="admin-panel image-owner-panel"><h3>Preview — not saved to product</h3><img src="'.adminH($preview['image_ref'].'/320.webp').'" alt="Replacement preview"><p>Confirm the dish, portion and product ID before saving. Original files are preserved.</p><form method="post">'.adminCsrfField().$hidden.'<input type="hidden" name="preview_key" value="'.adminH($preview['key']).'"><button class="admin-button" name="action" value="confirm">Confirm replacement</button><button class="admin-button" name="action" value="cancel">Cancel replacement</button></form></section>';}
else{echo '<form method="post" enctype="multipart/form-data" class="admin-panel">'.adminCsrfField().$hidden.'<input type="hidden" name="action" value="preview"><label>Replace Product Image<input type="file" name="photograph" accept="image/jpeg,image/png,image/webp" required></label><p>JPG, PNG or WebP; up to 8 MB, minimum 320 × 240; maximum 6000 × 6000 and 20 million pixels. Prefer 1200 × 900. Uploads are decoded and optimized; original files remain private.</p><button class="admin-button">Preview replacement</button></form>';if($history->previous($id))echo '<form method="post" class="admin-panel">'.adminCsrfField().$hidden.'<input type="hidden" name="action" value="restore"><p>Restore the photograph used before the most recent replacement. This is audited.</p><button class="admin-button">Restore previous photograph</button></form>';}
adminEnd();
