<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use PDO;
use Throwable;
use DomainException;
use InvalidArgumentException;
use Kios\Infrastructure\{OrderRepository,Environment};
use Kios\MerchantFoundation\{AuthenticatedUserProvider,TenantContext,PdoIdentityStore,Identity};

/** Internal orchestration only. Shared OrderRepository owns order pricing, persistence and KOT routing. */
final class StaffPos
{
    public function __construct(private PDO $db,private AuthenticatedUserProvider $auth){}
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public function homes():array
    {
        $user=$this->auth->currentUserId();if($user===null)return [];
        return $this->rows("SELECT DISTINCT o.merchant_id,o.outlet_id,o.name,r.role_code FROM merchant_outlet_memberships r JOIN merchant_outlets o ON o.merchant_id=r.merchant_id AND o.outlet_id=r.outlet_id AND o.active=1 JOIN merchant_organizations m ON m.merchant_id=o.merchant_id AND m.active=1 WHERE r.user_id=? AND r.active=1 AND r.role_code IN ('cashier','outlet_administrator','kitchen') ORDER BY o.name",[$user]);
    }
    private function context(string $m,string $o):TenantContext{
        if(Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')!=='1'||Environment::get('APP_ENV')!=='demo')throw new DomainException('Internal staff service disabled.');
        return TenantContext::resolve($this->auth,new PdoIdentityStore($this->db),$m,$o);
    }
    private function guard(TenantContext $c):int{
        if(!$this->db->inTransaction()||$this->auth->currentUserId()!==$c->userId)throw new DomainException('Current native staff identity required.');
        (new Entitlements($this->db,$this->auth))->requireFeature($c->merchantId,$c->outletId,'pos','drain-read');$roles=array_column($this->rows('SELECT role_code FROM merchant_memberships WHERE merchant_id=? AND user_id=? AND active=1 FOR UPDATE',[$c->merchantId,$c->userId]),'role_code');
        $roles=array_merge($roles,array_column($this->rows('SELECT role_code FROM merchant_outlet_memberships WHERE merchant_id=? AND outlet_id=? AND user_id=? AND active=1 FOR UPDATE',[$c->merchantId,$c->outletId,$c->userId]),'role_code'));
        if(!array_intersect($roles,['merchant_owner','outlet_administrator','cashier']))throw new DomainException('Assigned service staff required.');
        $r=$this->rows("SELECT l.legacy_outlet_id FROM merchant_outlets o JOIN merchant_organizations m ON m.merchant_id=o.merchant_id AND m.active=1 JOIN merchant_onboarding p ON p.merchant_id=m.merchant_id AND p.status='active' JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id JOIN outlets lo ON lo.outlet_id=l.legacy_outlet_id AND lo.active=1 WHERE o.merchant_id=? AND o.outlet_id=? AND o.active=1 FOR UPDATE",[$c->merchantId,$c->outletId]);
        if(count($r)!==1)throw new DomainException('Active outlet mapping required.');
        $this->rows('INSERT INTO merchant_seating_settings(merchant_id,outlet_id) VALUES(?,?) ON DUPLICATE KEY UPDATE outlet_id=VALUES(outlet_id)',[$c->merchantId,$c->outletId]);
        $this->rows('SELECT outlet_id FROM merchant_seating_settings WHERE merchant_id=? AND outlet_id=? FOR UPDATE',[$c->merchantId,$c->outletId]);return (int)$r[0]['legacy_outlet_id'];
    }
    private function transaction(TenantContext $c,callable $work):mixed{
        if($this->db->inTransaction())throw new \LogicException('POS owns its transaction.');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{$legacy=$this->guard($c);$result=$work($legacy);$this->db->commit();return $result;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function plain(mixed $v,int $max):string{if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>$max||preg_match('/[<>\x00-\x1f\x7f]/u',$v))throw new InvalidArgumentException('Use short plain-text notes.');return trim($v);}
    private static function version(array $row,string $version):void{if((string)$row['version']!==$version)throw new DomainException('Changed in another session. Reload before saving.');}
    private function session(TenantContext $c,string $id):array{Identity::validate($id);$r=$this->rows('SELECT * FROM merchant_service_sessions WHERE merchant_id=? AND outlet_id=? AND service_id=? FOR UPDATE',[$c->merchantId,$c->outletId,$id]);if(count($r)!==1)throw new DomainException('Service outside this outlet.');return $r[0];}
    private function occupied(TenantContext $c,string $id,?int $version=null):array{
        Identity::validate($id);$r=$this->rows('SELECT t.*,f.active AS floor_active FROM merchant_seating_tables t JOIN merchant_seating_floors f ON f.merchant_id=t.merchant_id AND f.outlet_id=t.outlet_id AND f.floor_id=t.floor_id WHERE t.merchant_id=? AND t.outlet_id=? AND t.table_id=? FOR UPDATE',[$c->merchantId,$c->outletId,$id]);
        if(count($r)!==1||!(int)$r[0]['active']||!(int)$r[0]['floor_active']||$r[0]['operational_state']!=='OCCUPIED'||(int)$r[0]['operational_until']<=time()||($version!==null&&(int)$r[0]['version']!==$version))throw new DomainException('An active, currently occupied seating session is required. Reload if the table changed.');return $r[0];
    }
    /** Strict immutable references; prices never come from the request. */
    private function selection(int $legacy,array $lines):array{
        if(!array_is_list($lines)||!$lines||count($lines)>50)throw new InvalidArgumentException('Choose between one and fifty product variations.');$cart=[];$notes=[];
        foreach($lines as $line){if(!is_array($line)||array_diff(array_keys($line),['product','variant','quantity','notes'])||array_diff(['product','variant','quantity','notes'],array_keys($line)))throw new InvalidArgumentException('Use only current POS fields.');
            foreach(['product','variant','quantity']as $key)if(!is_string($line[$key])||!preg_match('/^[1-9][0-9]{0,8}$/D',$line[$key]))throw new InvalidArgumentException('Use positive whole quantities.');
            if((int)$line['quantity']>99)throw new InvalidArgumentException('Quantity must be between 1 and 99.');
            $r=$this->rows('SELECT p.sku,v.code FROM products p JOIN product_variants v ON v.product_id=p.product_id WHERE p.outlet_id=? AND p.product_id=? AND v.variant_id=?',[$legacy,$line['product'],$line['variant']]);if(count($r)!==1)throw new DomainException('Product variation outside this outlet.');$sku=$r[0]['sku'];$variant=$r[0]['code'];if(isset($cart[$sku][$variant]))throw new InvalidArgumentException('Use one row per product variation.');$cart[$sku][$variant]=(int)$line['quantity'];$notes[$sku.'|'.$variant]=self::plain($line['notes'],240);
        }return [$cart,$notes];
    }
    private function prior(TenantContext $c,string $request,string $op,array $payload):array{
        if(!preg_match('/^[a-f0-9]{32}$/D',$request))throw new InvalidArgumentException('Reload the request form.');$hash=hash('sha256',json_encode([$c->merchantId,$c->outletId,$op,$payload],JSON_THROW_ON_ERROR));
        $r=$this->rows('SELECT * FROM merchant_service_events WHERE request_id=? FOR UPDATE',[$request]);if($r){$e=$r[0];if($e['merchant_id']!==$c->merchantId||$e['outlet_id']!==$c->outletId||$e['actor_id']!==$c->userId||!hash_equals($e['payload_hash'],$hash))throw new DomainException('Request identifier already used.');return [$hash,json_decode($e['result_json'],true,32,JSON_THROW_ON_ERROR)];}return [$hash,null];
    }
    private function audit(TenantContext $c,string $request,string $op,string $hash,string $id,array $result):void{$this->rows('INSERT INTO merchant_service_events VALUES(?,?,?,?,?,?,?,?,?)',[$request,$c->merchantId,$c->outletId,$id,$c->userId,$op,$hash,json_encode($result,JSON_THROW_ON_ERROR),time()]);}
    public function save(string $m,string $o,string $request,string $id,string $version,string $table,array $lines):array{
        $c=$this->context($m,$o);return $this->transaction($c,function($legacy)use($c,$request,$id,$version,$table,$lines){(new Entitlements($this->db,$this->auth))->requireFeature($c->merchantId,$c->outletId,'pos');[$hash,$prior]=$this->prior($c,$request,'save',[$id,$version,$table,$lines]);if($prior!==null)return $prior;$t=$this->occupied($c,$table);$service=$id===''?null:$this->session($c,$id);
            if($service!==null){self::version($service,$version);if($service['status']!=='DRAFT'||$service['table_id']!==$table)throw new DomainException('Only this table’s draft can be edited.');$this->occupied($c,$table,(int)$service['table_version']);}
            elseif($version!=='0'||$this->rows("SELECT service_id FROM merchant_service_sessions WHERE merchant_id=? AND outlet_id=? AND table_id=? AND status IN ('DRAFT','SUBMITTED') FOR UPDATE",[$c->merchantId,$c->outletId,$table]))throw new DomainException('This table already has an active service. Open its existing draft.');
            [$cart,$notes]=$this->selection($legacy,$lines);$repo=new OrderRepository($this->db,scopeOutletId:$legacy,checkoutAuthorization:fn()=>$this->guard($c),operatorIdentity:$c->userId);$quote=$repo->quoteInternalCart($cart);$stored=['input'=>$lines,'quote'=>$quote,'notes'=>$notes];
            if($service===null){$id=Identity::generate();$this->rows('INSERT INTO merchant_service_sessions(merchant_id,outlet_id,service_id,table_id,table_version,lines_json,legacy_outlet_id,created_epoch,updated_epoch) VALUES(?,?,?,?,?,?,?,?,?)',[$c->merchantId,$c->outletId,$id,$table,$t['version'],json_encode($stored,JSON_THROW_ON_ERROR),$legacy,time(),time()]);}
            else{$this->rows('UPDATE merchant_service_sessions SET lines_json=?,version=version+1,updated_epoch=? WHERE merchant_id=? AND outlet_id=? AND service_id=?',[json_encode($stored,JSON_THROW_ON_ERROR),time(),$c->merchantId,$c->outletId,$id]);}
            $result=['service'=>$id];$this->audit($c,$request,'save',$hash,$id,$result);return $result;
        });
    }
    public function submit(string $m,string $o,string $request,string $id,string $version):array{
        $c=$this->context($m,$o);$read=$this->transaction($c,function($legacy)use($c,$id,$version,$request){(new Entitlements($this->db,$this->auth))->requireFeature($c->merchantId,$c->outletId,'pos');[$hash,$prior]=$this->prior($c,$request,'submit',[$id,$version]);if($prior!==null)return ['prior'=>$prior];$s=$this->session($c,$id);self::version($s,$version);if($s['status']!=='DRAFT')throw new DomainException('Only a saved draft can be submitted.');$this->occupied($c,$s['table_id'],(int)$s['table_version']);return ['session'=>$s,'legacy'=>$legacy];});if(isset($read['prior']))return $read['prior'];
        $stored=json_decode($read['session']['lines_json'],true,32,JSON_THROW_ON_ERROR);[$cart]=$this->selection($read['legacy'],$stored['input']);$result=null;
        $guard=function()use($c,$id,$version,$request){$this->guard($c);(new Entitlements($this->db,$this->auth))->requireFeature($c->merchantId,$c->outletId,'pos');[$hash,$prior]=$this->prior($c,$request,'submit',[$id,$version]);if($prior!==null)throw new ServiceSubmissionReplay($prior);$s=$this->session($c,$id);self::version($s,$version);if($s['status']!=='DRAFT')throw new DomainException('Draft already submitted or cancelled.');$this->occupied($c,$s['table_id'],(int)$s['table_version']);};
        try{(new OrderRepository($this->db,scopeOutletId:$read['legacy'],checkoutAuthorization:$guard,operatorIdentity:$c->userId))->createInternalService($cart,function($order)use($c,$id,$version,$request,$stored,&$result){
                $s=$this->session($c,$id);self::version($s,$version);$snapshots=$this->rows('SELECT order_item_id,product_sku_snapshot,variant_code_snapshot,quantity,unit_price_paise,line_total_paise FROM order_items WHERE outlet_id=? AND order_id=? ORDER BY order_item_id',[$s['legacy_outlet_id'],$order]);$actual=[];foreach($snapshots as $row){$key=$row['product_sku_snapshot'].'|'.$row['variant_code_snapshot'];$actual[$key]=[(int)$row['quantity'],(int)$row['unit_price_paise'],(int)$row['line_total_paise']];$this->rows('INSERT INTO merchant_service_line_notes VALUES(?,?,?,?)',[$s['legacy_outlet_id'],$order,$row['order_item_id'],$stored['notes'][$key]??'']);}ksort($actual);$expected=$stored['quote']['prices'];ksort($expected);if($actual!==$expected)throw new DomainException('Prices changed since review. Save and review the draft again.');
                $this->rows("UPDATE merchant_service_sessions SET status='SUBMITTED',order_id=?,version=version+1,updated_epoch=? WHERE merchant_id=? AND outlet_id=? AND service_id=?",[$order,time(),$c->merchantId,$c->outletId,$id]);[$hash]=$this->prior($c,$request,'submit',[$id,$version]);$result=['service'=>$id,'order'=>$order];$this->audit($c,$request,'submit',$hash,$id,$result);
            });}catch(ServiceSubmissionReplay $replay){return $replay->result;}return $result;
    }
    public function finish(string $m,string $o,string $request,string $id,string $version,bool $cancel=false):array{
        $c=$this->context($m,$o);return $this->transaction($c,function()use($c,$request,$id,$version,$cancel){$op=$cancel?'cancel':'served';[$hash,$prior]=$this->prior($c,$request,$op,[$id,$version]);if($prior!==null)return $prior;$s=$this->session($c,$id);self::version($s,$version);
            if($cancel){if($s['status']!=='DRAFT')throw new DomainException('Only an unsubmitted draft may be cancelled. Submitted preparation must be completed.');}
            else{if($s['status']!=='SUBMITTED')throw new DomainException('Only submitted service can be served.');$r=$this->rows("SELECT status FROM orders WHERE outlet_id=? AND order_id=? AND order_mode='INTERNAL_SERVICE' FOR UPDATE",[$s['legacy_outlet_id'],$s['order_id']]);if(count($r)!==1||$r[0]['status']!=='ready')throw new DomainException('All kitchen stations must be READY before serving.');}
            $this->rows('UPDATE merchant_service_sessions SET status=?,version=version+1,updated_epoch=? WHERE merchant_id=? AND outlet_id=? AND service_id=?',[$cancel?'CANCELLED':'SERVED',time(),$c->merchantId,$c->outletId,$id]);$result=['service'=>$id];$this->audit($c,$request,$op,$hash,$id,$result);return $result;
        });
    }
    public function kitchen(string $m,string $o):array
    {
        $c=$this->context($m,$o);if(!$c->allows(\Kios\MerchantFoundation\Permission::PrepareOrders))throw new DomainException('Kitchen permission required.');
        $r=$this->rows('SELECT legacy_outlet_id FROM merchant_legacy_outlets WHERE merchant_id=? AND merchant_outlet_id=?',[$m,$o]);if(count($r)!==1)throw new DomainException('Outlet mapping required.');$legacy=(int)$r[0]['legacy_outlet_id'];$tasks=$this->rows("SELECT pt.*,ps.name AS station_name,t.name AS table_name,s.service_id,s.status AS service_status FROM preparation_tasks pt JOIN merchant_service_sessions s ON s.legacy_outlet_id=pt.outlet_id AND s.order_id=pt.order_id JOIN merchant_seating_tables t ON t.merchant_id=s.merchant_id AND t.outlet_id=s.outlet_id AND t.table_id=s.table_id JOIN preparation_stations ps ON ps.outlet_id=pt.outlet_id AND ps.station_id=pt.station_id WHERE s.merchant_id=? AND s.outlet_id=? AND s.status='SUBMITTED' ORDER BY pt.created_at,pt.preparation_task_id",[$m,$o]);$ops=new TenantOperations($this->db,$this->auth);$visible=[];foreach($tasks as $task){try{$task['payload_text']=$ops->kot($m,$o,(int)$task['preparation_task_id']);$visible[]=$task;}catch(DomainException){/* An ungranted station is omitted, never exposed. */}}return ['merchant'=>$m,'outlet'=>$o,'tasks'=>$visible];
    }
    public function advance(string $m,string $o,int $task,string $expected):array
    {
        $c=$this->context($m,$o);$r=$this->rows("SELECT pt.preparation_task_id FROM preparation_tasks pt JOIN merchant_service_sessions s ON s.legacy_outlet_id=pt.outlet_id AND s.order_id=pt.order_id WHERE s.merchant_id=? AND s.outlet_id=? AND s.status='SUBMITTED' AND pt.preparation_task_id=?",[$m,$o,$task]);if(count($r)!==1)throw new DomainException('Task outside active service.');return (new TenantOperations($this->db,$this->auth))->advance($m,$o,$task,\Kios\Domain\PreparationTaskStatus::from($expected));
    }
    public function load(string $m,string $o):array{
        $c=$this->context($m,$o);return $this->transaction($c,function($legacy)use($c){$sessions=$this->rows('SELECT s.*,o.status AS kitchen_status FROM merchant_service_sessions s LEFT JOIN orders o ON o.outlet_id=s.legacy_outlet_id AND o.order_id=s.order_id WHERE s.merchant_id=? AND s.outlet_id=? ORDER BY s.created_epoch DESC LIMIT 200',[$c->merchantId,$c->outletId]);foreach($sessions as &$s){$s['draft']=json_decode($s['lines_json'],true,32,JSON_THROW_ON_ERROR);$s['tasks']=$s['order_id']===null?[]:$this->rows("SELECT pt.preparation_task_id,pt.station_id,pt.status,ps.name AS station_name,pj.payload_text FROM preparation_tasks pt JOIN preparation_stations ps ON ps.outlet_id=pt.outlet_id AND ps.station_id=pt.station_id LEFT JOIN print_jobs pj ON pj.preparation_task_id=pt.preparation_task_id AND pj.order_id=pt.order_id AND pj.outlet_id=pt.outlet_id AND pj.job_type='STATION_KOT' AND pj.reprint_of_job_id IS NULL WHERE pt.outlet_id=? AND pt.order_id=?",[$legacy,$s['order_id']]);}unset($s);
            return ['merchant'=>$c->merchantId,'outlet'=>$c->outletId,'legacy'=>$legacy,'canPrepare'=>$c->allows(\Kios\MerchantFoundation\Permission::PrepareOrders),'actor'=>$c->userId,'floors'=>$this->rows('SELECT * FROM merchant_seating_floors WHERE merchant_id=? AND outlet_id=? ORDER BY name',[$c->merchantId,$c->outletId]),'tables'=>$this->rows('SELECT * FROM merchant_seating_tables WHERE merchant_id=? AND outlet_id=? ORDER BY name',[$c->merchantId,$c->outletId]),'products'=>$this->rows('SELECT p.product_id,p.name,p.available,p.active,p.category_id,c.name AS category_name,v.variant_id,v.label,v.price_paise,v.active AS variant_active FROM products p JOIN categories c ON c.category_id=p.category_id AND c.outlet_id=p.outlet_id JOIN product_variants v ON v.product_id=p.product_id WHERE p.outlet_id=? ORDER BY c.sort_order,p.display_order,v.display_order',[$legacy]),'sessions'=>$sessions];
        });
    }
}
