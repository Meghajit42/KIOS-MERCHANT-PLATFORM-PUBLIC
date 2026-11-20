<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';

function voiceFixture(): PDO {
    $pdo=kiosK6MenuFixture();
    $pdo->exec("ALTER TABLE products ADD COLUMN verification_status VARCHAR(80) NOT NULL DEFAULT 'confirmed'");
    $pdo->exec('ALTER TABLE products ADD COLUMN verification_note VARCHAR(500) NULL');
    $pdo->exec('ALTER TABLE products ADD COLUMN verification_evidence TEXT NULL');
    $pdo->exec("INSERT INTO categories(outlet_id,slug,name,active,sort_order) VALUES (1,'sweets','Sweets',1,0)");
    $pdo->exec("INSERT INTO preparation_stations(outlet_id,code,name,active) VALUES (1,'SWEETS','Sweets',1)");
    $pdo->exec("INSERT INTO products(outlet_id,category_id,station_id,routing_status,sku,name,vegetarian,active,available) VALUES (1,4,4,'approved','kaju-barfi','Kaju Barfi',1,1,0)");
    $id=$pdo->lastInsertId();
    $pdo->exec("INSERT INTO product_variants(product_id,code,label,unit,quantity_value,price_paise,active) VALUES ($id,'kaju-100g','100 g','gram',100,15000,1)");
    return $pdo;
}
function voiceSource(): array { return json_decode(file_get_contents(dirname(__DIR__).'/data/gopal_voice_catalogue_77.json'),true,512,JSON_THROW_ON_ERROR); }
return [
 'source preserves 67 sweets 6 snacks 4 dahi and special source prices' => static function(): void {
    $source=voiceSource();$counts=array_count_values(array_column($source,'source_category'));
    if(count($source)!==77||$counts!==['Sweets'=>67,'Snacks'=>6,'Dahi'=>4])throw new RuntimeException('Source counts changed.');
    if($source[0]['source_price_paise']!==1500||$source[0]['source_unit']!=='piece'||$source[76]['source_price_paise']!==60000||$source[76]['source_unit']!=='kg')throw new RuntimeException('Source economics changed.');
 },
 'import preserves weight conflict availability historical snapshots and replay' => static function(): void {
    $pdo=voiceFixture();$before=$pdo->query('SELECT * FROM order_items')->fetchAll();
    $import=new \Kios\Infrastructure\VoiceCatalogueImporter($pdo);
    if($import->import(voiceSource())!==76||$import->import(voiceSource())!==0)throw new RuntimeException('Insert-only replay failed.');
    $p=$pdo->query("SELECT * FROM products WHERE sku='kaju-barfi'")->fetch();
    if((int)$p['available']!==0||$p['verification_status']!=='source_conflict_needs_owner_confirmation')throw new RuntimeException('Conflict not preserved.');
    $v=$pdo->query('SELECT * FROM product_variants WHERE product_id='.(int)$p['product_id'])->fetchAll();
    if(count($v)!==1||$v[0]['unit']!=='gram'||(int)$v[0]['price_paise']!==15000||$before!==$pdo->query('SELECT * FROM order_items')->fetchAll())throw new RuntimeException('Operational or historical values changed.');
    $q=$pdo->query("SELECT COUNT(*) FROM products p JOIN categories c ON c.category_id=p.category_id WHERE c.slug='dahi'");
    if((int)$q->fetchColumn()!==4)throw new RuntimeException('Dahi category mismatch.');
    $q=$pdo->query("SELECT COUNT(*) FROM products p JOIN categories c ON c.category_id=p.category_id WHERE c.slug IN ('dahi','snacks') AND p.routing_status='owner_review'");
    $reviewCount=(int)$q->fetchColumn();
    $q=$pdo->query("SELECT COUNT(*) FROM products p JOIN categories c ON c.category_id=p.category_id WHERE c.slug='sweets' AND p.routing_status='approved'");
    if($reviewCount!==10||(int)$q->fetchColumn()!==67)throw new RuntimeException('voice-import routing approval did not preserve owner review for Dahi/Snacks and known sweets.');
 },
 'review confirmation is scoped version checked and auditable' => static function(): void {
    $pdo=voiceFixture();(new \Kios\Infrastructure\VoiceCatalogueImporter($pdo))->import(voiceSource());
    $r=new \Kios\Infrastructure\ProductVerificationRepository($pdo);$rows=$r->needsReview('demo-outlet');
    if(count($rows)!==6||$r->needsReview('other-outlet')!==[])throw new RuntimeException('Review filter mismatch.');
    $p=$rows[0];
    try{$r->confirm('other-outlet',(int)$p['product_id'],(int)$p['version'],'confirm_current');throw new RuntimeException('Cross outlet accepted.');}catch(DomainException){}
    $r->confirm('demo-outlet',(int)$p['product_id'],(int)$p['version'],'confirm_current');
    if(count($r->needsReview('demo-outlet'))!==5||(int)$pdo->query('SELECT COUNT(*) FROM menu_admin_events')->fetchColumn()!==1)throw new RuntimeException('Review audit failed.');
    try{$r->confirm('demo-outlet',(int)$p['product_id'],(int)$p['version'],'invalid');throw new RuntimeException('Invalid review accepted.');}catch(DomainException){}
 },
];
