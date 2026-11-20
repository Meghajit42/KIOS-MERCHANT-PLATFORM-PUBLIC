<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;
use Kios\Domain\{Money,PreparationStation,QuantityUnit,OperationalDepartment};
use Kios\Infrastructure\{MenuAdminRepository,StaffPasswords};
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity,Role};

/** Initial-owner configuration only. No public activation or financial grants. */
final class RestaurantSetup
{
    public function __construct(private readonly PDO $db,private readonly AuthenticatedUserProvider $authentication){
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new LogicException('MariaDB required.');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    private function transaction(string $merchant,string $outlet,callable $work):mixed
    {
        Identity::validate($outlet);if($this->db->inTransaction())throw new LogicException('Restaurant setup owns its transaction.');
        $setup=OwnerOutletScope::prepare($this->db,$this->authentication,$merchant,$outlet);
        $actor=$setup['owner_user_id'];$legacy=(int)$setup['legacy_outlet_id'];
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{
            $mapping=OwnerOutletScope::locked($this->db,$this->authentication,$merchant,$outlet,$actor);if((int)$mapping['legacy_outlet_id']!==$legacy)throw new DomainException('Outlet mapping changed.');
            if(!$this->rows('SELECT merchant_id FROM merchant_restaurant_setup WHERE merchant_id=? FOR UPDATE',[$merchant]))
                $this->rows('INSERT INTO merchant_restaurant_setup(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id) VALUES(?,?,?,?)',[$merchant,$setup['initial_outlet_id'],$setup['initial_legacy_outlet_id'],$actor]);
            $version=(int)$this->rows('SELECT version FROM merchant_restaurant_setup WHERE merchant_id=? FOR UPDATE',[$merchant])[0]['version'];
            $code=$this->rows('SELECT outlet_code FROM outlets WHERE outlet_id=?',[$legacy])[0]['outlet_code'];
            $result=$work($legacy,$code,$actor,$version,$setup);$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function load(string $merchant,string $outlet):array
    {
        $model=$this->transaction($merchant,$outlet,function($legacy,$code,$actor,$version,$setup)use($merchant,$outlet){
            $menu=new MenuAdminRepository($this->db,$actor);
            $categories=$menu->categories($code);$products=$menu->products($code);
            $stations=$this->rows('SELECT station_id,code,name,department_code,active,department_version FROM preparation_stations WHERE outlet_id=? ORDER BY station_id',[$legacy]);
            $staff=$this->rows('SELECT s.user_id,s.status,a.username,a.display_name,m.role_code FROM merchant_setup_staff s
                JOIN staff_accounts a ON a.account_id=s.account_id AND a.outlet_id=s.legacy_outlet_id
                JOIN merchant_outlet_memberships m ON m.user_id=s.user_id AND m.merchant_id=s.merchant_id AND m.outlet_id=s.merchant_outlet_id
                WHERE s.merchant_id=? AND s.merchant_outlet_id=? AND s.legacy_outlet_id=? ORDER BY a.username',[$merchant,$outlet,$legacy]);
            foreach($staff as &$row){$row['stations']=array_map('intval',array_column($this->rows('SELECT station_id FROM merchant_kitchen_grants WHERE user_id=? AND merchant_id=? AND merchant_outlet_id=? AND active=1',[$row['user_id'],$merchant,$outlet]),'station_id'));}unset($row);
            return ['merchant'=>$merchant,'outlet'=>$outlet,'version'=>$version,'categories'=>$categories,'products'=>$products,'stations'=>$stations,'staff'=>$staff,
                'owner'=>['user_id'=>$actor,'role'=>'merchant_owner','protected'=>true],
                'progress'=>['business'=>(bool)$setup['business_saved'],'outlet'=>(bool)$setup['outlet_saved'],'menu'=>false,'kitchen'=>false,'staff'=>false],
                'profile_progress'=>$setup['progress'],'legal_verification'=>$setup['legal_verification']];
        });
        $preview=[];$tenant=new TenantRestaurant($this->db,$this->authentication);
        foreach($model['categories'] as $category)foreach($tenant->catalogue($merchant,$outlet,$category['slug']) as $product)$preview[]=$product;
        $model['preview']=$preview;
        $model['progress']['menu']=count($preview)>0;
        $model['progress']['kitchen']=count(array_filter($model['stations'],fn($s)=>(int)$s['active']===1))>0&&count($preview)>0;
        $model['progress']['staff']=count(array_filter($model['staff'],fn($s)=>$s['status']==='active'))>0;
        return $model;
    }
    private function text(mixed $v,int $max,bool $required=true):string{
        if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen(trim($v))>$max||preg_match('/[\x00-\x1f\x7f]/u',$v)||($required&&trim($v)===''))throw new InvalidArgumentException('Use readable text within the field limit.');return trim($v);
    }
    private function number(mixed $v,int $min=0,int $max=9999):int{
        if(!is_int($v)&&(!is_string($v)||preg_match('/^(0|[1-9][0-9]{0,14})$/D',$v)!==1))throw new InvalidArgumentException('Use a whole number.');
        if((int)$v<$min||(int)$v>$max)throw new InvalidArgumentException('Number outside supported range.');return (int)$v;
    }
    private function fields(array $d,array $keys):void{if(array_diff(array_keys($d),$keys)||array_diff($keys,array_keys($d)))throw new InvalidArgumentException('Only the displayed setup fields are accepted.');}
    private function station(int $legacy,int $id,bool $active=true):array{
        $r=$this->rows('SELECT * FROM preparation_stations WHERE outlet_id=? AND station_id=?'.($active?' AND active=1':'').' FOR UPDATE',[$legacy,$id]);if(count($r)!==1)throw new DomainException('Station outside this outlet or unavailable.');return $r[0];
    }
    private function product(int $legacy,int $id):array{$r=$this->rows('SELECT * FROM products WHERE outlet_id=? AND product_id=? FOR UPDATE',[$legacy,$id]);if(!$r)throw new DomainException('Product outside this outlet.');return $r[0];}
    public function act(string $merchant,string $outlet,int $version,string $request,string $op,array $data):array
    {
        if($version<1||preg_match('/^[a-f0-9]{32}$/D',$request)!==1)throw new InvalidArgumentException('Reload the current submission.');
        $allowed=['category','station','product','availability','archive','staff-create','staff-activate','staff-access'];
        if(!in_array($op,$allowed,true))throw new DomainException('Setup action denied.');
        // Exclude activation secrets from request/event persistence; a retry is verified against the saved hash.
        $hashData=$data;if($op==='staff-activate')unset($hashData['password'],$hashData['confirmation']);
        $hash=hash('sha256',json_encode([$merchant,$outlet,$version,$op,$hashData],JSON_THROW_ON_ERROR));
        return $this->transaction($merchant,$outlet,function($legacy,$code,$actor,$current)use($merchant,$outlet,$version,$request,$op,$data,$hash){
            $ent=new Entitlements($this->db,$this->authentication);$ent->requireFeature($merchant,$outlet,str_starts_with($op,'staff-')?'staff':($op==='station'?'kitchen':'menu'));
            $prior=$this->rows('SELECT * FROM merchant_restaurant_events WHERE request_id=? FOR UPDATE',[$request]);
            if($prior){
                if($prior[0]['merchant_id']!==$merchant||$prior[0]['actor_user_id']!==$actor||!hash_equals($prior[0]['request_hash'],$hash))throw new DomainException('Submission already used.');
                if($op==='staff-activate'){
                    $r=$this->rows('SELECT a.password_hash FROM merchant_setup_staff s JOIN staff_accounts a ON a.account_id=s.account_id WHERE s.merchant_id=? AND s.user_id=? AND s.status=?',[$merchant,$data['user']??'','active']);
                    if(!$r||!is_string($data['password']??null)||($data['password']!==($data['confirmation']??null))||!StaffPasswords::verifies($data['password'],$r[0]['password_hash']))throw new DomainException('Activation retry denied.');
                }
                return ['duplicate'=>true,'result'=>json_decode($prior[0]['result_json'],true,32,JSON_THROW_ON_ERROR)];
            }
            if($op==='staff-create')$ent->limit($merchant,$outlet,'staff');if($op==='product'&&(int)($data['id']??-1)===0)$ent->limit($merchant,$outlet,'products');
            if($version!==$current)throw new DomainException('Setup changed in another tab. Reload before saving.');
            $menu=new MenuAdminRepository($this->db,$actor);$result=[];
            if($op==='category'){
                $this->fields($data,['id','name','sort']);$id=$this->number($data['id'],0,PHP_INT_MAX);$slug=null;$expected=0;
                if($id){$r=$this->rows('SELECT slug,version FROM categories WHERE category_id=? AND outlet_id=? FOR UPDATE',[$id,$legacy]);if(!$r)throw new DomainException('Category outside this outlet.');$slug=$r[0]['slug'];$expected=(int)$r[0]['version'];}
                $name=$this->text($data['name'],120);$menu->saveCategory($code,$slug,$expected,$name,true,$this->number($data['sort']),null);
                if(!$id)$id=(int)$this->rows('SELECT category_id FROM categories WHERE outlet_id=? AND name=?',[$legacy,$name])[0]['category_id'];$result=['categoryId'=>$id];
            }elseif($op==='station'){
                $this->fields($data,['id','code','name','department','active']);$id=$this->number($data['id'],0,PHP_INT_MAX);$name=$this->text($data['name'],120);$stationCode=$this->text($data['code'],40);$department=$this->text($data['department'],32);$active=$this->number($data['active'],0,1);
                if(PreparationStation::tryFrom($stationCode)===null||OperationalDepartment::tryFrom($department)===null)throw new InvalidArgumentException('Select a supported station and floor.');
                if($id){$priorStation=$this->station($legacy,$id,false);if($priorStation['code']!==$stationCode)throw new DomainException('Station code is immutable.');
                    if(!$active && $this->rows("SELECT preparation_task_id FROM preparation_tasks WHERE outlet_id=? AND station_id=? AND status IN ('queued','preparing') FOR UPDATE",[$legacy,$id]))throw new DomainException('Complete outstanding preparation before deactivating this station.');
                    $this->rows('UPDATE preparation_stations SET name=?,department_code=?,active=?,department_version=department_version+1 WHERE outlet_id=? AND station_id=?',[$name,$department,$active,$legacy,$id]);}
                else{$this->rows('INSERT INTO preparation_stations(outlet_id,code,name,department_code,active) VALUES(?,?,?,?,?)',[$legacy,$stationCode,$name,$department,$active]);$id=(int)$this->db->lastInsertId();}$result=['stationId'=>$id];
            }elseif($op==='product'){
                $this->fields($data,['id','name','description','sku','category','station','available','variants']);$id=$this->number($data['id'],0,PHP_INT_MAX);$priorProduct=$id?$this->product($legacy,$id):null;
                if($priorProduct&&(int)$priorProduct['active']!==1)throw new DomainException('Archived products cannot be silently restored.');
                $category=$this->rows('SELECT slug FROM categories WHERE outlet_id=? AND category_id=? AND active=1 FOR UPDATE',[$legacy,$this->number($data['category'],1,PHP_INT_MAX)]);if(!$category)throw new DomainException('Category outside this outlet.');
                $station=$this->station($legacy,$this->number($data['station'],1,PHP_INT_MAX));$sku=$this->text($data['sku'],80);
                if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D',$sku)!==1)throw new InvalidArgumentException('Use a simple internal SKU.');
                if($priorProduct&&$priorProduct['sku']!==$sku)throw new DomainException('Existing SKU is immutable.');$name=$this->text($data['name'],180);$description=$this->text($data['description'],1000,false);$available=$this->number($data['available'],0,1);
                $duplicate=$this->rows('SELECT product_id FROM products WHERE outlet_id=? AND sku=? FOR UPDATE',[$legacy,$sku]);
                if($duplicate&&(int)$duplicate[0]['product_id']!==$id)throw new DomainException('SKU already exists in this outlet.');
                $variants=$data['variants'];if(!is_array($variants)||!array_is_list($variants)||!$variants||count($variants)>12)throw new InvalidArgumentException('Add one to twelve serving options.');
                $converted=[];$used=[];foreach($variants as $index=>$v){if(!is_array($v))throw new InvalidArgumentException('Invalid serving option.');$this->fields($v,['id','label','unit','quantity','price']);
                    $variantId=$this->number($v['id'],0,PHP_INT_MAX);if($variantId&&isset($used[$variantId]))throw new DomainException('Duplicate serving ID.');$used[$variantId]=true;
                    if(!is_string($v['price'])||QuantityUnit::tryFrom((string)$v['unit'])===null)throw new InvalidArgumentException('Invalid serving or price.');
                    $converted[]=['id'=>$variantId,'label'=>$this->text($v['label'],100),'unit'=>$v['unit'],'quantity_value'=>$this->number($v['quantity'],1,100000),'price'=>Money::fromRupees($v['price']),'active'=>true,'display_order'=>$index];}
                $id=$menu->saveProduct($code,$id?:null,$priorProduct?(int)$priorProduct['version']:0,['name'=>$name,'category'=>$category[0]['slug'],'station'=>$station['code'],'routing_status'=>'approved','display_order'=>0,'vegetarian'=>false,'available'=>(bool)$available],$converted);
                // The existing repository generates internal SKU on creation. Apply the validated merchant SKU and description atomically.
                $this->rows('UPDATE products SET sku=?,description=? WHERE product_id=? AND outlet_id=?',[$sku,$description,$id,$legacy]);
                $p=$this->product($legacy,$id);if((int)$p['available']!==$available)$menu->setAvailability($code,$id,(int)$p['version'],(bool)$available);
                $result=['productId'=>$id];
            }elseif(in_array($op,['availability','archive'],true)){
                $this->fields($data,$op==='archive'?['id']:['id','available']);$id=$this->number($data['id'],1,PHP_INT_MAX);$p=$this->product($legacy,$id);
                if($op==='archive')$menu->archive($code,$id,(int)$p['version']);else{if(!(bool)$p['active'])throw new DomainException('Product archived.');$menu->setAvailability($code,$id,(int)$p['version'],(bool)$this->number($data['available'],0,1));}$result=['productId'=>$id];
            }else{$result=$this->staff($legacy,$merchant,$outlet,$op,$data);}
            $this->rows('UPDATE merchant_restaurant_setup SET version=version+1 WHERE merchant_id=?',[$merchant]);$result['version']=$version+1;
            $this->rows('INSERT INTO merchant_restaurant_events VALUES(?,?,?,?,?,?,?)',[$request,$merchant,$actor,$op,$hash,json_encode($result,JSON_THROW_ON_ERROR),time()]);
            return ['duplicate'=>false,'result'=>$result];
        });
    }
    private function staff(int $legacy,string $merchant,string $outlet,string $op,array $d):array
    {
        if($op==='staff-create'){
            $this->fields($d,['username','name','role']);$username=strtolower($this->text($d['username'],64));$name=$this->text($d['name'],100);
            if(preg_match('/^[a-z][a-z0-9._-]{2,63}$/D',$username)!==1)throw new InvalidArgumentException('Use a valid staff username.');
            $role=$this->role($d['role']);$user=Identity::generate();$account=bin2hex(random_bytes(16));
            $this->rows('INSERT INTO merchant_staff VALUES(?,?,0)',[$user,$name]);
            $this->rows("INSERT INTO staff_accounts(account_id,outlet_id,realm,username,display_name,role,password_hash,enabled,must_change,auth_version,created_at,updated_at) VALUES(?,?,'staff',?,?,'cashier',?,0,1,1,?,?)",[$account,$legacy,$username,$name,StaffPasswords::hash(StaffPasswords::generate()),time(),time()]);
            $this->rows('INSERT INTO merchant_auth_links VALUES(?,?,?,0)',[$account,$user,$legacy]);
            $this->rows('INSERT INTO merchant_outlet_memberships VALUES(?,?,?,?,0)',[$user,$merchant,$outlet,$role]);
            $this->rows("INSERT INTO merchant_setup_staff VALUES(?,?,?,?,?,'pending')",[$user,$merchant,$outlet,$legacy,$account]);return ['userId'=>$user,'role'=>$role,'status'=>'pending'];
        }
        $this->fields($d,$op==='staff-activate'?['user','password','confirmation']:['user','role','enabled','stations']);$user=$this->text($d['user'],36);Identity::validate($user);
        $r=$this->rows('SELECT s.*,a.enabled,a.auth_version FROM merchant_setup_staff s JOIN staff_accounts a ON a.account_id=s.account_id AND a.outlet_id=s.legacy_outlet_id WHERE s.user_id=? AND s.merchant_id=? AND s.merchant_outlet_id=? AND s.legacy_outlet_id=? FOR UPDATE',[$user,$merchant,$outlet,$legacy]);
        if(!$r)throw new DomainException('Staff outside this setup or protected owner.');$row=$r[0];
        if($this->rows('SELECT user_id FROM merchant_memberships WHERE user_id=?',[$user])||$this->rows('SELECT user_id FROM merchant_platform_memberships WHERE user_id=?',[$user]))throw new DomainException('Unexpected staff authority.');
        $membership=$this->rows('SELECT role_code,active FROM merchant_outlet_memberships WHERE user_id=? AND merchant_id=? AND outlet_id=? FOR UPDATE',[$user,$merchant,$outlet]);
        if(count($membership)!==1)throw new DomainException('Staff membership missing.');
        if($op==='staff-activate'){
            if($row['status']!=='pending'||!is_string($d['password'])||$d['password']!==$d['confirmation'])throw new DomainException('Pending activation and matching private password required.');
            StaffPasswords::validate($d['password']);$this->rows('UPDATE staff_accounts SET password_hash=?,enabled=1,must_change=0,auth_version=auth_version+1,updated_at=? WHERE account_id=?',[StaffPasswords::hash($d['password']),time(),$row['account_id']]);
            $this->rows('UPDATE merchant_staff SET active=1 WHERE user_id=?',[$user]);$this->rows('UPDATE merchant_auth_links SET active=1 WHERE account_id=?',[$row['account_id']]);
            $this->rows('UPDATE merchant_outlet_memberships SET active=1 WHERE user_id=? AND merchant_id=? AND outlet_id=?',[$user,$merchant,$outlet]);
            $this->rows("UPDATE merchant_setup_staff SET status='active' WHERE user_id=?",[$user]);return ['userId'=>$user,'role'=>$membership[0]['role_code'],'status'=>'active'];
        }
        $role=$this->role($d['role']);$enabled=$this->number($d['enabled'],0,1);if($row['status']==='pending')throw new DomainException('Activate the pending account before changing access.');
        if(!is_array($d['stations'])||!array_is_list($d['stations'])||count($d['stations'])>10)throw new InvalidArgumentException('Choose valid station grants.');
        $stations=[];foreach($d['stations'] as $id){$id=$this->number($id,1,PHP_INT_MAX);$this->station($legacy,$id);if(isset($stations[$id]))throw new DomainException('Duplicate station grant.');$stations[$id]=true;}
        if($role!=='kitchen'&&$stations)throw new DomainException('Station grants are for kitchen operators.');
        $this->rows('SELECT user_id FROM merchant_staff WHERE user_id=? FOR UPDATE',[$user]);
        $before=['role'=>$membership[0]['role_code'],'enabled'=>(bool)$row['enabled'],'stations'=>array_map('intval',array_column($this->rows('SELECT station_id FROM merchant_kitchen_grants WHERE user_id=? AND merchant_id=? AND merchant_outlet_id=? AND active=1 FOR UPDATE',[$user,$merchant,$outlet]),'station_id'))];
        $this->rows('UPDATE merchant_outlet_memberships SET role_code=?,active=? WHERE user_id=? AND merchant_id=? AND outlet_id=?',[$role,$enabled,$user,$merchant,$outlet]);
        $this->rows('UPDATE merchant_staff SET active=? WHERE user_id=?',[$enabled,$user]);$this->rows('UPDATE merchant_auth_links SET active=? WHERE account_id=?',[$enabled,$row['account_id']]);
        $this->rows('UPDATE staff_accounts SET enabled=?,auth_version=auth_version+1,updated_at=? WHERE account_id=?',[$enabled,time(),$row['account_id']]);
        $this->rows('UPDATE merchant_setup_staff SET status=? WHERE user_id=?',[$enabled?'active':'disabled',$user]);
        $this->rows('DELETE FROM merchant_kitchen_grants WHERE user_id=? AND merchant_id=? AND merchant_outlet_id=?',[$user,$merchant,$outlet]);
        foreach(array_keys($stations) as $station)$this->rows('INSERT INTO merchant_kitchen_grants VALUES(?,?,?,?,?,?)',[$user,$merchant,$outlet,$legacy,$station,$enabled]);
        return ['userId'=>$user,'access'=>['before'=>$before,'after'=>['role'=>$role,'enabled'=>(bool)$enabled,'stations'=>$enabled?array_keys($stations):[]]]];
    }
    private function role(mixed $role):string{
        if(!is_string($role)||!in_array($role,[Role::OutletAdministrator->value,Role::Cashier->value,Role::Kitchen->value],true))throw new DomainException('Choose outlet manager, cashier or kitchen operator. Original owner is protected.');return $role;
    }
}
