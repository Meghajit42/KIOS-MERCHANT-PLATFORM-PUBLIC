<?php
declare(strict_types=1);
namespace Kios\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

/** Outlet operations; all timestamps are epoch/UTC, business dates are Asia/Kolkata. */
final class ShopOperations
{
    public function __construct(private PDO $db, private ?Closure $clock = null) {
        if ($clock !== null && PHP_SAPI !== 'cli') throw new DomainException('Test clocks are CLI-only.');
    }
    public static function enabled(): bool { return Environment::get('KIOS_SHOP_OPERATIONS_ENABLED','0') === '1'; }
    public function now(): DateTimeImmutable {
        $now=$this->clock===null?new DateTimeImmutable('now',new DateTimeZone('UTC')):($this->clock)();
        if(!$now instanceof DateTimeImmutable)throw new DomainException('Invalid operating clock.');
        return $now->setTimezone(new DateTimeZone('Asia/Kolkata'));
    }
    public function settings(int $outlet,bool $lock=false):array {
        $suffix=$lock&&$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$this->db->prepare('SELECT * FROM shop_operations WHERE outlet_id=?'.$suffix);$q->execute([$outlet]);
        $s=$q->fetch(PDO::FETCH_ASSOC);if(!$s)throw new DomainException('Shop operating settings are unavailable; ordering is closed.');return $s;
    }
    public function state(int $outlet):array {return $this->describe($this->settings($outlet));}
    private function describe(array $s):array {
        $now=$this->now();$date=$now->format('Y-m-d');$time=$now->format('H:i');
        $enabled=(int)$s['manual_enabled']===1&&$s['manual_date']===$date&&(int)$s['day_ended']===0;
        $within=$time>=$s['opening_time']&&$time<$s['closing_time'];
        $next=$now->setTime((int)substr($s['opening_time'],0,2),(int)substr($s['opening_time'],3,2));
        if($time>=$s['opening_time'])$next=$next->modify('+1 day');
        $reason=!$enabled?'Awaiting authorized opening':(!$within?'Outside scheduled hours':'Open for ordering');
        return ['open'=>$enabled&&$within,'reason'=>$reason,'businessDate'=>$date,'nextOpening'=>$next->format('d M Y, H:i').' IST (requires authorized opening)','settings'=>$s];
    }
    /** Must run inside the order transaction. Closing uses this same row lock. */
    public function assertAccepting(int $outlet):array {
        if(!$this->db->inTransaction())throw new DomainException('Checkout requires a transaction.');
        $s=$this->settings($outlet,true);$state=$this->describe($s);
        if(!$state['open'])throw new DomainException('The shop is closed. Your order was not accepted. Next scheduled opening: '.$state['nextOpening']);return $s;
    }
    public function serializeStaffMutation(int $outlet):void {$this->settings($outlet,true);}
    public function registerOrder(int $id,int $outlet,array $settings):void {
        $epoch=$this->now()->getTimestamp();$q=$this->db->prepare('INSERT INTO shop_order_acceptance(order_id,outlet_id,accepted_epoch,unpaid_deadline_epoch) VALUES(?,?,?,?)');
        $q->execute([$id,$outlet,$epoch,$epoch+(int)$settings['unpaid_minutes']*60]);
    }
    public function assertPaymentCurrent(int $id,int $outlet):void {
        $q=$this->db->prepare('SELECT o.created_at,a.unpaid_deadline_epoch FROM orders o LEFT JOIN shop_order_acceptance a ON a.order_id=o.order_id WHERE o.order_id=? AND o.outlet_id=?');$q->execute([$id,$outlet]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Order not found.');$s=$this->settings($outlet);
        $deadline=$row['unpaid_deadline_epoch']===null?$this->utcEpoch($row['created_at'])+(int)$s['unpaid_minutes']*60:(int)$row['unpaid_deadline_epoch'];
        if($this->now()->getTimestamp()>=$deadline)throw new DomainException('This unpaid order has expired. No payment was recorded. Start a new order.');
    }
    private function atomic(callable $work):mixed {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{$r=$work();if($owns)$this->db->commit();return $r;}catch(Throwable $e){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function event(int $outlet,string $request,string $hash,string $action,array $detail,string $actor):void {
        $q=$this->db->prepare('INSERT INTO shop_operation_events(outlet_id,request_id,request_hash,action,actor_ref,occurred_epoch,detail_json) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$outlet,$request,$hash,$action,$actor,$this->now()->getTimestamp(),json_encode($detail,JSON_THROW_ON_ERROR)]);
    }
    public function act(int $outlet,string $action,int $version,string $request,array $fields=[],string $actor='owner:menu-admin'):array {
        if(!preg_match('/^[a-f0-9]{32}$/D',$request)||!in_array($action,['open','close','configure','end-day'],true))throw new DomainException('Invalid shop action.');
        $hash=hash('sha256',json_encode([$action,$version,$fields],JSON_THROW_ON_ERROR));
        return $this->atomic(function()use($outlet,$action,$version,$request,$fields,$actor,$hash){
            $s=$this->settings($outlet,true);$q=$this->db->prepare('SELECT request_hash FROM shop_operation_events WHERE outlet_id=? AND request_id=?');$q->execute([$outlet,$request]);$prior=$q->fetchColumn();
            if($prior!==false){if(!hash_equals($prior,$hash))throw new DomainException('This request was already used for a different action.');return ['duplicate'=>true];}
            if((int)$s['version']!==$version)throw new DomainException('Shop settings changed in another tab. Reload before continuing.');
            $next=$s;$summary=null;
            if($action==='configure'){
                foreach(['opening_time','closing_time'] as $k)if(!is_string($fields[$k]??null)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$fields[$k]))throw new DomainException('Use valid 24-hour opening and closing times.');
                if($fields['opening_time']>=$fields['closing_time'])throw new DomainException('Closing must be after opening within the same calendar day.');
                foreach(['unpaid_minutes'=>[1,240],'ready_warning_minutes'=>[1,240],'exception_minutes'=>[2,1440],'collected_seconds'=>[0,600],'aging_minutes'=>[1,120]] as $k=>$bounds){
                    $value=filter_var($fields[$k]??null,FILTER_VALIDATE_INT);if($value===false||$value<$bounds[0]||$value>$bounds[1])throw new DomainException('Invalid lifecycle timeout.');$next[$k]=$value;
                }
                if($next['exception_minutes']<$next['ready_warning_minutes'])throw new DomainException('Exception timeout must not precede the ready warning.');
                $next['opening_time']=$fields['opening_time'];$next['closing_time']=$fields['closing_time'];
            }elseif($action==='open'){$next['manual_enabled']=1;$next['manual_date']=$this->now()->format('Y-m-d');$next['day_ended']=0;}
            else{$next['manual_enabled']=0;if($action==='end-day'){
                $summary=$this->summary($outlet);$note=trim((string)($fields['acknowledgement']??''));
                if($summary['outstanding']!==[]&&(($fields['acknowledge']??'')!=='1'||strlen($note)<8||strlen($note)>1000))throw new DomainException('Acknowledge outstanding orders with a note before ending the day. No order will be cancelled or financially voided.');
                $summary['acknowledgement']=$note;$summary['actor']=$actor;$next['day_ended']=1;$next['manual_date']=$this->now()->format('Y-m-d');
                $q=$this->db->prepare('INSERT INTO shop_day_closings(outlet_id,business_date,request_id,closed_epoch,summary_json) VALUES(?,?,?,?,?)');$q->execute([$outlet,$this->now()->format('Y-m-d'),$request,$this->now()->getTimestamp(),json_encode($summary,JSON_THROW_ON_ERROR)]);
            }}
            $columns=['opening_time','closing_time','manual_date','manual_enabled','day_ended','unpaid_minutes','ready_warning_minutes','exception_minutes','collected_seconds','aging_minutes'];
            $q=$this->db->prepare('UPDATE shop_operations SET '.implode(',',array_map(fn($k)=>$k.'=?',$columns)).',version=version+1 WHERE outlet_id=? AND version=?');$values=array_map(fn($k)=>$next[$k],$columns);$q->execute([...$values,$outlet,$version]);if($q->rowCount()!==1)throw new DomainException('Shop state changed; reload.');
            $this->event($outlet,$request,$hash,$action,['before'=>$s,'after'=>$next,'summary'=>$summary],$actor);return ['duplicate'=>false,'summary'=>$summary];
        });
    }
    /** Only orders accepted under this lifecycle are mutated; legacy history is classified, never silently rewritten. */
    public function sweep(int $outlet):int {
        return $this->atomic(function()use($outlet){
            $s=$this->settings($outlet,true);$now=$this->now();$epoch=$now->getTimestamp();
            if((int)$s['manual_enabled']===1&&($s['manual_date']!==$now->format('Y-m-d')||$now->format('H:i')>=$s['closing_time'])){
                $action=$s['manual_date']!==$now->format('Y-m-d')?'day_boundary_close':'scheduled_close';
                $q=$this->db->prepare('UPDATE shop_operations SET manual_enabled=0,version=version+1 WHERE outlet_id=?');$q->execute([$outlet]);$req=bin2hex(random_bytes(16));$this->event($outlet,$req,hash('sha256',$req),$action,['prior_open_date'=>$s['manual_date']],'system');
            }
            $q=$this->db->prepare("SELECT o.order_id FROM orders o JOIN shop_order_acceptance a ON a.order_id=o.order_id AND a.outlet_id=o.outlet_id WHERE o.outlet_id=? AND o.status='awaiting_payment' AND a.unpaid_deadline_epoch<=? AND NOT EXISTS(SELECT 1 FROM payment_confirmations pc WHERE pc.order_id=o.order_id)");$q->execute([$outlet,$epoch]);$count=0;
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
                $update=$this->db->prepare("UPDATE orders SET status='expired' WHERE order_id=? AND outlet_id=? AND status='awaiting_payment' AND NOT EXISTS(SELECT 1 FROM payment_confirmations pc WHERE pc.order_id=orders.order_id)");$update->execute([$id,$outlet]);if($update->rowCount()===1){$h=$this->db->prepare("INSERT INTO order_status_history(order_id,status,changed_by) VALUES(?,'expired','system:unpaid-timeout')");$h->execute([$id]);$count++;}
            }return $count;
        });
    }
    private function utcEpoch(string $value):int {return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->getTimestamp();}
    public function rows(int $outlet):array {
        $q=$this->db->prepare("SELECT o.order_id,o.public_id,o.pickup_token,o.token,o.pickup_business_date,o.status,o.total_paise,o.created_at,o.updated_at,pc.amount_paise AS paid_paise,pc.payment_method,a.unpaid_deadline_epoch,(SELECT MAX(h.changed_at) FROM order_status_history h WHERE h.order_id=o.order_id AND h.status='ready') ready_at,(SELECT MAX(h.changed_at) FROM order_status_history h WHERE h.order_id=o.order_id AND h.status='collected') collected_at FROM orders o LEFT JOIN payment_confirmations pc ON pc.order_id=o.order_id LEFT JOIN shop_order_acceptance a ON a.order_id=o.order_id WHERE o.outlet_id=?".InternalOrderBoundary::customerFilter($this->db).' ORDER BY o.created_at DESC,o.order_id DESC');$q->execute([$outlet]);$s=$this->settings($outlet);$result=[];$now=$this->now();
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $row['age_seconds']=max(0,$now->getTimestamp()-$this->utcEpoch($row['created_at']));$row['ready_seconds']=$row['ready_at']===null?0:max(0,$now->getTimestamp()-$this->utcEpoch($row['ready_at']));
            $row['carry_over']=$row['pickup_business_date']!==$now->format('Y-m-d');$row['paid']=$row['paid_paise']!==null;
            $row['unpaid_expired']=!$row['paid']&&($row['status']==='expired'||($row['status']==='awaiting_payment'&&$now->getTimestamp()>=($row['unpaid_deadline_epoch']===null?$this->utcEpoch($row['created_at'])+(int)$s['unpaid_minutes']*60:(int)$row['unpaid_deadline_epoch'])));
            $row['bucket']=match(true){in_array($row['status'],['collected','cancelled','expired','draft'],true)=>$row['unpaid_expired']?'exceptions':'history',$row['unpaid_expired']=>'exceptions',($row['carry_over']||($row['paid']&&$row['status']==='ready'&&$row['ready_seconds']>=(int)$s['exception_minutes']*60))=>'exceptions',$row['status']==='awaiting_payment'=>'awaiting',$row['status']==='ready'=>'ready',default=>'preparing'};
            $row['aging']=$row['age_seconds']>=(int)$s['aging_minutes']*60;$row['ready_warning']=$row['status']==='ready'&&$row['ready_seconds']>=(int)$s['ready_warning_minutes']*60;$result[]=$row;
        }return $result;
    }
    public function summary(int $outlet):array {
        $now=$this->now();$date=$now->format('Y-m-d');$rows=$this->rows($outlet);$today=array_values(array_filter($rows,fn($r)=>$r['pickup_business_date']===$date));$counts=[];foreach($today as $r)$counts[$r['status']]=($counts[$r['status']]??0)+1;
        $start=$now->setTime(0,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$end=$now->setTime(0,0)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $q=$this->db->prepare('SELECT pc.payment_method,COUNT(*) count,COALESCE(SUM(pc.amount_paise),0) amount_paise FROM payment_confirmations pc JOIN orders o ON o.order_id=pc.order_id WHERE o.outlet_id=? AND pc.confirmed_at>=? AND pc.confirmed_at<? GROUP BY pc.payment_method');$q->execute([$outlet,$start,$end]);$payments=$q->fetchAll(PDO::FETCH_ASSOC);
        return ['businessDate'=>$date,'asOfEpoch'=>$now->getTimestamp(),'orderCount'=>count($today),'orderStateCounts'=>$counts,'todayPaidOrderCount'=>count(array_filter($today,fn($r)=>$r['paid'])),'todayUnpaidOrderCount'=>count(array_filter($today,fn($r)=>!$r['paid'])),'payments'=>$payments,'paymentTotalPaise'=>array_sum(array_column($payments,'amount_paise')),'outstanding'=>array_values(array_map(fn($r)=>['orderId'=>$r['order_id'],'businessDate'=>$r['pickup_business_date'],'token'=>$r['pickup_token'],'status'=>$r['status'],'paid'=>$r['paid'],'amountPaise'=>$r['total_paise']],array_filter($rows,fn($r)=>in_array($r['status'],['paid','preparing','ready','awaiting_payment'],true)&&!$r['unpaid_expired'])))];
    }
    public function publicTokens(int $outlet,string $name):array {
        $s=$this->settings($outlet);$date=$this->now()->format('Y-m-d');$tokens=[];
        if((int)$s['day_ended']===1&&$s['manual_date']===$date)return ['outlet'=>$name,'tokens'=>[]];
        foreach($this->rows($outlet) as $r){if(!$r['paid']||$r['carry_over'])continue;$status=null;
            if(in_array($r['bucket'],['preparing','ready'],true))$status=strtoupper($r['status']);
            if($r['status']==='collected'&&$r['collected_at']!==null&&$this->now()->getTimestamp()-$this->utcEpoch($r['collected_at'])<(int)$s['collected_seconds'])$status='COLLECTED';
            if(!in_array($status,['PREPARING','READY','COLLECTED'],true))continue;
            $tokens[]=['token'=>(string)($r['pickup_token']??$r['token']),'publicStatus'=>$status,'outlet'=>$name,'updatedAt'=>(string)$r['updated_at']];
        }return ['outlet'=>$name,'tokens'=>$tokens];
    }
}
