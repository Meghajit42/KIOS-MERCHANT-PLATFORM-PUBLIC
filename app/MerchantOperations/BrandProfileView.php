<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
final class BrandProfileView
{
    private static function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    public static function imageUrl(array $s,string $asset):string{return '/merchant-dev.php?brand_image='.rawurlencode($asset).'&merchant='.rawurlencode($s['merchant']).'&outlet='.rawurlencode($s['outlet']);}
    private static function controls(array $s,string $csrf,string $op):string{
        $html='';foreach(['_csrf'=>$csrf,'_brand_ui'=>'1','action'=>'brand-'.$op,'merchant'=>$s['merchant'],'outlet'=>$s['outlet'],'version'=>$s['version'],'request'=>bin2hex(random_bytes(16))] as $k=>$v)$html.='<input type="hidden" name="'.$k.'" value="'.self::h($v).'">';return $html;
    }
    public static function render(array $s,array $menu,string $csrf,string $nonce,string $size='desktop',?array $draft=null,string $message='',bool $previewOnly=false):string{
        $h=static fn($v)=>self::h($v);$c=BrandProfile::configuration($draft??$s['config']);$font=$c['font']==='serif'?'Georgia,serif':'system-ui,sans-serif';
        $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Brand studio · KIOS</title><link rel="stylesheet" href="/assets/brand-studio.css"><style nonce="'.$h($nonce).'">.brand-preview{--brand-primary:'.$c['primary'].';--brand-secondary:'.$c['secondary'].';--brand-accent:'.$c['accent'].';--brand-font:'.$font.'}</style></head><body><main data-brand-studio data-version="'.$s['version'].'"><header class="studio-header"><div><p class="eyebrow">KIOS · MERCHANT CONFIGURATION</p><h1>Make it yours</h1><p>Your restaurant identity, on two trusted templates.</p></div><form method="post"><input type="hidden" name="_csrf" value="'.$h($csrf).'"><input type="hidden" name="action" value="logout"><button>Sign out</button></form></header><aside class="studio-notice"><strong>Private preview · Ordering CLOSED</strong><p>Branding does not approve legal details, open your shop or publish your menu.</p></aside><nav class="studio-nav"><a href="/merchant-dev.php?restaurant=1&amp;merchant='.$h($s['merchant']).'&amp;outlet='.$h($s['outlet']).'">Restaurant setup</a><a href="/merchant-dev.php?brand=1&amp;merchant='.$h($s['merchant']).'&amp;outlet='.$h($s['outlet']).'">Brand studio</a></nav>';
        if($message!=='')$html.='<p class="studio-message" role="status">'.$h($message).'</p>';
        if($draft!==null)$html.='<p class="studio-message">Unsaved preview — your saved brand has not changed.</p>';
        if(!$previewOnly){$html.='<div class="studio-grid"><section class="studio-panel"><h2>Identity & template</h2><form method="post" id="brand-settings">'.self::controls($s,$csrf,'save').'<label>Restaurant display name<input name="name" maxlength="100" required value="'.$h($c['name']).'"></label><div class="colour-fields">';
            foreach(['primary'=>'Primary','secondary'=>'Secondary','accent'=>'Accent'] as $key=>$label)$html.='<label>'.$label.'<input name="'.$key.'" type="color" value="'.$h($c[$key]).'" required></label>';
            $html.='</div><p class="field-help">Use a dark primary and accent with a light secondary. Saved designs require 4.5:1 text contrast.</p>';
            foreach(['font'=>['system'=>'System sans serif','serif'=>'Classic serif'],'template'=>['modern'=>'Modern Restaurant','counter'=>'Compact Counter Service']] as $key=>$choices){$html.='<label>'.ucfirst($key).'<select name="'.$key.'">';foreach($choices as $v=>$label)$html.='<option value="'.$v.'"'.($c[$key]===$v?' selected':'').'>'.$label.'</option>';$html.='</select></label>';}
            $html.='<div class="studio-actions"><button>Save brand</button><button name="action" value="brand-preview" class="secondary">Preview without saving</button></div></form><form method="post" class="restore-defaults">'.self::controls($s,$csrf,'defaults').'<p>Restore the default colours, name, font and template; clear the current logo. Previous logos remain in history.</p><button class="secondary">Restore defaults</button></form></section><section class="studio-panel"><h2>Restaurant logo</h2><p>JPG, PNG or WebP · up to 1 MiB · 16–2048 px. Original uploads stay private; previews use a safe PNG up to 512 px, without stretching.</p>';
            if($s['logo'])$html.='<img class="logo-review" src="'.$h(self::imageUrl($s,$s['logo'])).'" alt="Current restaurant logo"><p>Current logo</p>';
            else $html.='<div class="logo-empty">No logo selected</div>';
            $html.='<form method="post" enctype="multipart/form-data" id="logo-upload">'.self::controls($s,$csrf,'upload').'<label>Choose a logo<input type="file" name="logo" accept="image/jpeg,image/png,image/webp" required></label><button>Upload & preview</button></form><h3>Logo previews & history</h3><p>New uploads do not replace your logo until you confirm. Retained versions can be restored.</p><div class="logo-history">';
            foreach($s['assets'] as $a){$html.='<article data-logo="'.$h($a['asset_id']).'"><img class="logo-review" src="'.$h(self::imageUrl($s,$a['asset_id'])).'" alt="'.($a['state']==='pending'?'Unconfirmed logo preview':'Retained logo version').'"><p>'.$h($a['state']).' · '.$a['width'].' × '.$a['height'].' px</p><code>'.substr($h($a['safe_sha256']),0,12).'</code>';
                foreach($a['state']==='pending'?['confirm'=>'Confirm logo','cancel'=>'Cancel upload']:['restore'=>'Restore version'] as $op=>$label)$html.='<form method="post">'.self::controls($s,$csrf,$op).'<input type="hidden" name="asset" value="'.$h($a['asset_id']).'"><button'.($op==='cancel'?' class="secondary"':'').'>'.$label.'</button></form>';
                $html.='</article>';
            }$html.='</div></section></div>';
        }
        $base='/merchant-dev.php?brand_preview=1&merchant='.rawurlencode($s['merchant']).'&outlet='.rawurlencode($s['outlet']);
        $html.='<section class="preview-workspace"><div class="preview-toolbar"><h2>Customer menu preview</h2><nav aria-label="Preview sizes">';
        foreach(['desktop'=>'Desktop','tablet'=>'Tablet','mobile'=>'Mobile'] as $value=>$label)$html.='<a href="'.$h($base.'&size='.$value).'"'.($size===$value?' aria-current="page"':'').'>'.$label.'</a>';
        $html.='</nav></div>'.BrandTemplates::render($c,$s['logo']?self::imageUrl($s,$s['logo']):null,$menu['preview'],$menu['categories'],$size).'</section><footer class="studio-footer">Internal synthetic development · No public ordering · No physical printing</footer></main></body></html>';
        return $html;
    }
}

