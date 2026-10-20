<?php

declare(strict_types=1);
namespace Cms\Core\Ai;

use Cms\Core\Config\Settings;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Media\PrivateMediaStorageProvider;
use Cms\Core\Payment\PaymentTransaction;
use Cms\Core\Queue\QueueJob;
use Cms\Core\Queue\QueueService;
use Cms\Core\Queue\QueueHandlerRegistry;
use PDO;

/** Durable image lifecycle owned by Core. Never serializes runtime credentials. */
final class AiImageService
{
    public function __construct(private readonly PDO $pdo, private readonly Settings $settings,
        private readonly string $root, private readonly ?AiImageProviderInterface $provider = null) {}

    public function models(): array { return array_map(static fn(AiModel $m): array => $m->toArray(), $this->adapter()->models()); }

    public function submit(string $plugin, int $user, string $key, array $input, string $pluginVersion = ''): array
    {
        if ($user <= 0 || $key === '' || strlen($key) > 191) throw new AiException('用户或幂等键无效。', 'request_invalid');
        $request = $this->normalize($input);
        $request['plugin_version'] = $pluginVersion;
        if ($request['operation'] === 'image.edit') {
            $original = $this->find($request['source_job'], $plugin, $user);
            if ($original['status'] !== 'completed') throw new AiException('原图任务尚未完成。', 'image_input_missing');
        }
        $hash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $keyHash = hash('sha256', $key);
        $config = $this->config();
        $this->adapter()->assertSupported($config);
        PaymentTransaction::beginImmediate($this->pdo);
        try {
            // Single global reservation lock also serializes MySQL quota checks.
            $this->pdo->exec('UPDATE cms_ai_image_locks SET marker=marker WHERE lock_id=1');
            $q = $this->pdo->prepare('SELECT * FROM cms_ai_image_jobs WHERE plugin_id=? AND user_id=? AND key_hash=?');
            $q->execute([$plugin,$user,$keyHash]); $existing=$q->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if (!hash_equals($existing['request_hash'], $hash)) throw new AiException('幂等键已用于另一设计。', 'idempotency_conflict');
                PaymentTransaction::commit($this->pdo); return $this->publicJob($existing);
            }
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM cms_ai_image_jobs WHERE user_id=? AND created_at>=?');
            $q->execute([$user,gmdate('Y-m-d\T00:00:00+00:00')]);
            if ((int)$q->fetchColumn() >= (int)$config['image_daily_limit']) throw new AiException('今日图像任务配额已用完。', 'quota_exceeded');
            $q->execute([$user,gmdate('c',time()-60)]);
            if ((int)$q->fetchColumn() >= (int)$config['image_minute_limit']) throw new AiException('图像任务提交过快。', 'rate_limited');
            $id=AiRequest::newRequestId(); $now=gmdate('c');
            $this->pdo->prepare('INSERT INTO cms_ai_image_jobs (job_id,plugin_id,user_id,key_hash,request_hash,request_json,provider,model,config_hash,timeout_seconds,status,estimated_cost_micro,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id,$plugin,$user,$keyHash,$hash,json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$config['provider'],$config['image_model'],$this->fingerprint($config),(int)$config['timeout_seconds'],'queued',$config['image_cost_micro'],$now,$now]);
            (new QueueService($this->pdo))->enqueue(new QueueJob('ai.image',['job_id'=>$id], 'core',1));
            $this->audit($id,$plugin,$user,'queued');
            PaymentTransaction::commit($this->pdo);
            return $this->find($id,$plugin,$user);
        } catch (\Throwable $e) { if(PaymentTransaction::isActive($this->pdo)) PaymentTransaction::rollback($this->pdo); throw $e; }
    }

