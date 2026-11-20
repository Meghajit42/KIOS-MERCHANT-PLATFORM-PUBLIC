<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;

use DomainException;
use InvalidArgumentException;
use Kios\Infrastructure\Environment;
use Kios\MerchantFoundation\Identity;

/** Append-only lifecycle over the existing immutable M06A assignments. No scheduler or financial writes. */
trait SubscriptionLifecycle
{
    public static function lifecycleEnabled(): bool
    {
        return self::enabled() && Environment::get('KIOS_SUBSCRIPTION_LIFECYCLE_ENABLED', '0') === '1';
    }

    private function lifecycleEvent(string $merchant, bool $lock = false): ?array
    {
        if (!self::lifecycleEnabled()) return null;
        $rows = $this->rows('SELECT * FROM merchant_subscription_events WHERE merchant_id=? ORDER BY version DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''), [$merchant]);
        return $rows[0] ?? null;
    }

    private function assignmentVersion(string $merchant, int $version, bool $lock): array
    {
        $rows = $this->rows('SELECT a.*,p.name,p.description,p.features_json,p.limits_json,p.metadata_paise FROM merchant_plan_assignments a JOIN merchant_plan_versions p ON p.plan_id=a.plan_id AND p.version=a.plan_version WHERE a.merchant_id=? AND a.version=?' . ($lock ? ' FOR UPDATE' : ''), [$merchant, $version]);
        if (!$rows) throw new DomainException('Subscription assignment integrity failure.');
        return $rows[0];
    }

    /** Read-time transitions have no hidden GET writes. The immutable event records all UTC boundaries. */
    private function lifecycleState(string $merchant, ?array $event, bool $lock = false): ?array
    {
        if ($event === null) return null;
        $c = json_decode($event['config_json'], true, 16, JSON_THROW_ON_ERROR);
        $a = $this->assignmentVersion($merchant, (int)$c['assignment_version'], $lock);
        $pending = $c['pending_version'] === null ? null : $this->assignmentVersion($merchant, (int)$c['pending_version'], $lock);
        $blocked = false;
        if ($pending && (int)$pending['effective_epoch'] <= $this->now()) {
            $removed = array_diff(json_decode($a['features_json'], true), json_decode($pending['features_json'], true));
            // Recheck at activation: work may have started after a downgrade was scheduled.
            $blocked = (bool)array_intersect($removed, ['pos','kitchen','floor','reservations']) && $this->activeWork($merchant);
            if (!$blocked) {
                $a = $pending;
                $c['assignment_version'] = (int)$a['version'];
                $c['pending_version'] = null;
                $c['trial_end'] = null;
                $pending = null;
            }
        }
        $now = $this->now();
        $status = match (true) {
            $c['mode'] === 'CANCELLED' || ($c['cancel_at'] !== null && $now >= $c['cancel_at']) => 'CANCELLED',
            $c['mode'] === 'SUSPENDED' => 'SUSPENDED',
            $now < (int)$a['effective_epoch'] => 'SCHEDULED',
            $now < (int)$a['expires_epoch'] && $c['trial_end'] !== null && $now < $c['trial_end'] => 'TRIAL',
            $now < (int)$a['expires_epoch'] => 'ACTIVE',
            $now < (int)$a['expires_epoch'] + $c['grace_seconds'] => 'GRACE',
            default => 'EXPIRED',
        };
        return ['event'=>$event,'config'=>$c,'assignment'=>$a,'pending'=>$pending,'change_blocked'=>$blocked,'state'=>$status,'now'=>$now];
    }

    /** Authoritative effective state, always selected under the same M06A assignment-head lock for writes. */
    private function managedState(string $merchant, bool $lock = false): ?array
    {
        if (!self::lifecycleEnabled()) return null;
        if ($lock) $this->rows('SELECT version FROM merchant_plan_heads WHERE merchant_id=? FOR UPDATE', [$merchant]);
        return $this->lifecycleState($merchant, $this->lifecycleEvent($merchant, $lock), $lock);
    }

    public function lifecycle(string $request, string $merchant, string $expected, string $assignmentExpected, string $action, array $input): array
    {
        if (!self::lifecycleEnabled()) throw new DomainException('Private lifecycle management disabled.');
        self::request($request);
        Identity::validate($merchant);
        if (!ctype_digit($expected) || strlen($expected)>8 || !ctype_digit($assignmentExpected) || strlen($assignmentExpected)>8) throw new InvalidArgumentException('Invalid subscription revisions.');
        $actions=['adopt','start','start-trial','activate','renew','schedule','discard-schedule','suspend','cancel-now','cancel-end','reactivate'];
        if (!in_array($action,$actions,true)) throw new InvalidArgumentException('Unknown lifecycle transition.');
        $keys=['choice','effective','expires','trial_end','grace_days','renewable','reason'];
        if (array_diff(array_keys($input),$keys)||array_diff($keys,array_keys($input))) throw new InvalidArgumentException('Use the exact lifecycle form fields.');
        foreach ($input as $v) if (!is_string($v)) throw new InvalidArgumentException('Invalid lifecycle field.');
        $reason=self::text($input['reason'],240);
        $hash=hash('sha256',json_encode([$merchant,$expected,$assignmentExpected,$action,$input],JSON_THROW_ON_ERROR));
        return $this->write(function(string $actor) use($request,$merchant,$expected,$assignmentExpected,$action,$input,$reason,$hash):array {
            if (!$this->rows('SELECT merchant_id FROM merchant_organizations WHERE merchant_id=? AND active=1 FOR UPDATE',[$merchant])) throw new DomainException('Active synthetic merchant required.');
            $this->rows('INSERT INTO merchant_plan_heads(merchant_id) VALUES(?) ON DUPLICATE KEY UPDATE merchant_id=VALUES(merchant_id)',[$merchant]);
            $head=$this->rows('SELECT version FROM merchant_plan_heads WHERE merchant_id=? FOR UPDATE',[$merchant])[0];
            $prior=$this->rows('SELECT * FROM merchant_subscription_events WHERE request_id=? FOR UPDATE',[$request]);
            if ($prior) {
                $p=$prior[0];
                if ($p['merchant_id']!==$merchant || $p['actor_id']!==$actor || !hash_equals($p['payload_hash'],$hash)) throw new DomainException('Lifecycle request key conflict.');
                return ['merchant'=>$merchant,'version'=>(int)$p['version'],'assignmentVersion'=>(int)$p['assignment_head'],'duplicate'=>true];
            }
            $event=$this->lifecycleEvent($merchant,true);
            if ((string)($event['version']??0)!==$expected || (string)$head['version']!==$assignmentExpected) throw new DomainException('Subscription changed; reload current revisions.');
            $state=$this->lifecycleState($merchant,$event,true);
            $old=$state['assignment']??$this->rawAssignment($merchant,true);
            $c=$state['config']??['assignment_version'=>0,'pending_version'=>null,'mode'=>'RUNNING','trial_end'=>null,'grace_seconds'=>0,'renewable'=>true,'cancel_at'=>null];
            $from=$state['state']??'UNMANAGED';
            $create=false;
            if (in_array($action,['adopt','start','start-trial'],true)) {
                if ($event) throw new DomainException('Subscription already initialized; use a validated transition.');
                $c['grace_seconds']=BillDrafts::number($input['grace_days'],0,30)*86400;
                if (!in_array($input['renewable'],['0','1'],true)) throw new InvalidArgumentException('Select renewal eligibility.');
                $c['renewable']=$input['renewable']==='1';
                if ($action==='adopt') {
                    if (!$old || (string)$old['version']!==(string)$head['version']) throw new DomainException('Resolve legacy future assignments before adoption.');
                    $c['assignment_version']=(int)$old['version'];
                } else $create=true;
            } else {
                if (!$state) throw new DomainException('Initialize the subscription first.');
                switch ($action) {
                    case 'activate':
                        if (!in_array($from,['TRIAL','SCHEDULED'],true)) throw new DomainException('Only trials or scheduled subscriptions can activate early.');
                        $create=true; break;
                    case 'renew':
                        if (!$c['renewable'] || !in_array($from,['TRIAL','ACTIVE','GRACE','EXPIRED'],true) || $c['cancel_at']!==null || $c['pending_version']!==null) throw new DomainException('Subscription is not eligible for renewal. Resolve cancellation or scheduled change first.');
                        $create=true; break;
                    case 'schedule':
                        if (!in_array($from,['TRIAL','ACTIVE','GRACE'],true) || $c['cancel_at']!==null) throw new DomainException('Cannot schedule in this subscription state.');
                        $create=true; break;
                    case 'discard-schedule':
                        if ($c['pending_version']===null) throw new DomainException('No scheduled plan change remains.');
                        $c['pending_version']=null; break;
                    case 'suspend':
                        if (!in_array($from,['TRIAL','SCHEDULED','ACTIVE','GRACE'],true)) throw new DomainException('Subscription cannot be suspended from this state.');
                        $c['mode']='SUSPENDED'; $c['pending_version']=null; break;
                    case 'cancel-now':
                        if ($from==='CANCELLED') throw new DomainException('Subscription is already cancelled.');
                        $c['mode']='CANCELLED';$c['pending_version']=null;$c['cancel_at']=$this->now();break;
                    case 'cancel-end':
                        if (!in_array($from,['TRIAL','ACTIVE'],true) || $c['cancel_at']!==null) throw new DomainException('Period-end cancellation is not available.');
                        $c['cancel_at']=(int)$old['expires_epoch'];$c['pending_version']=null;break;
                    case 'reactivate':
                        if (!in_array($from,['SUSPENDED','CANCELLED','EXPIRED'],true) && $c['cancel_at']===null) throw new DomainException('Subscription does not require reactivation.');
                        $create=true; break;
                }
            }
            if ($create) {
                $choice=explode(':',$input['choice'],2);
                if(count($choice)!==2||!ctype_digit($choice[1])||strlen($choice[1])>8)throw new InvalidArgumentException('Select the latest active plan.');
                $plans=in_array($action,['renew','activate'],true) ? $this->rows('SELECT * FROM merchant_plan_versions WHERE plan_id=? AND version=? FOR UPDATE',[$old['plan_id'],$old['plan_version']]) : $this->rows('SELECT * FROM merchant_plan_versions WHERE plan_id=? ORDER BY version DESC LIMIT 1 FOR UPDATE',[$choice[0]]);
                if (!$plans || (!in_array($action,['renew','activate'],true) && (!(int)$plans[0]['active'] || (string)$plans[0]['version']!==$choice[1]))) throw new DomainException('Select the latest active plan version.');
                $p=$plans[0];$now=$this->now();
                $start=self::instant($input['effective']);$end=self::instant($input['expires']);
                if($end<=max($start,$now)||$end-$start>5*366*86400)throw new InvalidArgumentException('Use a valid future end within five years.');
                if($action==='schedule') {
                    if($start<=$now || $start>(int)$old['expires_epoch']+$c['grace_seconds'])throw new InvalidArgumentException('Schedule within the current period or grace, strictly after now.');
                } elseif(!in_array($action,['start','start-trial'],true)) {
                    // Server chooses immediate reactivation/renewal/early activation. Client dates cannot defer safety checks.
                    $start=$now;
                    if($action==='renew' && $end<=(int)$old['expires_epoch'])throw new InvalidArgumentException('Renewal must extend the existing end.');
                } elseif($start<$now-60)throw new InvalidArgumentException('Initial period cannot be backdated.');
                // Renew/activate reuse the pinned version; the submitted choice cannot upgrade it.
                if(in_array($action,['renew','activate'],true)&&($choice[0]!==$old['plan_id']))throw new DomainException('Renewal and early activation retain the pinned plan.');
                $removed=array_diff($old?json_decode($old['features_json'],true):self::FEATURES,json_decode($p['features_json'],true));
                if(array_intersect($removed,['pos','kitchen','floor','reservations'])&&$this->activeWork($merchant))throw new DomainException('Finish services, occupancy and bookings before removing operational modules.');
                $v=(int)$head['version']+1;
                $assignmentKey=substr(hash('sha256','lifecycle-assignment:'.$request),0,32);
                $this->rows('INSERT INTO merchant_plan_assignments VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$merchant,$v,$p['plan_id'],$p['version'],$start,$end,$actor,$assignmentKey,$hash,$reason,$now]);
                $this->rows('UPDATE merchant_plan_heads SET version=? WHERE merchant_id=?',[$v,$merchant]);
                $head['version']=$v;
                if($action==='schedule')$c['pending_version']=$v;
                else {
                    $c['assignment_version']=$v;$c['pending_version']=null;$c['mode']='RUNNING';$c['trial_end']=null;$c['cancel_at']=null;
                    if($action==='start-trial') {
                        $trial=self::instant($input['trial_end']);
                        if($trial<=$start || $trial>$end || $trial-$start>90*86400)throw new InvalidArgumentException('Trial end must be within the period, at most 90 days after start.');
                        $c['trial_end']=$trial;
                    }
                }
            }
            $version=(int)$expected+1;
            $synthetic=['config_json'=>json_encode($c,JSON_THROW_ON_ERROR),'version'=>$version];
            $next=$this->lifecycleState($merchant,$synthetic,true);
            $this->rows('INSERT INTO merchant_subscription_events(merchant_id,version,assignment_head,action,from_status,to_status,config_json,actor_id,request_id,payload_hash,reason,created_epoch) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$merchant,$version,(int)$head['version'],$action,$from,$next['state'],json_encode($c,JSON_THROW_ON_ERROR),$actor,$request,$hash,$reason,$this->now()]);
            return ['merchant'=>$merchant,'version'=>$version,'assignmentVersion'=>(int)$head['version'],'duplicate'=>false];
        });
    }

    public function lifecyclePlatform(): array
    {
        $this->admin();
        $rows=$this->rows('SELECT m.merchant_id,m.name,COALESCE(h.version,0) assignment_revision FROM merchant_organizations m LEFT JOIN merchant_plan_heads h ON h.merchant_id=m.merchant_id WHERE m.active=1 ORDER BY m.name');
        foreach($rows as &$r) {
            $s=$this->managedState($r['merchant_id']);
            $r['lifecycle']=$s;
            $r['legacy']=$s?null:$this->rawAssignment($r['merchant_id']);
        }
        unset($r);
        return ['merchants'=>$rows,'history'=>$this->rows('SELECT e.*,m.name merchant_name FROM merchant_subscription_events e JOIN merchant_organizations m ON m.merchant_id=e.merchant_id ORDER BY e.created_epoch DESC,e.version DESC LIMIT 200'),'now'=>$this->now()];
    }
}
