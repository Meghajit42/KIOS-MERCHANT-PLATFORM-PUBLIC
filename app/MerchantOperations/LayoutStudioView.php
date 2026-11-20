<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
final class LayoutStudioView
{
    private static function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    public static function render(array $brand,array $layout,array $menu,string $csrf,string $nonce):string{
        $h=static fn($v)=>self::h($v);$c=LayoutConfiguration::validate($layout['config']);
        $effective=$brand;$effective['config']=array_replace($brand['config'],$c['presentation']);
        $base=BrandProfileView::render($effective,$menu,$csrf,$nonce,'desktop',null,'',true);
        $old=BrandTemplates::render($effective['config'],$brand['logo']?BrandProfileView::imageUrl($brand,$brand['logo']):null,$menu['preview'],$menu['categories'],'desktop');
        $base=str_replace($old,LayoutPreview::render($brand,$c,$menu,'desktop'),$base);
        $form='<form method="post" id="layout-form" class="studio-panel"><h2>Customize your layout</h2>';
        foreach(['_csrf'=>$csrf,'action'=>'layout-save','merchant'=>$brand['merchant'],'outlet'=>$brand['outlet'],'version'=>$layout['version'],'request'=>bin2hex(random_bytes(16))] as $k=>$v)$form.='<input type="hidden" name="'.$k.'" value="'.$h($v).'">';
        foreach(['cards'=>['compact'=>'Compact cards','image'=>'Image-focused cards'],'navigation'=>['pills'=>'Category buttons','list'=>'Category list'],'logo'=>['left'=>'Logo on the left','center'=>'Centered logo'],'density'=>['comfortable'=>'Comfortable spacing','compact'=>'Compact spacing']] as $k=>$opts){$form.='<label>'.ucfirst($k).'<select name="'.$k.'">';foreach($opts as $v=>$name)$form.='<option value="'.$v.'"'.($c[$k]===$v?' selected':'').'>'.$name.'</option>';$form.='</select></label>';}
        foreach(['template'=>['modern'=>'Modern Restaurant','counter'=>'Compact Counter Service'],'font'=>['system'=>'System sans serif','serif'=>'Classic serif']] as $k=>$options){$form.='<label>'.ucfirst($k).'<select name="presentation['.$k.']">';foreach($options as $v=>$label)$form.='<option value="'.$v.'"'.($c['presentation'][$k]===$v?' selected':'').'>'.$label.'</option>';$form.='</select></label>';}
        foreach(['primary','secondary','accent'] as $k)$form.='<label>'.ucfirst($k).' colour<input type="color" name="presentation['.$k.']" value="'.$h($c['presentation'][$k]).'"></label>';
        $form.='<h3>Section order</h3><ol id="layout-sections">';
        foreach($c['sections'] as $section)$form.='<li><input type="hidden" name="sections[]" value="'.$section.'"><span>'.ucfirst($section).'</span><button type="button" data-move="up" aria-label="Move '.$section.' up">↑</button><button type="button" data-move="down" aria-label="Move '.$section.' down">↓</button></li>';
        $form.='</ol><p id="draft-status" role="status">Saved draft</p><button>Save Draft</button><button name="action" value="layout-defaults" class="secondary">Restore Defaults</button><p><a href="/merchant-dev.php?brand=1&amp;merchant='.$h($brand['merchant']).'&amp;outlet='.$h($brand['outlet']).'">Brand colours, typography, logo & template</a></p></form>';
        $form=preg_replace('/<p id="draft-status".*?<\/p><button>Save Draft<\/button><button name="action" value="layout-defaults" class="secondary">Restore Defaults<\/button>/s','',$form);
        $toolbar='<div class="layout-toolbar" role="region" aria-label="Draft controls"><button form="layout-form" data-layout-action="layout-save">Save Draft</button><button form="layout-form" data-layout-action="layout-defaults" class="secondary">Restore Defaults</button><span id="draft-status" role="status">Saved draft</span><div class="layout-devices">';
        foreach(['desktop'=>'Desktop','tablet'=>'Tablet','mobile'=>'Mobile'] as $device=>$label)$toolbar.='<button type="button" data-device="'.$device.'" aria-pressed="'.($device==='desktop'?'true':'false').'">'.$label.'</button>';
        $toolbar.='</div></div>';
        $base=str_replace('<section class="preview-workspace">',$toolbar.'<div class="layout-editor">'.$form.'<section class="preview-workspace">',$base);
        $base=str_replace('<footer class="studio-footer">','</div><footer class="studio-footer">',$base);
        return str_replace('</head>','<link rel="stylesheet" href="/assets/layout-studio.css"><script src="/assets/layout-studio.js" defer></script></head>',$base);
    }
}
