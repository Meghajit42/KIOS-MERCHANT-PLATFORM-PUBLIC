<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Kios\Application\{GopalMenuV1,DemoCatalog,GopalMenuImageCatalog};
use Kios\Domain\{Money,ProductVariant,QuantityUnit};
$n=0;$fail=0;function check(bool $v,string $name):void{global$n,$fail;$n++;echo($v?'PASS ':'FAIL ').$name.PHP_EOL;if(!$v)$fail++;}
GopalMenuV1::assertIntegrity();$rows=GopalMenuV1::boardItems();$products=DemoCatalog::products();$cats=DemoCatalog::categories();
check(count($rows)===105&&count($products)===105,'105 synthetic products');check(count($cats)===13,'13 numbered categories');check(count(array_unique(array_column($rows,'sku')))===105,'Unique synthetic IDs');
$all=true;foreach($rows as$i=>$r){$all=$all&&$r['name']===sprintf('Synthetic Item %03d',$i+1)&&$r['variants'][0]['price_paise']===1237+($i+1)*137;}check($all,'Independent deterministic names and price formula');
check(count(DemoCatalog::productsForCategory('synthetic-category-01'))===9,'First category count');check(count(DemoCatalog::productsForCategory('synthetic-category-13'))===8,'Last category count');check(DemoCatalog::productsForCategory('foreign-category')===[],'Unknown category empty');
$p=DemoCatalog::findProduct('synthetic-item-001');check($p!==null&&$p->name==='Synthetic Item 001','Product lookup');check(DemoCatalog::findProduct('foreign-product')===null,'Unknown product denied');check(DemoCatalog::findCategory('synthetic-category-01')!==null&&DemoCatalog::findCategory('foreign-category')===null,'Scoped category lookup');
$v=$p->variant('default');check($v!==null&&$v->unit===QuantityUnit::Portion&&$v->quantityValue===1,'Serving shape');check($p->variant('foreign-serving')===null,'Unknown serving rejected');check($v->unitPrice->multiply(3)->paise()===4122,'Integer minor-unit multiplication');check($v->unitPrice->add(Money::fromPaise(5))->paise()===1379,'Integer minor-unit addition');
check($p->imageReference==='/assets/brand-product-placeholder.svg','Generic image reference');check(is_file(dirname(__DIR__).'/public'.$p->imageReference),'Included placeholder exists');$html=GopalMenuImageCatalog::render($p);check(str_contains($html,'brand-product-placeholder.svg')&&!str_contains($html,'<script'),'Image adapter uses generic fallback');
check(str_contains(file_get_contents(dirname(__DIR__).'/.env.example'),'KIOS_CUSTOMER_ORDER_INTENTS_ENABLED=0'),'Order intents default disabled');
try{new ProductVariant('invalid','Invalid',QuantityUnit::Portion,0,Money::fromPaise(10));check(false,'Invalid serving rejected');}catch(InvalidArgumentException){check(true,'Invalid serving rejected');}
echo "RESULT: $n checks, $fail failures".PHP_EOL;exit($fail?1:0);
