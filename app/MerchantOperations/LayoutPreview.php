<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
final class LayoutPreview
{
    private static function pieces(string $html):array{
        preg_match('/<section[^>]*>/',$html,$start);preg_match('/<header\b.*?<\/header>/s',$html,$header);
        preg_match('/<nav\b.*?<\/nav>/s',$html,$nav);preg_match('/<footer\b.*?<\/footer>/s',$html,$footer);
        if(!$start||!$header||!$nav||!$footer)throw new \LogicException('Approved template structure unavailable.');
        $offset=strpos($html,$nav[0])+strlen($nav[0]);$body=substr($html,$offset,strrpos($html,$footer[0])-$offset);
        return [$start[0],$header[0],$nav[0],$body,$footer[0]];
    }
    public static function render(array $brand,array $layout,array $menu,string $size):string{
        $layout=LayoutConfiguration::validate($layout);$brand['config']=array_replace($brand['config'],$layout['presentation']);
        $logo=$brand['logo']?BrandProfileView::imageUrl($brand,$brand['logo']):null;
        [$start,$header,$nav,$body,$footer]=self::pieces(BrandTemplates::render($brand['config'],$logo,$menu['preview'],$menu['categories'],$size));
        $featured=self::pieces(BrandTemplates::render($brand['config'],$logo,array_slice($menu['preview'],0,2),$menu['categories'],$size))[3];
        $featured=str_replace(['id="category-','href="#category-'],['id="featured-category-','href="#featured-category-'],$featured);
        $parts=['header'=>$header,'business'=>'<aside class="layout-business"><strong>Welcome to '.htmlspecialchars($brand['config']['name'],ENT_QUOTES,'UTF-8').'</strong><p>Private menu preview · Ordering CLOSED</p></aside>','categories'=>$nav,'featured'=>'<section class="layout-featured"><h3>Featured products</h3>'.$featured.'</section>','catalogue'=>$body,'footer'=>$footer];
        $classes=' cards-'.$layout['cards'].' navigation-'.$layout['navigation'].' logo-'.$layout['logo'].' density-'.$layout['density'];
        $start=str_replace('class="','class="layout-preview'.$classes.' ',$start);$html=$start;
        foreach($layout['sections'] as $section)$html.='<div data-layout-section="'.$section.'">'.$parts[$section].'</div>';
        return $html.'</section>';
    }
}
