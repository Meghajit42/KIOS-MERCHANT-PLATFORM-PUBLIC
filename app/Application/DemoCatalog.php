<?php
declare(strict_types=1);
namespace Kios\Application;
use Kios\Domain\{Category,Product};
/** Independently generated public demonstration fixture adapter. */
final class DemoCatalog {
 public static function categories():array{return GopalMenuV1::categories();}
 public static function products():array{return GopalMenuV1::products();}
 public static function findProduct(string $id):?Product{foreach(self::products()as$p)if($p->id===$id)return $p;return null;}
 public static function findCategory(string $id):?Category{foreach(self::categories()as$c)if($c->id===$id)return $c;return null;}
 public static function productsForCategory(string $categoryId):array{return array_values(array_filter(self::products(),static fn(Product $p):bool=>$p->categoryId===$categoryId));}
}
