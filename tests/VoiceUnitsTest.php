<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use Kios\Application\CustomerMenuLabels;
use Kios\Domain\{Product,ProductVariant,QuantityUnit,Money,PreparationStation,Cart};
return [
 'voice pieces show unit and 1 2 3 trusted quantity totals' => static function(): void {
    $p=new Product('voice-samosa','Samosa','snacks','demo-outlet',PreparationStation::Sweets,true,true,true,[new ProductVariant('default','1 piece',QuantityUnit::Piece,1,Money::fromPaise(1000))]);
    if(CustomerMenuLabels::startingPrice($p)!=='₹10.00 / piece'||CustomerMenuLabels::quantityUnit($p,$p->variants()[0])!==['singular'=>'piece','plural'=>'pieces'])throw new RuntimeException('Piece labels mismatch.');
    foreach([1,2,3] as $n){$c=new Cart(fn($sku)=>$p);$c->add($p->id,'default',$n);if($c->total()->paise()!==1000*$n)throw new RuntimeException('Piece amount mismatch.');}
 },
 'voice kg price basis agrees with approved weighted portions' => static function(): void {
    foreach([['voice-jalebi','Jalebi','sweets',20000],['voice-mishti-doi','Mishti Doi','dahi',28000]] as [$id,$name,$cat,$basis]){
      $variants=[];foreach([100,250,500,1000] as $w)$variants[]=new ProductVariant('weight-'.$w,$w.' g',QuantityUnit::Gram,$w,Money::fromPaise(intdiv($basis*$w,1000)));
      $p=new Product($id,$name,$cat,'demo-outlet',PreparationStation::Sweets,true,true,true,$variants);
      if(CustomerMenuLabels::startingPrice($p)!==Money::fromPaise($basis)->formatRupees().' / kg')throw new RuntimeException('Kg price basis mismatch.');
      $cart=new Cart(fn($sku)=>$p);$cart->add($id,'weight-250',2);if($cart->total()->paise()!==intdiv($basis,2))throw new RuntimeException('Weighted amount mismatch.');
    }
 },
 'unit-priced voice items retain unit language' => static function(): void {
    $v=new ProductVariant('default','1 unit',QuantityUnit::Count,1,Money::fromPaise(5000));
    $p=new Product('voice-rabri','Rabri','sweets','demo-outlet',PreparationStation::Sweets,true,true,true,[$v]);
    if(CustomerMenuLabels::startingPrice($p)!=='₹50.00 / unit'||CustomerMenuLabels::quantityUnit($p,$v)['plural']!=='units')throw new RuntimeException('Unit labels mismatch.');
 },
];
