<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use Kios\Infrastructure\Environment;
use PDO;
final class CloudDemo {
 public static function allowed(array $server):bool {
  return Environment::get('KIOS_MERCHANT_CLOUD_ENABLED','0')==='1'
   && Environment::get('APP_ENV')==='demo'
   && ($server['HTTP_HOST']??'')==='kios-merchant-demo.joviaaninternational.com'
   && ($server['HTTPS']??'')==='on'
   && defined('KIOS_PRIVATE_GATE_VERIFIED') && KIOS_PRIVATE_GATE_VERIFIED===true;
 }
 public static function actionAllowed(string $action):bool {
  if(PlatformAccess::enabled()&&in_array($action,['onboarding-create','onboarding-activate'],true))return false;
  return in_array($action,['integration-save','integration-review','usage-price','usage-event','usage-save','platform-create','platform-owner-activate','platform-admin','plans-lifecycle','plans-save','plans-assign','billing-save','pos-save','pos-submit','pos-served','pos-cancel','pos-task','seating-timezone','seating-floor','seating-table','seating-status','seating-reserve','seating-transition','seating-checkin','seating-assign','inventory-ingredient','inventory-update','inventory-receipt','inventory-adjustment','inventory-wastage','inventory-recipe','inventory-consume','inventory-reverse','login','logout','change-password','onboarding-create','onboarding-activate',
   'setup-save-business','setup-save-outlet','setup-submit','setup-reopen','setup-review',
   'restaurant-category','restaurant-station','restaurant-product','restaurant-availability','restaurant-archive','restaurant-staff-create','restaurant-staff-activate','restaurant-staff-access',
   'brand-save','brand-defaults','brand-upload','brand-remove','brand-confirm','brand-cancel','brand-restore','brand-preview','layout-save','layout-defaults','design-publish','design-restore',
   'catalogue','order','queue','kot','prints'],true);
 }
 public static function presentation(string $html):string {
  if(str_contains($html,'class="platform-console"')||str_contains($html,'class="platform-console usage-billing"'))return $html;
  $html=str_replace('/merchant-dev.php','/merchant.php',$html);
  if(str_contains($html,'<body')) {
   if(isset($GLOBALS['kios_feature_flags']))$html=preg_replace('/<body(\s|>)/','<body data-entitlements="'.htmlspecialchars(json_encode($GLOBALS['kios_feature_flags']),ENT_QUOTES,'UTF-8').'"$1',$html,1)??$html;
   if(Entitlements::enabled())$links[]=['Subscription overview','Your configured plan, feature status and immutable assignment history.','subscription=1'];
   if(Environment::get('KIOS_BILL_DRAFTS_ENABLED','0')==='1')$links[]=['Bill preparation','Nonfinancial drafts and reconciled portions.','billing=1'];
   if(Environment::get('KIOS_OPERATIONAL_ANALYTICS_ENABLED','0')==='1')$links[]=['Operational analytics','Read-only operational counts, timing, stock and activity.','analytics=1'];
  if(Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')==='1'){$links[]=['Staff POS','Table-linked internal service; no payment due.','pos=1'];$links[]=['Internal kitchen','Prepare authorized internal station tickets.','service-kitchen=1'];}
  if(Environment::get('KIOS_MERCHANT_SEATING_ENABLED','0')==='1')$html=preg_replace('/<body(\s|>)/','<body data-seating-enabled="1"$1',$html,1)??$html;
   if(Entitlements::enabled())$links[]=['Subscription overview','Your configured plan, feature status and immutable assignment history.','subscription=1'];
   if(Environment::get('KIOS_BILL_DRAFTS_ENABLED','0')==='1')$links[]=['Bill preparation','Nonfinancial drafts and reconciled portions.','billing=1'];
   if(Environment::get('KIOS_OPERATIONAL_ANALYTICS_ENABLED','0')==='1')$links[]=['Operational analytics','Read-only operational counts, timing, stock and activity.','analytics=1'];
  if(Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')==='1')$html=preg_replace('/<body(\s|>)/','<body data-pos-enabled="1"$1',$html,1)??$html;
   if(Environment::get('KIOS_OPERATIONAL_ANALYTICS_ENABLED','0')==='1')$html=preg_replace('/<body(\s|>)/','<body data-analytics-enabled="1"$1',$html,1)??$html;
   $inventoryEnabled=Environment::get('KIOS_MERCHANT_INVENTORY_ENABLED','0')==='1';if($inventoryEnabled)$html=preg_replace('/<body(\s|>)/','<body data-inventory-enabled="1"$1',$html,1)??$html;
   $nav='<nav class="cloud-navigation" aria-label="Merchant home"><a href="/merchant.php">KIOS Merchant · Dashboard</a><span>Private demo · Ordering closed · Payments and printing disabled</span></nav>';
   $html=preg_replace('/(<body[^>]*>)/','$1'.$nav,$html,1)??$html;
   $html=str_replace('</head>','<link rel="stylesheet" href="/assets/cloud-demo.css"><link rel="stylesheet" href="/assets/merchant-experience.css"><script src="/assets/merchant-experience.js" defer></script></head>',$html);
  }
  return $html;
 }
 public static function dashboard(PDO $db,StaffSessionIdentity $identity,array $home,string $csrf):string {
  // The canonical owner service validates current merchant/outlet membership.
  $state=(new OwnerOnboarding($db,$identity))->load($home['merchant_id']);
  $h=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES,'UTF-8');
  $scope='merchant='.rawurlencode($home['merchant_id']).'&amp;outlet='.rawurlencode($home['merchant_outlet_id']);
  $links=[
   ['Business profile & outlets','Configure your restaurant details and operating setup.','setup=1'],
   ['Products & menu','Manage categories, products and serving prices.','restaurant=1&section=menu'],
   ['Pricing & availability','Review prices and product availability.','restaurant=1&section=pricing'],
   ['Kitchen configuration','Review stations and preparation assignments.','restaurant=1&section=kitchen'],
   ['Staff management','Manage only this merchant’s staff access.','restaurant=1&section=staff'],
   ['Branding & templates','Use your logo, approved colours and template.','brand=1'],
   ['Visual customization','Reorder sections, choose cards and save a draft.','layout=1'],
   ['Publish & version history','Publish a demo design or restore an earlier version.','design=1'],
   ['Restaurant preview','Preview the ordering interface; checkout remains closed.','brand_preview=1'],
  ];
   if(IntegratedSetup::enabled()){$links[]=['Setup assistant','Actual setup progress for this outlet.','assistant=1'];$links[]=['My outlets','Choose a scoped outlet dashboard and configuration.','outlet_directory=1'];}
   if(UsageBilling::enabled())$links[]=['Billing estimates','Illustrative pricing, synthetic usage and saved estimates — never a payment request.','usage=1'];
  if(Entitlements::enabled())$links[]=['Subscription overview','Your configured plan, feature status and immutable assignment history.','subscription=1'];
   if(Environment::get('KIOS_BILL_DRAFTS_ENABLED','0')==='1')$links[]=['Bill preparation','Nonfinancial drafts and reconciled portions.','billing=1'];
   if(Environment::get('KIOS_OPERATIONAL_ANALYTICS_ENABLED','0')==='1')$links[]=['Operational analytics','Read-only operational counts, timing, stock and activity.','analytics=1'];
  if(Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')==='1'){$links[]=['Staff POS','Table-linked internal service; no payment due.','pos=1'];$links[]=['Internal kitchen','Prepare authorized internal station tickets.','service-kitchen=1'];}
  if(Environment::get('KIOS_MERCHANT_SEATING_ENABLED','0')==='1')$links[]=['Floor Manager','Configure floors, seat guests and manage reservations.','seating=1'];
  if(Environment::get('KIOS_MERCHANT_INVENTORY_ENABLED','0')==='1')$links[]=['Inventory & recipes','Track ingredients, movements and low stock.','inventory=1'];
  $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KIOS Merchant dashboard</title><link rel="stylesheet" href="/assets/cloud-demo.css"></head><body><main data-cloud-dashboard data-merchant="'.$h($home['merchant_id']).'" data-outlet="'.$h($home['merchant_outlet_id']).'"><header><div><p class="eyebrow">KIOS · Private merchant demonstration</p><h1>'.$h((string)$state['business']['business_name']).'</h1><p>Restaurant command centre · Configure, customize and preview.</p></div><form method="post"><input type="hidden" name="_csrf" value="'.$h($csrf).'"><input type="hidden" name="action" value="logout"><button>Sign out</button></form></header><p class="notice">Synthetic data only. Public registration, customer ordering, real payments, notifications and physical printing are disabled.</p><section class="dashboard-grid" aria-label="Merchant tools">';
  $html=str_replace('<section class="dashboard-grid"', '<section class="merchant-metrics" aria-label="Setup overview"><article><strong>Ordering closed</strong><span>Private demonstration only</span></article><article><strong>'.((int)$state['business_saved']+(int)$state['outlet_saved']).' / 2</strong><span>Business and outlet details saved</span></article><article><strong>'.$h((string)$state['progress']).'</strong><span>Setup review status</span></article></section><section class="dashboard-grid"',$html);
  if(Entitlements::enabled()){$ent=new Entitlements($db,$identity);$GLOBALS['kios_feature_flags']=$ent->availability($home['merchant_id'],$home['merchant_outlet_id']);$links=array_values(array_filter($links,static function($link)use($ent,$home){parse_str($link[2],$q);$f=EntitlementRoutes::feature($q);if($f===null)return true;try{$ent->requireFeature($home['merchant_id'],$home['merchant_outlet_id'],$f,in_array($f,['pos','kitchen','floor'],true)?'drain-read':'read');return true;}catch(\DomainException){return false;}}));}
  foreach($links as [$name,$description,$query])$html.='<a class="dashboard-tool" href="/merchant.php?'.$query.'&amp;'.$scope.'"><h2>'.$name.'</h2><p>'.$description.'</p><span>Open →</span></a>';
  return $html.'</section></main></body></html>';
 }
}
