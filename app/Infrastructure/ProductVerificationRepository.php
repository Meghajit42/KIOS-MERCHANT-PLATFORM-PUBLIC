<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use DomainException;
use PDO;
use Throwable;

final class ProductVerificationRepository
{
    public const STATUSES = ['confirmed', 'needs_owner_confirmation', 'source_conflict_needs_owner_confirmation', 'possible_duplicate_needs_owner_confirmation'];

    public function __construct(private readonly PDO $pdo) {}

    public function needsReview(string $outlet): array
    {
        $q = $this->pdo->prepare("SELECT p.product_id, p.name, p.version, p.verification_status, p.verification_note, p.verification_evidence, c.name AS category_name FROM products p JOIN outlets o ON o.outlet_id=p.outlet_id JOIN categories c ON c.category_id=p.category_id AND c.outlet_id=p.outlet_id WHERE o.outlet_code=? AND p.verification_status <> 'confirmed' ORDER BY c.sort_order,p.display_order,p.product_id");
        $q->execute([$outlet]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $q = $this->pdo->prepare('SELECT label,unit,quantity_value,price_paise FROM product_variants WHERE product_id=? AND active=1 ORDER BY display_order,variant_id');
            $q->execute([$row['product_id']]);
            $row['variants'] = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    public function confirm(string $outlet, int $id, int $version, string $action): void
    {
        if (!in_array($action, ['confirm_current', 'keep_separate'], true)) {
            throw new DomainException('Choose an available review action.');
        }
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $q = $this->pdo->prepare('SELECT p.outlet_id,p.name,p.verification_status,p.version FROM products p JOIN outlets o ON o.outlet_id=p.outlet_id WHERE o.outlet_code=? AND p.product_id=?' . $lock);
            $q->execute([$outlet,$id]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['version']!==$version || $row['verification_status']==='confirmed'
                || ($action==='keep_separate') !== ($row['verification_status']==='possible_duplicate_needs_owner_confirmation')) {
                throw new DomainException('This item changed or the review action does not fit. Reload the review list.');
            }
            $q=$this->pdo->prepare("UPDATE products SET verification_status='confirmed',version=version+1 WHERE product_id=? AND outlet_id=? AND version=?");
            $q->execute([$id,$row['outlet_id'],$version]);
            if ($q->rowCount()!==1) { throw new DomainException('Reload this item before confirming.'); }
            $q=$this->pdo->prepare("INSERT INTO menu_admin_events(outlet_id,action,entity_type,entity_id,before_summary,after_summary,actor_ref) VALUES (?,'verification_confirmed','product',?,?,?,'menu-admin')");
            $q->execute([$row['outlet_id'],(string)$id,$row['verification_status'],$row['name'].' · '.$action]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
