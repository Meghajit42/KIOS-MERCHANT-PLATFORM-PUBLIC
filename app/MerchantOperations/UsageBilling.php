<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;

use PDO;
use DomainException;
use InvalidArgumentException;
use Kios\Infrastructure\Environment;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity,TenantContext,PdoIdentityStore,AuthorizationService};

/** Demonstration scenarios only. Never reads orders as billable events or writes financial records. */
final class UsageBilling
{
    public const NOTICE='DEMONSTRATION ESTIMATE — NOT AN INVOICE OR PAYMENT REQUEST';
    public const POLICY='UTC calendar periods, start inclusive/end exclusive; current assigned immutable plan as scenario; pricing effective at period start; full-period subscription and optional support, no proration/tax/collection; outlet views omit merchant subscription/support; only explicitly simulated eligible events count.';
    public function __construct(private PDO $db,private AuthenticatedUserProvider $auth,private ?\Closure $clock=null){}
    private function now():int{return $this->clock?($this->clock)():time();}
    public static function enabled():bool{return Environment::get('KIOS_USAGE_BILLING_ENABLED','0')==='1'&&Environment::get('APP_ENV')==='demo'&&PlatformAccess::enabled()&&Entitlements::lifecycleEnabled();}
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function gate():void{if(!self::enabled())throw new DomainException('Private billing preparation disabled.');}
    private function admin():string
    {
        $this->gate();$actor=$this->auth->currentUserId();
        if(!$actor)throw new DomainException('Native platform authority required.');
        if($this->db->inTransaction()){
            if(!$this->rows("SELECT p.user_id FROM merchant_platform_memberships p JOIN merchant_staff s ON s.user_id=p.user_id AND s.active=1 JOIN merchant_role_permissions r ON r.role_code=p.role_code AND r.permission_code='manage_platform' WHERE p.user_id=? AND p.active=1 FOR UPDATE",[$actor]))throw new DomainException('Current platform authority required.');
        }else(new AuthorizationService($this->auth,new PdoIdentityStore($this->db)))->requirePlatformAdministration();
        return$actor;
    }
    private static function fields(array $a,array $keys):void{if(array_diff(array_keys($a),$keys)||array_diff($keys,array_keys($a)))throw new InvalidArgumentException('Use only the displayed fields.');foreach($a as$v)if(!is_string($v))throw new InvalidArgumentException('Invalid field type.');}
    private static function text(string $s):string{if(trim($s)===''||!mb_check_encoding($s,'UTF-8')||mb_strlen($s)>240||preg_match('/[<>\x00-\x1f\x7f]/u',$s))throw new InvalidArgumentException('Use a short plain-text reason.');return trim($s);}
    private static function integer(string $v,int $min,int $max):int{if(!preg_match('/^-?(0|[1-9][0-9]{0,8})$/D',$v)||(int)$v<$min||(int)$v>$max)throw new InvalidArgumentException('Use bounded integer minor units.');return(int)$v;}
    public static function period(string $key,string $cycle):array
    {
        if(!in_array($cycle,['MONTHLY','ANNUAL'],true)||!preg_match('/^20[2-9][0-9]-(0[1-9]|1[0-2])$/D',$key)||($cycle==='ANNUAL'&&substr($key,-2)!=='01'))throw new InvalidArgumentException('Choose an exact UTC calendar month, or January for an annual period.');
        $start=new \DateTimeImmutable($key.'-01T00:00:00Z');return[$start->getTimestamp(),$start->modify($cycle==='MONTHLY'?'+1 month':'+1 year')->getTimestamp()];
    }
    private function scope(string $m,string $o,bool $platform):void
    {
        $this->gate();Identity::validate($m);if($o!=='all')Identity::validate($o);
        if($platform)$this->admin();else{
            if($o==='all'){$found=$this->rows('SELECT outlet_id FROM merchant_outlets WHERE merchant_id=? ORDER BY outlet_id LIMIT 1',[$m]);if(!$found)throw new DomainException('Merchant scope unavailable.');$resolve=$found[0]['outlet_id'];}else $resolve=$o;
            if($this->db->inTransaction()){
                // Match Entitlements' trusted transaction pattern: do not reuse a caller-owned directory snapshot.
                $user=$this->auth->currentUserId();if(!$user||!$this->rows('SELECT o.outlet_id FROM merchant_outlets o JOIN merchant_organizations m ON m.merchant_id=o.merchant_id AND m.active=1 JOIN merchant_staff s ON s.user_id=? AND s.active=1 WHERE o.merchant_id=? AND o.outlet_id=? AND o.active=1 FOR UPDATE',[$user,$m,$resolve]))throw new DomainException('Current native tenant identity required.');
            }else $user=TenantContext::resolve($this->auth,new PdoIdentityStore($this->db),$m,$resolve)->userId;
            if(!$this->rows("SELECT user_id FROM merchant_memberships WHERE merchant_id=? AND user_id=? AND role_code='merchant_owner' AND active=1".($this->db->inTransaction()?' FOR UPDATE':''),[$m,$user]))throw new DomainException('Merchant owner billing visibility required.');
        }
        if(!$this->rows('SELECT merchant_id FROM merchant_organizations WHERE merchant_id=?',[$m]))throw new DomainException('Unknown merchant.');
        if($o!=='all'&&!$this->rows('SELECT outlet_id FROM merchant_outlets WHERE merchant_id=? AND outlet_id=?',[$m,$o]))throw new DomainException('Outlet ownership required.');
    }
    private function audit(string $m,string $actor,string $request,string $hash,string $op,string $id,string $reason):array
    {
        $this->rows('INSERT INTO merchant_billing_audit(merchant_id,actor_id,request_id,payload_hash,operation,record_id,reason,created_epoch) VALUES(?,?,?,?,?,?,?,?)',[$m===''?null:$m,$actor,$request,$hash,$op,$id,$reason,$this->now()]);return['id'=>$id,'duplicate'=>false];
    }
    private function write(string $m,string $request,string $op,array $data,string $reason,string $confirm,callable $fn):array
    {
        $this->admin();if(!preg_match('/^[a-f0-9]{32}$/D',$request)||$confirm!=='yes')throw new InvalidArgumentException('Confirm this synthetic change.');$reason=self::text($reason);$hash=hash('sha256',json_encode([$m,$op,$data,$reason],JSON_THROW_ON_ERROR));
        return(new MerchantProvisioner($this->db,$this->auth))->administer(function(string $actor)use($m,$request,$op,$data,$reason,$hash,$fn){
            $prior=$this->rows('SELECT * FROM merchant_billing_audit WHERE request_id=? FOR UPDATE',[$request]);
            if($prior){$p=$prior[0];if(($p['merchant_id']??'')!==$m||$p['actor_id']!==$actor||$p['operation']!==$op||!hash_equals($p['payload_hash'],$hash))throw new DomainException('Request receipt conflict.');return['id'=>$p['record_id'],'duplicate'=>true];}
            $id=$fn();return $this->audit($m,$actor,$request,$hash,$op,$id,$reason);
        });
    }
    public function price(string $request,array $d,string $reason,string $confirm):array
    {
        self::fields($d,['plan','plan_version','cycle','expected','subscription','fee','support','effective']);
        if(!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$d['plan'])||!in_array($d['cycle'],['MONTHLY','ANNUAL'],true))throw new InvalidArgumentException('Choose a versioned plan and cycle.');
        $pv=self::integer($d['plan_version'],1,999999);$expected=self::integer($d['expected'],0,999999);$sub=self::integer($d['subscription'],0,12000000);$fee=self::integer($d['fee'],0,300);$support=self::integer($d['support'],0,1000000);$effective=Entitlements::instant($d['effective']);
        return $this->write('',$request,'price',$d,$reason,$confirm,function()use($d,$pv,$expected,$sub,$fee,$support,$effective){
            if(!$this->rows('SELECT version FROM merchant_plan_versions WHERE plan_id=? AND version=? FOR UPDATE',[$d['plan'],$pv]))throw new DomainException('Existing immutable plan version required.');
            $this->rows('INSERT INTO merchant_pricing_heads VALUES(?,?,?,0) ON DUPLICATE KEY UPDATE version=version',[$d['plan'],$pv,$d['cycle']]);
            $head=$this->rows('SELECT version FROM merchant_pricing_heads WHERE plan_id=? AND plan_version=? AND cycle=? FOR UPDATE',[$d['plan'],$pv,$d['cycle']])[0];if((int)$head['version']!==$expected)throw new DomainException('Pricing changed; reload.');
            $old=$this->rows('SELECT effective_epoch FROM merchant_pricing_versions WHERE plan_id=? AND plan_version=? AND cycle=? ORDER BY version DESC LIMIT 1',[$d['plan'],$pv,$d['cycle']]);
            $floor=$old?$this->now():self::period(gmdate('Y-m',$this->now()),'MONTHLY')[0];if($effective<$floor||$effective>$this->now()+2*366*86400||($old&&$effective<=(int)$old[0]['effective_epoch']))throw new DomainException('Use a later effective instant; existing prices cannot be backdated.');
            $id=Identity::generate();$this->rows('INSERT INTO merchant_pricing_versions VALUES(?,?,?,?,?,?,?,?,?)',[$id,$d['plan'],$pv,$d['cycle'],$expected+1,$sub,$fee,$support,$effective]);
            $this->rows('UPDATE merchant_pricing_heads SET version=version+1 WHERE plan_id=? AND plan_version=? AND cycle=?',[$d['plan'],$pv,$d['cycle']]);return$id;
        });
    }
    private function head(string $m,string $expected):int
    {
        Identity::validate($m);$v=self::integer($expected,0,99999999);
        if(!$this->rows('SELECT merchant_id FROM merchant_organizations WHERE merchant_id=? FOR UPDATE',[$m]))throw new DomainException('Unknown merchant.');
        $this->rows('INSERT INTO merchant_meter_heads VALUES(?,0) ON DUPLICATE KEY UPDATE version=version',[$m]);$h=(int)$this->rows('SELECT version FROM merchant_meter_heads WHERE merchant_id=? FOR UPDATE',[$m])[0]['version'];if($h!==$v)throw new DomainException('Usage changed; reload.');return$h;
    }
    private function advance(string $m):void{$this->rows('UPDATE merchant_meter_heads SET version=version+1 WHERE merchant_id=?',[$m]);}
    public function event(string $m,string $request,array $d,string $reason,string $confirm):array
    {
        self::fields($d,['outlet','source','classification','occurred','reference','amount','expected']);
        if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$d['source']))throw new InvalidArgumentException('Use an opaque synthetic source ID.');
        if(!in_array($d['classification'],['SIMULATED_ELIGIBLE','INTERNAL_SERVICE','BILL_DRAFT','KOT','DEMO_EXCLUDED','REVERSAL','ADJUSTMENT'],true))throw new InvalidArgumentException('Explicit synthetic classification required.');
        $epoch=Entitlements::instant($d['occurred']);$amount=self::integer($d['amount'],-1000000,1000000);
        return$this->write($m,$request,'event',$d,$reason,$confirm,function()use($m,$d,$epoch,$amount){
            $this->head($m,$d['expected']);$this->scope($m,$d['outlet'],true);if($epoch>$this->now()||$epoch<$this->now()-366*86400)throw new InvalidArgumentException('Use a recent nonfuture synthetic timestamp.');
            $units=$d['classification']==='SIMULATED_ELIGIBLE'?1:0;$reference=null;$delta=0;$occurred=$epoch;
            if($d['classification']==='REVERSAL'){
                Identity::validate($d['reference']);$old=$this->rows('SELECT * FROM merchant_usage_ledger WHERE merchant_id=? AND outlet_id=? AND event_id=? FOR UPDATE',[$m,$d['outlet'],$d['reference']]);
                if(!$old||$old[0]['classification']==='REVERSAL'||$amount!==0||$epoch!==(int)$old[0]['occurred_epoch'])throw new DomainException('Reverse the exact original scope and UTC occurrence; do not reverse a reversal.');$old=$old[0];$units=-(int)$old['units'];$delta=-(int)$old['adjustment_paise'];$reference=$old['event_id'];
            }else{
                if($d['reference']!=='')throw new InvalidArgumentException('Only reversals reference an event.');
                if($d['classification']==='ADJUSTMENT'){if($amount===0)throw new InvalidArgumentException('Adjustment must be nonzero.');$delta=$amount;}elseif($amount!==0)throw new InvalidArgumentException('Usage events cannot override prices.');
                if(in_array($d['classification'],['SIMULATED_ELIGIBLE','ADJUSTMENT'],true)){
                    $state=(new Entitlements($this->db,$this->auth))->platformLifecycleForTransaction($m);$a=$state['assignment']??null;
                    if(!$a||!in_array($state['state'],['TRIAL','ACTIVE','GRACE'],true)||!in_array('billing',json_decode($a['features_json'],true),true)||!PlatformAccess::canStart($this->db,$m,$d['outlet']))throw new DomainException('Current billing entitlement and administrative access required for simulated eligibility.');
                }
            }
            $id=Identity::generate();$this->rows('INSERT INTO merchant_usage_ledger VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$m,$d['outlet'],$d['source'],$d['classification'],$occurred,gmdate('Y-m',$occurred),$units,$delta,$reference]);$this->advance($m);return$id;
        });
    }
    private function calculate(string $m,string $outlet,string $key,string $cycle,bool $support):array
    {
        [$start,$end]=self::period($key,$cycle);
        // Reuse current native lifecycle selection; this is an explicitly labelled scenario, not historic invoicing.
        $state=(new Entitlements($this->db,$this->auth))->platformLifecycleForTransaction($m);$a=$state['assignment']??null;
        // Owner reads use native overview instead of platform-only introspection.
        return $this->calculation($m,$outlet,$key,$cycle,$support,$a,$state,$start,$end);
    }
    private function calculation(string $m,string $outlet,string $key,string $cycle,bool $support,?array $a,?array $state,int $start,int $end):array
    {
        $prices=$a?$this->rows('SELECT * FROM merchant_pricing_versions WHERE plan_id=? AND plan_version=? AND cycle=? AND effective_epoch<=? ORDER BY effective_epoch DESC,version DESC LIMIT 1',[$a['plan_id'],$a['plan_version'],$cycle,$start]):[];$price=$prices[0]??null;
        $args=[$m,$start,$end];$where='merchant_id=? AND occurred_epoch>=? AND occurred_epoch<?';if($outlet!=='all'){$where.=' AND outlet_id=?';$args[]=$outlet;}
        $tot=$this->rows('SELECT COUNT(*) records,COALESCE(SUM(units),0) units,COALESCE(SUM(adjustment_paise),0) adjustment FROM merchant_usage_ledger WHERE '.$where,$args)[0];
        $units=(int)$tot['units'];$adjustment=(int)$tot['adjustment'];if($units<0||$units>1000000||abs($adjustment)>1000000000000)throw new DomainException('Scenario exceeds calculation limits.');
        $fee=$price?$units*(int)$price['fee_paise']:null;$subscription=$price?($outlet==='all'?(int)$price['subscription_paise']:0):null;$supportCost=$price?($outlet==='all'&&$support?(int)$price['support_paise']:0):null;
        $hashes=$this->rows('SELECT event_id,units,adjustment_paise FROM merchant_usage_ledger WHERE '.$where.' ORDER BY event_id',$args);
        return['notice'=>self::NOTICE,'policy'=>self::POLICY,'merchant'=>$m,'outlet'=>$outlet,'period'=>$key,'cycle'=>$cycle,'startUtc'=>gmdate('c',$start),'endExclusiveUtc'=>gmdate('c',$end),'assignment'=>$a?array_intersect_key($a,array_flip(['version','plan_id','plan_version','effective_epoch','expires_epoch'])):null,'lifecycle'=>$state['state']??'UNASSIGNED','price'=>$price,'syntheticRecords'=>(int)$tot['records'],'eligibleNetUnits'=>$units,'adjustmentPaise'=>$adjustment,'usagePaise'=>$fee,'subscriptionPaise'=>$subscription,'supportPaise'=>$supportCost,'totalPaise'=>$price?$fee+$subscription+$supportCost+$adjustment:null,'currency'=>'INR','commercialEligible'=>false,'usageReferences'=>$hashes,'reconciles'=>$price!==null,'sourceVersion'=>(int)($this->rows('SELECT version FROM merchant_meter_heads WHERE merchant_id=?',[$m])[0]['version']??0),'ledgerSha256'=>hash('sha256',json_encode($hashes,JSON_THROW_ON_ERROR)),'createdUtc'=>gmdate('c',$this->now())];
    }
    public function save(string $m,string $request,array $d,string $reason,string $confirm):array
    {
        self::fields($d,['period','cycle','support','expected']);if(!in_array($d['support'],['0','1'],true))throw new InvalidArgumentException('Choose optional support.');
        return$this->write($m,$request,'preview',$d,$reason,$confirm,function()use($m,$d){$version=$this->head($m,$d['expected']);$s=$this->calculate($m,'all',$d['period'],$d['cycle'],$d['support']==='1');if(!$s['reconciles'])throw new DomainException('No applicable illustrative price at period start.');$id=Identity::generate();$this->rows('INSERT INTO merchant_billing_previews VALUES(?,?,?,?)',[$id,$m,$version,json_encode($s,JSON_THROW_ON_ERROR)]);$this->advance($m);return$id;});
    }
    public function load(string $m,string $o,string $key,string $cycle,bool $support,bool $platform,string $page='1'):array
    {
        $this->scope($m,$o,$platform);[$start,$end]=self::period($key,$cycle);$n=self::integer($page,1,999999);
        if($this->db->inTransaction())throw new \LogicException('Estimate read owns transaction.');$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{
            $this->scope($m,$o,$platform);
            if($platform){$state=(new Entitlements($this->db,$this->auth))->platformLifecycleForTransaction($m);$a=$state['assignment']??null;}else{$known=$this->rows('SELECT outlet_id FROM merchant_outlets WHERE merchant_id=? ORDER BY outlet_id LIMIT 1',[$m])[0]['outlet_id'];$overview=(new Entitlements($this->db,$this->auth))->overview($m,$o==='all'?$known:$o);$state=$overview['lifecycle'];$a=$overview['assignment'];}
            $s=$this->calculation($m,$o,$key,$cycle,$support,$a,$state,$start,$end);$args=[$m,$start,$end];$where='merchant_id=? AND occurred_epoch>=? AND occurred_epoch<?';if($o!=='all'){$where.=' AND outlet_id=?';$args[]=$o;}$pages=max(1,(int)ceil($s['syntheticRecords']/25));$n=min($n,$pages);
            $s['ledger']=$this->rows('SELECT * FROM merchant_usage_ledger WHERE '.$where.' ORDER BY occurred_epoch DESC,event_id LIMIT 25 OFFSET '.(($n-1)*25),$args);$s['page']=$n;$s['pages']=$pages;
            $s['outlets']=$this->rows('SELECT outlet_id,name FROM merchant_outlets WHERE merchant_id=? ORDER BY name',[$m]);
            $s['history']=$o==='all'?$this->rows('SELECT preview_id,source_version,snapshot_json FROM merchant_billing_previews WHERE merchant_id=? ORDER BY source_version DESC LIMIT 25',[$m]):[];
            $s['audit']=$this->rows('SELECT operation,record_id,reason,created_epoch,actor_id FROM merchant_billing_audit WHERE merchant_id=? ORDER BY audit_id DESC LIMIT 25',[$m]);$s['platform']=$platform;$this->db->commit();return$s;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function catalogue():array
    {
        $this->admin();return['plans'=>$this->rows('SELECT plan_id,version,name FROM merchant_plan_versions ORDER BY plan_id,version DESC'),'prices'=>$this->rows('SELECT p.*,a.reason,a.created_epoch FROM merchant_pricing_versions p JOIN merchant_billing_audit a ON a.record_id=p.price_id AND a.operation=\'price\' ORDER BY a.audit_id DESC LIMIT 100'),'heads'=>$this->rows('SELECT * FROM merchant_pricing_heads'),'merchants'=>(new PlatformAdministration($this->db,$this->auth))->directory()['merchants'],'usage'=>$this->rows('SELECT m.name,e.merchant_id,COUNT(*) records,SUM(e.units) units FROM merchant_usage_ledger e JOIN merchant_organizations m ON m.merchant_id=e.merchant_id GROUP BY e.merchant_id,m.name ORDER BY m.name LIMIT 100')];
    }
    public static function report(array $s):string{return json_encode(['notice'=>self::NOTICE,'report'=>'Authorized demonstration estimate and current paginated ledger; no invoice or payment request','estimate'=>array_diff_key($s,array_flip(['outlets','audit','platform']))],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);}
}
