<?php
declare(strict_types=1);
namespace Kios\Infrastructure;
use PDO;
final class ProductImageHistory {
 public function __construct(private PDO $db){}
 public function product(int $id):array {$q=$this->db->prepare('SELECT p.* FROM products p JOIN outlets o ON o.outlet_id=p.outlet_id WHERE p.product_id=? AND o.outlet_code=?');$q->execute([$id,'demo-outlet']);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new \DomainException('Product not found.');return $p;}
 public function previous(int $id):?array {$p=$this->product($id);$q=$this->db->prepare("SELECT before_summary FROM menu_admin_events WHERE outlet_id=? AND entity_type='product' AND entity_id=? AND action='owner_image_replaced' ORDER BY event_id DESC LIMIT 1");$q->execute([$p['outlet_id'],(string)$id]);$s=$q->fetchColumn();return is_string($s)?json_decode($s,true,512,JSON_THROW_ON_ERROR):null;}
 public function replace(int $id,int $version,array $image,bool $restore=false):void {
  $this->db->beginTransaction();try{$p=$this->product($id);if((int)$p['version']!==$version)throw new \DomainException('Product changed in another tab. Reload before saving.');
   if($restore){$image=$this->previous($id);if(!$image)throw new \DomainException('No previous photograph is available.');}
   $ref=$image['image_ref']??null;if(!$restore&&(!is_string($ref)||!preg_match('~^/assets/menu-managed/'.preg_quote($p['sku'],'~').'/[a-f0-9]{64}$~D',$ref)))throw new \DomainException('Invalid image mapping.');
   $before=['image_ref'=>$p['image_ref'],'image_width'=>$p['image_width'],'image_height'=>$p['image_height']];$after=['image_ref'=>$ref,'image_width'=>$image['image_width']??null,'image_height'=>$image['image_height']??null];
   $q=$this->db->prepare('UPDATE products SET image_ref=?,image_width=?,image_height=?,version=version+1 WHERE product_id=? AND outlet_id=? AND version=?');$q->execute([$ref,$after['image_width'],$after['image_height'],$id,$p['outlet_id'],$version]);if($q->rowCount()!==1)throw new \DomainException('Product changed in another tab. Reload before saving.');
   $q=$this->db->prepare("INSERT INTO menu_admin_events(outlet_id,action,entity_type,entity_id,before_summary,after_summary,actor_ref) VALUES(?,?,'product',?,?,?,'owner:menu-admin')");$q->execute([$p['outlet_id'],$restore?'owner_image_restored':'owner_image_replaced',(string)$id,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);$this->db->commit();
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
