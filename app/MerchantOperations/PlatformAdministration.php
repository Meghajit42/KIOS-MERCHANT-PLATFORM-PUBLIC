<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;

use PDO;
use DomainException;
use InvalidArgumentException;
use Kios\Infrastructure\Environment;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity,PdoIdentityStore,Role};

/** Private platform facade. Native M02 transactions/identity services remain the authority. */
final class PlatformAdministration
{
    public function __construct(private PDO $db,private AuthenticatedUserProvider $auth){}
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function admin():string
    {
        if(!PlatformAccess::enabled()||Environment::get('APP_ENV')!=='demo')throw new DomainException('Private platform administration disabled.');
        return (new MerchantProvisioner($this->db,$this->auth))->assertAdministrator();
    }
    private static function text(mixed $v,int $max):string
    {
        if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||trim($v)===''||mb_strlen(trim($v))>$max||preg_match('/[<>\x00-\x1f\x7f]/u',$v))throw new InvalidArgumentException('Use bounded plain text.');return trim($v);
    }
    private static function exact(array $data,array $keys):void
    {
        if(array_diff(array_keys($data),$keys)||array_diff($keys,array_keys($data)))throw new InvalidArgumentException('Use only the displayed fields.');
        foreach($data as$v)if(!is_string($v))throw new InvalidArgumentException('Invalid field type.');
    }
    private static function request(string $r):void{if(!preg_match('/^[a-f0-9]{32}$/D',$r))throw new InvalidArgumentException('Reload submission form.');}
    private function head(string $m):array
    {
        $org=$this->rows('SELECT active FROM merchant_organizations WHERE merchant_id=? FOR UPDATE',[$m]);if(!$org)throw new DomainException('Unknown merchant.');
        $this->rows('INSERT INTO merchant_plan_heads(merchant_id) VALUES(?) ON DUPLICATE KEY UPDATE merchant_id=VALUES(merchant_id)',[$m]);
        $this->rows('SELECT version FROM merchant_plan_heads WHERE merchant_id=? FOR UPDATE',[$m]);
        $this->rows('INSERT INTO merchant_platform_controls VALUES(?,0,?) ON DUPLICATE KEY UPDATE merchant_id=VALUES(merchant_id)',[$m,(int)$org[0]['active']?'ACTIVE_DEMO':'PROVISIONING']);
        return $this->rows('SELECT * FROM merchant_platform_controls WHERE merchant_id=? FOR UPDATE',[$m])[0];
    }
    private function prior(string $m,string $actor,string $request,string $hash):?array
    {
        $old=$this->rows('SELECT * FROM merchant_platform_events WHERE request_id=? FOR UPDATE',[$request]);
        if(!$old)return null;$r=$old[0];if($r['merchant_id']!==$m||$r['actor_id']!==$actor||!hash_equals($r['payload_hash'],$hash))throw new DomainException('Administrative request key conflict.');
        return ['merchant'=>$m,'version'=>(int)$r['version'],'duplicate'=>true,'result'=>json_decode($r['details_json'],true)['result']];
    }
    private function audit(string $m,string $actor,string $request,string $hash,int $version,string $op,string $reason,array $detail,array $result):array
    {
        $this->rows('INSERT INTO merchant_platform_events(merchant_id,version,actor_id,request_id,payload_hash,operation,details_json,reason,created_epoch) VALUES(?,?,?,?,?,?,?,?,?)',[$m,$version,$actor,$request,$hash,$op,json_encode(['detail'=>$detail,'result'=>$result],JSON_THROW_ON_ERROR),$reason,time()]);
        $this->rows('UPDATE merchant_platform_controls SET version=? WHERE merchant_id=?',[$version,$m]);
        return ['merchant'=>$m,'version'=>$version,'duplicate'=>false,'result'=>$result];
    }
    /** Native creation/activation observers share the M02 transaction: no unaudited partial activation. */
    public function provision(string $request,array $data):array
    {
        $this->admin();self::request($request);$hash=hash('sha256',json_encode(['provision',$data],JSON_THROW_ON_ERROR));
        $observer=function(PDO $db,string $actor,array $result)use($request,$hash):void{
            $m=$result['receipt']['merchant_id'];$head=$this->head($m);if($this->prior($m,$actor,$request,$hash))return;
            $this->rows("UPDATE merchant_platform_controls SET status='PROVISIONING' WHERE merchant_id=?",[$m]);
            if(IntegratedSetup::enabled())IntegratedSetup::initialize($db,$m,$result['receipt']['merchant_outlet_id'],$actor);
            $this->audit($m,$actor,$request,$hash,(int)$head['version']+1,'provision','Controlled synthetic merchant provisioning',[],['merchant'=>$m,'outlet'=>$result['receipt']['merchant_outlet_id']]);
        };
        return (new MerchantProvisioner($this->db,$this->auth,$observer))->provision($request,$data);
    }
    public function activateOwner(string $m,int $version,string $request,string $password,string $confirmation):array
    {
        $this->admin();Identity::validate($m);self::request($request);$hash=hash('sha256',json_encode(['owner-activate',$m,$version]));
        $observer=function(PDO $db,string $actor,array $result)use($m,$request,$hash):void{
            $head=$this->head($m);if($this->prior($m,$actor,$request,$hash))return;
            if($head['status']!=='PROVISIONING')throw new DomainException('Owner activation requires provisioning status.');
            $this->audit($m,$actor,$request,$hash,(int)$head['version']+1,'owner-activate','Attended synthetic owner credential establishment',[],['outlet'=>$result['receipt']['merchant_outlet_id']]);
        };
        return (new MerchantProvisioner($this->db,$this->auth,$observer))->activate($m,$version,$request,$password,$confirmation);
    }
    private function eligible(string $m):void
    {
        if(!$this->rows("SELECT p.merchant_id FROM merchant_onboarding p JOIN merchant_organizations m ON m.merchant_id=p.merchant_id AND m.active=1 JOIN merchant_staff s ON s.user_id=p.owner_user_id AND s.active=1 JOIN merchant_memberships mm ON mm.user_id=p.owner_user_id AND mm.merchant_id=p.merchant_id AND mm.active=1 JOIN staff_accounts a ON a.account_id=p.owner_account_id AND a.enabled=1 AND a.must_change=0 WHERE p.merchant_id=? AND p.status='active' FOR UPDATE",[$m]))throw new DomainException('Native owner activation is required.');
        if(IntegratedSetup::enabled()){$initial=$this->rows('SELECT merchant_outlet_id FROM merchant_onboarding WHERE merchant_id=? FOR UPDATE',[$m]);if(!$initial||!IntegratedSetup::reviewed($this->db,$m,$initial[0]['merchant_outlet_id']))throw new DomainException('Review the initial outlet configuration before private access activation.');}
        $state=(new Entitlements($this->db,$this->auth))->platformLifecycleForTransaction($m);
        if(!$state||!in_array($state['state'],['TRIAL','ACTIVE','GRACE'],true))throw new DomainException('A current authorized subscription lifecycle is required.');
    }
    public function act(string $m,string $expected,string $request,string $op,array $data,string $reason,string $confirm):array
    {
        $this->admin();Identity::validate($m);self::request($request);$reason=self::text($reason,240);
        if(!ctype_digit($expected)||strlen($expected)>8||$confirm!=='yes')throw new InvalidArgumentException('Confirm the current administrative change.');
        $keys=match($op){'merchant-status'=>['status'],'outlet-create'=>['name','opening','closing'],'outlet-update'=>['outlet','name','opening','closing'],'outlet-status'=>['outlet','status'],'administrator'=>['outlet','user','role','active'],default=>throw new InvalidArgumentException('Unknown administration operation.')};
        self::exact($data,$keys);$hash=hash('sha256',json_encode([$m,$expected,$op,$data,$reason],JSON_THROW_ON_ERROR));
        if($op==='administrator'){
            Identity::validate($data['outlet']);Identity::validate($data['user']);
            if(!in_array($data['role'],['outlet_administrator','cashier','kitchen'],true)||!in_array($data['active'],['0','1'],true))throw new InvalidArgumentException('Choose an existing outlet role bundle.');
            $head=null;
            return (new PdoIdentityStore($this->db))->manageOutletAdministrator($this->auth,$m,$data['outlet'],$data['user'],Role::from($data['role']),$data['active']==='1',
                function(PDO $db,string $actor)use($m,$expected,$request,$hash,&$head):?array{$head=$this->head($m);$old=$this->prior($m,$actor,$request,$hash);if($old)return $old;if((string)$head['version']!==$expected)throw new DomainException('Administration changed; reload.');if($head['status']!=='ACTIVE_DEMO')throw new DomainException('Reactivate administrative access first.');return null;},
                function(PDO $db,string $actor,?array $before,array $after)use($m,$request,$hash,$op,$reason,$data,&$head):array{return $this->audit($m,$actor,$request,$hash,(int)$head['version']+1,$op,$reason,['outlet'=>$data['outlet'],'user'=>$data['user'],'before'=>$before,'after'=>$after],['outlet'=>$data['outlet'],'user'=>$data['user'],'role'=>$after['role'],'active'=>$after['active']]);});
        }
        $native=new MerchantProvisioner($this->db,$this->auth);
        return $native->administer(function(string $actor)use($native,$m,$expected,$request,$op,$data,$reason,$hash):array{
            $head=$this->head($m);$prior=$this->prior($m,$actor,$request,$hash);if($prior)return $prior;
            if((string)$head['version']!==$expected)throw new DomainException('Administration changed; reload.');
            $detail=[];$result=[];
            if($op==='merchant-status'){
                $next=$data['status'];$allowed=['PROVISIONING'=>['ACTIVE_DEMO','ARCHIVED'],'ACTIVE_DEMO'=>['SUSPENDED','ARCHIVED'],'SUSPENDED'=>['ACTIVE_DEMO','ARCHIVED'],'ARCHIVED'=>['ACTIVE_DEMO','SUSPENDED']];
                if(!in_array($next,$allowed[$head['status']],true))throw new DomainException('Invalid merchant status transition.');
                if($next==='ARCHIVED'&&PlatformAccess::hasWork($this->db,$m))throw new DomainException('Complete services, tickets, tables and reservations before archiving.');
                if($next==='ACTIVE_DEMO'){
                    $this->eligible($m);
                    if($head['status']==='PROVISIONING'&&!$this->rows('SELECT merchant_id FROM merchant_owner_setup WHERE merchant_id=? AND business_saved=1 AND outlet_saved=1',[$m]))throw new DomainException('Owner must save business and initial outlet setup before demonstration activation.');
                }
                $this->rows('UPDATE merchant_platform_controls SET status=? WHERE merchant_id=?',[$next,$m]);$detail=['before'=>$head['status'],'after'=>$next];$result=['status'=>$next];
            }else{
                if($head['status']!=='ACTIVE_DEMO')throw new DomainException('Active demonstration access required for outlet administration.');
                if(in_array($op,['outlet-update','outlet-status'],true)){
                    Identity::validate($data['outlet']);$out=$this->rows('SELECT o.*,l.legacy_outlet_id FROM merchant_outlets o JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id WHERE o.merchant_id=? AND o.outlet_id=? FOR UPDATE',[$m,$data['outlet']]);if(!$out)throw new DomainException('Mapped outlet ownership required.');$out=$out[0];
                }
                if($op==='outlet-status'){
                    if(!in_array($data['status'],['ACTIVE_DEMO','SUSPENDED','ARCHIVED'],true))throw new InvalidArgumentException('Invalid outlet status.');$old=PlatformAccess::status($this->db,$m,$data['outlet'])['outlet'];if($old===$data['status'])throw new DomainException('Outlet already has this status.');
                    if($data['status']==='ARCHIVED'&&PlatformAccess::hasWork($this->db,$m,$data['outlet']))throw new DomainException('Complete outlet work before archiving.');
                    $this->rows('INSERT INTO merchant_platform_outlets VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)',[$m,$data['outlet'],$data['status']]);$detail=['before'=>$old,'after'=>$data['status']];$result=['outlet'=>$data['outlet'],'status'=>$data['status']];
                }else{
                    $name=self::text($data['name'],160);foreach(['opening','closing']as$k)if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$data[$k]))throw new InvalidArgumentException('Use valid 24-hour times.');if($data['opening']>=$data['closing'])throw new InvalidArgumentException('Closing must follow opening.');
                    $duplicates=$this->rows('SELECT outlet_id FROM merchant_outlets WHERE merchant_id=? AND LOWER(name)=LOWER(?) FOR UPDATE',[$m,$name]);foreach($duplicates as$d)if($op==='outlet-create'||$d['outlet_id']!==$data['outlet'])throw new DomainException('An outlet with this name already exists.');
                    if($op==='outlet-create'){(new Entitlements($this->db,$this->auth))->platformOutletLimit($m);$result=$native->additionalOutlet($m,$name,$data['opening'],$data['closing']);if(IntegratedSetup::enabled())IntegratedSetup::initialize($this->db,$m,$result['outlet'],$actor);$detail=['name'=>$name,'opening'=>$data['opening'],'closing'=>$data['closing']];}
                    else{
                        $shop=$this->rows('SELECT * FROM shop_operations WHERE outlet_id=? FOR UPDATE',[$out['legacy_outlet_id']])[0];if((int)$shop['manual_enabled']!==0||PlatformAccess::hasWork($this->db,$m,$data['outlet']))throw new DomainException('Finish outlet work and keep the shop closed before changing its configuration.');
                        $this->rows('UPDATE merchant_outlets SET name=? WHERE merchant_id=? AND outlet_id=?',[$name,$m,$data['outlet']]);$this->rows('UPDATE outlets SET name=? WHERE outlet_id=?',[$name,$out['legacy_outlet_id']]);$fields=array_intersect_key($shop,array_flip(['unpaid_minutes','ready_warning_minutes','exception_minutes','collected_seconds','aging_minutes']));$fields['opening_time']=$data['opening'];$fields['closing_time']=$data['closing'];(new \Kios\Infrastructure\ShopOperations($this->db))->act((int)$out['legacy_outlet_id'],'configure',(int)$shop['version'],substr(hash('sha256','platform-outlet-config:'.$request),0,32),$fields,'platform:'.$actor);$detail=['before'=>['name'=>$out['name'],'opening'=>$shop['opening_time'],'closing'=>$shop['closing_time']],'after'=>['name'=>$name,'opening'=>$data['opening'],'closing'=>$data['closing']]];$result=['outlet'=>$data['outlet']];
                    }
                }
            }
            return $this->audit($m,$actor,$request,$hash,(int)$head['version']+1,$op,$reason,$detail,$result);
        });
    }

    /** Avoid parameter/literal coercible-collation comparisons; never alter tenant/schema collations. */
    private static function filters(string $query,string $status,array $columns,string $statusExpression):array
    {
        $clauses=[];$args=[];
        if($query!==''){
            $parts=[];foreach($columns as$column){$parts[]='LOCATE(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('.$column.' USING utf8mb4) COLLATE utf8mb4_unicode_ci)>0';$args[]=$query;}
            $clauses[]='('.implode(' OR ',$parts).')';
        }
        if($status!==''){$clauses[]='CAST('.$statusExpression.' AS BINARY)=CAST(? AS BINARY)';$args[]=$status;}
        return [$clauses?' WHERE '.implode(' AND ',$clauses):'',$args];
    }
    public function directory(string $query='',string $status='',string $page='1'):array
    {
        $this->admin();if(mb_strlen($query)>100||!mb_check_encoding($query,'UTF-8')||!ctype_digit($page)||strlen($page)>6||(int)$page<1||!in_array($status,['','PROVISIONING','ACTIVE_DEMO','SUSPENDED','ARCHIVED'],true))throw new InvalidArgumentException('Invalid directory filter.');
        [$where,$args]=self::filters($query,$status,['m.name','m.merchant_id'],"COALESCE(c.status,IF(m.active=1,'ACTIVE_DEMO','PROVISIONING'))");
        $total=(int)$this->rows('SELECT COUNT(*) n FROM merchant_organizations m LEFT JOIN merchant_platform_controls c ON c.merchant_id=m.merchant_id'.$where,$args)[0]['n'];$pages=max(1,(int)ceil($total/20));$p=min((int)$page,$pages);$offset=($p-1)*20;
        $list=$this->rows("SELECT m.merchant_id,m.name,COALESCE(c.status,IF(m.active=1,'ACTIVE_DEMO','PROVISIONING')) status,COALESCE(c.version,0) version,(SELECT COUNT(*) FROM merchant_outlets o WHERE o.merchant_id=m.merchant_id) outlets,p.status onboarding_status,s.progress setup_progress,NULLIF(GREATEST(COALESCE((SELECT MAX(e.created_epoch) FROM merchant_platform_events e WHERE e.merchant_id=m.merchant_id),0),COALESCE((SELECT MAX(e.occurred_epoch) FROM merchant_provisioning_events e WHERE e.merchant_id=m.merchant_id),0),COALESCE((SELECT MAX(e.created_epoch) FROM merchant_subscription_events e WHERE e.merchant_id=m.merchant_id),0),COALESCE((SELECT MAX(e.created_epoch) FROM merchant_plan_assignments e WHERE e.merchant_id=m.merchant_id),0)),0) last_activity FROM merchant_organizations m LEFT JOIN merchant_platform_controls c ON c.merchant_id=m.merchant_id LEFT JOIN merchant_onboarding p ON p.merchant_id=m.merchant_id LEFT JOIN merchant_owner_setup s ON s.merchant_id=m.merchant_id".$where.' ORDER BY m.name,m.merchant_id LIMIT 20 OFFSET '.$offset,$args);
        $life=array_column((new Entitlements($this->db,$this->auth))->lifecyclePlatform()['merchants'],null,'merchant_id');foreach($list as&$m)$m['subscription']=$life[$m['merchant_id']]['lifecycle']??null;unset($m);
        return ['merchants'=>$list,'query'=>$query,'status'=>$status,'page'=>$p,'pages'=>$pages,'total'=>$total];
    }
    public function outlets(string $query='',string $status='',string $page='1'):array
    {
        $this->admin();if(mb_strlen($query)>100||!mb_check_encoding($query,'UTF-8')||!ctype_digit($page)||strlen($page)>6||(int)$page<1||!in_array($status,['','ACTIVE_DEMO','SUSPENDED','ARCHIVED'],true))throw new InvalidArgumentException('Invalid outlet filter.');
        [$where,$args]=self::filters($query,$status,['o.name','m.name','o.outlet_id'],"COALESCE(c.status,'ACTIVE_DEMO')");
        $joins=' FROM merchant_outlets o JOIN merchant_organizations m ON m.merchant_id=o.merchant_id LEFT JOIN merchant_platform_outlets c ON c.merchant_id=o.merchant_id AND c.outlet_id=o.outlet_id';
        $total=(int)$this->rows('SELECT COUNT(*) n'.$joins.$where,$args)[0]['n'];$pages=max(1,(int)ceil($total/20));$p=min((int)$page,$pages);
        return ['query'=>$query,'status'=>$status,'page'=>$p,'pages'=>$pages,'total'=>$total,'outlets'=>$this->rows("SELECT o.merchant_id,o.outlet_id,o.name,m.name merchant_name,COALESCE(c.status,'ACTIVE_DEMO') admin_status,l.legacy_outlet_id,so.manual_enabled,so.opening_time,so.closing_time".$joins.' LEFT JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id LEFT JOIN shop_operations so ON so.outlet_id=l.legacy_outlet_id'.$where.' ORDER BY m.name,o.name,o.outlet_id LIMIT 20 OFFSET '.(($p-1)*20),$args)];
    }
    public function overview():array
    {
        $this->admin();$life=(new Entitlements($this->db,$this->auth))->lifecyclePlatform();$plans=[];$phases=[];foreach($life['merchants']as$m){$s=$m['lifecycle'];$plan=$s['assignment']['plan_id']??$m['legacy']['plan_id']??'Unassigned';$plans[$plan]=($plans[$plan]??0)+1;$phase=$s['state']??'UNMANAGED';$phases[$phase]=($phases[$phase]??0)+1;}
        return ['merchants'=>(int)$this->rows('SELECT COUNT(*) n FROM merchant_organizations')[0]['n'],'outlets'=>(int)$this->rows('SELECT COUNT(*) n FROM merchant_outlets')[0]['n'],'statuses'=>$this->rows("SELECT COALESCE(c.status,IF(m.active=1,'ACTIVE_DEMO','PROVISIONING')) status,COUNT(*) n FROM merchant_organizations m LEFT JOIN merchant_platform_controls c ON c.merchant_id=m.merchant_id GROUP BY 1"),'plans'=>$plans,'phases'=>$phases,'pending'=>(int)$this->rows("SELECT COUNT(*) n FROM merchant_onboarding p LEFT JOIN merchant_owner_setup s ON s.merchant_id=p.merchant_id WHERE p.status<>'active' OR s.merchant_id IS NULL OR s.progress<>'APPROVED'")[0]['n'],'activity'=>$this->auditPage('1')['events']];
    }
    public function detail(string $m):array
    {
        $actor=$this->admin();Identity::validate($m);$org=$this->rows("SELECT m.*,COALESCE(c.version,0) admin_version,COALESCE(c.status,IF(m.active=1,'ACTIVE_DEMO','PROVISIONING')) admin_status FROM merchant_organizations m LEFT JOIN merchant_platform_controls c ON c.merchant_id=m.merchant_id WHERE m.merchant_id=?",[$m]);if(!$org)throw new DomainException('Unknown merchant.');
        $p=$this->rows('SELECT merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id,status,version,created_by,created_epoch,activated_epoch FROM merchant_onboarding WHERE merchant_id=?',[$m]);$setup=$this->rows('SELECT progress,business_saved,outlet_saved FROM merchant_owner_setup WHERE merchant_id=?',[$m]);
        $outlets=$this->rows("SELECT o.*,COALESCE(c.status,'ACTIVE_DEMO') admin_status,l.legacy_outlet_id,s.opening_time,s.closing_time,s.manual_enabled FROM merchant_outlets o LEFT JOIN merchant_platform_outlets c ON c.merchant_id=o.merchant_id AND c.outlet_id=o.outlet_id LEFT JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id LEFT JOIN shop_operations s ON s.outlet_id=l.legacy_outlet_id WHERE o.merchant_id=? ORDER BY o.name,o.outlet_id",[$m]);
        $admins=$this->rows('SELECT mm.user_id,s.display_name,mm.role_code,mm.active,NULL outlet_id FROM merchant_memberships mm JOIN merchant_staff s ON s.user_id=mm.user_id WHERE mm.merchant_id=? UNION ALL SELECT mm.user_id,s.display_name,mm.role_code,mm.active,mm.outlet_id FROM merchant_outlet_memberships mm JOIN merchant_staff s ON s.user_id=mm.user_id WHERE mm.merchant_id=?',[$m,$m]);
        $staff=$this->rows("SELECT DISTINCT s.user_id,s.display_name,a.username FROM merchant_staff s JOIN merchant_auth_links l ON l.user_id=s.user_id AND l.active=1 JOIN merchant_legacy_outlets o ON o.legacy_outlet_id=l.legacy_outlet_id AND o.merchant_id=? JOIN staff_accounts a ON a.account_id=l.account_id AND a.enabled=1 AND a.must_change=0 WHERE s.active=1 AND NOT EXISTS(SELECT 1 FROM merchant_memberships mm WHERE mm.user_id=s.user_id) AND NOT EXISTS(SELECT 1 FROM merchant_platform_memberships mm WHERE mm.user_id=s.user_id) AND NOT EXISTS(SELECT 1 FROM merchant_financial_grants f WHERE f.user_id=s.user_id) AND NOT EXISTS(SELECT 1 FROM merchant_worker_grants w WHERE w.actor_user_id=s.user_id) ORDER BY a.username LIMIT 100",[$m]);
        $life=array_column((new Entitlements($this->db,$this->auth))->lifecyclePlatform()['merchants'],null,'merchant_id');
        return ['merchant'=>$org[0],'onboarding'=>$p[0]??null,'setup'=>$setup[0]??null,'outlets'=>$outlets,'administrators'=>$admins,'eligibleStaff'=>$staff,'subscription'=>$life[$m]['lifecycle']??null,'creatorCanActivate'=>isset($p[0])&&$p[0]['created_by']===$actor,'events'=>$this->auditPage('1',$m)['events']];
    }
    public function auditPage(string $page='1',?string $m=null):array
    {
        $this->admin();if(!ctype_digit($page)||strlen($page)>6||(int)$page<1)throw new InvalidArgumentException('Invalid audit page.');if($m!==null)Identity::validate($m);$args=$m===null?[]:[$m];$where=$m===null?'':' WHERE e.merchant_id=?';$total=(int)$this->rows('SELECT COUNT(*) n FROM merchant_platform_events e'.$where,$args)[0]['n'];$pages=max(1,(int)ceil($total/20));$p=min((int)$page,$pages);
        return ['page'=>$p,'pages'=>$pages,'total'=>$total,'events'=>$this->rows('SELECT e.*,m.name merchant_name FROM merchant_platform_events e JOIN merchant_organizations m ON m.merchant_id=e.merchant_id'.$where.' ORDER BY e.event_id DESC LIMIT 20 OFFSET '.(($p-1)*20),$args),'provisioning'=>$this->rows('SELECT e.operation,e.occurred_epoch,e.actor_user_id,p.merchant_id,m.name FROM merchant_provisioning_events e JOIN merchant_onboarding p ON p.merchant_id=e.merchant_id JOIN merchant_organizations m ON m.merchant_id=p.merchant_id ORDER BY e.event_id DESC LIMIT 20')];
    }
}
