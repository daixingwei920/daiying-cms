<?php
declare(strict_types=1);
namespace Official\Restaurant;

use PDO;

final class RestaurantPosRepository
{
    public function __construct(private readonly PDO $pdo){$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);}
    private static function now():string{return gmdate('Y-m-d H:i:s');}
    private static function json(array $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function hydrate(array $row):array{
        foreach(['locations','catalog','mapping','settings','snapshot'] as $key){if(array_key_exists($key.'_json',$row)){$row[$key]=json_decode((string)($row[$key.'_json']??'[]'),true)?:[];unset($row[$key.'_json']);}}
        return $row;
    }
    public function connections():array{return array_map(self::hydrate(...),$this->pdo->query('SELECT * FROM restaurant_pos_connections ORDER BY id')->fetchAll());}
    public function connection(int $id):?array{$s=$this->pdo->prepare('SELECT * FROM restaurant_pos_connections WHERE id=:id');$s->execute([':id'=>$id]);$r=$s->fetch();return is_array($r)?self::hydrate($r):null;}
    public function byBrand(string $brand,string $environment):?array{$s=$this->pdo->prepare('SELECT id FROM restaurant_pos_connections WHERE brand=:brand AND environment=:env');$s->execute([':brand'=>$brand,':env'=>$environment]);$id=$s->fetchColumn();return $id===false?null:$this->connection((int)$id);}
    public function create(string $brand,string $environment,string $siteHash):array{
        $existing=$this->byBrand($brand,$environment);if($existing){return $existing;}
        $s=$this->pdo->prepare('INSERT INTO restaurant_pos_connections (brand,environment,site_hash,created_at,updated_at) VALUES (:brand,:env,:site,:now,:updated)');
        try{$s->execute([':brand'=>$brand,':env'=>$environment,':site'=>$siteHash,':now'=>self::now(),':updated'=>self::now()]);}catch(\PDOException $e){if(!$this->byBrand($brand,$environment)){throw $e;}}
        return $this->byBrand($brand,$environment)??throw new \RuntimeException('无法保存收银连接。');
    }
    public function update(int $id,array $values):void{
        $allowed=['site_hash','revision','status','account_id','location_id','location_name','currency','locations','catalog','mapping','settings','auto_send','start_after_order_id','scan_cursor','worker_seen_at','probe_job_id','probe_confirmed','last_error'];
        $assign=[];$params=[':id'=>$id,':updated'=>self::now()];
        foreach($values as $key=>$value){if(!in_array($key,$allowed,true)){throw new \InvalidArgumentException('Unsupported POS connection field.');}$column=in_array($key,['locations','catalog','mapping','settings'],true)?$key.'_json':$key;$assign[]=$column.'=:'.$key;$params[':'.$key]=is_array($value)?self::json($value):$value;}
        if(!$assign){return;}$this->pdo->prepare('UPDATE restaurant_pos_connections SET '.implode(',',$assign).',updated_at=:updated WHERE id=:id')->execute($params);
    }
    public function acquire(int $id,int $seconds=600):string{
        $nonce=bin2hex(random_bytes(24));$s=$this->pdo->prepare("UPDATE restaurant_pos_connections SET lease_token=:token,lease_until=:until WHERE id=:id AND lease_until<:now");
        $s->execute([':token'=>$nonce,':until'=>time()+$seconds,':id'=>$id,':now'=>time()]);
        if($s->rowCount()!==1){throw new \RuntimeException('这台收银系统正在处理连接或传单，请稍后重试。');}return $nonce;
    }
    public function leaseValid(int $id,string $nonce):bool{$s=$this->pdo->prepare('SELECT id FROM restaurant_pos_connections WHERE id=:id AND lease_token=:nonce AND lease_until>:now');$s->execute([':id'=>$id,':nonce'=>$nonce,':now'=>time()]);return $s->fetchColumn()!==false;}
    public function release(int $id,string $nonce):void{$this->pdo->prepare("UPDATE restaurant_pos_connections SET lease_token='',lease_until=0 WHERE id=:id AND lease_token=:nonce")->execute([':id'=>$id,':nonce'=>$nonce]);}
    public function addState(string $state,string $brand,string $environment,int $actor,string $sessionHash,string $appHash):void{
        $this->pdo->prepare('INSERT INTO restaurant_pos_oauth_states (state_hash,brand,environment,actor_id,session_hash,app_hash,expires_at,created_at) VALUES (:hash,:brand,:env,:actor,:session,:app,:expiry,:now)')->execute([':hash'=>hash('sha256',$state),':brand'=>$brand,':env'=>$environment,':actor'=>$actor,':session'=>$sessionHash,':app'=>$appHash,':expiry'=>time()+600,':now'=>self::now()]);
    }
    public function consumeState(string $state,string $brand,string $environment,int $actor,string $sessionHash,string $appHash):void{
        $s=$this->pdo->prepare('UPDATE restaurant_pos_oauth_states SET used_at=:now WHERE state_hash=:hash AND brand=:brand AND environment=:env AND actor_id=:actor AND session_hash=:session AND app_hash=:app AND expires_at>:expiry AND used_at IS NULL');
        $s->execute([':now'=>time(),':hash'=>hash('sha256',$state),':brand'=>$brand,':env'=>$environment,':actor'=>$actor,':session'=>$sessionHash,':app'=>$appHash,':expiry'=>time()]);
        if($s->rowCount()!==1){throw new \RuntimeException('连接请求已过期或不属于当前登录，请重新点击连接。');}
    }
    public function enqueue(int $connection,int $revision,?int $order,array $snapshot,bool $test,string $dedup):array{
        $existing=$this->byDedup($dedup);if($existing){return $existing;}
        $reference='DY'.substr(hash('sha256',$dedup),0,30);
        try{$this->pdo->prepare('INSERT INTO restaurant_pos_jobs (connection_id,revision,commerce_order_id,dedup_key,reference,snapshot_json,is_test,created_at,updated_at) VALUES (:conn,:rev,:order,:dedup,:reference,:snapshot,:test,:now,:updated)')->execute([':conn'=>$connection,':rev'=>$revision,':order'=>$order,':dedup'=>$dedup,':reference'=>$reference,':snapshot'=>self::json($snapshot),':test'=>$test?1:0,':now'=>self::now(),':updated'=>self::now()]);}catch(\PDOException $e){if(!$this->byDedup($dedup)){throw $e;}}
        return $this->byDedup($dedup)??throw new \RuntimeException('无法保存传单记录。');
    }
    private function byDedup(string $key):?array{$s=$this->pdo->prepare('SELECT id FROM restaurant_pos_jobs WHERE dedup_key=:key');$s->execute([':key'=>$key]);$id=$s->fetchColumn();return $id===false?null:$this->job((int)$id);}
    public function job(int $id):?array{$s=$this->pdo->prepare('SELECT * FROM restaurant_pos_jobs WHERE id=:id');$s->execute([':id'=>$id]);$r=$s->fetch();return is_array($r)?self::hydrate($r):null;}
    public function jobs(int $connection,int $limit=50):array{$s=$this->pdo->prepare('SELECT * FROM restaurant_pos_jobs WHERE connection_id=:conn ORDER BY id DESC LIMIT '.max(1,min(100,$limit)));$s->execute([':conn'=>$connection]);return array_map(self::hydrate(...),$s->fetchAll());}
    public function queued(int $limit=20):array{return array_map(self::hydrate(...),$this->pdo->query("SELECT * FROM restaurant_pos_jobs WHERE status='queued' ORDER BY id LIMIT ".max(1,min(50,$limit)))->fetchAll());}
    public function claim(int $id):string{
        $nonce=bin2hex(random_bytes(24));$s=$this->pdo->prepare("UPDATE restaurant_pos_jobs SET status='sending',claim_token=:nonce,attempts=attempts+1,updated_at=:now WHERE id=:id AND status='queued'");$s->execute([':nonce'=>$nonce,':now'=>self::now(),':id'=>$id]);
        if($s->rowCount()!==1){throw new \RuntimeException('这张订单已在处理，不会重复传单。');}return $nonce;
    }
    public function checkpoint(int $id,string $nonce,array $values):void{
        $assign=[];$params=[':id'=>$id,':nonce'=>$nonce,':now'=>self::now()];
        foreach($values as $key=>$value){if(!in_array($key,['status','stage','remote_order_id','remote_payment_id','last_error'],true)){throw new \InvalidArgumentException('Unsupported POS job field.');}$assign[]=$key.'=:'.$key;$params[':'.$key]=$value;}
        $s=$this->pdo->prepare('UPDATE restaurant_pos_jobs SET '.implode(',',$assign).',updated_at=:now WHERE id=:id AND claim_token=:nonce');$s->execute($params);
        if($s->rowCount()!==1){throw new \RuntimeException('传单记录已被其他操作更新，请核对收银机。');}
    }
    public function hold(int $id,string $reason):void{$this->pdo->prepare("UPDATE restaurant_pos_jobs SET status='needs_review',last_error=:reason,updated_at=:now WHERE id=:id AND status NOT IN ('delivered')")->execute([':reason'=>$reason,':now'=>self::now(),':id'=>$id]);}
    public function recoverInterrupted():int{$s=$this->pdo->prepare("UPDATE restaurant_pos_jobs SET status='needs_review',last_error='interrupted',updated_at=:now WHERE status='sending' AND updated_at<:cutoff");$s->execute([':now'=>self::now(),':cutoff'=>gmdate('Y-m-d H:i:s',time()-900)]);return $s->rowCount();}
    public function disableOtherConnections(int $id):void{$this->pdo->prepare('UPDATE restaurant_pos_connections SET auto_send=0 WHERE id<>:id')->execute([':id'=>$id]);}
    public function activate(int $id,int $cutoff):void
    {
        $this->pdo->beginTransaction();
        try{$this->pdo->exec('UPDATE restaurant_pos_connections SET auto_send=0');$this->update($id,['auto_send'=>1,'start_after_order_id'=>$cutoff,'scan_cursor'=>$cutoff,'last_error'=>'']);$this->pdo->commit();}
        catch(\Throwable $e){$this->pdo->rollBack();throw $e;}
    }
    public function isPrimaryPrinterForOrder(int $order,string $siteHash):bool
    {
        // POS is sandbox-only in this release. Never replace website kitchen printing.
        return false;
    }
    public function audit(?int $connection,?int $job,int $actor,string $action,string $detail=''):void{
        $this->pdo->prepare('INSERT INTO restaurant_pos_audit (connection_id,job_id,actor_id,action,detail,created_at) VALUES (:conn,:job,:actor,:action,:detail,:now)')->execute([':conn'=>$connection,':job'=>$job,':actor'=>$actor,':action'=>$action,':detail'=>mb_substr($detail,0,500),':now'=>self::now()]);
    }
    public function maxRestaurantOrderId():int{return (int)$this->pdo->query('SELECT COALESCE(MAX(commerce_order_id),0) FROM restaurant_carts')->fetchColumn();}
    public function candidateOrders(int $after,int $limit=50):array{$s=$this->pdo->prepare('SELECT commerce_order_id FROM restaurant_carts WHERE commerce_order_id>:after ORDER BY commerce_order_id LIMIT '.max(1,min(100,$limit)));$s->execute([':after'=>$after]);return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));}
}
