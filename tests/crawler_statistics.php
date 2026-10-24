<?php
declare(strict_types=1);
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';
use Cms\Core\Config\Settings;
use Cms\Core\Http\{Request,Response};
use Cms\Core\Seo\{CrawlerRegistry,CrawlerLog,CrawlerStatistics};

$checks=0;
function ck(bool $ok,string $message):void{global$checks;if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS '.$message,PHP_EOL;}
foreach(CrawlerRegistry::agents()as$canonical=>$info){
 foreach($info['aliases']as$alias){ck(CrawlerRegistry::identify('Mozilla/5.0 (compatible; '.strtoupper($alias).'/2.0; +https://example.invalid/info)')===$canonical,'UA alias canonicalizes: '.$alias);}
 ck(CrawlerRegistry::identify('Fake'.$canonical.'Suffix')===null,'Embedded fake token rejected: '.$canonical);
 ck(CrawlerRegistry::identify($canonical.'-UnknownSuffix')===null,'Undeclared suffix rejected: '.$canonical);
}
ck(CrawlerRegistry::identify('Sogou-inst-spider/4.0')==='Sogou web spider','Upstream Sogou hyphen form recognized');
ck(CrawlerRegistry::identify('OAI SearchBot')===null,'Non-Sogou token separators stay literal');
ck(CrawlerRegistry::identify('Mozilla/5.0 (+https://example.invalid/GPTBot)')===null,'Documentation URL does not impersonate a UA token');
ck(CrawlerRegistry::identify(str_repeat('x',512).' Bytespider')===null,'UA parsing remains bounded to 512 bytes');
$root=sys_get_temp_dir().'/cms-crawler-stats-'.bin2hex(random_bytes(5));mkdir($root,0700);
try{
 $settings=Settings::fromArray(['seo'=>['crawler_log_enabled'=>true,'crawler_retention_days'=>7]]);$log=new CrawlerLog($root,$settings);
 foreach(['Baiduspider','Baiduspider-render','Baiduspider-image']as$ua)$log->record(new Request('GET','/articles/example',['secret'=>'QUERY-SECRET'],[],['HTTP_USER_AGENT'=>$ua.' RAW-UA-SECRET','REMOTE_ADDR'=>'198.51.100.7']),Response::html(''));
 foreach(['Sogou web spider','Sogou inst spider']as$ua)$log->record(new Request('HEAD','/robots.txt',[],[],['HTTP_USER_AGENT'=>$ua]),Response::text(''));
 foreach([['POST','/articles/example'],['GET','/admin'],['GET','/preview/token']]as[$method,$path])$log->record(new Request($method,$path,[],[],['HTTP_USER_AGENT'=>'360Spider']),Response::html(''));
 $stored=$log->rows();ck(count($stored)===2&&$stored[0]['hits']===3&&$stored[1]['hits']===2,'Aliases share bounded family aggregates and private/POST routes stay excluded');
 $raw=file_get_contents($root.'/storage/logs/seo-crawlers.json');ck(!str_contains($raw,'SECRET')&&!str_contains($raw,'198.51.100.7')&&!str_contains($raw,'example'),'No raw UA, IP, query or concrete article slug persisted');
 $today=gmdate('Y-m-d');$day=static fn(int$n):string=>gmdate('Y-m-d',time()-$n*86400);
 $row=static fn(string$a,int$offset,int$status,int$hits):array=>['day'=>$day($offset),'agent'=>$a,'route'=>'/articles/{slug}','status'=>$status,'hits'=>$hits];
 $rows=[$row('Baiduspider',0,200,5),$row('Sogou web spider',0,404,2),$row('GPTBot',0,200,3),$row('360Spider',1,302,4),$row('YisouSpider',6,500,6),$row('Baiduspider',7,200,100),$row('Bytespider',29,200,50)];
 $view=new CrawlerStatistics($settings);$request=static fn(array$q=[]):Request=>new Request('GET','/admin/seo/crawlers',$q);
 $s=$view->snapshot($rows,$request());ck($s['total']===20&&$s['todayHits']===10&&$s['domestic']===17&&$s['errors']===8&&count($s['ranking'])===5,'Totals sum request hits across days, families and status classes');
 ck(array_sum($s['daily'])===20&&count($s['daily'])===7&&$s['daily'][$day(2)]===0,'UTC trend includes zero days within effective retention');
 ck(array_key_first($s['ranking'])==='YisouSpider'&&$s['rowCount']===5,'Ranking and aggregate row counts differ correctly from request counts');
 foreach([['platform','domestic',17],['platform','other',3],['agent','Sogou web spider',2],['status','2',8],['status','3',4],['status','4',2],['status','5',6],['days','1',10]]as[$key,$value,$expected])ck($view->snapshot($rows,$request([$key=>$value]))['total']===$expected,'Filter '.$key.'='.$value.' has correct sums');
 ck($view->snapshot($rows,$request(['platform'=>'domestic','agent'=>'GPTBot']))['total']===0,'Intersecting incompatible filters yield a truthful empty state');
 $thirty=new CrawlerStatistics(Settings::fromArray(['seo'=>['crawler_retention_days'=>30]]));ck($thirty->snapshot($rows,$request(['days'=>'30']))['total']===170,'Thirty-day display uses available retained history');
 ck($view->snapshot($rows,$request(['days'=>'30']))['days']===7&&$view->snapshot($rows,$request(['days'=>'30']))['total']===20,'Long selector never invents history beyond configured retention');
 foreach([['agent'=>['nested'=>'x']],['agent'=>'<script>alert(1)</script>'],['days'=>'99'],['platform'=>'china-ip'],['status'=>'200'],['page'=>'-1'],['page'=>'1e3']]as$q){$rejected=false;try{$view->snapshot($rows,$request($q));}catch(InvalidArgumentException){$rejected=true;}ck($rejected,'Malformed filter fails closed: '.json_encode($q));}
 $bad=[$row('UnknownBot',0,200,999),array_replace($row('Baiduspider',0,200,5),['hits'=>-1]),array_replace($row('Baiduspider',0,200,5),['route'=>'/<script>alert(1)</script>']),array_replace($row('Baiduspider',0,200,5),['status'=>'200'])];
 ck($view->snapshot(array_merge($rows,$bad),$request())['total']===20&&!str_contains($view->render($bad,$request()),'<script>'),'Malformed ledger rows cannot inflate counts or render stored HTML');
 $many=[];for($i=0;$i<51;$i++)$many[]=$row('Sogou web spider',0,200+$i,1);
 $s=$view->snapshot($many,$request(['page'=>'2']));ck($s['total']===51&&$s['pages']===2&&count($s['rows'])===1&&$s['rowCount']===51,'Pagination caps detail rows without changing summary totals');
 ck($view->snapshot($many,$request(['page'=>'99999']))['page']===2,'Page overflow clamps to existing last page');
 $html=$view->render($many,$request(['platform'=>'domestic','agent'=>'Sogou web spider']));$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);$href=$xp->evaluate('string(//nav/a/@href)');parse_str((string)parse_url($href,PHP_URL_QUERY),$query);
 ck(($query['agent']??'')==='Sogou web spider'&&($query['platform']??'')==='domestic'&&($query['page']??'')==='2','Pagination preserves URL-encoded family and platform filters');
 $sub=(new CrawlerStatistics(Settings::fromArray(['site'=>['base_path'=>'/cms'],'seo'=>['crawler_retention_days'=>7]])))->render($many,$request());
 ck(str_contains($sub,'action="/cms/admin/seo/crawlers"')&&str_contains($sub,'href="/cms/admin/seo/crawlers?')&&str_contains($sub,'href="/cms/admin/settings"')&&!str_contains($sub,'/cms/cms/'),'Subdirectory filters, settings, reset and pagination URLs remain scoped');
 ck(str_contains($html,'UA 自称，来源未验证')&&str_contains($html,'不是请求所在地')&&str_contains($html,'并发可能丢样'),'Interface preserves source, geography and sampling limitations');
 $off=new CrawlerStatistics(Settings::fromArray([]));$empty=$off->render([],$request());ck(str_contains($empty,'采集已关闭')&&str_contains($empty,'暂无记录'),'Disabled collection and empty data have actionable native states');
 ck(!str_contains($empty,'IP地址')&&!str_contains($html,'来源已验证'),'No fabricated source verification or IP detail');
 echo 'Checks: '.$checks.'; network/model requests: 0; production writes: 0',PHP_EOL;
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);
}
