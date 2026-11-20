<?php
declare(strict_types=1);
namespace Kios\CustomerOrders;

use PDO;
use Kios\CustomerIdentity\{CustomerAccounts,CustomerIdentityProvider};
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity,TenantContext,PdoIdentityStore,Permission,Role};
use Kios\MerchantOperations\Entitlements;
use Kios\Infrastructure\{Environment,MenuRepository};
use Kios\Domain\Money;

/** Private synthetic simulation ledger. Never calls checkout, POS, KOT, usage, billing or delivery. */
final class OrderIntents
{
    public function __construct(private PDO $db, private CustomerIdentityProvider $customer,
        private AuthenticatedUserProvider $operator, private ?\Closure $clock=null) {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new \LogicException('MariaDB required.');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }
    private function now():int{return $this->clock?($this->clock)():time();}
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function transaction(string $c,string $m,string $o,bool $active,?int $accountVersion,callable $work):array {
        if(Environment::get('APP_ENV')!=='demo'||Environment::get('KIOS_CUSTOMER_ORDER_INTENTS_ENABLED','0')!=='1')throw new \DomainException('Order simulation disabled.');
        foreach(['KIOS_PUBLIC_ORDERING_ENABLED','KIOS_PUBLIC_REGISTRATION_ENABLED','KIOS_PAYMENTS_ENABLED','KIOS_PHYSICAL_PRINTING_ENABLED']as $flag)
            if(Environment::get($flag,'0')!=='0')throw new \DomainException('Private simulation boundary required.');
        if($this->db->inTransaction())throw new \LogicException('Intent service owns transaction.');
        foreach([$c,$m,$o]as$id)Identity::validate($id);
        (new CustomerAccounts($this->db))->profile($this->customer,$c);
        $ctx=TenantContext::resolve($this->operator,new PdoIdentityStore($this->db),$m,$o);
        if(!$ctx->allows(Permission::ReadOrders))throw new \DomainException('Internal outlet operator required.');
        $actor=$ctx->userId;
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try {
            $account=$this->rows('SELECT status,version FROM customer_accounts WHERE customer_id=? FOR UPDATE',[$c]);
            if(!$account||$this->customer->currentCustomerId()!==$c||$account[0]['status']==='DEACTIVATED'
                ||($active&&$account[0]['status']!=='ACTIVE')||($accountVersion!==null&&(int)$account[0]['version']!==$accountVersion))throw new \DomainException('Current owned account required.');
            if($this->operator->currentUserId()!==$actor||!$this->rows('SELECT user_id FROM merchant_staff WHERE user_id=? AND active=1 FOR UPDATE',[$actor]))throw new \DomainException('Internal authority changed.');
            $roles=$this->rows('SELECT role_code FROM merchant_memberships WHERE user_id=? AND merchant_id=? AND active=1 FOR UPDATE',[$actor,$m]);
            $roles=array_merge($roles,$this->rows('SELECT role_code FROM merchant_outlet_memberships WHERE user_id=? AND merchant_id=? AND outlet_id=? AND active=1 FOR UPDATE',[$actor,$m,$o]));
            $ok=false;foreach($roles as$r)if(Role::from($r['role_code'])->allows(Permission::ReadOrders))$ok=true;
            if(!$ok)throw new \DomainException('Internal outlet permission revoked.');
            (new Entitlements($this->db,$this->operator))->requireFeature($m,$o,'menu','read');
            $map=$this->rows('SELECT l.legacy_outlet_id,lo.outlet_code FROM merchant_legacy_outlets l JOIN merchant_outlets o ON o.merchant_id=l.merchant_id AND o.outlet_id=l.merchant_outlet_id AND o.active=1 JOIN merchant_organizations m ON m.merchant_id=o.merchant_id AND m.active=1 JOIN outlets lo ON lo.outlet_id=l.legacy_outlet_id AND lo.active=1 WHERE l.merchant_id=? AND l.merchant_outlet_id=? FOR UPDATE',[$m,$o]);
            if(count($map)!==1)throw new \DomainException('Current outlet mapping required.');
            $result=$work($actor,(int)$map[0]['legacy_outlet_id'],$map[0]['outlet_code']);
            if($this->customer->currentCustomerId()!==$c||$this->operator->currentUserId()!==$actor)throw new \DomainException('Identity changed.');
            $this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function selection(array $lines):array {
        if(!array_is_list($lines)||!$lines||count($lines)>50)throw new \InvalidArgumentException('Select one to fifty unique serving options.');
        $seen=[];foreach($lines as$line){
            $keys=['productId','variantId','quantity','productVersion','variantVersion'];
            if(!is_array($line)||array_diff(array_keys($line),$keys)||array_diff($keys,array_keys($line)))throw new \InvalidArgumentException('Only reference, quantity and revision fields allowed.');
            foreach($keys as$k)if(!is_int($line[$k])||$line[$k]<1||$line[$k]>2147483647)throw new \InvalidArgumentException('Use bounded positive integers.');
            if($line['quantity']>99)throw new \InvalidArgumentException('Quantity must be 1 through 99.');
            $key=$line['productId'].':'.$line['variantId'];if(isset($seen[$key]))throw new \InvalidArgumentException('Duplicate serving row.');$seen[$key]=true;
        }
        usort($lines,fn($a,$b)=>[$a['productId'],$a['variantId']]<=>[$b['productId'],$b['variantId']]);return $lines;
    }
    private function quote(int $legacy,string $code,array $lines):array {
        $snap=[];$total=Money::zero();
        foreach($lines as$line){
            // Locks the same authoritative product, serving, category and station rows used by native menu reads.
            $r=$this->rows('SELECT p.version product_version,v.version variant_version,p.sku,p.name,p.active,p.available,p.routing_status,p.station_id,p.category_id,c.slug,c.active category_active,c.version category_version,c.default_department_code,s.active station_active,s.department_code,s.department_version,v.active variant_active,v.code,v.label,v.unit,v.quantity_value,v.price_paise FROM products p JOIN product_variants v ON v.product_id=p.product_id JOIN categories c ON c.category_id=p.category_id AND c.outlet_id=p.outlet_id JOIN preparation_stations s ON s.station_id=p.station_id AND s.outlet_id=p.outlet_id WHERE p.outlet_id=? AND p.product_id=? AND v.variant_id=? FOR UPDATE',[$legacy,$line['productId'],$line['variantId']]);
            if(count($r)!==1||(int)$r[0]['product_version']!==$line['productVersion']||(int)$r[0]['variant_version']!==$line['variantVersion'])throw new \DomainException('Unavailable or stale catalogue reference.');
            $row=$r[0];$selected=null;
            foreach((new MenuRepository($this->db))->customerProductsForCategory($code,$row['slug'])as$product)if($product->id===$row['sku'])$selected=$product;
            if(!$selected)throw new \DomainException('Product is not currently eligible.');
            $v=$selected->variant($row['code']);if(!$v->active)throw new \DomainException('Serving unavailable.');
            $amount=$v->unitPrice->multiply($line['quantity']);$total=$total->add($amount);
            $snap[]=$line+['name'=>$selected->name,'serving'=>$v->label,'unit'=>$v->unit->value,'quantityValue'=>$v->quantityValue,'unitPricePaise'=>$v->unitPrice->paise(),'lineTotalPaise'=>$amount->paise(),'catalogueFingerprint'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR))];
        }
        return ['lines'=>$snap,'totalPaise'=>$total->paise()];
    }
    private function prior(string $request,string $hash,string $actor,string $c,string $m,string $o):?array {
        if(!preg_match('/^[a-f0-9]{32}$/D',$request))throw new \InvalidArgumentException('A submission identifier is required.');
        $r=$this->rows('SELECT * FROM customer_order_intent_events WHERE request_id=? FOR UPDATE',[$request]);if(!$r)return null;$p=$r[0];
        if($p['customer_id']!==$c||$p['merchant_id']!==$m||$p['outlet_id']!==$o||$p['actor_id']!==$actor||!hash_equals($p['payload_hash'],$hash))throw new \DomainException('Request identifier conflict.');
        return json_decode($p['result_json'],true,32,JSON_THROW_ON_ERROR);
    }
    private function record(string $c,string $m,string $o,string $id):array {
        Identity::validate($id);$r=$this->rows('SELECT * FROM customer_order_intents WHERE customer_id=? AND merchant_id=? AND outlet_id=? AND intent_id=? FOR UPDATE',[$c,$m,$o,$id]);
        if(count($r)!==1)throw new \DomainException('Intent unavailable.');return $r[0];
    }
    private function model(array $r):array {
        return ['intentId'=>$r['intent_id'],'customerId'=>$r['customer_id'],'merchantId'=>$r['merchant_id'],'outletId'=>$r['outlet_id'],'status'=>$r['status'],'version'=>(int)$r['version'],'totalPaise'=>(int)$r['total_paise'],'createdEpoch'=>(int)$r['created_epoch'],'expiresEpoch'=>(int)$r['expires_epoch'],'simulationOnly'=>true,'lines'=>json_decode($r['snapshot_json'],true,32,JSON_THROW_ON_ERROR)];
    }
    private function audit(string $request,string $hash,string $actor,string $c,string $m,string $o,string $id,string $action,array $result):void {
        $this->rows('INSERT INTO customer_order_intent_events VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$request,$id,$c,$m,$o,$actor,$action,$hash,$result['version'],json_encode($result,JSON_THROW_ON_ERROR),$this->now(),'SIMULATION']);
    }
    public function create(string $c,string $m,string $o,int $accountVersion,string $request,array $lines):array {
        $lines=$this->selection($lines);$hash=hash('sha256',json_encode(['CREATE',$c,$m,$o,$accountVersion,$lines],JSON_THROW_ON_ERROR));
        return $this->transaction($c,$m,$o,true,$accountVersion,function($actor,$legacy,$code)use($c,$m,$o,$request,$hash,$lines){
            $prior=$this->prior($request,$hash,$actor,$c,$m,$o);if($prior!==null)return $prior;
            $quote=$this->quote($legacy,$code,$lines);$id=Identity::generate();$now=$this->now();
            $this->rows("INSERT INTO customer_order_intents VALUES(?,?,?,?,'DRAFT',1,?,?,?,?,?,?)",[$id,$c,$m,$o,$quote['totalPaise'],json_encode($quote['lines'],JSON_THROW_ON_ERROR),$now,$now+1800,$now,$legacy]);
            foreach($quote['lines']as$line)$this->rows('INSERT INTO customer_order_intent_lines VALUES(?,?,?,?,?)',[$id,$line['productId'],$line['variantId'],$line['quantity'],$legacy]);
            $result=$this->model($this->record($c,$m,$o,$id));$this->audit($request,$hash,$actor,$c,$m,$o,$id,'CREATE',$result);return $result;
        });
    }
    public function read(string $c,string $m,string $o,string $id):array {
        return $this->transaction($c,$m,$o,false,null,fn()=> $this->model($this->record($c,$m,$o,$id)));
    }
    public function transition(string $c,string $m,string $o,int $accountVersion,string $request,string $id,int $expected,string $next):array {
        $hash=hash('sha256',json_encode(['TRANSITION',$c,$m,$o,$accountVersion,$id,$expected,$next],JSON_THROW_ON_ERROR));
        return $this->transaction($c,$m,$o,!in_array($next,['CANCELLED','EXPIRED'],true),$accountVersion,function($actor,$legacy,$code)use($c,$m,$o,$request,$hash,$id,$expected,$next){
            $prior=$this->prior($request,$hash,$actor,$c,$m,$o);if($prior!==null)return $prior;
            $r=$this->record($c,$m,$o,$id);$allowed=['DRAFT'=>['SUBMITTED','CANCELLED','EXPIRED'],'SUBMITTED'=>['PENDING_MERCHANT_ACCEPTANCE','CANCELLED','EXPIRED'],'PENDING_MERCHANT_ACCEPTANCE'=>['ACCEPTED','REJECTED','CANCELLED','EXPIRED']];
            if((int)$r['version']!==$expected||!in_array($next,$allowed[$r['status']]??[],true))throw new \DomainException('Invalid transition or stale version.');
            if(($next==='EXPIRED')!==($this->now()>=(int)$r['expires_epoch']))throw new \DomainException('Intent deadline requires explicit expiry.');
            if(in_array($next,['SUBMITTED','PENDING_MERCHANT_ACCEPTANCE','ACCEPTED'],true)){
                $saved=json_decode($r['snapshot_json'],true,32,JSON_THROW_ON_ERROR);$input=[];foreach($saved as$line)$input[]=array_intersect_key($line,array_flip(['productId','variantId','quantity','productVersion','variantVersion']));
                $current=$this->quote($legacy,$code,$input);if($current['lines']!==$saved||$current['totalPaise']!==(int)$r['total_paise'])throw new \DomainException('Catalogue changed; create a new reviewed draft.');
            }
            $this->rows('UPDATE customer_order_intents SET status=?,version=version+1,updated_epoch=? WHERE intent_id=?',[$next,$this->now(),$id]);
            $result=$this->model($this->record($c,$m,$o,$id));$this->audit($request,$hash,$actor,$c,$m,$o,$id,$next,$result);return $result;
        });
    }
}
