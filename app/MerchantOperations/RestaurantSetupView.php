<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use Kios\Domain\{Money,PreparationStation};
final class RestaurantSetupView
{
    private static function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    private static function hidden(array $data):string{$s='';foreach($data as $k=>$v)$s.='<input type="hidden" name="'.self::h($k).'" value="'.self::h($v).'">';return $s;}
    private static function start(array $s,string $csrf,string $op,string $id=''):string{return '<form method="post"'.($id!==''?' id="'.self::h($id).'"':'').'>'.self::hidden(['_csrf'=>$csrf,'_restaurant_ui'=>'1','action'=>'restaurant-'.$op,'merchant'=>$s['merchant'],'outlet'=>$s['outlet'],'version'=>$s['version'],'request'=>bin2hex(random_bytes(16))]);}
    private static function input(string $label,string $key,mixed $value='',bool $required=true,string $type='text'):string{return '<label>'.self::h($label).'<input name="'.self::h($key).'" type="'.$type.'" value="'.self::h($value).'"'.($required?' required':'').' maxlength="1000"></label>';}
    private static function select(string $label,string $key,mixed $value,array $options):string{$s='<label>'.self::h($label).'<select name="'.self::h($key).'">';foreach($options as $k=>$v)$s.='<option value="'.self::h($k).'"'.((string)$k===(string)$value?' selected':'').'>'.self::h($v).'</option>';return $s.'</select></label>';}
    public static function render(array $s,string $csrf,string $section='menu',string $error='',string $notice=''):string
    {
        $h=static fn($v)=>self::h($v);$prefix='/merchant-dev.php?restaurant=1&merchant='.rawurlencode($s['merchant']).'&outlet='.rawurlencode($s['outlet']);
        $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Restaurant setup · KIOS</title><link rel="stylesheet" href="/assets/merchant-setup.css"><link rel="stylesheet" href="/assets/restaurant-setup.css"></head><body><main data-restaurant data-merchant="'.$h($s['merchant']).'" data-outlet="'.$h($s['outlet']).'" data-version="'.$s['version'].'"><header><div><p class="eyebrow">KIOS · Merchant configuration</p><h1>Restaurant setup</h1><p>Your menu, kitchen and staff. Internal synthetic testing only.</p></div><form method="post">'.self::hidden(['_csrf'=>$csrf,'action'=>'logout']).'<button class="secondary">Sign out</button></form></header><section class="status-panel"><strong>Ordering CLOSED · Preview only</strong><p>Completing configuration does not authorize commerce. Legal verification: '.$h($s['legal_verification']).'. Profile review: '.$h($s['profile_progress']).'.</p><ul class="progress-checks">';
        foreach($s['progress'] as $key=>$complete)$html.='<li data-completion="'.$h($key).'">'.ucfirst($key).': '.($complete?'Configured':'To configure').'</li>';
        $html.='</ul></section><nav aria-label="Restaurant setup sections">';
        foreach(['menu'=>'Menu Setup','pricing'=>'Pricing','kitchen'=>'Kitchen Setup','staff'=>'Staff Setup','preview'=>'Preview'] as $key=>$label)$html.='<a href="'.$h($prefix.'&section='.$key).'"'.($section===$key?' aria-current="page"':'').'>'.$label.'</a>';
        $html.='<a href="/merchant-dev.php?setup=1&merchant='.$h($s['merchant']).'">Business profile</a>';
        if(\Kios\Infrastructure\Environment::get('KIOS_MERCHANT_BRANDING_ENABLED','0')==='1')$html.='<a href="/merchant-dev.php?brand=1&amp;merchant='.$h($s['merchant']).'&amp;outlet='.$h($s['outlet']).'">Brand studio</a>';
        $html.='</nav>';
        if($error!=='')$html.='<p class="message error" role="alert">'.$h($error).'</p>';if($notice!=='')$html.='<p class="message" role="status">'.$h($notice).'</p>';
        $categories=array_column($s['categories'],'name','category_id');$stations=[];foreach($s['stations'] as $station)if((int)$station['active']===1)$stations[$station['station_id']]=$station['name'];
        if($section==='menu'){
            $html.='<div class="setup-grid"><section class="panel"><h2>Categories</h2><p>Use sort order to arrange categories.</p>';
            foreach($s['categories'] as $c)$html.='<details data-category="'.$c['category_id'].'"><summary>'.$h($c['name']).' · order '.$c['sort_order'].'</summary>'.self::start($s,$csrf,'category').self::hidden(['id'=>$c['category_id']]).self::input('Category name','name',$c['name']).self::input('Sort order','sort',$c['sort_order']).'<button>Save category</button></form></details>';
            $html.='<h3>Add category</h3>'.self::start($s,$csrf,'category','category-form').self::hidden(['id'=>0]).self::input('Category name','name').self::input('Sort order','sort',0).'<button>Add category</button></form></section><section class="panel"><h2>Products</h2><p>Configure a category and active kitchen station first. New products use an identified generic placeholder; no photograph is claimed.</p>';
            foreach($s['products'] as $p){$html.='<details data-product="'.$p['product_id'].'"><summary>'.$h($p['name']).' · '.$h($p['sku']).' · '.((int)$p['active']?((int)$p['available']?'Available':'Unavailable'):'Archived').'</summary>';
                if((int)$p['active'])$html.=self::productForm($s,$csrf,$p,$categories,$stations);
                $html.='</details>';
            }
            if($categories&&$stations)$html.='<h3>Add product</h3>'.self::productForm($s,$csrf,null,$categories,$stations);
            $html.='</section></div>';
        }elseif($section==='pricing'){
            $html.='<section class="panel"><h2>Pricing and availability</h2><p>Prices are authoritative for future orders. Existing order snapshots are preserved.</p>';
            foreach($s['products'] as $p){$html.='<details data-product="'.$p['product_id'].'"><summary>'.$h($p['name']).' · '.((int)$p['available']?'Available':'Unavailable').'</summary><ul>';
                foreach($p['variants'] as $v)$html.='<li>'.$h($v['label']).' · '.$h(Money::fromPaise($v['price_paise'])->formatRupees()).'</li>';$html.='</ul>';
                if((int)$p['active']){$html.=self::start($s,$csrf,'availability').self::hidden(['id'=>$p['product_id']]).self::select('Availability','available',$p['available'],[1=>'AVAILABLE',0=>'UNAVAILABLE']).'<button>Save availability</button></form>'.self::start($s,$csrf,'archive').self::hidden(['id'=>$p['product_id']]).'<button class="secondary">Archive product</button></form><h3>Edit product and serving options</h3>'.self::productForm($s,$csrf,$p,$categories,$stations);}
                $html.='</details>';
            }$html.='</section>';
        }elseif($section==='kitchen'){
            $html.='<section class="panel"><h2>Kitchen stations</h2><p>Supported routing slots can have your own display names. Codes stay fixed for compatibility. Deactivated stations are excluded from the purchasable preview.</p>';
            foreach($s['stations'] as $station){$id=(int)$station['station_id'];$products=array_filter($s['products'],fn($p)=>(int)$p['station_id']===$id&&(int)$p['active']===1);$staff=array_filter($s['staff'],fn($person)=>in_array($id,$person['stations'],true));$html.='<details data-station="'.$id.'"><summary>'.$h($station['name']).' · '.((int)$station['active']?'Active':'Inactive').' · '.count($products).' assigned products · '.count($staff).' assigned staff</summary><p>Configuration and assignments</p><p>'.($staff?implode(', ',array_map(fn($person)=>$h($person['display_name']),$staff)):'No staff assigned').'</p>'.self::stationForm($s,$csrf,$station).'</details>';}
            $html.='<h3>Add station</h3>'.self::stationForm($s,$csrf,null).'</section>';
        }elseif($section==='staff'){
            $roles=['outlet_administrator'=>'Outlet Manager','cashier'=>'Cashier','kitchen'=>'Kitchen Operator'];
            $html.='<section class="panel"><h2>Staff access</h2><p>Staff belong to this outlet. Cashiers manage payment tasks; kitchen operators work at assigned stations; outlet managers configure their authorized outlet.</p><p>Merchant Owner: protected original owner. No ownership transfer, platform grant, financial closing grant or external invitation is available.</p>';
            foreach($s['staff'] as $staff){$html.='<details data-staff="'.$h($staff['user_id']).'"><summary>'.$h($staff['display_name']).' · '.$h($roles[$staff['role_code']]).' · '.$h($staff['status']).'</summary><p>Username: '.$h($staff['username']).'</p>';
                if($staff['status']==='pending')$html.='<p>Attended synthetic activation: the staff member chooses a password privately. No email/SMS is sent.</p>'.self::start($s,$csrf,'staff-activate').self::hidden(['user'=>$staff['user_id']]).self::input('New private password','password','',true,'password').self::input('Confirm private password','confirmation','',true,'password').'<button>Activate staff</button></form>';
                $html.=self::start($s,$csrf,'staff-access').self::hidden(['user'=>$staff['user_id']]).self::select('Outlet role','role',$staff['role_code'],$roles).self::select('Account access','enabled',$staff['status']==='active'?1:0,[0=>'Disabled',1=>'Enabled']).'<fieldset><legend>Kitchen stations — kitchen operators only</legend>';
                foreach($stations as $id=>$name)$html.='<label class="check"><input type="checkbox" name="stations[]" value="'.$id.'"'.(in_array((int)$id,$staff['stations'],true)?' checked':'').'>'.$h($name).'</label>';
                $html.='</fieldset><button>Save staff access</button></form></details>';
            }
            $html.='<h3>Add pending staff</h3>'.self::start($s,$csrf,'staff-create','staff-form').self::input('Username','username').self::input('Display name','name').self::select('Outlet role','role','cashier',$roles).'<button>Create pending staff</button></form></section>';
        }else{
            $html.='<section class="panel"><h2>Customer menu preview</h2><p>This uses the existing customer eligibility and pricing rules. It cannot place an order. Unavailable, archived and inactive-station products are excluded.</p><div class="preview-grid">';
            foreach($s['preview'] as $p){$html.='<article data-preview-product="'.$p['productId'].'"><h3>'.$h($p['name']).'</h3><p>'.$h($p['sku']).'</p><ul>';
                foreach($p['variants'] as $v)$html.='<li>'.$h($v['label']).' · '.$h(Money::fromPaise($v['pricePaise'])->formatRupees()).'</li>';
                $html.='</ul><span>Configured for preview · ordering closed</span></article>';
            }$html.='</div>'.(!$s['preview']?'<p>No eligible products yet.</p>':'').'</section>';
        }
        return $html.'<footer>Internal configuration · No public ordering · No physical printing</footer></main></body></html>';
    }
    private static function stationForm(array $s,string $csrf,?array $station):string{
        $options=[];foreach(PreparationStation::cases() as $slot)$options[$slot->value]=str_replace('_',' ',$slot->value);
        return self::start($s,$csrf,'station',$station===null?'station-form':'').self::hidden(['id'=>$station['station_id']??0]).self::select('Routing slot (fixed after creation)','code',$station['code']??'GENERAL_KITCHEN',$options).self::input('Station display name','name',$station['name']??'').self::select('Department','department',$station['department_code']??'GROUND_FLOOR',['GROUND_FLOOR'=>'Ground Floor','FIRST_FLOOR_KITCHEN'=>'First Floor']).self::select('Station status','active',$station['active']??1,[1=>'Active',0=>'Inactive']).'<button>Save kitchen station</button></form>';
    }
    private static function productForm(array $s,string $csrf,?array $p,array $categories,array $stations):string{
        $html=self::start($s,$csrf,'product',$p===null?'product-form':'').self::hidden(['id'=>$p['product_id']??0]).self::input('Product name','name',$p['name']??'').self::input('Description','description',$p['description']??'',false).self::input('Internal SKU — fixed after creation','sku',$p['sku']??'').self::select('Category','category',$p['category_id']??array_key_first($categories),$categories).self::select('Preparation station','station',$p['station_id']??array_key_first($stations),$stations).self::select('Availability','available',$p['available']??1,[1=>'AVAILABLE',0=>'UNAVAILABLE']);
        $variants=array_values(array_filter($p['variants']??[],fn($v)=>$v['active']));if(!$variants)$variants=[['id'=>0,'label'=>'Regular','unit'=>'portion','quantity_value'=>1,'price_paise'=>2000]];
        foreach($variants as $i=>$v)$html.='<fieldset><legend>Serving option '.($i+1).'</legend>'.self::hidden(['variants['.$i.'][id]'=>$v['id']]).self::input('Serving label','variants['.$i.'][label]',$v['label']).self::select('Unit','variants['.$i.'][unit]',$v['unit'],['piece'=>'Piece','gram'=>'Gram','plate'=>'Plate','portion'=>'Portion','count'=>'Count']).self::input('Serving quantity','variants['.$i.'][quantity]',$v['quantity_value']).self::input('Price (rupees)','variants['.$i.'][price]',number_format($v['price_paise']/100,2,'.','')).'</fieldset>';
        // Optional added variant is removed by the HTTP adapter when its label is blank.
        if(count($variants)<12){$i=count($variants);$html.='<fieldset><legend>Optional additional serving</legend>'.self::hidden(['variants['.$i.'][id]'=>0]).self::input('Serving label (leave blank to omit)','variants['.$i.'][label]','',false).self::select('Unit','variants['.$i.'][unit]','portion',['piece'=>'Piece','gram'=>'Gram','plate'=>'Plate','portion'=>'Portion','count'=>'Count']).self::input('Serving quantity','variants['.$i.'][quantity]',1,false).self::input('Price (rupees)','variants['.$i.'][price]','',false).'</fieldset>';}
        return $html.'<button>Save product and serving options</button></form>';
    }
}
