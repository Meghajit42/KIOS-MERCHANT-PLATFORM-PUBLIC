<?php
declare(strict_types=1);
namespace Kios\Infrastructure;
final class CatalogueReviewStore {
 public const DECISIONS=['PENDING','KEEP','REPLACE PHOTO','CORRECT DETAILS','REMOVE FROM FINAL MENU'];
 public function __construct(private string $directory){}
 public function read():array {return $this->locked(false,null);}
 public function save(int $id,string $decision,string $notes,int $version):array {
  if($id<1||!in_array($decision,self::DECISIONS,true)||strlen($notes)>2000||!preg_match('//u',$notes))throw new \DomainException('Choose a valid decision and notes up to 2000 bytes.');
  return $this->locked(true,[$id,$decision,$notes,$version]);
 }
 private function locked(bool $write,?array $change):array {
  if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory))throw new \RuntimeException('Review storage unavailable.');
  $lock=fopen($this->directory.'/review.lock','c');if(!$lock||!flock($lock,$write?LOCK_EX:LOCK_SH))throw new \RuntimeException('Review lock unavailable.');
  try {
   $file=$this->directory.'/decisions.json';$state=is_file($file)?json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR):['decisions'=>[],'audit'=>[]];
   if($write){[$id,$decision,$notes,$version]=$change;$before=$state['decisions'][(string)$id]??['decision'=>'PENDING','notes'=>'','version'=>0];
    if($before['version']!==$version)throw new \DomainException('Another reviewer updated this product. Reload before saving.');
    $after=['decision'=>$decision,'notes'=>$notes,'version'=>$version+1,'updated_utc'=>gmdate('c')];$state['decisions'][(string)$id]=$after;
    $state['audit'][]=['product_id'=>$id,'actor'=>'authenticated-owner','before'=>$before,'after'=>$after,'sequence'=>count($state['audit'])+1];
    $temp=tempnam($this->directory,'review-');if($temp===false)throw new \RuntimeException('Review write unavailable.');
    try{chmod($temp,0600);$data=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(file_put_contents($temp,$data)!==strlen($data)||!rename($temp,$file))throw new \RuntimeException('Review save failed.');}finally{if(is_file($temp))unlink($temp);}
   }return $state;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}
