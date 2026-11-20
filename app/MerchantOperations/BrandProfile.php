<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity};

/** Initial-outlet owner branding. Private transactional raster storage; no public file URLs. */
final class BrandProfile
{
    public function __construct(private readonly PDO $db,private readonly AuthenticatedUserProvider $authentication){
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new LogicException('MariaDB required.');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }
    public static function defaults():array{return ['name'=>'Your restaurant','primary'=>'#991b1b','secondary'=>'#fef3c7','accent'=>'#166534','font'=>'system','template'=>'modern'];}
    public static function contrast(string $a,string $b):float{
        $l=static function(string $c):float{$v=[];foreach([1,3,5] as $i){$x=hexdec(substr($c,$i,2))/255;$v[]=$x<=0.04045?$x/12.92:(($x+0.055)/1.055)**2.4;}return $v[0]*0.2126+$v[1]*0.7152+$v[2]*0.0722;};
        $x=$l($a);$y=$l($b);return (max($x,$y)+0.05)/(min($x,$y)+0.05);
    }
    public static function configuration(array $d):array{
        if(array_diff(array_keys($d),array_keys(self::defaults()))||array_diff(array_keys(self::defaults()),array_keys($d)))throw new InvalidArgumentException('Only the displayed branding fields are accepted.');
        foreach($d as $v)if(!is_string($v))throw new InvalidArgumentException('Use readable branding values.');
        $d['name']=trim($d['name']);if(!mb_check_encoding($d['name'],'UTF-8')||mb_strlen($d['name'])<1||mb_strlen($d['name'])>100||preg_match('/[<>\x00-\x1f\x7f]/u',$d['name']))throw new InvalidArgumentException('Use a restaurant name of 1–100 plain-text characters.');
        foreach(['primary','secondary','accent'] as $key){if(preg_match('/^#[a-fA-F0-9]{6}$/D',$d[$key])!==1)throw new InvalidArgumentException('Choose six-digit colour values.');$d[$key]=strtolower($d[$key]);}
        if(!in_array($d['font'],['system','serif'],true)||!in_array($d['template'],['modern','counter'],true))throw new InvalidArgumentException('Choose an approved font and template.');
        if(self::contrast($d['primary'],'#ffffff')<4.5||self::contrast($d['accent'],'#ffffff')<4.5||self::contrast($d['secondary'],'#17201c')<4.5)throw new InvalidArgumentException('Choose darker primary/accent colours and a light secondary colour for readable contrast (4.5:1).');
        return $d;
    }
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function scoped(string $merchant,string $outlet,callable $work):mixed{
        Identity::validate($outlet);if($this->db->inTransaction())throw new LogicException('Branding owns its transaction.');
        $setup=OwnerOutletScope::prepare($this->db,$this->authentication,$merchant,$outlet);
        $actor=$setup['owner_user_id'];$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{
            OwnerOutletScope::locked($this->db,$this->authentication,$merchant,$outlet,$actor);
            (new Entitlements($this->db,$this->authentication))->requireFeature($merchant,$outlet,'branding');
            $inherited=$this->rows('SELECT config_json FROM merchant_brand_profiles WHERE merchant_id=? AND merchant_outlet_id=?',[$merchant,$setup['initial_outlet_id']]);$defaults=$inherited?json_decode($inherited[0]['config_json'],true,16,JSON_THROW_ON_ERROR):self::defaults();
            if(!$this->rows('SELECT merchant_id FROM merchant_brand_profiles WHERE merchant_id=? AND merchant_outlet_id=? FOR UPDATE',[$merchant,$outlet])){
                $this->rows('INSERT INTO merchant_brand_profiles(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id,config_json) VALUES(?,?,?,?,?)',[$merchant,$outlet,$setup['legacy_outlet_id'],$actor,json_encode($defaults,JSON_THROW_ON_ERROR)]);
                $this->event($merchant,$outlet,$actor,bin2hex(random_bytes(16)),'initialize',hash('sha256','initialize'),['version'=>1]);
            }
            $profile=$this->rows('SELECT * FROM merchant_brand_profiles WHERE merchant_id=? AND merchant_outlet_id=? FOR UPDATE',[$merchant,$outlet]);if(count($profile)!==1)throw new DomainException('Brand scope mismatch.');
            $r=$work($profile[0],$actor);$this->db->commit();return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function event(string $m,string $o,string $actor,string $request,string $op,string $hash,array $result):void{
        $this->rows('INSERT INTO merchant_brand_events VALUES(?,?,?,?,?,?,?,?)',[$request,$m,$o,$actor,$op,$hash,json_encode($result,JSON_THROW_ON_ERROR),time()]);
    }
    public function load(string $m,string $o):array{return $this->scoped($m,$o,function($p)use($m,$o){return ['merchant'=>$m,'outlet'=>$o,'version'=>(int)$p['version'],'config'=>json_decode($p['config_json'],true,16,JSON_THROW_ON_ERROR),'logo'=>$p['current_asset_id'],'assets'=>$this->rows("SELECT asset_id,state,original_mime,original_sha256,safe_sha256,width,height,created_epoch FROM merchant_brand_assets WHERE merchant_id=? AND merchant_outlet_id=? AND state<>'cancelled' ORDER BY created_epoch DESC,asset_id",[$m,$o])];});}
    /** Input is bytes, never a caller-controlled path. HTTP layer separately enforces is_uploaded_file. */
    private function raster(string $raw):array{
        if(strlen($raw)<12||strlen($raw)>1048576||preg_match('/<\?(?:php|=)|<script\b|<svg\b/i',$raw))throw new InvalidArgumentException('Use a raster JPG, PNG or WebP file up to 1 MiB.');
        $info=@getimagesizefromstring($raw);$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$mime!==($info['mime']??null)||$info[0]<16||$info[1]<16||$info[0]>2048||$info[1]>2048||$info[0]*$info[1]>4194304)throw new InvalidArgumentException('Use a valid 16–2048 px raster image, maximum 4 megapixels.');
        $image=@imagecreatefromstring($raw);if(!$image)throw new InvalidArgumentException('Image could not be decoded.');
        try{
            $scale=min(1,512/max($info[0],$info[1]));$width=max(1,(int)round($info[0]*$scale));$height=max(1,(int)round($info[1]*$scale));
            $canvas=imagecreatetruecolor($width,$height);if(!$canvas)throw new LogicException('Image processing unavailable.');
            try{imagealphablending($canvas,false);imagesavealpha($canvas,true);imagefill($canvas,0,0,imagecolorallocatealpha($canvas,0,0,0,127));imagecopyresampled($canvas,$image,0,0,0,0,$width,$height,$info[0],$info[1]);ob_start();try{if(!imagepng($canvas,null,7))throw new LogicException('PNG encoding failed.');$safe=ob_get_contents();}finally{ob_end_clean();}}finally{imagedestroy($canvas);}
        }finally{imagedestroy($image);}
        if(!is_string($safe)||strlen($safe)>1048576)throw new InvalidArgumentException('Optimized image exceeds the safe size limit.');
        return [$mime,$safe,$width,$height];
    }
    public function act(string $m,string $o,int $version,string $request,string $op,array $data=[],?string $upload=null):array{
        if($version<1||preg_match('/^[a-f0-9]{32}$/D',$request)!==1||!in_array($op,['save','defaults','upload','confirm','cancel','restore'],true))throw new InvalidArgumentException('Reload the current branding form.');
        if($op==='save')$data=self::configuration($data);
        elseif(in_array($op,['confirm','cancel','restore'],true)){if(array_keys($data)!==['asset']||!is_string($data['asset']))throw new InvalidArgumentException('Choose a logo version.');Identity::validate($data['asset']);}
        elseif($data!==[])throw new InvalidArgumentException('Unexpected branding fields.');
        if(($op==='upload')!==($upload!==null))throw new InvalidArgumentException('Unexpected image payload.');
        $hash=hash('sha256',json_encode([$m,$o,$version,$op,$data,$upload===null?null:hash('sha256',$upload)],JSON_THROW_ON_ERROR));
        return $this->scoped($m,$o,function($p,$actor)use($m,$o,$version,$request,$op,$data,$upload,$hash){
            $prior=$this->rows('SELECT * FROM merchant_brand_events WHERE request_id=? FOR UPDATE',[$request]);
            if($prior){if($prior[0]['merchant_id']!==$m||$prior[0]['merchant_outlet_id']!==$o||$prior[0]['actor_user_id']!==$actor||!hash_equals($hash,$prior[0]['request_hash']))throw new DomainException('Submission already used.');return ['duplicate'=>true,'result'=>json_decode($prior[0]['result_json'],true,16,JSON_THROW_ON_ERROR)];}
            if((int)$p['version']!==$version)throw new DomainException('Branding changed in another tab. Reload before saving.');
            $config=json_decode($p['config_json'],true,16,JSON_THROW_ON_ERROR);$oldConfig=$config;$logo=$p['current_asset_id'];$oldLogo=$logo;$asset=null;
            if($op==='save')$config=$data;
            elseif($op==='defaults'){$config=self::defaults();$logo=null;}
            elseif($op==='upload'){
                if((int)$this->rows('SELECT COUNT(*) AS n FROM merchant_brand_assets WHERE merchant_id=?',[$m])[0]['n']>=30
                    ||(int)$this->rows("SELECT COUNT(*) AS n FROM merchant_brand_assets WHERE merchant_id=? AND state='pending'",[$m])[0]['n']>=3
                    ||(int)$this->rows("SELECT COUNT(*) AS n FROM merchant_brand_events WHERE merchant_id=? AND operation='upload' AND occurred_epoch>=?",[$m,time()-3600])[0]['n']>=10)throw new DomainException('Logo upload limit reached; cancel pending previews or contact the development administrator.');
                [$mime,$safe,$width,$height]=$this->raster($upload);$asset=Identity::generate();
                $this->rows('INSERT INTO merchant_brand_assets(asset_id,merchant_id,merchant_outlet_id,original_mime,original_sha256,original_bytes,safe_png,safe_sha256,width,height,created_by,created_epoch) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$asset,$m,$o,$mime,hash('sha256',$upload),$upload,$safe,hash('sha256',$safe),$width,$height,$actor,time()]);
            }else{
                $asset=$data['asset'];$row=$this->rows('SELECT state FROM merchant_brand_assets WHERE merchant_id=? AND merchant_outlet_id=? AND asset_id=? FOR UPDATE',[$m,$o,$asset]);
                $expected=$op==='restore'?'retained':'pending';if(count($row)!==1||$row[0]['state']!==$expected)throw new DomainException('Logo version is not available in this outlet.');
                if($op==='cancel')$this->rows("UPDATE merchant_brand_assets SET state='cancelled' WHERE asset_id=?",[$asset]);
                else{$logo=$asset;$this->rows("UPDATE merchant_brand_assets SET state='retained' WHERE asset_id=?",[$asset]);}
            }
            $this->rows('UPDATE merchant_brand_profiles SET config_json=?,current_asset_id=?,version=version+1 WHERE merchant_id=? AND merchant_outlet_id=?',[json_encode($config,JSON_THROW_ON_ERROR),$logo,$m,$o]);
            $result=['version'=>$version+1,'asset'=>$asset,'before'=>['config'=>$oldConfig,'logo'=>$oldLogo],'after'=>['config'=>$config,'logo'=>$logo]];
            $this->event($m,$o,$actor,$request,$op,$hash,$result);return ['duplicate'=>false,'result'=>$result];
        });
    }
    /** Drafts share the existing original-owner transaction and brand revision lock. */
    public function layout(string $m,string $o,?int $version=null,?string $request=null,?array $configuration=null):array{
        if($version!==null&&($version<1||$request===null||preg_match('/^[a-f0-9]{32}$/D',$request)!==1||$configuration===null))throw new InvalidArgumentException('Reload the draft form.');
        $configuration=$configuration===null?null:LayoutConfiguration::validate($configuration);
        if($version===null&&($request!==null||$configuration!==null))throw new InvalidArgumentException('Incomplete draft submission.');
        return $this->scoped($m,$o,function($p,$actor)use($m,$o,$version,$request,$configuration){
            $rows=$this->rows('SELECT config_json FROM merchant_layout_drafts WHERE merchant_id=? AND merchant_outlet_id=? FOR UPDATE',[$m,$o]);
            $before=$rows?LayoutConfiguration::validate(json_decode($rows[0]['config_json'],true,16,JSON_THROW_ON_ERROR)):LayoutConfiguration::inherited(json_decode($p['config_json'],true,16,JSON_THROW_ON_ERROR));
            if($version===null)return ['version'=>(int)$p['version'],'config'=>$before];
            $hash=hash('sha256',json_encode([$m,$o,$version,$configuration],JSON_THROW_ON_ERROR));
            $prior=$this->rows('SELECT * FROM merchant_brand_events WHERE request_id=? FOR UPDATE',[$request]);
            if($prior){if($prior[0]['merchant_id']!==$m||$prior[0]['merchant_outlet_id']!==$o||$prior[0]['actor_user_id']!==$actor||$prior[0]['operation']!=='layout-save'||!hash_equals($prior[0]['request_hash'],$hash))throw new DomainException('Submission already used.');return ['duplicate'=>true,'result'=>json_decode($prior[0]['result_json'],true,16,JSON_THROW_ON_ERROR)];}
            if((int)$p['version']!==$version)throw new DomainException('Settings changed in another tab. Reload before saving.');
            $json=json_encode($configuration,JSON_THROW_ON_ERROR);
            if($rows)$this->rows('UPDATE merchant_layout_drafts SET config_json=?,updated_epoch=? WHERE merchant_id=? AND merchant_outlet_id=?',[$json,time(),$m,$o]);
            else $this->rows('INSERT INTO merchant_layout_drafts VALUES(?,?,?,?)',[$m,$o,$json,time()]);
            $this->rows('UPDATE merchant_brand_profiles SET version=version+1 WHERE merchant_id=? AND merchant_outlet_id=?',[$m,$o]);
            $result=['version'=>$version+1,'before'=>$before,'after'=>$configuration,'publication'=>'none'];
            $this->event($m,$o,$actor,$request,'layout-save',$hash,$result);return ['duplicate'=>false,'result'=>$result];
        });
    }
    /** Immutable design snapshots. Restoration changes the owned active pointer only. */
    public function designs(string $m,string $o,?string $selected=null):array{
        if($selected!==null)Identity::validate($selected);
        return $this->scoped($m,$o,function($p)use($m,$o,$selected){
            $active=$this->rows('SELECT version_id FROM merchant_design_active WHERE merchant_id=? AND merchant_outlet_id=?',[$m,$o]);
            $id=$selected??($active[0]['version_id']??null);$snapshot=null;
            if($id!==null){$row=$this->rows('SELECT * FROM merchant_design_versions WHERE merchant_id=? AND merchant_outlet_id=? AND version_id=?',[$m,$o,$id]);if(count($row)!==1)throw new DomainException('Design outside this outlet.');if(!hash_equals($row[0]['snapshot_sha256'],hash('sha256',$row[0]['snapshot_json'])))throw new LogicException('Design integrity failure.');$snapshot=json_decode($row[0]['snapshot_json'],true,32,JSON_THROW_ON_ERROR);}
            return ['merchant'=>$m,'outlet'=>$o,'revision'=>(int)$p['version'],'active'=>$active[0]['version_id']??null,'selected'=>$id,'snapshot'=>$snapshot,'history'=>$this->rows('SELECT version_id,snapshot_sha256,created_by,created_epoch FROM merchant_design_versions WHERE merchant_id=? AND merchant_outlet_id=? ORDER BY created_epoch DESC,version_id',[$m,$o])];
        });
    }
    public function publish(string $m,string $o,int $revision,string $request,?string $restore=null):array{
        if($revision<1||preg_match('/^[a-f0-9]{32}$/D',$request)!==1)throw new InvalidArgumentException('Reload the current design controls.');
        if($restore!==null)Identity::validate($restore);
        return $this->scoped($m,$o,function($p,$actor)use($m,$o,$revision,$request,$restore){
            $op=$restore===null?'design-publish':'design-restore';$hash=hash('sha256',json_encode([$m,$o,$revision,$restore],JSON_THROW_ON_ERROR));
            $prior=$this->rows('SELECT * FROM merchant_brand_events WHERE request_id=? FOR UPDATE',[$request]);
            if($prior){if($prior[0]['merchant_id']!==$m||$prior[0]['merchant_outlet_id']!==$o||$prior[0]['actor_user_id']!==$actor||$prior[0]['operation']!==$op||!hash_equals($hash,$prior[0]['request_hash']))throw new DomainException('Submission already used.');return ['duplicate'=>true,'result'=>json_decode($prior[0]['result_json'],true,16,JSON_THROW_ON_ERROR)];}
            if((int)$p['version']!==$revision)throw new DomainException('Draft or brand changed. Reload before confirming.');
            $previous=$this->rows('SELECT version_id FROM merchant_design_active WHERE merchant_id=? AND merchant_outlet_id=? FOR UPDATE',[$m,$o]);
            if($restore===null){
                if((int)$this->rows('SELECT COUNT(*) AS n FROM merchant_design_versions WHERE merchant_id=?',[$m])[0]['n']>=100)throw new DomainException('Development version-history limit reached.');
                $brand=BrandProfile::configuration(json_decode($p['config_json'],true,16,JSON_THROW_ON_ERROR));
                $draft=$this->rows('SELECT config_json FROM merchant_layout_drafts WHERE merchant_id=? AND merchant_outlet_id=? FOR UPDATE',[$m,$o]);
                $layout=$draft?LayoutConfiguration::validate(json_decode($draft[0]['config_json'],true,16,JSON_THROW_ON_ERROR)):LayoutConfiguration::inherited($brand);
                $logo=$p['current_asset_id'];if($logo!==null&&!$this->rows("SELECT asset_id FROM merchant_brand_assets WHERE merchant_id=? AND merchant_outlet_id=? AND asset_id=? AND state='retained'",[$m,$o,$logo]))throw new DomainException('Owned retained logo required.');
                $snapshot=json_encode(['config'=>$brand,'logo'=>$logo,'layout'=>$layout],JSON_THROW_ON_ERROR);$id=Identity::generate();
                $this->rows('INSERT INTO merchant_design_versions VALUES(?,?,?,?,?,?,?)',[$id,$m,$o,$snapshot,hash('sha256',$snapshot),$actor,time()]);
            }else{
                $row=$this->rows('SELECT snapshot_json,snapshot_sha256 FROM merchant_design_versions WHERE merchant_id=? AND merchant_outlet_id=? AND version_id=?',[$m,$o,$restore]);
                if(count($row)!==1||!hash_equals($row[0]['snapshot_sha256'],hash('sha256',$row[0]['snapshot_json'])))throw new DomainException('Owned valid version required.');$id=$restore;
            }
            if($previous)$this->rows('UPDATE merchant_design_active SET version_id=? WHERE merchant_id=? AND merchant_outlet_id=?',[$id,$m,$o]);
            else $this->rows('INSERT INTO merchant_design_active VALUES(?,?,?)',[$m,$o,$id]);
            $this->rows('UPDATE merchant_brand_profiles SET version=version+1 WHERE merchant_id=? AND merchant_outlet_id=?',[$m,$o]);
            $result=['revision'=>$revision+1,'previous'=>$previous[0]['version_id']??null,'active'=>$id,'publicOrdering'=>'CLOSED'];
            $this->event($m,$o,$actor,$request,$op,$hash,$result);return ['duplicate'=>false,'result'=>$result];
        });
    }
    /** Originals have no retrieval route; only re-encoded PNG bytes are ever returned. */
    public function image(string $m,string $o,string $asset):array{
        Identity::validate($asset);return $this->scoped($m,$o,function()use($m,$o,$asset){$r=$this->rows("SELECT safe_png,safe_sha256,width,height FROM merchant_brand_assets WHERE merchant_id=? AND merchant_outlet_id=? AND asset_id=? AND state IN ('pending','retained')",[$m,$o,$asset]);if(count($r)!==1)throw new DomainException('Image unavailable.');return $r[0]+['filename'=>$asset.'.png'];});
    }
}
