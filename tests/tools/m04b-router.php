<?php
declare(strict_types=1);
// Qualification fixture only; never deploy this local transport adapter.
if(PHP_SAPI!=='cli-server'||getenv('USERNAME')!=='WDAGUtilityAccount'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$assets=['integrated-setup.css','integrated-setup.js','platform-admin.css','usage-billing.css','usage-billing.js','platform-admin.js','subscriptions.css','subscriptions.js','bill-drafts.css','bill-drafts.js','operational-analytics.css','operational-analytics.js','staff-pos.css','staff-pos.js','inventory.css','seating.css','seating.js','inventory.js','merchant-setup.css','restaurant-setup.css','layout-studio.css','layout-studio.js','brand-studio.css','brand-product-placeholder.svg','cloud-demo.css','merchant-experience.css','merchant-experience.js'];
foreach($assets as $asset)if($path==='/assets/'.$asset){header('Content-Type: '.(str_ends_with($asset,'.css')?'text/css':(str_ends_with($asset,'.js')?'text/javascript':'image/svg+xml')));readfile(dirname(__DIR__,2).'/public/assets/'.$asset);exit;}
if($path!=='/merchant.php'&&!str_starts_with($path,'/api/')){http_response_code(404);exit;}
$_SERVER['HTTP_HOST']='kios-merchant-demo.joviaaninternational.com';$_SERVER['HTTPS']='on';define('KIOS_PRIVATE_GATE_VERIFIED',true);
require dirname(__DIR__,2).'/public/merchant-cloud.php';
