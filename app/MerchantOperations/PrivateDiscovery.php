<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use PDO;
use Kios\Infrastructure\Environment;
use Kios\MerchantFoundation\AuthenticatedUserProvider;

/** Private scoped preview adapter; visibility metadata never confers tenant authority. */
final class PrivateDiscovery
{
    public function __construct(private PDO $db, private AuthenticatedUserProvider $auth) {}
    private function rows(string $sql,array $args):array {$s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function read(string $route,string $m,string $o,int $legacy,array $query):array
    {
        $scope=\Kios\MerchantFoundation\TenantContext::resolve($this->auth,new \Kios\MerchantFoundation\PdoIdentityStore($this->db),$m,$o);
        if(!$scope->allows(\Kios\MerchantFoundation\Permission::ReadOrders))throw new \DomainException();
        (new Entitlements($this->db,$this->auth))->requireFeature($m,$o,'menu','read');
        $mapping=$this->rows('SELECT legacy_outlet_id FROM merchant_legacy_outlets WHERE merchant_id=? AND merchant_outlet_id=?',[$m,$o]);
        if(!$mapping||(int)$mapping[0]['legacy_outlet_id']!==$legacy)throw new \DomainException();
        foreach(['category','cuisine'] as $key)if(isset($query[$key])&&!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$query[$key]))throw new \InvalidArgumentException();
        $sort=$query['sort']??'name';if(!in_array($sort,['name','name-desc'],true))throw new \InvalidArgumentException();
        // Explicit operator-owned synthetic fixture settings, default empty. Never accept visibility from a request.
        $settings=json_decode(Environment::get('KIOS_PRIVATE_DISCOVERY_SCOPES','[]'),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($settings)||!array_is_list($settings)||count($settings)>1000)throw new \RuntimeException();
        $profile=null;
        foreach($settings as $entry){
            if(!is_array($entry)||array_diff(array_keys($entry),['merchant','outlet','visible','categories','cuisines'])||!is_string($entry['merchant']??null)||!is_string($entry['outlet']??null)||!is_bool($entry['visible']??null))throw new \RuntimeException();
            foreach(['categories','cuisines']as $key){if(!is_array($entry[$key]??null)||!array_is_list($entry[$key])||count($entry[$key])>20)throw new \RuntimeException();foreach($entry[$key]as $v)if(!is_string($v)||!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$v))throw new \RuntimeException();}
            if($entry['merchant']===$m&&$entry['outlet']===$o){if($profile!==null)throw new \RuntimeException();$profile=$entry;}
        }
        if(!$profile||!$profile['visible']||!PlatformAccess::canStart($this->db,$m,$o))return [];
        if(isset($query['cuisine'])&&!in_array($query['cuisine'],$profile['cuisines'],true))return [];
        if($route==='restaurants'){
            if(isset($query['category'])&&!in_array($query['category'],$profile['categories'],true))return [];
            $rows=$this->rows('SELECT m.name merchant_name,o.name name FROM merchant_organizations m JOIN merchant_outlets o ON o.merchant_id=m.merchant_id WHERE m.merchant_id=? AND o.outlet_id=? AND m.active=1 AND o.active=1',[$m,$o]);
            return array_map(fn($v)=>['merchantId'=>$m,'outletId'=>$o,'name'=>$v['name'],'merchantName'=>$v['merchant_name'],'categories'=>$profile['categories'],'cuisines'=>$profile['cuisines'],'acceptingOrders'=>false,'orderingStatus'=>'CLOSED'],$rows);
        }
        $data=[];$native=new TenantRestaurant($this->db,$this->auth);
        foreach($this->rows('SELECT slug,name FROM categories WHERE outlet_id=? AND active=1 ORDER BY category_id',[$legacy])as $category){
            if(isset($query['category'])&&$category['slug']!==$query['category'])continue;
            foreach($native->catalogue($m,$o,$category['slug'])as $product){
                $details=$this->rows('SELECT description,vegetarian FROM products WHERE outlet_id=? AND product_id=? AND active=1 AND available=1',[$legacy,$product['productId']]);if(!$details)continue;
                $data[]=['productId'=>$product['productId'],'merchantId'=>$m,'outletId'=>$o,'name'=>$product['name'],'category'=>$category['slug'],'categoryName'=>$category['name'],'description'=>$details[0]['description'],'servingOptions'=>$product['variants'],'displayPricePaise'=>min(array_column($product['variants'],'pricePaise')),'imageReference'=>$product['imageReference'],'vegetarian'=>(int)$details[0]['vegetarian']===1?true:null,'available'=>true,'acceptingOrders'=>false];
            }
        }
        usort($data,fn($a,$b)=>($sort==='name-desc'?-1:1)*(strcmp(mb_strtolower($a['name']),mb_strtolower($b['name']))?:($a['productId']<=>$b['productId'])));
        return $data;
    }
}
