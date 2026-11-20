<?php
declare(strict_types=1);
namespace Kios\Application;
use Kios\Domain\{Category,Money,PreparationStation,Product,ProductVariant,QuantityUnit};
/** Public synthetic fixtures. Historical class name retained only for interface compatibility. */
final class GopalMenuV1 {
 public const VERSION='synthetic_public_v1';
 public static function stationMap():array {
  $stations=['SWEETS','CHAAT','NORTH_INDIAN','SOUTH_INDIAN','MOMO','TANDOOR','BEVERAGE'];$out=[];
  for($c=1;$c<=13;$c++)$out[sprintf('synthetic-category-%02d',$c)]=$stations[($c-1)%count($stations)];return $out;
 }
 public static function categories():array { $out=[];foreach(self::stationMap()as$slug=>$station)$out[]=new Category($slug,'Synthetic Category '.substr($slug,-2));return $out; }
 public static function boardItems():array {
  $out=[];$i=0;$group=0;foreach(self::stationMap()as$slug=>$station){$count=$group++===0?9:8;for($j=0;$j<$count;$j++){
   $i++;$out[]=['sku'=>sprintf('synthetic-item-%03d',$i),'name'=>sprintf('Synthetic Item %03d',$i),'category_slug'=>$slug,'station'=>$station,'image_slug'=>'brand-product-placeholder','variants'=>[['code'=>'default','label'=>'1 portion','unit'=>QuantityUnit::Portion->value,'quantity_value'=>1,'price_paise'=>1237+$i*137]]];
  }}return $out;
 }
 public static function products():array {
  $out=[];foreach(self::boardItems()as$r){$v=$r['variants'][0];$out[]=new Product($r['sku'],$r['name'],$r['category_slug'],'demo-outlet',PreparationStation::from($r['station']),true,true,true,[new ProductVariant($v['code'],$v['label'],QuantityUnit::Portion,1,Money::fromPaise($v['price_paise']))],'/assets/brand-product-placeholder.svg');}return $out;
 }
 public static function assertIntegrity():void {
  $rows=self::boardItems();if(count($rows)!==105||count(self::categories())!==13||count(array_unique(array_column($rows,'sku')))!==105)throw new \RuntimeException('Synthetic catalogue invariant failed.');
 }
}
