<?php
declare(strict_types=1);
return [
 'C1 route fixtures contain expected catalogue identities and no customer review metadata' => static function(string $root): void {
    foreach(['snacks'=>6,'dahi'=>4] as $category=>$count){
      $html=file_get_contents($root.'/docs/fixtures/c1d/customer-'.$category.'.html');
      if(substr_count($html,'class="product-card panel"')!==$count)throw new RuntimeException('Category fixture count mismatch.');
    }
    $html=file_get_contents($root.'/docs/fixtures/c1d/customer-sweets.html');
    if(substr_count($html,'<h2>Kaju Barfi</h2>')!==1)throw new RuntimeException('Kaju duplicate or missing.');
    foreach(glob($root.'/docs/fixtures/c1d/customer-*.html') as $path){
      $html=file_get_contents($path);
      foreach(['needs_owner_confirmation','source_conflict','possible_duplicate','Shop confirmation required'] as $secret){if(str_contains($html,$secret))throw new RuntimeException('Customer metadata leak.');}
    }
 },
 'C1 fixtures redact CSRF and intent values and exporter stays CLI read-only' => static function(string $root): void {
    foreach(glob($root.'/docs/fixtures/c1d/*.html') as $path){
      $html=file_get_contents($path);preg_match_all('/name="_csrf"[^>]*value="([^"]*)"/',$html,$values);
      foreach($values[1] as $value)if($value!=='C1_FIXTURE_CSRF_REDACTED')throw new RuntimeException('CSRF not redacted.');
      if(preg_match('/name="checkout_intent_key"[^>]*value="(?!C1_FIXTURE_INTENT_REDACTED)/',$html))throw new RuntimeException('Checkout intent not redacted.');
    }
    $export=file_get_contents($root.'/tests/tools/export-c1-fixtures.php');
    if(!str_contains($export,"PHP_SAPI !== 'cli'")||!str_contains($export,'SET SESSION TRANSACTION READ ONLY'))throw new RuntimeException('Exporter guard missing.');
    $review=file_get_contents($root.'/public/admin/review.php');if(!str_contains($review,'adminRequireLogin();'))throw new RuntimeException('Production owner guard missing.');
 },
];
