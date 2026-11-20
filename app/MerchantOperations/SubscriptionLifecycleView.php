<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;

final class SubscriptionLifecycleView
{
    private static function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    private static function date(?int $v):string{return $v===null?'Not scheduled':gmdate('d M Y · H:i',$v).' UTC';}
    private static function field(string $label,string $name,string $value,string $type='text'):string{
        return '<label>'.self::h($label).'<input type="'.$type.'" name="'.$name.'" value="'.self::h($value).'"></label>';
    }
    public static function status(array $s):string
    {
        $c=$s['config'];$a=$s['assignment'];
        $explanation=match($s['state']){
            'TRIAL'=>'Trial access is active. No charge or obligation is created when the trial ends.',
            'SCHEDULED'=>'Activation is scheduled. Licensed new work remains unavailable until the UTC start.',
            'ACTIVE'=>'Plan features are available alongside the operator’s existing permissions.',
            'GRACE'=>'The period has ended. Current plan access continues only until the grace deadline.',
            'SUSPENDED'=>'New licensed work is paused. Authorized staff can still complete existing restaurant work.',
            'CANCELLED'=>'Subscription is cancelled. Records are retained and existing work can be completed safely.',
            default=>'The period and any grace have ended. New work requires an authorized renewal or reactivation.',
        };
        $x='<div class="lifecycle-status" data-lifecycle-state="'.self::h($s['state']).'"><span class="lifecycle-badge">'.self::h($s['state']).'</span><p>'.self::h($explanation).'</p><dl class="lifecycle-dates">';
        $dates=['Starts'=>(int)$a['effective_epoch'],'Period ends'=>(int)$a['expires_epoch'],'Trial ends'=>$c['trial_end'],'Grace ends'=>(int)$a['expires_epoch']+$c['grace_seconds'],'Cancellation'=>$c['cancel_at']];
        foreach($dates as$label=>$epoch)$x.='<div><dt>'.self::h($label).'</dt><dd>'.self::h(self::date($epoch)).'</dd></div>';
        $x.='<div><dt>Renewal eligibility</dt><dd>'.($c['renewable']?'Eligible for administrator review':'Not eligible').'</dd></div><div><dt>Upcoming renewal review</dt><dd>'.($c['renewable']?self::h(self::date((int)$a['expires_epoch'])):'Not scheduled').'</dd></div></dl><p>No automatic renewal or collection. Dates are authoritative server UTC.</p>';
        if($s['pending'])$x.='<p class="lifecycle-notice"><strong>Scheduled change:</strong> '.self::h($s['pending']['name']).' v'.(int)$s['pending']['plan_version'].' · '.self::h(self::date((int)$s['pending']['effective_epoch'])).'</p>';
        if($s['change_blocked'])$x.='<p class="lifecycle-notice" role="status">Scheduled downgrade is held: complete active services, tables and bookings first. The current plan remains in effect subject to its own expiry.</p>';
        return $x.'</div>';
    }
    public static function platform(array $data,array $plans,string $csrf):string
    {
        $x='<section id="lifecycle" class="panel"><p class="eyebrow">Private subscription administration</p><h2>Subscription lifecycle</h2><p>Review, trial, renew, schedule or pause a synthetic subscription. Every change is version-checked and audited. No payment is collected.</p><div class="lifecycle-merchants">';
        foreach($data['merchants']as$m){
            $s=$m['lifecycle'];$a=$s['assignment']??$m['legacy'];$rev=(int)($s['event']['version']??0);
            $x.='<article class="lifecycle-merchant" data-lifecycle-merchant="'.self::h($m['merchant_id']).'"><h3>'.self::h($m['name']).'</h3><p>'.($a?self::h($a['name']).' · v'.(int)$a['plan_version']:'No assignment').'</p>';
            $x.=$s?self::status($s):'<p class="lifecycle-notice">M06A assignment preserved. Adopt its existing dates to enable lifecycle management, or initialize a new period.</p>';
            $x.='<details><summary>Manage subscription</summary><form method="post" data-subscription-form data-lifecycle-form>';
            foreach(['_csrf'=>$csrf,'action'=>'plans-lifecycle','request'=>bin2hex(random_bytes(16)),'merchant'=>$m['merchant_id'],'expected'=>(string)$rev,'assignment_expected'=>(string)$m['assignment_revision']]as$k=>$v)$x.='<input type="hidden" name="'.$k.'" value="'.self::h($v).'">';
            $x.='<p>Lifecycle revision '.$rev.' · assignment revision '.(int)$m['assignment_revision'].'</p><label>Action<select name="transition">';
            $choices=$s?['activate'=>'Activate early','renew'=>'Renew current plan','schedule'=>'Schedule plan change','discard-schedule'=>'Discard scheduled change','suspend'=>'Suspend new work','cancel-end'=>'Cancel at period end','cancel-now'=>'Cancel now','reactivate'=>'Reactivate subscription']:['adopt'=>'Adopt existing M06A period','start-trial'=>'Start trial','start'=>'Start or schedule initial period'];
            foreach($choices as$k=>$v)$x.='<option value="'.$k.'">'.$v.'</option>';
            $x.='</select></label><p class="lifecycle-help" role="status">Choose an action. The server validates the current state and preserves active restaurant work.</p><div class="lifecycle-fields"><label>Plan for initial period, change or reactivation<select name="choice">';
            foreach($plans as$p)if((int)$p['active'])$x.='<option value="'.self::h($p['plan_id']).':'.(int)$p['version'].'"'.($a&&$a['plan_id']===$p['plan_id']?' selected':'').'>'.self::h($p['name']).' · v'.(int)$p['version'].'</option>';
            $x.='</select></label>'.self::field('Start / scheduled change · UTC','effective',gmdate('Y-m-d\TH:i',$data['now']),'datetime-local').self::field('New period end · UTC','expires',gmdate('Y-m-d\TH:i',max($data['now'],(int)($a['expires_epoch']??0))+30*86400),'datetime-local').self::field('Trial end · UTC (trial creation only)','trial_end',gmdate('Y-m-d\TH:i',$data['now']+14*86400),'datetime-local').self::field('Grace days · 0–30 (initialization only)','grace_days','7','number').'<label>Renewal eligibility (initialization only)<select name="renewable"><option value="1">Eligible for review</option><option value="0">Not eligible</option></select></label></div><label>Required reason<input name="reason" maxlength="240" required placeholder="Explain the authorized synthetic change"></label><button>Confirm lifecycle change</button></form></details></article>';
        }
        $x.='</div></section><section class="panel" id="lifecycle-history"><h2>Immutable lifecycle history</h2><p>Explicit actions retain authenticated platform attribution. Automatic UTC phases are derived from the recorded period boundaries; reading a page never writes an audit event.</p><div class="subscription-scroll" tabindex="0"><table><thead><tr><th>Merchant</th><th>Revision</th><th>Action</th><th>Transition</th><th>Reason</th><th>Recorded · UTC</th></tr></thead><tbody>';
        foreach($data['history']as$r)$x.='<tr><td>'.self::h($r['merchant_name']).'</td><td>'.(int)$r['version'].'</td><td>'.self::h($r['action']).'</td><td>'.self::h($r['from_status'].' → '.$r['to_status']).'</td><td>'.self::h($r['reason']).'</td><td>'.self::h(gmdate('Y-m-d H:i',(int)$r['created_epoch'])).'</td></tr>';
        return $x.'</tbody></table></div></section>';
    }
    public static function history(array $rows):string
    {
        $x='<section class="panel" id="lifecycle-history"><h2>Subscription lifecycle history</h2><p>Period, trial, grace and scheduled boundaries are preserved in immutable platform records. No financial obligation is created.</p><div class="subscription-scroll" tabindex="0"><table><thead><tr><th>Revision</th><th>Action</th><th>State at action</th><th>Reason</th><th>Recorded · UTC</th></tr></thead><tbody>';
        foreach($rows as$r)$x.='<tr><td>'.(int)$r['version'].'</td><td>'.self::h($r['action']).'</td><td>'.self::h($r['from_status'].' → '.$r['to_status']).'</td><td>'.self::h($r['reason']).'</td><td>'.self::h(gmdate('Y-m-d H:i',(int)$r['created_epoch'])).'</td></tr>';
        return $x.'</tbody></table></div></section>';
    }
}
