<?php
declare(strict_types=1);
namespace Cms\Core\Seo;

use Cms\Core\Config\Settings;

/** Best-effort bounded outcome counters, never an exact traffic/drop denominator. */
final class CrawlerObservation
{
    public const REASONS = ['stored','lock_busy','row_limit','byte_limit','invalid_ledger','io_error','unsafe_path','hit_cap'];
    private const SHARDS = 8;
    private const MAX_BYTES = 8192;
    private const MAX_COUNT = 10000000;
    public function __construct(private readonly string $root, private readonly Settings $settings) {}

    public function record(string $reason): void
    {
        if (!in_array($reason,self::REASONS,true)) { return; }
        $this->write(getmypid()%self::SHARDS,$reason);
    }

    public function prune(): void
    {
        for($i=0;$i<self::SHARDS;$i++) { $this->write($i,null); }
    }

    /** No file creation, pruning or blocking lock on the admin read path. */
    public function snapshot(): array
    {
        $days=[];$unavailable=0;
        for($i=0;$i<self::SHARDS;$i++){
            $path=$this->path($i);
            if(is_link($path)||$this->unsafeParents()) { $unavailable++;continue; }
            if(!file_exists($path))continue;
            if(!is_file($path)){ $unavailable++;continue; }
            $f=@fopen($path,'rb');if($f===false){$unavailable++;continue;}
            try{
                if(!@flock($f,LOCK_SH|LOCK_NB)){ $unavailable++;continue; }
                $data=$this->decode($f);
                if($data===null){$unavailable++;continue;}
                foreach($data as$day=>$counts){
                    if($day<$this->floor()||$day>gmdate('Y-m-d'))continue;
                    foreach($counts as$key=>$n)$days[$day][$key]=($days[$day][$key]??0)+$n;
                }
            }catch(\Throwable){$unavailable++;}finally{fclose($f);}
        }
        ksort($days);
        return ['days'=>$days,'unavailable_shards'=>$unavailable,'exact'=>false,'shards'=>self::SHARDS];
    }

    private function write(int $shard,?string $reason): void
    {
        $path=$this->path($shard);
        if($this->unsafeParents()||is_link($path)||($reason===null&&!is_file($path)))return;
        if(file_exists($path)&&!is_file($path))return;
        if(!is_dir(dirname($path))&&!@mkdir(dirname($path),0700,true)&&!is_dir(dirname($path)))return;
        $f=@fopen($path,'c+b');if($f===false)return;
        try{
            if(!@flock($f,LOCK_EX|LOCK_NB))return;
            @chmod($path,0600);$data=$this->decode($f);if($data===null)return;
            $floor=$this->floor();$today=gmdate('Y-m-d');
            $data=array_filter($data,static fn($day)=>$day>=$floor&&$day<=$today,ARRAY_FILTER_USE_KEY);
            if($reason!==null)$data[$today][$reason]=min(self::MAX_COUNT,($data[$today][$reason]??0)+1);
            ksort($data);$raw=json_encode(['version'=>1,'days'=>$data],JSON_UNESCAPED_SLASHES);
            if(!is_string($raw)||strlen($raw)>self::MAX_BYTES)return;
            rewind($f);
            if(@fwrite($f,$raw)===strlen($raw)){@ftruncate($f,strlen($raw));@fflush($f);}
        }catch(\Throwable){/* Observability must not fail or delay the public response. */}finally{fclose($f);}
    }

    private function decode($f): ?array
    {
        if((fstat($f)['size']??0)>self::MAX_BYTES)return null;
        $raw=stream_get_contents($f,self::MAX_BYTES+1);
        if($raw==='')return [];
        $value=json_decode((string)$raw,true);
        if(!is_array($value)||($value['version']??null)!==1||!is_array($value['days']??null)||count($value['days'])>30)return null;
        foreach($value['days']as$day=>$counts){
            if(!is_string($day)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)||!is_array($counts))return null;
            foreach($counts as$key=>$n)if(!in_array($key,self::REASONS,true)||!is_int($n)||$n<0||$n>self::MAX_COUNT)return null;
        }
        return $value['days'];
    }
    private function path(int $shard): string { return $this->root.'/storage/logs/seo-crawler-observation-'.$shard.'.json'; }
    private function unsafeParents(): bool { return is_link($this->root.'/storage')||is_link($this->root.'/storage/logs'); }
    private function floor(): string
    {
        $days=max(1,min(30,(int)$this->settings->get('seo.crawler_retention_days',7)));
        return gmdate('Y-m-d',time()-($days-1)*86400);
    }
}
