<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$info=json_decode(file_get_contents(dirname(__DIR__).'/docs/C1B_MARIADB_RESULTS.json'),true,512,JSON_THROW_ON_ERROR);
$db=$info['demo_database'];
if(!preg_match('/^kios_c1b_demo_[0-9]+$/D',$db))exit(2);
$pdo=new PDO('mysql:host=127.0.0.1;port=33082;dbname='.$db.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$source=json_decode(file_get_contents(dirname(__DIR__).'/data/gopal_voice_catalogue_77.json'),true,512,JSON_THROW_ON_ERROR);
$q=$pdo->query('SELECT p.*,c.slug AS category_slug FROM products p JOIN categories c ON c.category_id=p.category_id AND c.outlet_id=p.outlet_id WHERE p.verification_evidence IS NOT NULL ORDER BY p.product_id');
$rows=$q->fetchAll();$orders=[];$anomalies=[];$csv=[];
foreach($rows as $p){
 $e=json_decode($p['verification_evidence'],true,512,JSON_THROW_ON_ERROR);$n=$e['source_order'];
 if(isset($orders[$n]))$anomalies[]='duplicate source order';$orders[$n]=true;
 $s=$source[$n-1]??null;
 if(!$s||$e!==$s||$p['sku']!==$s['reconciliation_target_product_id'])$anomalies[]='evidence mismatch';
 if($p['category_slug']!==strtolower($s['source_category']))$anomalies[]='category mismatch';
 if((int)$p['display_order']<0)$anomalies[]='invalid display order';
 $q=$pdo->prepare('SELECT unit,quantity_value,price_paise,label FROM product_variants WHERE product_id=?');$q->execute([$p['product_id']]);$variants=$q->fetchAll();
 if(!$variants)$anomalies[]='missing variants';
 if($s['source_name']!=='Kaju Barfi')foreach($variants as $v){
   $expected=$s['source_unit']==='kg'?intdiv($s['source_price_paise']*(int)$v['quantity_value'],1000):$s['source_price_paise'];
   if((int)$v['price_paise']!==$expected)$anomalies[]='price mismatch';
   if($v['unit']!==($s['source_unit']==='kg'?'gram':($s['source_unit']==='unit'?'count':'piece')))$anomalies[]='unit mismatch';
 }
 $csv[]=['source_order'=>$n,'source_name'=>$s['source_name'],'source_category'=>$s['source_category'],'source_price'=>$s['source_price_paise'],'source_unit'=>$s['source_unit'],'existing_product_id'=>$s['source_name']==='Kaju Barfi'?$p['product_id']:'','existing_name'=>$s['source_name']==='Kaju Barfi'?$p['name']:'','existing_price'=>$s['source_name']==='Kaju Barfi'?'15000;36000;70000;135000':'','existing_unit'=>$s['source_name']==='Kaju Barfi'?'100g;250g;500g;1kg':'','reconciliation_class'=>$s['reconciliation_result'],'verification_status'=>$p['verification_status'],'action'=>$s['source_name']==='Kaju Barfi'?'preserved current operational record':'inserted canonical record','operational_product_id'=>$p['product_id']];
}
if(count($orders)!==77)$anomalies[]='source count';
$checks=[
 'orphan_categories'=>'SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.category_id=p.category_id AND c.outlet_id=p.outlet_id WHERE c.category_id IS NULL',
 'orphan_stations'=>'SELECT COUNT(*) FROM products p LEFT JOIN preparation_stations s ON s.station_id=p.station_id AND s.outlet_id=p.outlet_id WHERE s.station_id IS NULL',
 'orphan_variants'=>'SELECT COUNT(*) FROM product_variants v LEFT JOIN products p ON p.product_id=v.product_id WHERE p.product_id IS NULL',
 'order_total_mismatch'=>'SELECT COUNT(*) FROM orders o WHERE o.total_paise <> (SELECT SUM(i.line_total_paise) FROM order_items i WHERE i.order_id=o.order_id)',
 'partial_orders'=>'SELECT COUNT(*) FROM orders o WHERE NOT EXISTS (SELECT 1 FROM order_items i WHERE i.order_id=o.order_id)',
];
$counts=[];foreach($checks as $label=>$sql){$counts[$label]=(int)$pdo->query($sql)->fetchColumn();if($counts[$label]!==0)$anomalies[]=$label;}
usort($csv,fn($a,$b)=>$a['source_order']<=>$b['source_order']);
$f=fopen(dirname(__DIR__).'/docs/GOPAL_C1_RECONCILIATION.csv','wb');fputcsv($f,array_keys($csv[0]));foreach($csv as $row)fputcsv($f,$row);fclose($f);
$result=['source_evidence_count'=>count($orders),'structural_counts'=>$counts,'anomalies'=>$anomalies,'status'=>$anomalies===[]?'PASS':'FAIL'];
file_put_contents(dirname(__DIR__).'/docs/C1B_CONSISTENCY_RESULTS.json',json_encode($result,JSON_PRETTY_PRINT)."\n");
echo json_encode($result),"\n";exit($anomalies===[]?0:1);