    /** Import a bounded private edit reference. No provider call or billing. */
    public function importReference(string $plugin,int $user,string $bytes,string $version=''): array
    {
        $size=@getimagesizefromstring($bytes);
        if($user<1||strlen($bytes)>8388608||!$size||!in_array($size['mime']??'',['image/png','image/jpeg','image/webp'],true)||$size[0]*$size[1]>4000000)throw new AiException('旧Logo需为8MB以内、400万像素以内的PNG/JPEG/WebP。','image_input_invalid');
        $im=@imagecreatefromstring($bytes);if(!$im)throw new AiException('旧Logo无法解码。','image_input_invalid');
        imagealphablending($im,false);imagesavealpha($im,true);ob_start();imagepng($im);$png=(string)ob_get_clean();unset($im);
        $id=AiRequest::newRequestId();$now=gmdate('c');$request=['operation'=>'image.reference','plugin_version'=>$version];
        PaymentTransaction::beginImmediate($this->pdo);
        try{
            $this->pdo->exec('UPDATE cms_ai_image_locks SET marker=marker WHERE lock_id=1');
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM cms_ai_image_jobs WHERE user_id=? AND provider='upload' AND created_at>=?");
            $q->execute([$user,gmdate('Y-m-d\\T00:00:00+00:00')]);if((int)$q->fetchColumn()>=20)throw new AiException('今日参考图配额已用完。','quota_exceeded');
            $q->execute([$user,gmdate('c',time()-60)]);if((int)$q->fetchColumn()>=2)throw new AiException('参考图上传过快。','rate_limited');
            $this->pdo->prepare('INSERT INTO cms_ai_image_jobs(job_id,plugin_id,user_id,key_hash,request_hash,request_json,provider,model,config_hash,timeout_seconds,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$plugin,$user,hash('sha256',$id),hash('sha256',$png),json_encode($request),'upload','reference','',0,'importing',$now,$now]);
            PaymentTransaction::commit($this->pdo);
        }catch(\Throwable $e){if(PaymentTransaction::isActive($this->pdo))PaymentTransaction::rollback($this->pdo);throw $e;}
        $tmp=$this->spool().'/'.$id.'.upload.png';
        try{
            if(file_put_contents($tmp,$png)===false)throw new AiException('参考图存储失败。','storage_failed');chmod($tmp,0600);
            $media=$this->library()->registerLocalFile($tmp,'reference.png');
            $this->pdo->prepare("UPDATE cms_ai_image_jobs SET status='completed',media_id=?,updated_at=? WHERE job_id=?")->execute([$media,gmdate('c'),$id]);
        }catch(\Throwable $e){$this->pdo->prepare("UPDATE cms_ai_image_jobs SET status='failed',updated_at=? WHERE job_id=?")->execute([gmdate('c'),$id]);throw $e;}
        finally{if(is_file($tmp))unlink($tmp);}
        $this->audit($id,$plugin,$user,'reference_imported');return $this->find($id,$plugin,$user);
    }

    public function find(string $id, string $plugin, int $user): array
    {
        $q=$this->pdo->prepare('SELECT * FROM cms_ai_image_jobs WHERE job_id=? AND plugin_id=? AND user_id=?');
        $q->execute([$id,$plugin,$user]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new AiException('作品不存在。','not_found');
        return $this->publicJob($row);
    }

    public function history(string $plugin,int $user): array
    {
        $q=$this->pdo->prepare('SELECT * FROM cms_ai_image_jobs WHERE plugin_id=? AND user_id=? ORDER BY created_at DESC,job_id DESC LIMIT 100');
        $q->execute([$plugin,$user]); return array_map($this->publicJob(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Scoped server-side access; plugins must enforce download entitlement. */
    public function imageBytes(string $id,string $plugin,int $user): string
    {
        $row=$this->find($id,$plugin,$user);
        if($row['status']!=='completed') throw new AiException('原图尚不可用。','image_not_ready');
        $file=$this->library()->fileForResponse((int)$row['media_id']);
        $bytes=file_get_contents($file['path']);
        if(!is_string($bytes)) throw new AiException('原图文件不可用。','image_storage_failed');
        return $bytes;
    }

    public function registerWorker(): void { QueueHandlerRegistry::register('ai.image', fn(array $p) => $this->work((string)($p['job_id']??''))); }

    public function work(string $id): void
    {
        $row=$this->row($id);
        if($row===null) return;
        if(in_array($row['status'],['completed','failed','uncertain'],true)) { $this->settleQueue($id); return; }
        $dir=$this->spool(); $lock=fopen($dir.'/'.$id.'.lock','c');
        if($lock===false || !flock($lock,LOCK_EX|LOCK_NB)) { if(is_resource($lock)) fclose($lock); return; }
        try {
            $row=$this->row($id); if($row===null) return;
            if(!$this->ownerActive($row)) { $this->fail($row,'image_owner_unavailable',false); return; }
            $receipt=$dir.'/'.$id.'.json';
            if(is_file($receipt)) { $this->complete($row,$receipt); return; }
            if($row['status']!=='queued') return; // Unknown dispatch is never retried.
            $q=$this->pdo->prepare("UPDATE cms_ai_image_jobs SET status='dispatching',updated_at=? WHERE job_id=? AND status='queued'");
            $q->execute([gmdate('c'),$id]); if($q->rowCount()!==1) return;
            try {
                $config=$this->config();
                if(!hash_equals($row['config_hash'],$this->fingerprint($config))) throw new AiException('站点 AI 配置已改变，请新建任务。','image_config_changed');
                $input=json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR);
                $png=$input['operation']==='image.edit' ? $this->imageBytes($input['source_job'],$row['plugin_id'],(int)$row['user_id']) : null;
                $result=$this->adapter()->execute($input,$config,$png);
                // Durable receipt before media import enables recovery without another provider call.
                $json=json_encode(['png'=>base64_encode($result['png']),'usage'=>$result['usage']],JSON_THROW_ON_ERROR);
                $part=$receipt.'.part';
                if(file_put_contents($part,$json,LOCK_EX)!==strlen($json)) throw new AiException('响应未能持久化。','image_result_unknown');
                chmod($part,0600);
                if(!rename($part,$receipt)) throw new AiException('响应未能持久化。','image_result_unknown');
                $this->pdo->prepare("UPDATE cms_ai_image_jobs SET status='response_ready',updated_at=? WHERE job_id=?")->execute([gmdate('c'),$id]);
            } catch (\Throwable $e) {
                $reason=$e instanceof AiException ? $e->reason() : 'image_result_unknown';
                $this->fail($row,$reason,!in_array($reason,['image_config_changed','image_provider_rejected','disabled','api_key_missing','image_model_unsupported'],true));
                return;
            }
            $this->complete($row,$receipt);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    /** Recover only an already-owned job; never dispatch a fresh provider request. */
    public function recoverOne(string $id,string $plugin,int $user): array
    {
        $this->find($id,$plugin,$user);
        $row=$this->row($id);
        if($row!==null&&in_array($row['status'],['queued','dispatching','response_ready'],true))$this->recoverRow($row);
        return $this->find($id,$plugin,$user);
    }

    /** Called by worker startup/Cron. Terminal ambiguity retains quota, never re-dispatches. */
    public function recover(): array
    {
        $rows=$this->pdo->query("SELECT * FROM cms_ai_image_jobs WHERE status IN ('queued','dispatching','response_ready') ORDER BY created_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $counts=['resumed'=>0,'uncertain'=>0];
        foreach($rows as $row){$result=$this->recoverRow($row);foreach($counts as$key=>$_)$counts[$key]+=$result[$key];}
        return $counts;
    }

    private function recoverRow(array $row): array
    {
        $counts=['resumed'=>0,'uncertain'=>0];
        if(is_file($this->spool().'/'.$row['job_id'].'.json')){$this->work($row['job_id']);$counts['resumed']++;return $counts;}
        if($row['status']==='dispatching'&&strtotime($row['updated_at'])+(int)$row['timeout_seconds']+30<time()){
            $lock=fopen($this->spool().'/'.$row['job_id'].'.lock','c');
            if(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB)){
                $current=$this->row($row['job_id']);
                if($current!==null&&$current['status']==='dispatching'&&strtotime($current['updated_at'])+(int)$current['timeout_seconds']+30<time()){
                    $this->fail($current,'image_result_unknown',true);$counts['uncertain']++;
                }
                flock($lock,LOCK_UN);
            }
            if(is_resource($lock))fclose($lock);
        }elseif($row['status']==='queued'){
            $this->pdo->prepare("UPDATE cms_core_queue_jobs SET status='pending',updated_at=? WHERE job_type='ai.image' AND status='running' AND payload_json=?")
                ->execute([gmdate('c'),json_encode(['job_id'=>$row['job_id']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        }
        return $counts;
    }

    private function complete(array $row,string $receipt): void
    {
        if(($this->row($row['job_id'])['status']??'')==='completed') return;
        $result=json_decode((string)file_get_contents($receipt),true,32,JSON_THROW_ON_ERROR);
        $png=base64_decode($result['png'],true);
        if(!is_string($png)) throw new AiException('持久化响应无效。','image_storage_failed');
        $temp=$receipt.'.png';
        if(file_put_contents($temp,$png)!==strlen($png)) throw new AiException('媒体文件写入失败。','image_storage_failed');
        chmod($temp,0600);
        try { $media=$this->library()->registerLocalFile($temp,'ai-image.png'); }
        finally { if(is_file($temp)) unlink($temp); }
        PaymentTransaction::beginImmediate($this->pdo);
        try {
            $q=$this->pdo->prepare("UPDATE cms_ai_image_jobs SET status='completed',media_id=?,usage_json=?,error_reason='',updated_at=? WHERE job_id=? AND status IN ('dispatching','response_ready')");
            $q->execute([$media,json_encode($result['usage'],JSON_THROW_ON_ERROR),gmdate('c'),$row['job_id']]);
            if($q->rowCount()===1) {
                $request=new AiRequest([['role'=>'user','content'=>'image']],operation:(string)(json_decode($row['request_json'],true)['operation']??'image.generate'),pluginId:$row['plugin_id'],requestId:$row['job_id']);
                $response=AiResponse::fromChatResult(['provider'=>$row['provider'],'model'=>$row['model'],'content'=>'','raw'=>['usage'=>$result['usage']]],$row['job_id']);
                (new AiUsageLedger($this->pdo))->recordSuccess($request,$response);
                // NULL on the image job means cost unknown; ledger's legacy numeric estimate stays compatible.
                if($row['estimated_cost_micro']!==null) $this->pdo->prepare('UPDATE cms_ai_usage_ledger SET estimated_cost=? WHERE request_id=?')->execute([(int)$row['estimated_cost_micro']/1000000,$row['job_id']]);
                $this->audit($row['job_id'],$row['plugin_id'],(int)$row['user_id'],'completed');
            }
            PaymentTransaction::commit($this->pdo);
        } catch(\Throwable $e) { if(PaymentTransaction::isActive($this->pdo)) PaymentTransaction::rollback($this->pdo); throw $e; }
        unlink($receipt);
        $this->settleQueue($row['job_id']);
    }

    private function fail(array $row,string $reason,bool $unknown): void
    {
        $this->pdo->prepare("UPDATE cms_ai_image_jobs SET status=?,error_reason=?,updated_at=? WHERE job_id=? AND status NOT IN ('completed','failed','uncertain')")
            ->execute([$unknown?'uncertain':'failed',$reason,gmdate('c'),$row['job_id']]);
        $this->audit($row['job_id'],$row['plugin_id'],(int)$row['user_id'],$unknown?'uncertain':'failed');
        $this->settleQueue($row['job_id']);
    }
    private function settleQueue(string $id): void {
        $this->pdo->prepare("UPDATE cms_core_queue_jobs SET status='done',completed_at=?,updated_at=? WHERE job_type='ai.image' AND status IN ('pending','running','retry') AND payload_json=?")
            ->execute([gmdate('c'),gmdate('c'),json_encode(['job_id'=>$id],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }
    private function ownerActive(array $row): bool {
        $q=$this->pdo->prepare("SELECT id FROM cms_front_users WHERE id=? AND status='active'"); $q->execute([$row['user_id']]); if(!$q->fetchColumn()) return false;
        if($row['plugin_id']==='core') return true;
        $q=$this->pdo->prepare("SELECT version FROM cms_plugins WHERE plugin_id=? AND status='Enabled'"); $q->execute([$row['plugin_id']]);
        return $q->fetchColumn()===(json_decode($row['request_json'],true)['plugin_version']??'');
    }
    private function row(string $id): ?array { if(!preg_match('/^ai_[a-f0-9]{24}$/D',$id)) return null; $q=$this->pdo->prepare('SELECT * FROM cms_ai_image_jobs WHERE job_id=?'); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC)?:null; }
    private function config(): array {
        $c=(new AiService($this->pdo,$this->settings))->runtimeConfigForGateway([]);
        if(empty($c['enabled'])) throw new AiException('全局 AI 未启用。','disabled');
        if(empty($c['api_key'])) throw new AiException('站点 API Key 未配置。','api_key_missing');
        return $c;
    }
    private function fingerprint(array $c): string { return hash('sha256',json_encode([$c['provider'],$c['base_url'],$c['image_model'],$c['timeout_seconds']],JSON_THROW_ON_ERROR)); }
    private function adapter(): AiImageProviderInterface { return $this->provider??new OpenAiImageProvider(); }
    private function library(): MediaLibrary { return new MediaLibrary($this->pdo,$this->root.'/storage/private-media',storageProvider:new PrivateMediaStorageProvider($this->root.'/storage/private-media')); }
    private function spool(): string {
        $p=$this->root.'/storage/ai-images'; if(!is_dir($p) && !mkdir($p,0700,true) && !is_dir($p)) throw new AiException('无法创建图像任务目录。','image_storage_failed'); return $p;
    }
    private function audit(string $id,string $plugin,int $user,string $result): void { (new AiAuditLogger($this->pdo))->record(['request_id'=>$id,'plugin_id'=>$plugin,'user_id'=>$user,'action'=>'ai.image','result'=>$result]); }
    private function publicJob(array $row): array {
        $public = array_intersect_key($row,array_flip(['job_id','status','media_id','model','provider','estimated_cost_micro','currency','usage_json','error_reason','created_at','updated_at']));
        $public['cost_status'] = $row['estimated_cost_micro'] === null ? 'unknown' : 'admin_estimate';
        if($row['status']==='dispatching')$public['recovery_after']=gmdate('c',strtotime($row['updated_at'])+(int)$row['timeout_seconds']+31);
        return $public;
    }
    private function normalize(array $r): array {
        if(array_diff(array_keys($r),['prompt','operation','source_job','size','quality','background'])) throw new AiException('图像请求包含不允许的参数。','request_invalid');
        $r=['prompt'=>(string)($r['prompt']??''),'operation'=>(string)($r['operation']??'image.generate'),'source_job'=>(string)($r['source_job']??''),
            'size'=>(string)($r['size']??'1024x1024'),'quality'=>(string)($r['quality']??'low'),'background'=>(string)($r['background']??'transparent')];
        if(trim($r['prompt'])==='' || strlen($r['prompt'])>12000 || !mb_check_encoding($r['prompt'],'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$r['prompt'])
            || !in_array($r['operation'],['image.generate','image.edit'],true) || !in_array($r['size'],['1024x1024','1536x1024','1024x1536'],true)
            || !in_array($r['quality'],['low','medium','high'],true) || !in_array($r['background'],['transparent','opaque','auto'],true)) throw new AiException('图像参数无效。','request_invalid');
        return $r;
    }
}
