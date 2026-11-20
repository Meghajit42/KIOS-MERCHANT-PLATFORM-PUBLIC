<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use PDO;
use RuntimeException;
use Throwable;

/** Local-only, insert-only catalogue expansion; preserves operational edits on replay. */
final class VoiceCatalogueImporter
{
    public function __construct(private readonly PDO $pdo) {}

    public function import(array $source): int
    {
        if (count($source) !== 77) { throw new RuntimeException('Expected the complete 77-entry source catalogue.'); }
        foreach ($source as $entry) {
            if (!in_array($entry['verification_status'] ?? null, ProductVerificationRepository::STATUSES, true)
                || !in_array($entry['source_unit'] ?? null, ['piece','unit','kg'], true)
                || !is_int($entry['source_price_paise'] ?? null) || $entry['source_price_paise'] < 1) {
                throw new RuntimeException('Invalid source verification status, unit or price.');
            }
        }
        $this->pdo->beginTransaction();
        try {
            // Serialize imports for this outlet before any catalogue reads or inserts.
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $outlet=$this->pdo->query("SELECT outlet_id FROM outlets WHERE outlet_code='demo-outlet'" . $lock)->fetchColumn();
            if (!$outlet) { throw new RuntimeException('Seed the existing demo catalogue first.'); }
            $q=$this->pdo->prepare("SELECT station_id FROM preparation_stations WHERE outlet_id=? AND code='SWEETS'");$q->execute([$outlet]);$station=$q->fetchColumn();
            if (!$station) { throw new RuntimeException('Existing sweets station is required.'); }
            $inserted=0;
            foreach ($source as $row) {
                $slug=strtolower($row['source_category']);
                $q=$this->pdo->prepare('SELECT category_id FROM categories WHERE outlet_id=? AND slug=?');$q->execute([$outlet,$slug]);$category=$q->fetchColumn();
                if (!$category) {
                    $q=$this->pdo->prepare('INSERT INTO categories(outlet_id,slug,name,active,sort_order) VALUES (?,?,?,1,?)');
                    $q->execute([$outlet,$slug,$row['source_category'],$slug==='snacks'?100:101]);$category=$this->pdo->lastInsertId();
                }
                $q=$this->pdo->prepare('SELECT product_id FROM products WHERE outlet_id=? AND sku=?');$q->execute([$outlet,$row['reconciliation_target_product_id']]);$existing=$q->fetchColumn();
                if ($existing) {
                    if ($row['source_name']==='Kaju Barfi') {
                        $q=$this->pdo->prepare("UPDATE products SET verification_status=?,verification_note=?,verification_evidence=?,version=version+1 WHERE product_id=? AND verification_evidence IS NULL");
                        $q->execute([$row['verification_status'],'Voice source proposes piece pricing; current weight offerings preserved.',json_encode($row,JSON_THROW_ON_ERROR),$existing]);
                    }
                    continue;
                }
                $q=$this->pdo->prepare('SELECT product_id FROM products WHERE outlet_id=? AND LOWER(name)=LOWER(?)');$q->execute([$outlet,$row['source_name']]);
                if ($q->fetchColumn()) { throw new RuntimeException('A new exact-name match requires reconciliation before import.'); }
                $note=$slug==='snacks'?'SNACKS_STATION_OWNER_CONFIRMATION_REQUIRED: local provisional SWEETS routing.':($row['verification_status']==='confirmed'?'Voice catalogue import.':'Confirm the exact voice-source name or product relationship.');
                $identityNotes = [
                    'Boil Cake' => 'Likely Bhapa Sandesh / steamed chhena sweet; owner name confirmation pending.',
                    'Malai Gari' => 'Research suggests this may be Malai Kari / Malaikari. Shop confirmation required before renaming.',
                    'Khirtua' => 'Research strongly suggests Kheertua; owner spelling confirmation pending.',
                ];
                $note = $identityNotes[$row['source_name']] ?? $note;
                $routingStatus = in_array($slug, ['dahi', 'snacks'], true) ? 'owner_review' : 'approved';
                $q=$this->pdo->prepare('INSERT INTO products(outlet_id,category_id,station_id,routing_status,sku,name,vegetarian,active,available,display_order,verification_status,verification_note,verification_evidence) VALUES (?,?,?,?,?,?,1,1,1,?,?,?,?)');
                $q->execute([$outlet,$category,$station,$routingStatus,$row['reconciliation_target_product_id'],$row['source_name'],$row['source_order'],$row['verification_status'],$note,json_encode($row,JSON_THROW_ON_ERROR)]);
                $id=$this->pdo->lastInsertId();
                $weights=$row['source_unit']==='kg'?[100,250,500,1000]:[1];
                foreach ($weights as $i=>$weight) {
                    $kg=$row['source_unit']==='kg';
                    $q=$this->pdo->prepare('INSERT INTO product_variants(product_id,code,label,unit,quantity_value,price_paise,active,display_order) VALUES (?,?,?,?,?,?,1,?)');
                    $q->execute([$id,$kg?'weight-'.$weight:'default',$kg?($weight===1000?'1 kg':$weight.' g'):('1 '.$row['source_unit']),$kg?'gram':($row['source_unit']==='unit'?'count':'piece'),$weight,$kg?intdiv($row['source_price_paise']*$weight,1000):$row['source_price_paise'],$i]);
                }
                $inserted++;
            }
            $this->pdo->commit();return $inserted;
        } catch(Throwable $e) { $this->pdo->rollBack();throw $e; }
    }
}
