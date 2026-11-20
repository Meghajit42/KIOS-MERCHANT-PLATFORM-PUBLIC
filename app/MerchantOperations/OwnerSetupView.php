<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
final class OwnerSetupView
{
    private static function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    private static function hidden(array $fields):string{$out='';foreach($fields as $name=>$value)$out.='<input type="hidden" name="'.self::h($name).'" value="'.self::h($value).'">';return $out;}
    private static function controls(array $state,string $csrf,string $action):string{return self::hidden(['_csrf'=>$csrf,'_setup_ui'=>'1','action'=>$action,'merchant'=>$state['merchant_id'],'outlet'=>$state['merchant_outlet_id'],'version'=>$state['version'],'request'=>bin2hex(random_bytes(16))]);}
    private static function field(string $label,string $name,string $value,int $max,bool $required=true,bool $area=false):string
    {
        $h=static fn($v)=>self::h($v);$flags=' name="'.$h($name).'" maxlength="'.$max.'"'.($required?' required':'');
        return '<label>'.$h($label).($area?'<textarea'.$flags.' rows="3">'.$h($value).'</textarea>':'<input'.$flags.' value="'.$h($value).'">').'</label>';
    }
    private static function select(string $label,string $name,string $selected,array $options):string
    {
        $html='<label>'.self::h($label).'<select name="'.self::h($name).'">';foreach($options as $value=>$text)$html.='<option value="'.self::h($value).'"'.($value===$selected?' selected':'').'>'.self::h($text).'</option>';return $html.'</select></label>';
    }
    public static function render(array $s,string $csrf,bool $review=false,string $error='',string $notice=''):string
    {
        $h=static fn($v)=>self::h($v);$b=$s['business'];$o=$s['outlet'];$title=$review?'Review merchant setup':'Set up your business';
        $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.' · KIOS</title><link rel="stylesheet" href="/assets/merchant-setup.css"></head><body><main data-owner-setup data-merchant="'.$h($s['merchant_id']).'" data-outlet="'.$h($s['merchant_outlet_id']).'" data-progress="'.$h($s['progress']).'"><header><div><p class="eyebrow">KIOS · Merchant account</p><h1>'.$title.'</h1><p>Welcome, '.$h($s['owner_name']).'. Your changes are saved as setup drafts.</p></div><form method="post">'.self::hidden(['_csrf'=>$csrf,'action'=>'logout']).'<button class="secondary">Sign out</button></form></header>';
        $html.='<section class="status-panel"><div><strong data-progress-label>'.$h(str_replace('_',' ',$s['progress'])).'</strong><p>Business details '.((int)$s['business_saved']?'saved':'to complete').' · Outlet details '.((int)$s['outlet_saved']?'saved':'to complete').'</p></div><span class="badge">'.($s['ordering_open']?'Ordering open':'Ordering closed').'</span><p>Completing or approving setup does not open ordering. Registration details remain unverified.</p></section>';
        if($error!=='')$html.='<p class="message error" role="alert">'.$h($error).'</p>';
        if($notice!=='')$html.='<p class="message" role="status">'.$h($notice).'</p>';
        if($s['review_note']!=='')$html.='<section class="message"><strong>Last platform review</strong><p>'.$h($s['review_note']).'</p></section>';
        $html.='<nav aria-label="Setup sections"><a href="#business">1. Business</a><a href="#outlet">2. Outlet</a><a href="#finish">3. Submit</a>';
        if(!$review && \Kios\Infrastructure\Environment::get('KIOS_MERCHANT_RESTAURANT_SETUP_ENABLED','0')==='1')$html.='<a href="/merchant-dev.php?restaurant=1&amp;merchant='.$h($s['merchant_id']).'&amp;outlet='.$h($s['merchant_outlet_id']).'">Restaurant setup</a>';
        $html.='</nav><div class="setup-grid">';
        $editable=!$review&&in_array($s['progress'],['NOT_STARTED','IN_PROGRESS','SETUP_COMPLETE'],true);
        if($editable){
            $html.='<section id="business" class="panel"><h2>Business details</h2><p>These are editable drafts. Optional registration numbers are not legal verification.</p><form method="post" id="business-form">'.self::controls($s,$csrf,'setup-save-business');
            $html.=self::field('Business display name','business_name',$b['business_name'],200).self::select('Business category','category',$b['category'],['restaurant'=>'Restaurant','cafe'=>'Cafe']).self::field('Administrative email','admin_contact',$b['admin_contact'],254).self::field('Business description','description',$b['description'],1000,false,true).self::field('Operating location','location',$b['location'],500,true,true).self::field('Registration identifier — optional draft','registration_id',$b['registration_id'],100,false).self::select('Business operating intention','operating_status',$b['operating_status'],['planning'=>'Planning','operating'=>'Operating','temporarily_closed'=>'Temporarily closed']).'<button>Save business and continue</button></form></section>';
            $html.='<section id="outlet" class="panel"><h2>Outlet details</h2><p>Hours and opening preferences are drafts; they do not change the operating shop.</p><form method="post" id="outlet-form">'.self::controls($s,$csrf,'setup-save-outlet');
            $html.=self::field('Outlet display name','outlet_name',$o['outlet_name'],160).self::field('Outlet email or phone','contact',$o['contact'],120).self::field('Outlet address','address',$o['address'],500,true,true).'<fieldset><legend>Operating days</legend><div class="days">';
            foreach(['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'] as $day=>$label)$html.='<label><input type="checkbox" name="days[]" value="'.$day.'"'.(in_array($day,$o['days'],true)?' checked':'').'>'.$label.'</label>';
            $html.='</div></fieldset><div class="times"><label>Opening time (IST)<input type="time" name="opening_time" value="'.$h($o['opening_time']).'" required></label><label>Closing time (IST)<input type="time" name="closing_time" value="'.$h($o['closing_time']).'" required></label></div>'.self::select('Temporary preference — does not open ordering','temporary_preference',$o['temporary_preference'],['closed'=>'Prefer closed','prefer_open'=>'Prefer open after operational authorization']).'<button>Save outlet and continue</button></form></section>';
        }else{
            foreach(['business'=>['Business details',$b],'outlet'=>['Outlet details',$o]] as $id=>[$label,$fields]){
                $html.='<section id="'.$id.'" class="panel"><h2>'.$label.'</h2><dl>';foreach($fields as $key=>$value)$html.='<dt>'.$h(ucfirst(str_replace('_',' ',$key))).'</dt><dd>'.$h(is_array($value)?implode(', ',$value):$value).'</dd>';$html.='</dl></section>';
            }
        }
        $html.='</div><section id="finish" class="panel"><h2>Next step</h2>';
        if($review){
            if($s['progress']==='APPROVAL_PENDING')$html.='<p>Approve setup information only. This does not verify legal claims, grant permissions or open ordering.</p><form method="post" id="review-form">'.self::controls($s,$csrf,'setup-review').self::select('Decision','decision','APPROVED',['APPROVED'=>'Approve setup','REJECTED'=>'Return for correction']).self::field('Review note — at least eight characters for correction','note','',500,false,true).'<button>Save platform review</button></form>';
            else $html.='<p>This setup is not waiting for a decision.</p>';
        }elseif($s['progress']==='SETUP_COMPLETE')$html.='<p>Your details are complete. Submit them for platform review.</p><form method="post" id="submit-form">'.self::controls($s,$csrf,'setup-submit').'<button>Submit setup for review</button></form>';
        elseif(in_array($s['progress'],['APPROVAL_PENDING','APPROVED','REJECTED'],true))$html.='<p>'.($s['progress']==='APPROVAL_PENDING'?'Your setup is waiting for review.':($s['progress']==='APPROVED'?'Setup approved. Operational authorization remains separate.':'Please address the review feedback and submit again.')).'</p><form method="post" id="reopen-form">'.self::controls($s,$csrf,'setup-reopen').'<button class="secondary">Reopen setup to edit</button></form>';
        else $html.='<p>Save both sections before submitting. You can sign out and return later.</p>';
        return $html.'</section><footer>Internal synthetic development only · Public registration disabled · Legal verification: '.$h($s['legal_verification']).'</footer></main></body></html>';
    }
}
