<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
final class DesignPublicationView
{
    private static function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    public static function render(array $state,array $brand,array $menu,string $csrf,string $nonce):string{
        $h=static fn($v)=>self::h($v);$snapshot=$state['snapshot'];$shown=$brand;
        if($snapshot){$shown['config']=array_replace($snapshot['config'],$snapshot['layout']['presentation']);$shown['logo']=$snapshot['logo'];}
        $html=BrandProfileView::render($shown,$menu,$csrf,$nonce,'desktop',null,'',true);
        if($snapshot){$old=BrandTemplates::render($shown['config'],$shown['logo']?BrandProfileView::imageUrl($brand,$shown['logo']):null,$menu['preview'],$menu['categories'],'desktop');$html=str_replace($old,LayoutPreview::render($shown,$snapshot['layout'],$menu,'desktop'),$html);}
        $controls='<section class="studio-panel" data-design-publication data-revision="'.$state['revision'].'" data-active="'.$h($state['active']??'').'"><h2>Design publication · Isolated preview only</h2><p>Publishing a design never opens ordering or registration.</p><p>Active version: <strong>'.$h($state['active']??'None published').'</strong></p><a href="/merchant-dev.php?layout=1&amp;merchant='.$h($brand['merchant']).'&amp;outlet='.$h($brand['outlet']).'">Customize and preview draft</a>';
        $form=static function(string $action,?string $version,string $label)use($state,$csrf,$h):string{$f='<form method="post">';foreach(['_csrf'=>$csrf,'action'=>$action,'merchant'=>$state['merchant'],'outlet'=>$state['outlet'],'revision'=>$state['revision'],'request'=>bin2hex(random_bytes(16))] as $k=>$v)$f.='<input type="hidden" name="'.$k.'" value="'.$h($v).'">';if($version!==null)$f.='<input type="hidden" name="version_id" value="'.$h($version).'">';return $f.'<button>'.$label.'</button></form>';};
        $controls.=$form('design-publish',null,'Publish saved draft');$controls.='<h3>Version history</h3><ul>';
        foreach($state['history'] as $v)$controls.='<li><a href="/merchant-dev.php?design=1&amp;merchant='.$h($brand['merchant']).'&amp;outlet='.$h($brand['outlet']).'&amp;version_id='.$h($v['version_id']).'">Preview version '.$h($v['version_id']).'</a> · '.gmdate('Y-m-d H:i:s',(int)$v['created_epoch']).' UTC'.($state['active']===$v['version_id']?' · ACTIVE':'').'</li>';
        $controls.='</ul>';
        if($state['selected'])$controls.='<p>Previewing selected version '.$h($state['selected']).'. Confirm below to make this version active.</p>'.$form('design-restore',$state['selected'],'Confirm restoration of selected version');
        else $controls.='<p>No published design exists. The menu below is a draft-only reference, not an active design.</p>';
        $html=str_replace('</head>','<link rel="stylesheet" href="/assets/layout-studio.css"></head>',$html);
        return str_replace('<section class="preview-workspace">',$controls.'</section><section class="preview-workspace">',$html);
    }
}
