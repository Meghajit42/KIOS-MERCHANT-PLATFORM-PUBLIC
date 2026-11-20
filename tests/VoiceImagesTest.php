<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
return [
 'voice image remains attached to stable SKU after owner rename and category edit' => static function(): void {
    $product=new \Kios\Domain\Product('voice-samosa','Owner edited name','sweets','demo-outlet',\Kios\Domain\PreparationStation::Sweets,true,true,true,[new \Kios\Domain\ProductVariant('default','1 piece',\Kios\Domain\QuantityUnit::Piece,1,\Kios\Domain\Money::fromPaise(1000))]);
    $image=\Kios\Application\GopalMenuImageCatalog::imageFor($product);
    if(($image['asset_base']??null)!=='/assets/menu/voice/samosa')throw new RuntimeException('Owner edit lost stable product imagery.');
 },
 'voice images cover all 77 source identities with owner supplied guidance' => static function(string $root): void {
    $map=json_decode(file_get_contents($root.'/app/Data/gopal-voice-image-map.json'),true,512,JSON_THROW_ON_ERROR);
    $source=json_decode(file_get_contents($root.'/data/gopal_voice_catalogue_77.json'),true,512,JSON_THROW_ON_ERROR);
    if(count($map['images'])!==77||count($map['fallbacks'])!==0)throw new RuntimeException('Image program incomplete.');
    foreach($source as $row){
      $name=$row['source_name'];$entry=$map['images'][$name]??$map['fallbacks'][$name]??null;
      if(!is_array($entry)||$entry['category']!==strtolower($row['source_category']))throw new RuntimeException('Missing exact product image mapping.');
      if(in_array($name,['Boil Cake','Malai Gari','Khirtua'],true)&&$row['verification_status']!=='needs_owner_confirmation')throw new RuntimeException('Source naming review was incorrectly confirmed.');
    }
 },
 'voice responsive assets resolve with unique hashes and no upscaling' => static function(string $root): void {
    $map=json_decode(file_get_contents($root.'/app/Data/gopal-voice-image-map.json'),true,512,JSON_THROW_ON_ERROR);
    $seen=[];$masters=[];
    foreach($map['images'] as $entry){
      if(isset($masters[$entry['source_sha256']]))throw new RuntimeException('Unintended duplicate master.');$masters[$entry['source_sha256']]=true;
      foreach([320,480,640,960] as $width){
        $path=$root.'/public'.$entry['asset_base'].'/'.$width.'.webp';
        if(!is_file($path))throw new RuntimeException('Broken responsive path.');
        $size=getimagesize($path);if(!$size||$size[0]!==$width||$width>$entry['source_width']||$size[1]!==(int)round($entry['source_height']*$width/$entry['source_width']))throw new RuntimeException('Invalid responsive dimensions.');
        $hash=hash_file('sha256',$path);if(isset($seen[$hash]))throw new RuntimeException('Unintended duplicate derivative.');$seen[$hash]=true;
      }
    }
 },
];
