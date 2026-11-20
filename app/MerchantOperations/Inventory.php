<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use PDO;
use DomainException;
use InvalidArgumentException;
use Throwable;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity};

/** Original-owner, explicitly mapped outlet inventory. No order/payment hooks or external IO. */
final class Inventory
{
    private const LIMIT=1000000000000;
    public function __construct(private PDO $db,private AuthenticatedUserProvider $auth){}
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public static function quantity(mixed $value):int{
        if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/D',$value,$m))throw new InvalidArgumentException('Use a nonnegative quantity with up to three decimal places.');
        $n=(int)$m[1]*1000+(int)str_pad($m[2]??'',3,'0');if($n>self::LIMIT)throw new InvalidArgumentException('Quantity exceeds the supported limit.');return $n;
    }
    public static function display(int $n):string{return intdiv($n,1000).'.'.str_pad((string)($n%1000),3,'0',STR_PAD_LEFT);}
    private static function text(mixed $v,int $max=120):string{if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>$max||preg_match('/[<>\x00-\x1f\x7f]/u',$v))throw new InvalidArgumentException('Use short plain text.');return trim($v);}
    private static function fields(array $d,array $keys):void{if(array_diff(array_keys($d),$keys)||array_diff($keys,array_keys($d)))throw new InvalidArgumentException('Only the current form fields are accepted.');}
    private function scoped(string $m,string $o,callable $work):mixed{
        for($attempt=0;$attempt<3;$attempt++){
            try{return $this->scopedAttempt($m,$o,$work);}
            catch(\PDOException $error){
                // InnoDB deadlock victims have not committed. Roll back and
                // revalidate the complete native identity/scope on each retry.
                if($this->db->inTransaction())$this->db->rollBack();
                if((int)($error->errorInfo[1]??0)!==1213||$attempt===2)throw $error;
                usleep(25000*($attempt+1));
            }
        }
        throw new \LogicException('Inventory retry invariant failed.');
    }
    private function scopedAttempt(string $m,string $o,callable $work):mixed{
        Identity::validate($o);if($this->db->inTransaction())throw new \LogicException('Inventory owns its transaction.');
        $s=OwnerOutletScope::prepare($this->db,$this->auth,$m,$o);$actor=$s['owner_user_id'];
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{
            // Match setup's account-first lock order before taking outlet/owner locks.
            if($this->auth->currentUserId()!==$actor)throw new DomainException('Current owner required.');
            // Shared owner row serializes requests, recipe revisions and stock changes.
            $q=[OwnerOutletScope::locked($this->db,$this->auth,$m,$o,$actor)];
            (new Entitlements($this->db,$this->auth))->requireFeature($m,$o,'inventory','read');$r=$work($actor,(int)$q[0]['legacy_outlet_id']);$this->db->commit();return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function item(string $m,string $o,string $id):array{Identity::validate($id);$r=$this->rows('SELECT * FROM merchant_inventory_items WHERE merchant_id=? AND outlet_id=? AND item_id=? FOR UPDATE',[$m,$o,$id]);if(count($r)!==1)throw new DomainException('Ingredient outside this outlet.');return $r[0];}
    public function load(string $m,string $o):array{return $this->scoped($m,$o,function($actor,$legacy)use($m,$o){
        $canRecipes=true;try{(new Entitlements($this->db,$this->auth))->requireFeature($m,$o,'recipes','read');}catch(DomainException){$canRecipes=false;}$items=$this->rows('SELECT * FROM merchant_inventory_items WHERE merchant_id=? AND outlet_id=? ORDER BY name,item_id',[$m,$o]);foreach($items as &$i){$i['stock_state']=(int)$i['balance_milli']===0?'Exhausted':((int)$i['balance_milli']<=(int)$i['minimum_milli']?'Low stock':'In stock');}unset($i);
        return ['merchant'=>$m,'outlet'=>$o,'items'=>$items,'products'=>$canRecipes?$this->rows('SELECT product_id,name FROM products WHERE outlet_id=? AND active=1 ORDER BY name',[$legacy]):[],'recipes'=>$canRecipes?$this->rows('SELECT * FROM merchant_inventory_recipes WHERE merchant_id=? AND outlet_id=? ORDER BY product_id,revision DESC',[$m,$o]):[],'history'=>$this->rows('SELECT e.*,s.display_name FROM merchant_inventory_events e JOIN merchant_staff s ON s.user_id=e.actor_id WHERE merchant_id=? AND outlet_id=? ORDER BY occurred_epoch DESC,request_id LIMIT 200',[$m,$o]),'movements'=>$this->rows('SELECT v.*,i.name,i.unit FROM merchant_inventory_movements v JOIN merchant_inventory_items i ON i.merchant_id=v.merchant_id AND i.outlet_id=v.outlet_id AND i.item_id=v.item_id WHERE v.merchant_id=? AND v.outlet_id=? ORDER BY request_id,movement_id',[$m,$o])];
    });}
    public function act(string $m,string $o,string $request,string $op,array $d):array{
        if(!preg_match('/^[a-f0-9]{32}$/D',$request)||!in_array($op,['ingredient','update','receipt','adjustment','wastage','recipe','consume','reverse'],true))throw new InvalidArgumentException('Reload the current inventory form.');
        $hash=hash('sha256',json_encode([$m,$o,$op,$d],JSON_THROW_ON_ERROR));
        return $this->scoped($m,$o,function($actor,$legacy)use($m,$o,$request,$op,$d,$hash){
            (new Entitlements($this->db,$this->auth))->requireFeature($m,$o,'inventory');if(in_array($op,['recipe','consume'],true))(new Entitlements($this->db,$this->auth))->requireFeature($m,$o,'recipes');
            $prior=$this->rows('SELECT * FROM merchant_inventory_events WHERE request_id=? FOR UPDATE',[$request]);if($prior){$p=$prior[0];if($p['merchant_id']!==$m||$p['outlet_id']!==$o||$p['actor_id']!==$actor||!hash_equals($hash,$p['payload_hash']))throw new DomainException('Request identifier already used.');return ['duplicate'=>true,'result'=>json_decode($p['result_json'],true,32,JSON_THROW_ON_ERROR)];}
            $changes=[];$result=[];$reverse=null;
            if($op==='ingredient'){
                self::fields($d,['name','unit','opening','minimum','location','supplier']);$name=self::text($d['name']);if($name===''||!in_array($d['unit'],['g','ml','each'],true))throw new InvalidArgumentException('Name and base unit required.');$id=Identity::generate();$opening=self::quantity($d['opening']);
                $this->rows('INSERT INTO merchant_inventory_items(merchant_id,outlet_id,item_id,name,unit,location_label,supplier_reference,minimum_milli) VALUES(?,?,?,?,?,?,?,?)',[$m,$o,$id,$name,$d['unit'],self::text($d['location']),self::text($d['supplier']),self::quantity($d['minimum'])]);if($opening)$changes[$id]=$opening;$result=['item'=>$id,'metadata'=>$d];
            }elseif($op==='update'){
                self::fields($d,['item','version','name','minimum','location','supplier']);$i=$this->item($m,$o,$d['item']);if((string)$i['version']!==$d['version'])throw new DomainException('Ingredient changed. Reload before saving.');$name=self::text($d['name']);if($name==='')throw new InvalidArgumentException('Name required.');
                $this->rows('UPDATE merchant_inventory_items SET name=?,minimum_milli=?,location_label=?,supplier_reference=?,version=version+1 WHERE merchant_id=? AND outlet_id=? AND item_id=?',[$name,self::quantity($d['minimum']),self::text($d['location']),self::text($d['supplier']),$m,$o,$d['item']]);$result=['metadata'=>$d,'before'=>['name'=>$i['name'],'minimum_milli'=>$i['minimum_milli'],'location'=>$i['location_label'],'supplier'=>$i['supplier_reference']]];
            }elseif(in_array($op,['receipt','adjustment','wastage'],true)){
                self::fields($d,$op==='adjustment'?['item','quantity','reason','direction']:['item','quantity','reason']);$n=self::quantity($d['quantity']);if(!$n||self::text($d['reason'],240)==='')throw new InvalidArgumentException('Positive quantity and reason required.');$sign=$op==='wastage'?-1:1;if($op==='adjustment'){if(!in_array($d['direction'],['increase','decrease'],true))throw new InvalidArgumentException('Choose increase or decrease.');$sign=$d['direction']==='decrease'?-1:1;}$changes[$d['item']]=$sign*$n;$result=['reason'=>$d['reason']];
            }elseif($op==='recipe'){
                self::fields($d,['product','lines']);if(!is_string($d['product'])||!ctype_digit($d['product'])||!$this->rows('SELECT product_id FROM products WHERE outlet_id=? AND product_id=? AND active=1 FOR UPDATE',[$legacy,$d['product']]))throw new DomainException('Product outside this outlet.');
                if(!is_array($d['lines'])||!array_is_list($d['lines'])||count($d['lines'])<1||count($d['lines'])>50)throw new InvalidArgumentException('Choose 1–50 recipe ingredients.');$lines=[];
                foreach($d['lines'] as $line){if(!is_array($line))throw new InvalidArgumentException('Invalid recipe line.');self::fields($line,['item','quantity','unit']);$i=$this->item($m,$o,$line['item']);$n=self::quantity($line['quantity']);if(!$n||$line['unit']!==$i['unit']||isset($lines[$line['item']]))throw new InvalidArgumentException('Use unique ingredients, positive quantities and matching base units.');$lines[$line['item']]=['quantity_milli'=>$n,'unit'=>$i['unit']];}ksort($lines);
                $rev=(int)$this->rows('SELECT COALESCE(MAX(revision),0)+1 AS next_revision FROM merchant_inventory_recipes WHERE merchant_id=? AND outlet_id=? AND product_id=?',[$m,$o,$d['product']])[0]['next_revision'];$result=['recipe'=>Identity::generate(),'product'=>$d['product'],'revision'=>$rev,'lines'=>$lines];
            }elseif($op==='consume'){
                self::fields($d,['recipe','servings','reason']);Identity::validate($d['recipe']);if(!is_string($d['servings'])||!preg_match('/^[1-9][0-9]{0,3}$/D',$d['servings'])||self::text($d['reason'],240)==='')throw new InvalidArgumentException('Use 1–9999 synthetic servings and a reason.');$recipe=$this->rows('SELECT * FROM merchant_inventory_recipes WHERE merchant_id=? AND outlet_id=? AND recipe_id=?',[$m,$o,$d['recipe']]);if(count($recipe)!==1)throw new DomainException('Recipe outside this outlet.');foreach(json_decode($recipe[0]['lines_json'],true,32,JSON_THROW_ON_ERROR) as $id=>$line){$n=$line['quantity_milli']*(int)$d['servings'];if($n>self::LIMIT)throw new InvalidArgumentException('Consumption exceeds limit.');$changes[$id]=-$n;}$result=$d;
            }else{
                self::fields($d,['original','reason']);if(!preg_match('/^[a-f0-9]{32}$/D',$d['original'])||self::text($d['reason'],240)==='')throw new InvalidArgumentException('Original request and reason required.');$event=$this->rows('SELECT operation FROM merchant_inventory_events WHERE merchant_id=? AND outlet_id=? AND request_id=?',[$m,$o,$d['original']]);if(count($event)!==1||!in_array($event[0]['operation'],['ingredient','receipt','adjustment','wastage','consume'],true)||$this->rows('SELECT request_id FROM merchant_inventory_events WHERE reversed_request=?',[$d['original']]))throw new DomainException('This movement cannot be reversed.');foreach($this->rows('SELECT item_id,delta_milli FROM merchant_inventory_movements WHERE merchant_id=? AND outlet_id=? AND request_id=?',[$m,$o,$d['original']]) as $row)$changes[$row['item_id']]=-(int)$row['delta_milli'];if(!$changes)throw new DomainException('No stock movement to reverse.');$reverse=$d['original'];$result=$d;
            }
            ksort($changes);$balances=[];foreach($changes as $id=>$delta){$i=$this->item($m,$o,$id);$after=(int)$i['balance_milli']+$delta;if($after<0||$after>self::LIMIT)throw new DomainException('Insufficient stock or stock limit exceeded.');$balances[$id]=$after;$this->rows('UPDATE merchant_inventory_items SET balance_milli=?,version=version+1 WHERE merchant_id=? AND outlet_id=? AND item_id=?',[$after,$m,$o,$id]);}
            $result['balances_milli']=$balances;$this->rows('INSERT INTO merchant_inventory_events VALUES(?,?,?,?,?,?,?,?,?)',[$request,$m,$o,$actor,$op,$hash,json_encode($result,JSON_THROW_ON_ERROR),time(),$reverse]);
            if($op==='recipe')$this->rows('INSERT INTO merchant_inventory_recipes VALUES(?,?,?,?,?,?,?)',[$m,$o,$result['recipe'],(int)$d['product'],$result['revision'],json_encode($result['lines'],JSON_THROW_ON_ERROR),$request]);
            foreach($changes as $id=>$delta)$this->rows('INSERT INTO merchant_inventory_movements VALUES(?,?,?,?,?,?,?)',[Identity::generate(),$m,$o,$request,$id,$delta,$balances[$id]]);
            return ['duplicate'=>false,'result'=>$result];
        });
    }
}
