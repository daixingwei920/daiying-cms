<?php
declare(strict_types=1);
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';
use Cms\Core\Config\Settings;
use Cms\Core\Http\{Request,Response};
use Cms\Core\Seo\CrawlerLog;

function sample(CrawlerLog $log, int $n): void {
    for ($i=0;$i<$n;$i++) $log->record(new Request('GET','/articles/synthetic',[],[],['HTTP_USER_AGENT'=>'Baiduspider']),Response::html(''));
}
if (($argv[1]??'')==='--worker') {
    $root=$argv[2]??'';
    if (!is_file($root.'/.sampling-test')) throw new RuntimeException('Marked isolated fixture required');
    sample(new CrawlerLog($root,Settings::fromArray(['seo'=>['crawler_log_enabled'=>true]])),100);
    exit;
}
$root=sys_get_temp_dir().'/cms-crawler-sampling-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/logs',0700,true);touch($root.'/.sampling-test');
$path=$root.'/storage/logs/seo-crawlers.json';
$run=static function()use($root):void {
    $workers=[];
    for($i=0;$i<4;$i++){
        $p=proc_open([PHP_BINARY,__FILE__,'--worker',$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('Worker creation failed');
        fclose($pipes[0]);$workers[]=[$p,$pipes];
    }
    foreach($workers as[$p,$pipes]){
        $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($p)!==0)throw new RuntimeException($out);
    }
};
$count=static function()use($path):int {
    $rows=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($rows)||count($rows)>1)throw new RuntimeException('Unexpected aggregate');
    return array_sum(array_column($rows,'hits'));
};
try{
    file_put_contents($path,'[]');$lock=fopen($path,'r+b');
    if(!flock($lock,LOCK_EX))throw new RuntimeException('Fixture lock failed');
    $run();flock($lock,LOCK_UN);fclose($lock);
    $held=$count();if($held!==0)throw new RuntimeException('Held nonblocking lock unexpectedly recorded requests');
    $log=new CrawlerLog($root,Settings::fromArray(['seo'=>['crawler_log_enabled'=>true]]));
    sample($log,400);$serial=$count();if($serial!==400)throw new RuntimeException('Serial sampling lost requests');
    file_put_contents($path,'[]');$run();$parallel=$count();
    if($parallel<1||$parallel>400)throw new RuntimeException('Parallel bounds invalid');
    echo json_encode(['status'=>'PASS','attempts_per_case'=>400,'held_lock_recorded'=>$held,'serial_recorded'=>$serial,
        'parallel_recorded'=>$parallel,'parallel_missing'=>400-$parallel,'valid_bounded_json'=>true,
        'interpretation'=>'Controlled fixture demonstrates LOCK_NB contention loss. Historical 109/400 is one run, not a fixed sample rate. No traffic extrapolation.',
        'production_writes'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
}finally{
    unlink($path);unlink($root.'/.sampling-test');rmdir($root.'/storage/logs');rmdir($root.'/storage');rmdir($root);
}
