<?php
declare(strict_types=1);
namespace Kios\DeliveryIdentity;
use Kios\MerchantFoundation\Identity;
/** Syntax-only future reference, NOT evidence of merchant acceptance/order ownership/dispatch eligibility. */
final readonly class FutureDeliveryRequest
{
    private function __construct(public string $customerOrderId,public string $merchantId,public string $outletId,public int $restaurantOrderId) {}
    public static function reference(array $fields): self {
        $keys=['customerOrderId','merchantId','outletId','restaurantOrderId'];
        if (array_diff(array_keys($fields),$keys)||array_diff($keys,array_keys($fields))) throw new \InvalidArgumentException('Only future authoritative-order references allowed; intents/assignment/pickup claims are not accepted.');
        foreach (['customerOrderId','merchantId','outletId'] as $key) {if(!is_string($fields[$key]))throw new \InvalidArgumentException('UUID reference required.');Identity::validate($fields[$key]);}
        if (!is_int($fields['restaurantOrderId'])||$fields['restaurantOrderId']<1||$fields['restaurantOrderId']>2147483647) throw new \InvalidArgumentException('Bounded authoritative restaurant order reference required.');
        return new self($fields['customerOrderId'],$fields['merchantId'],$fields['outletId'],$fields['restaurantOrderId']);
    }
    public function dispatchAuthorized(): bool { return false; }
    public function verificationState(): string { return 'UNVERIFIED_REFERENCE'; }
}
