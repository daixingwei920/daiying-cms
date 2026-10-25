<?php
declare(strict_types=1);
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';
use Cms\Core\Config\Settings;
use Cms\Core\Http\{Request,Response};
use Cms\Core\Seo\{CrawlerLog,CrawlerObservation,CrawlerStatistics};

function requests(CrawlerLog $log,int $n):void {
    for($i=0;$i<$n;$i++)$log->record(new Request('GET','/articles/PRIVATE-SLUG',['query'=>'QUERY-SECRET'],[],['HTTP_USER_AGENT'=>'Baiduspider RAW-UA','REMOTE_ADDR'=>'198.51.100.22']),Response::html(''));
}
if(($argv[1]??'')==='--worker'){
    $root=$argv[2]??'';if(!is_file($root.'/.observation-test'))throw new RuntimeException('Marked isolated fixture required');
    requests(new CrawlerLog($root,Settings::fromArray(['seo'=>['crawler_log_enabled'=>true]])),100);exit;
}
$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS '.$message,PHP_EOL;}
function removeFixture(string $path):void{
    if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
    foreach(scandir($path)as$name)if($name!=='.'&&$name!=='..')removeFixture($path.'/'.$name);rmdir($path);
}
$root=sys_get_temp_dir().'/cms-crawler-observation-'.bin2hex(random_bytes(6));mkdir($root.'/storage/logs',0700,true);touch($root.'/.observation-test');
$settings=Settings::fromArray(['seo'=>['crawler_log_enabled'=>true,'crawler_retention_days'=>7]]);
$log=new CrawlerLog($root,$settings);$observer=new CrawlerObservation($root,$settings);
$path=$root.'/storage/logs/seo-crawlers.json';$side=$root.'/storage/logs/seo-crawler-observation-'.(getmypid()%8).'.json';$today=gmdate('Y-m-d');
$totals=static function()use($observer):array{$result=array_fill_keys(CrawlerObservation::REASONS,0);foreach($observer->snapshot()['days']as$values)foreach($values as$k=>$n)$result[$k]+=$n;return $result;};
$reset=static function()use($root,$path):void{foreach(glob($root.'/storage/logs/seo-crawler-observation-*.json')?:[]as$p)unlink($p);if(is_file($path)||is_link($path))unlink($path);file_put_contents($path,'[]');};
try{
    requests($log,40);check($totals()['stored']===40&&$log->rows()[0]['hits']===40,'Serial requests agree with stored outcome counters');
    $before=$totals();$lock=fopen($path,'r+b');flock($lock,LOCK_EX);$start=microtime(true);requests($log,10);$elapsed=microtime(true)-$start;flock($lock,LOCK_UN);fclose($lock);
    check($totals()['lock_busy']===10&&$log->rows()[0]['hits']===40&&$elapsed<1,'Held primary lock drops ten samples without waiting, with separate lock-busy evidence');
    $lock=fopen($side,'r+b');flock($lock,LOCK_EX);requests($log,5);$during=$observer->snapshot();flock($lock,LOCK_UN);fclose($lock);
    check($totals()['stored']===40&&$log->rows()[0]['hits']===45&&$during['unavailable_shards']===1,'Observation contention never blocks primary recording and visibly undercounts');
    $before=$totals();$log->rows();$log->prune();check($before===$totals(),'Admin reads and pruning do not invent request outcomes');
    $raw=file_get_contents($side);check(!str_contains($raw,'PRIVATE')&&!str_contains($raw,'RAW-UA')&&!str_contains($raw,'QUERY')&&!str_contains($raw,'198.51.'),'Counters persist no IP, UA, query, slug or per-request details');
    foreach([['not json','invalid_ledger'],[str_repeat('x',524289),'byte_limit']]as[$data,$reason]){file_put_contents($path,$data);requests($log,1);check($totals()[$reason]===1,'Primary failure observed: '.$reason);}
    $rows=[];for($i=0;$i<2000;$i++)$rows[]=['day'=>$today,'agent'=>'GPTBot','route'=>'/','status'=>$i,'hits'=>1];
    file_put_contents($path,json_encode($rows));requests($log,1);check($totals()['row_limit']===1&&count($log->rows())===2000,'Row capacity is distinguished from contention');
    file_put_contents($path,json_encode([['day'=>$today,'agent'=>'Baiduspider','route'=>'/articles/{slug}','status'=>200,'hits'=>10000000]]));requests($log,1);
    check($totals()['hit_cap']===1&&$log->rows()[0]['hits']===10000000,'Saturated aggregate does not claim another stored request');
    unlink($path);mkdir($path);requests($log,1);rmdir($path);check($totals()['io_error']===1,'Non-file primary ledger fails closed with I/O observation');
    $target=$root.'/untouched';file_put_contents($target,'UNCHANGED');symlink($target,$path);requests($log,1);unlink($path);
    check($totals()['unsafe_path']===1&&file_get_contents($target)==='UNCHANGED','Symlink ledger is rejected without touching its target');
    $reset();$disabled=new CrawlerLog($root,Settings::fromArray(['seo'=>['crawler_log_enabled'=>false]]));requests($disabled,3);
    foreach([['POST','/articles/x','Baiduspider'],['GET','/admin','Baiduspider'],['GET','/','UnknownBot']]as[$method,$route,$ua])$log->record(new Request($method,$route,[],[],['HTTP_USER_AGENT'=>$ua]),Response::html(''));
    check(glob($root.'/storage/logs/seo-crawler-observation-*.json')===[],'Disabled collection, private routes, POST and unknown UA create no observation');
    $old=gmdate('Y-m-d',time()-8*86400);file_put_contents($side,json_encode(['version'=>1,'days'=>[$old=>['stored'=>9],$today=>['stored'=>2]]]));$log->prune();
    check(!str_contains(file_get_contents($side),$old)&&$totals()['stored']===2,'Existing scheduled prune also physically expires observation days');
    file_put_contents($side,'broken');requests($log,1);check($observer->snapshot()['unavailable_shards']===1&&$log->rows()[0]['hits']===1,'Corrupt observer cannot break the primary write and is flagged unavailable');
    file_put_contents($side,str_repeat('x',8193));check($observer->snapshot()['unavailable_shards']===1&&filesize($side)===8193,'Oversized observer is not expanded or silently reset');
    file_put_contents($side,json_encode(['version'=>1,'days'=>[$today=>['stored'=>10000000]]]));requests($log,2);
    check($totals()['stored']===10000000&&filesize($side)<=8192,'Per-shard daily counters saturate and stay bounded');
    $reset();$workers=[];for($i=0;$i<4;$i++){
        $p=proc_open([PHP_BINARY,__FILE__,'--worker',$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Worker failed');fclose($pipes[0]);$workers[]=[$p,$pipes];
    }
    foreach($workers as[$p,$pipes]){$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($out);}
    $hits=array_sum(array_column($log->rows(),'hits'));$counts=$totals();$observed=array_sum($counts);$missed=$observed-$counts['stored'];
    check($hits>0&&$hits<=400&&$counts['stored']<=$hits&&$missed<=400-$hits&&$observed<=400,'Four real workers preserve bounded lower-bound counters without claiming complete accounting');
    foreach(glob($root.'/storage/logs/seo-crawler-observation-*.json')as$p)check(filesize($p)<=8192,'Concurrent observation shard bounded');
    echo json_encode(['attempted'=>400,'primary_recorded'=>$hits,'actually_missing_in_fixture'=>400-$hits,'observed'=>$counts,'observation_missing_in_fixture'=>400-$observed,'exact'=>false],JSON_UNESCAPED_SLASHES),PHP_EOL;
    $html=(new CrawlerStatistics($settings))->renderObservation($observer->snapshot(),new Request('GET','/admin/seo/crawlers',['platform'=>'other','status'=>'5']));
    check(str_contains($html,'不受平台、蜘蛛或 HTTP 状态筛选影响')&&str_contains($html,'不能计算真实访问量、完整丢样数或丢样率')&&str_contains($html,'观测下界'),'UI explains site-wide scope, lower bounds and no traffic/drop-rate inference');
    $oldSnapshot=['days'=>[$old=>['stored'=>999],$today=>['stored'=>3]],'unavailable_shards'=>0];$html=(new CrawlerStatistics($settings))->renderObservation($oldSnapshot,new Request('GET','/admin/seo/crawlers',['days'=>'1']));
    check(str_contains($html,'<strong>3</strong>')&&!str_contains($html,'999'),'Observation display follows effective UTC time window');
    echo 'PASS '.$checks.' observation checks; isolated synthetic fixture only',PHP_EOL;
}finally{removeFixture($root);}
