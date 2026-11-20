<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;

use Closure;
use DateTimeImmutable;
use DomainException;
use LogicException;
use PDO;
use Throwable;
use Kios\Infrastructure\ShopOperations;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,AuthorizationService,PdoIdentityStore,Permission,Role,TenantContext};

/** Selected internal closing boundary; no cash adjustments, refunds or opening API. */
final class TenantFinance
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuthenticatedUserProvider $authentication,
        private readonly ?Closure $clock=null,
    ) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new LogicException('MariaDB required.');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
        if ($clock!==null && PHP_SAPI!=='cli')throw new DomainException('Test clocks are CLI-only.');
    }

    private function rows(string $sql,array $values=[]):array
    {
        $q=$this->db->prepare($sql);$q->execute($values);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Locks are shared with identity writers and checkout/payment's outlet serialization. */
    private function guard(TenantContext $context,int $legacy,string $capability):void
    {
        if (!$this->db->inTransaction() || $this->authentication->currentUserId()!==$context->userId) {
            throw new DomainException('Current financial identity required.');
        }
        $staff=$this->rows('SELECT active FROM merchant_staff WHERE user_id=? FOR UPDATE',[$context->userId]);
        $mapping=$this->rows('SELECT m.active AS merchant_active,o.active AS outlet_active,
            l.legacy_outlet_id,lo.active AS legacy_active FROM merchant_outlets o
            JOIN merchant_organizations m ON m.merchant_id=o.merchant_id
            JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id
            JOIN outlets lo ON lo.outlet_id=l.legacy_outlet_id
            WHERE o.merchant_id=? AND o.outlet_id=? FOR UPDATE',[$context->merchantId,$context->outletId]);
        if (!$staff || !(bool)$staff[0]['active'] || !$mapping
            || !(bool)$mapping[0]['merchant_active'] || !(bool)$mapping[0]['outlet_active']
            || !(bool)$mapping[0]['legacy_active'] || (int)$mapping[0]['legacy_outlet_id']!==$legacy) {
            throw new DomainException('Financial scope revoked.');
        }
        $roles=$this->rows('SELECT role_code FROM merchant_memberships WHERE user_id=? AND merchant_id=? AND active=1 FOR UPDATE',[$context->userId,$context->merchantId]);
        $roles=array_merge($roles,$this->rows('SELECT role_code FROM merchant_outlet_memberships
            WHERE user_id=? AND merchant_id=? AND outlet_id=? AND active=1 FOR UPDATE',[$context->userId,$context->merchantId,$context->outletId]));
        $permission=$capability==='close_day'?Permission::ManageOutlet:Permission::RecordCash;
        $allowed=false;
        foreach($roles as $role)$allowed=$allowed||Role::from($role['role_code'])->allows($permission);
        if (!$allowed)throw new DomainException('Financial role denied.');
        // A role is necessary but never automatically provisions financial capabilities.
        $needed=$capability==='close_day'?['read_reconciliation','close_day']:['read_reconciliation'];
        foreach($needed as $grant) {
            if (!$this->rows('SELECT active FROM merchant_financial_grants
                WHERE user_id=? AND merchant_id=? AND merchant_outlet_id=? AND legacy_outlet_id=?
                AND capability=? AND active=1 FOR UPDATE',[$context->userId,$context->merchantId,$context->outletId,$legacy,$grant])) {
                throw new DomainException('Explicit outlet financial permission required.');
            }
        }
    }

    private function authorized(string $merchant,string $outlet,string $capability,callable $work):mixed
    {
        if ($this->db->inTransaction())throw new LogicException('Financial operation owns its transaction.');
        $permission=$capability==='close_day'?Permission::ManageOutlet:Permission::RecordCash;
        $context=(new AuthorizationService($this->authentication,new PdoIdentityStore($this->db)))
            ->requireResource($permission,$merchant,$outlet,$merchant,$outlet);
        $map=$this->rows('SELECT legacy_outlet_id FROM merchant_legacy_outlets WHERE merchant_id=? AND merchant_outlet_id=?',[$merchant,$outlet]);
        if (count($map)!==1)throw new DomainException('Explicit outlet mapping required.');
        $legacy=(int)$map[0]['legacy_outlet_id'];
        // Do not let an earlier authentication read establish a stale repeatable-read
        // payment snapshot before waiting for the shop lock. Applies to this transaction only.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            $this->guard($context,$legacy,$capability);
            $shop=new ShopOperations($this->db,$this->clock);
            $shop->serializeStaffMutation($legacy);
            $result=$work($shop,$context,$legacy);
            $this->db->commit();return $result;
        } catch(Throwable $error) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
    }

    /** Read-only: deliberately does not invoke legacy sweep/expiry mutations. */
    public function preview(string $merchant,string $outlet):array
    {
        return $this->authorized($merchant,$outlet,'read_reconciliation',
            static fn(ShopOperations $shop,TenantContext $context,int $legacy):array=>[
                'state'=>$shop->state($legacy),'summary'=>$shop->summary($legacy),
            ]);
    }

    public function history(string $merchant,string $outlet):array
    {
        return $this->authorized($merchant,$outlet,'read_reconciliation',fn(ShopOperations $shop,TenantContext $context,int $legacy):array=>[
            'closings'=>$this->rows('SELECT closing_id,business_date,closed_epoch FROM shop_day_closings WHERE outlet_id=? ORDER BY closing_id DESC LIMIT 100',[$legacy]),
            'audit'=>$this->rows("SELECT actor_user_id,operation,resource_id,business_date FROM merchant_operation_events
                WHERE merchant_id=? AND merchant_outlet_id=? AND legacy_outlet_id=? AND operation='financial-day-closed'
                ORDER BY event_id DESC LIMIT 100",[$merchant,$outlet,$legacy]),
        ]);
    }

    public function closing(string $merchant,string $outlet,int $closing):array
    {
        if($closing<1)throw new DomainException('Closing identifier required.');
        return $this->authorized($merchant,$outlet,'read_reconciliation',function(ShopOperations $shop,TenantContext $context,int $legacy)use($closing):array {
            $rows=$this->rows('SELECT closing_id,business_date,closed_epoch,summary_json FROM shop_day_closings WHERE outlet_id=? AND closing_id=?',[$legacy,$closing]);
            if(!$rows)throw new DomainException('Closing outside authorized outlet.');
            return $rows[0];
        });
    }

    public function endDay(string $merchant,string $outlet,string $date,int $version,string $request,array $fields):array
    {
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed || $parsed->format('Y-m-d')!==$date || $version<1
            || preg_match('/^[a-f0-9]{32}$/D',$request)!==1
            || array_diff(array_keys($fields),['acknowledge','acknowledgement']))throw new DomainException('Invalid closing request.');
        foreach($fields as $value)if(!is_string($value))throw new DomainException('Invalid closing fields.');
        // Canonical shape gives identical semantics and idempotency across clients.
        $fields=['acknowledge'=>$fields['acknowledge']??'','acknowledgement'=>$fields['acknowledgement']??''];
        if(strlen($fields['acknowledgement'])>1000)throw new DomainException('Closing note too long.');
        if(!ShopOperations::enabled())throw new DomainException('Shop lifecycle is disabled.');
        return $this->authorized($merchant,$outlet,'close_day',function(ShopOperations $shop,TenantContext $context,int $legacy)use($date,$version,$request,$fields):array {
            if($shop->now()->format('Y-m-d')!==$date)throw new DomainException('Reload for the current business date.');
            $prior=$this->rows('SELECT actor_ref,action FROM shop_operation_events WHERE outlet_id=? AND request_id=?',[$legacy,$request]);
            if($prior && ($prior[0]['actor_ref']!==$context->userId || $prior[0]['action']!=='end-day'))throw new DomainException('Closing request belongs to another actor or operation.');
            $result=$shop->act($legacy,'end-day',$version,$request,$fields,$context->userId);
            $closing=$this->rows('SELECT closing_id,business_date,summary_json FROM shop_day_closings WHERE outlet_id=? AND request_id=?',[$legacy,$request]);
            if(count($closing)!==1)throw new DomainException('Closing snapshot missing.');
            $snapshot=json_decode($closing[0]['summary_json'],true,64,JSON_THROW_ON_ERROR);
            if($closing[0]['business_date']!==$date || ($snapshot['businessDate']??null)!==$date
                || ($snapshot['actor']??null)!==$context->userId || $shop->now()->format('Y-m-d')!==$date)throw new DomainException('Closing date or actor changed; retry with a fresh request.');
            if(!$result['duplicate']) {
                $this->rows('INSERT INTO merchant_operation_events(merchant_id,merchant_outlet_id,legacy_outlet_id,actor_user_id,operation,resource_id,business_date)
                    VALUES(?,?,?,?,?,?,?)',[$context->merchantId,$context->outletId,$legacy,$context->userId,'financial-day-closed',(int)$closing[0]['closing_id'],$date]);
            }
            return ['duplicate'=>$result['duplicate'],'closingId'=>(int)$closing[0]['closing_id'],'summary'=>$snapshot];
        });
    }
}
