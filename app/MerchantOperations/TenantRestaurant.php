<?php
declare(strict_types=1);

namespace Kios\MerchantOperations;

use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;
use Kios\Domain\{CheckoutIntent, Money, Order, OrderType};
use Kios\Infrastructure\{MenuRepository, OrderRepository};
use Kios\MerchantFoundation\{AuthenticatedUserProvider, AuthorizationService, Permission, PdoIdentityStore, TenantContext};

/**
 * Internal selected application entry point. No HTTP/public registration wiring.
 * All merchant/outlet parameters are selections checked against current authority.
 */
final class TenantRestaurant
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuthenticatedUserProvider $authentication,
    ) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new LogicException('MariaDB required.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    private function rows(string $sql, array $values): array
    {
        $q = $this->db->prepare($sql);
        $q->execute($values);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scope(string $merchantId, string $outletId, Permission $permission): array
    {
        $context = (new AuthorizationService($this->authentication, new PdoIdentityStore($this->db)))
            ->requireResource($permission, $merchantId, $outletId, $merchantId, $outletId);
        $rows = $this->rows(
            'SELECT l.legacy_outlet_id,o.outlet_code FROM merchant_legacy_outlets l
             JOIN outlets o ON o.outlet_id=l.legacy_outlet_id AND o.active=1
             WHERE l.merchant_id=? AND l.merchant_outlet_id=?',
            [$context->merchantId, $context->outletId],
        );
        if (count($rows) !== 1) {
            throw new DomainException('Explicit outlet mapping required.');
        }
        (new Entitlements($this->db,$this->authentication))->requireFeature($merchantId,$outletId,'menu','read');return [$context, (int)$rows[0]['legacy_outlet_id'], $rows[0]['outlet_code']];
    }

    /** Recheck current write authority under locks shared with membership writers. */
    private function lockAuthority(TenantContext $context, int $legacyId, Permission $permission): void
    {
        (new Entitlements($this->db,$this->authentication))->requireFeature($context->merchantId,$context->outletId,'menu');
        if (!$this->db->inTransaction() || $this->authentication->currentUserId() !== $context->userId) {
            throw new DomainException('Current authority required.');
        }
        $staff = $this->rows('SELECT active FROM merchant_staff WHERE user_id=? FOR UPDATE', [$context->userId]);
        $scope = $this->rows(
            'SELECT m.active AS merchant_active,o.active AS outlet_active,l.legacy_outlet_id
             FROM merchant_outlets o JOIN merchant_organizations m ON m.merchant_id=o.merchant_id
             JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id
             WHERE o.merchant_id=? AND o.outlet_id=? FOR UPDATE', [$context->merchantId, $context->outletId],
        );
        if ($staff === [] || !(bool)$staff[0]['active'] || $scope === []
            || !(bool)$scope[0]['merchant_active'] || !(bool)$scope[0]['outlet_active']
            || (int)$scope[0]['legacy_outlet_id'] !== $legacyId) {
            throw new DomainException('Tenant authority changed.');
        }
        $roles = $this->rows(
            'SELECT role_code FROM merchant_memberships WHERE user_id=? AND merchant_id=? AND active=1 FOR UPDATE',
            [$context->userId, $context->merchantId],
        );
        $roles = array_merge($roles, $this->rows(
            'SELECT role_code FROM merchant_outlet_memberships WHERE user_id=? AND merchant_id=? AND outlet_id=? AND active=1 FOR UPDATE',
            [$context->userId, $context->merchantId, $context->outletId],
        ));
        foreach ($roles as $role) {
            if (\Kios\MerchantFoundation\Role::from($role['role_code'])->allows($permission)) {
                return;
            }
        }
        throw new DomainException('Write permission revoked.');
    }

    public function catalogue(string $merchantId, string $outletId, string $category): array
    {
        [, $legacyId, $code] = $this->scope($merchantId, $outletId, Permission::ReadOrders);
        $result = [];
        foreach ((new MenuRepository($this->db))->customerProductsForCategory($code, $category) as $product) {
            $ids = $this->rows(
                'SELECT p.product_id,v.variant_id,v.code FROM products p JOIN product_variants v ON v.product_id=p.product_id
                 WHERE p.outlet_id=? AND p.sku=? AND v.active=1 ORDER BY v.display_order,v.variant_id',
                [$legacyId, $product->id],
            );
            $variants = [];
            foreach ($ids as $row) {
                $variant = $product->variant($row['code']);
                $variants[] = ['variantId'=>(int)$row['variant_id'], 'code'=>$row['code'],
                    'label'=>$variant->label, 'unit'=>$variant->unit->value,
                    'quantityValue'=>$variant->quantityValue, 'pricePaise'=>$variant->unitPrice->paise()];
            }
            $result[] = ['productId'=>(int)$ids[0]['product_id'], 'sku'=>$product->id,
                'name'=>$product->name, 'imageReference'=>$product->imageReference, 'category'=>$category, 'merchantId'=>$merchantId, 'outletId'=>$outletId,
                'variants'=>$variants];
        }
        return $result;
    }

    /** Strict immutable product/variant references. Foreign IDs never fall back to SKU lookup. */
    private function cart(int $legacyId, array $lines): array
    {
        if ($lines === [] || count($lines) > 100) {
            throw new InvalidArgumentException('A bounded nonempty cart is required.');
        }
        $cart = [];
        foreach ($lines as $line) {
            if (!is_array($line) || array_diff(array_keys($line), ['productId','variantId','quantity']) !== []
                || !isset($line['productId'], $line['variantId'], $line['quantity'])
                || !is_int($line['productId']) || $line['productId'] < 1
                || !is_int($line['variantId']) || $line['variantId'] < 1
                || !is_int($line['quantity']) || $line['quantity'] < 1 || $line['quantity'] > 99) {
                throw new InvalidArgumentException('Invalid cart line.');
            }
            $rows = $this->rows(
                'SELECT p.sku,v.code FROM products p JOIN product_variants v ON v.product_id=p.product_id
                 WHERE p.outlet_id=? AND p.product_id=? AND v.variant_id=?',
                [$legacyId, $line['productId'], $line['variantId']],
            );
            if (count($rows) !== 1) {
                throw new DomainException('Cart reference outside authorized outlet.');
            }
            $sku = $rows[0]['sku'];
            $variant = $rows[0]['code'];
            $quantity = ($cart[$sku][$variant] ?? 0) + $line['quantity'];
            if ($quantity > 99) {
                throw new InvalidArgumentException('Combined quantity exceeds limit.');
            }
            $cart[$sku][$variant] = $quantity;
        }
        return $cart;
    }

    /** sessionBinding comes from the trusted server session adapter, never an HTTP field. */
    public function checkout(
        string $merchantId, string $outletId, array $lines, OrderType $type,
        string $intentKey, string $sessionBinding,
    ): Order {
        [$context, $legacyId, $code] = $this->scope($merchantId, $outletId, Permission::RecordCash);
        if (!CheckoutIntent::isValidKey($intentKey)
            || preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sessionBinding) !== 1) {
            throw new InvalidArgumentException('Invalid checkout intent.');
        }
        $cart = $this->cart($legacyId, $lines);
        $key = hash('sha256', implode('|', [$merchantId,$outletId,$context->userId,$intentKey]));
        $session = hash('sha256', $context->userId . '|' . $sessionBinding);
        $repo = new OrderRepository($this->db, scopeOutletId: $legacyId,
            checkoutAuthorization: fn() => $this->lockAuthority($context, $legacyId, Permission::RecordCash));
        return $repo->createFromCheckoutIntent(
            $cart, $type, $key, $session, CheckoutIntent::fingerprintCart($cart, $code, $type), $code,
        );
    }

    public function order(string $merchantId, string $outletId, int $orderId): ?Order
    {
        [$context, $legacyId] = $this->scope($merchantId, $outletId, Permission::ReadOrders);
        $repo = new OrderRepository($this->db, scopeOutletId: $legacyId,
            checkoutAuthorization: fn() => $this->lockAuthority($context, $legacyId, Permission::ReadOrders));
        return $repo->findById($orderId);
    }

    public function setPrice(
        string $merchantId, string $outletId, int $productId, int $variantId,
        Money $price, int $expectedVersion,
    ): void {
        [$context, $legacyId] = $this->scope($merchantId, $outletId, Permission::ManageOutlet);
        $this->db->beginTransaction();
        try {
            $this->lockAuthority($context, $legacyId, Permission::ManageOutlet);
            $rows = $this->rows(
                'SELECT v.version,v.price_paise FROM product_variants v JOIN products p ON p.product_id=v.product_id
                 WHERE p.outlet_id=? AND p.product_id=? AND v.variant_id=? FOR UPDATE',
                [$legacyId, $productId, $variantId],
            );
            if ($rows === [] || (int)$rows[0]['version'] !== $expectedVersion) {
                throw new DomainException('Price reference or version invalid.');
            }
            $this->rows('UPDATE product_variants SET price_paise=?,version=version+1 WHERE variant_id=? AND version=?',
                [$price->paise(), $variantId, $expectedVersion]);
            $this->rows(
                "INSERT INTO menu_admin_events(outlet_id,action,entity_type,entity_id,before_summary,after_summary,actor_ref)
                 VALUES(?,'tenant-price','variant',?,?,?,?)",
                [$legacyId, (string)$variantId, (string)$rows[0]['price_paise'], (string)$price->paise(), $context->userId],
            );
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}