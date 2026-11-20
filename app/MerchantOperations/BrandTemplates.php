<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use Kios\Domain\Money;

/** Presentation only. Products/variants come from the existing authorized catalogue. */
final class BrandTemplates
{
    private static function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    public static function render(array $config,?string $logoUrl,array $catalogue,array $categories,string $size='desktop'):string{
        $config=BrandProfile::configuration($config);$size=in_array($size,['desktop','tablet','mobile'],true)?$size:'desktop';
        $h=static fn($v)=>self::h($v);$s='<section class="brand-preview viewport-'.$size.' template-'.$h($config['template']).'" data-brand-preview data-template="'.$h($config['template']).'" aria-label="Authenticated ordering preview"><header class="restaurant-brand">';
        if($logoUrl!==null)$s.='<img class="restaurant-logo" src="'.$h($logoUrl).'" alt="Restaurant logo" width="80" height="80">';
        $s.='<div><p class="brand-eyebrow">YOUR MENU, YOUR WAY</p><h2>'.$h($config['name']).'</h2><p>Preview only · Ordering CLOSED</p></div></header><nav class="brand-categories" aria-label="Menu categories">';
        foreach($categories as $c){if(!array_filter($catalogue,fn($p)=>$p['category']===$c['slug']))continue;$s.='<a href="#category-'.$h($c['category_id']).'">'.$h($c['name']).'</a>';}$s.='</nav>';
        foreach($categories as $c){$products=array_filter($catalogue,fn($p)=>$p['category']===$c['slug']);if(!$products)continue;$s.='<section id="category-'.$h($c['category_id']).'" class="brand-category"><h3>'.$h($c['name']).'</h3><div class="brand-products">';
            foreach($products as $p){$s.='<article class="brand-product" data-brand-product="'.$h($p['productId']).'"><div class="brand-photo"><img src="/assets/brand-product-placeholder.svg" alt="Product photograph not supplied" loading="lazy" width="320" height="240"></div><div class="brand-product-info"><h4>'.$h($p['name']).'</h4><ul>';
                foreach($p['variants'] as $v)$s.='<li>'.$h($v['label']).' · '.$h($v['quantityValue']).' '.$h($v['unit']).'<strong>'.$h(Money::fromPaise($v['pricePaise'])->formatRupees()).'</strong></li>';
                $s.='</ul><div class="brand-quantity"><button type="button" disabled aria-label="Decrease quantity; preview only">−</button><span>Quantity 1</span><button type="button" disabled aria-label="Increase quantity; preview only">+</button></div><button class="preview-select" disabled>Select · Preview only</button></div></article>';
            }$s.='</div></section>';
        }
        if(!$catalogue)$s.='<p class="brand-empty">Your available menu will appear here after restaurant setup.</p>';
        return $s.'<footer>No orders, payments or public access are enabled by this preview.</footer></section>';
    }
}
