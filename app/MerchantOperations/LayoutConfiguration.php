<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use InvalidArgumentException;

/** Closed presentation vocabulary. No markup, paths, asset IDs or publication flags. */
final class LayoutConfiguration
{
    public const SECTIONS=['header','business','categories','featured','catalogue','footer'];
    public static function inherited(array $brand):array{$d=self::defaults();$d['presentation']=array_intersect_key(BrandProfile::configuration($brand),$d['presentation']);return $d;}
    public static function defaults():array{return ['sections'=>self::SECTIONS,'cards'=>'image','navigation'=>'pills','logo'=>'left','density'=>'comfortable','presentation'=>array_intersect_key(BrandProfile::defaults(),array_flip(['template','font','primary','secondary','accent']))];}
    public static function validate(array $d):array{
        if(array_diff(array_keys($d),array_keys(self::defaults()))||array_diff(array_keys(self::defaults()),array_keys($d)))throw new InvalidArgumentException('Only displayed layout settings are accepted.');
        if(!is_array($d['sections'])||!array_is_list($d['sections'])||count($d['sections'])!==6)throw new InvalidArgumentException('Keep all six sections.');
        foreach($d['sections'] as $s)if(!is_string($s)||!in_array($s,self::SECTIONS,true))throw new InvalidArgumentException('Unsupported section.');
        if(count(array_unique($d['sections']))!==6)throw new InvalidArgumentException('Each section must appear once.');
        foreach(['cards'=>['compact','image'],'navigation'=>['pills','list'],'logo'=>['left','center'],'density'=>['comfortable','compact']] as $k=>$values)if(!is_string($d[$k])||!in_array($d[$k],$values,true))throw new InvalidArgumentException('Choose an approved presentation option.');
        $p=$d['presentation'];$keys=['template','font','primary','secondary','accent'];
        if(!is_array($p)||array_diff(array_keys($p),$keys)||array_diff($keys,array_keys($p)))throw new InvalidArgumentException('Choose only approved brand presentation values.');
        $validated=BrandProfile::configuration(array_replace(BrandProfile::defaults(),$p));
        $d['presentation']=array_intersect_key($validated,array_flip($keys));return $d;
    }
}
