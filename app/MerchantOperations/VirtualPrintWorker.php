<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;

use PDO;
use DomainException;
use Kios\Domain\PrintJobStatus;
use Kios\Infrastructure\PrintJobService;
use Kios\MerchantFoundation\Identity;

/** Explicit non-user principal with only virtual delivery capability in one immutable scope. */
final class VirtualPrintWorker
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $serviceId,
        private readonly string $credential,
    ) {
        Identity::validate($serviceId);
        if (preg_match('/^[a-f0-9]{64}$/D',$credential)!==1) {
            throw new DomainException('Service authentication rejected.');
        }
    }

    private function grant(string $merchant,string $outlet,bool $lock=false): array
    {
        $q=$this->db->prepare(
            'SELECT g.*,s.active AS staff_active,o.active AS outlet_active,m.active AS merchant_active,lo.active AS legacy_active
             FROM merchant_worker_grants g JOIN merchant_staff s ON s.user_id=g.actor_user_id
             JOIN merchant_outlets o ON o.outlet_id=g.merchant_outlet_id AND o.merchant_id=g.merchant_id
             JOIN merchant_organizations m ON m.merchant_id=g.merchant_id
             JOIN outlets lo ON lo.outlet_id=g.legacy_outlet_id
             WHERE g.service_id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$this->serviceId]);$g=$q->fetch(PDO::FETCH_ASSOC);
        if (!$g || !hash_equals($g['credential_hash'],hash('sha256',$this->credential))
            || $g['merchant_id']!==$merchant || $g['merchant_outlet_id']!==$outlet
            || $g['capability']!=='virtual_print' || (int)$g['expires_at']<=time()
            || !(bool)$g['active'] || !(bool)$g['staff_active'] || !(bool)$g['outlet_active']
            || !(bool)$g['merchant_active'] || !(bool)$g['legacy_active']) {
            throw new DomainException('Service scope denied.');
        }
        return $g;
    }

    public function deliver(string $merchant,string $outlet,int $jobId): PrintJobStatus
    {
        $g=$this->grant($merchant,$outlet);
        $legacy=(int)$g['legacy_outlet_id'];
        $verify=function()use($merchant,$outlet,$jobId,$legacy):void {
            $current=$this->grant($merchant,$outlet,true);
            if ((int)$current['legacy_outlet_id']!==$legacy)throw new DomainException('Service mapping changed.');
            $q=$this->db->prepare("SELECT j.print_job_id FROM print_jobs j
                JOIN printers p ON p.outlet_id=j.outlet_id AND p.printer_id=j.printer_id
                WHERE j.outlet_id=? AND j.print_job_id=? AND j.adapter_type_snapshot='virtual'
                AND p.adapter_type='virtual' AND p.enabled=1 FOR UPDATE");
            $q->execute([$legacy,$jobId]);
            if ($q->fetchColumn()===false)throw new DomainException('Virtual job scope denied.');
            $q=$this->db->prepare('INSERT INTO merchant_operation_events
                (merchant_id,merchant_outlet_id,legacy_outlet_id,actor_user_id,operation,resource_id,business_date)
                VALUES(?,?,?,?,?,?,?)');
            $q->execute([$merchant,$outlet,$legacy,$current['actor_user_id'],'worker-virtual-authorized',$jobId,
                (new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')]);
        };
        // Preflight the exact job without creating an unrestricted list or claim operation.
        $q=$this->db->prepare("SELECT status FROM print_jobs WHERE outlet_id=? AND print_job_id=? AND adapter_type_snapshot='virtual'");
        $q->execute([$legacy,$jobId]);$status=$q->fetchColumn();
        if ($status===false)throw new DomainException('Virtual job scope denied.');
        $service=new PrintJobService($this->db,authorization:$verify,virtualOnly:true);
        return in_array($status,['failed','retry_pending'],true)
            ?$service->retry($jobId,$legacy,$g['actor_user_id'])
            :$service->dispatch($jobId,$legacy,actor:$g['actor_user_id']);
    }
}