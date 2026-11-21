<?php

declare(strict_types=1);

$source = dirname(__DIR__);
$base = sys_get_temp_dir() . '/cms-crawler-verification-' . bin2hex(random_bytes(5));
mkdir($base . '/system', 0700, true);
copy($source . '/system/official-plugins.php', $base . '/system/official-plugins.php');
define('CMS_ROOT', $base);
require $source . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Http\{Request, Response};
use Cms\Core\Seo\{CrawlerLog, CrawlerSourcePolicy as Policy, CrawlerSourceVerification as Verification, CrawlerStatistics};
use Cms\Core\Scheduler\{SchedulerService, SchedulerTaskRegistry};

$checks = 0; $roots = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++; echo 'PASS ' . $label . PHP_EOL;
}
function fixture(): array {
    global $base, $roots;
    $root = $base . '/case-' . ++$roots; mkdir($root, 0700);
    return [$root, Settings::fromArray(['seo' => ['crawler_log_enabled' => true], 'security' => ['encryption_key' => 'synthetic-fixture-master-key']])];
}
function state(string $root): array { return json_decode(file_get_contents($root . '/storage/cache/seo-crawler-sources.json'), true, 32, JSON_THROW_ON_ERROR); }
function putState(string $root, array $data): void { file_put_contents($root . '/storage/cache/seo-crawler-sources.json', json_encode($data)); }
function request(string $agent, string $ip): Request {
    return new Request('GET', '/articles/test-only', ['secret' => 'QUERY-SECRET'], [], ['HTTP_USER_AGENT' => $agent . '/1.0', 'REMOTE_ADDR' => $ip, 'HTTP_X_FORWARDED_FOR' => '8.8.8.8']);
}
$feed = json_encode(['prefixes' => [['ipv4Prefix' => '8.8.8.0/24'], ['ipv6Prefix' => '2001:4860::/32']]]);
try {
    foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '198.51.100.7', '203.0.113.1', '0.0.0.0', '224.0.0.1', '::1', 'fe80::1', 'fc00::1', '2001:db8::1', '::ffff:127.0.0.1', '8.8.8.8:80', "8.8.8.8\n"] as $ip) {
        check(Policy::publicIp($ip) === null, 'Non-public/ambiguous peer rejected: ' . json_encode($ip));
    }
    check(Policy::publicIp('::ffff:8.8.8.8') === '8.8.8.8', 'Mapped IPv4 canonicalizes before verification');
    check(Policy::publicIp('2001:4860:4860::8888') === '2001:4860:4860::8888', 'Public IPv6 is supported');
    foreach ([['8.8.8.0','8.8.8.0/24',true],['8.8.8.255','8.8.8.0/24',true],['8.8.9.0','8.8.8.0/24',false],['8.8.8.16','8.8.8.16/28',true],['8.8.8.31','8.8.8.16/28',true],['8.8.8.32','8.8.8.16/28',false],['2001:4860::1','2001:4860::/32',true],['2001:4861::1','2001:4860::/32',false],['8.8.8.8','2001:4860::/32',false],['8.8.8.8','0.0.0.0/0',false]] as [$ip,$cidr,$expected]) {
        check(Policy::matches($ip,$cidr) === $expected, 'CIDR boundary and family: ' . $ip . ' ' . $cidr);
    }
    check(count(Policy::parseFeed($feed)) === 2, 'Official feed schema supports IPv4 and IPv6 prefixes');
    foreach (['{}','{"prefixes":[]}','{"prefixes":[{"ipv4Prefix":"0.0.0.0/0"}]}','{"prefixes":[{"ipv4Prefix":"10.0.0.0/8"}]}','{"prefixes":[{"ipv4Prefix":"8.8.8.0/24"},{"ipv4Prefix":"bad"}]}',str_repeat('x',262145)] as $raw) {
        check(Policy::parseFeed($raw) === null, 'Malformed, empty, broad or oversized feed fails closed');
    }
    foreach (['googlebot.com.evil.invalid','evilgooglebot.com','googlebot.com','x.googlebot.com..',"x.googlebot.com\n"] as $host) {
        check(!Policy::allowedHostname($host,['googlebot.com']), 'Hostname suffix spoof rejected: ' . json_encode($host));
    }
    check(Policy::allowedHostname('crawl-8-8-8-8.GOOGLEBOT.COM.',['googlebot.com']), 'Canonical trailing dot and DNS case supported');
    $dnsCalls = [];
    $lookup = static function (string $host, int $type) use (&$dnsCalls): array {
        $dnsCalls[] = [$host,$type];
        return $type === DNS_PTR ? [['target'=>'crawl-8-8-8-8.googlebot.com.']] : [['ip'=>'8.8.8.8']];
    };
    check(Policy::verifyDns('8.8.8.8',['googlebot.com'],$lookup) === true && count($dnsCalls) === 2, 'PTR and matching forward address are both required');
    check($dnsCalls[0][0] === '8.8.8.8.in-addr.arpa', 'IPv4 reverse query is constructed from the peer');
    check(Policy::verifyDns('8.8.8.8',['googlebot.com'],static fn($h,$t)=>$t===DNS_PTR?[['target'=>'crawl.googlebot.com']]:[['ip'=>'1.1.1.1']]) === false, 'Correct PTR with wrong forward address is not verified');
    $forwards=0;
    check(Policy::verifyDns('8.8.8.8',['googlebot.com'],static function($h,$t)use(&$forwards){if($t!==DNS_PTR)$forwards++;return [['target'=>'googlebot.com.evil.invalid']];})===false && $forwards===0,'Spoofed suffix never triggers a forward lookup');
    check(Policy::verifyDns('8.8.8.8',['googlebot.com'],static fn()=>false) === null,'DNS errors are unavailable, never successful or authoritative mismatch');
    check(Policy::verifyDns('8.8.8.8',['googlebot.com'],static fn()=>[]) === false,'Completed lookup with no PTR is not verified');
    $v6Query='';
    check(Policy::verifyDns('2001:4860::8888',['googlebot.com'],static function($h,$t)use(&$v6Query){if($t===DNS_PTR){$v6Query=$h;return [['target'=>'v6.googlebot.com']];}return [['ipv6'=>'2001:4860::8888']];})===true && str_ends_with($v6Query,'.ip6.arpa') && substr_count($v6Query,'.')===33,'IPv6 reverse and binary forward comparison work');

    [$root,$settings]=fixture(); $fetchCalls=$networkDns=0;
    $fetcher=static function($url)use(&$fetchCalls,$feed){$fetchCalls++;return str_ends_with($url,'searchbot.json')?json_encode(['prefixes'=>[['ipv4Prefix'=>'1.1.1.0/24']]]):$feed;};
    $verifier=new Verification($root,$settings,$fetcher,static function()use(&$networkDns){$networkDns++;return false;});
    $log=new CrawlerLog($root,$settings,$verifier);
    $log->record(request('GPTBot','8.8.8.8'),Response::html(''));
    $log->record(request('GPTBot','8.8.8.8'),Response::html(''));
    check($fetchCalls===0&&$networkDns===0,'Recording requests never invokes DNS, HTTPS or a worker');
    check(count($log->rows())===1&&$log->rows()[0]['verification']==='source_pending'&&$log->rows()[0]['hits']===2,'Repeated pending requests retain truthful counts in one source batch');
    $pendingRaw=file_get_contents($root.'/storage/cache/seo-crawler-sources.json');
    check(!str_contains($pendingRaw,'8.8.8.8')&&str_contains($pendingRaw,'ciphertext')&&!str_contains($pendingRaw,'QUERY-SECRET'),'Queued source IP is authenticated-encrypted without request details');
    check((fileperms($root.'/storage/cache/seo-crawler-sources.json')&0777)===0600,'Verification state is private0600');
    $legacy=['day'=>gmdate('Y-m-d'),'agent'=>'GPTBot','route'=>'/articles/{slug}','status'=>200,'hits'=>10,'verification'=>'source_unverified'];
    $rows=$log->rows();$rows[]=$legacy;file_put_contents($root.'/storage/logs/seo-crawlers.json',json_encode($rows));
    $log->record(request('Googlebot','1.1.1.1'),Response::html(''));
    $result=$verifier->run();
    check($result['verified']===1&&$result['not_verified']===1&&$fetchCalls===2&&$networkDns===1,'Worker verifies matching official range and completes a negative DNS check');
    $rows=$log->rows();$verified=array_values(array_filter($rows,static fn($r)=>$r['verification']==='source_verified_ip'));
    check(count($verified)===1&&$verified[0]['hits']===2&&$verified[0]['verification_evidence']==='openai_training','Worker promotes only its pending source batch with evidence');
    check(in_array($legacy,$rows,true),'Historical aggregates remain byte-for-byte unchanged and unverified');
    check(!str_contains(file_get_contents($root.'/storage/cache/seo-crawler-sources.json'),'ciphertext'),'Completed tasks remove encrypted source addresses');
    $snapshot=(new CrawlerStatistics($settings))->snapshot($rows,new Request('GET','/admin/seo/crawlers'));
    check($snapshot['total']===13&&$snapshot['ranking']['GPTBot']['sources']['source_verified_ip']===2&&$snapshot['ranking']['GPTBot']['sources']['source_unverified']===10,'Mixed UA families separate verified and historical counts without inflating totals');
    $html=(new CrawlerStatistics($settings))->render($rows,new Request('GET','/admin/seo/crawlers'));
    check(str_contains($html,'已验证 2 / 12')&&str_contains($html,'历史未验证')&&str_contains($html,'未通过核验'),'UI exposes mixed evidence instead of an all-verified family badge');
    check(!str_contains($html,'8.8.8.8')&&!str_contains($html,'1.1.1.1'),'Administrative evidence does not expose source IPs');
    $oldFetch=$fetchCalls;$oldDns=$networkDns;
    $log->record(request('GPTBot','8.8.8.8'),Response::html(''));
    check($fetchCalls===$oldFetch&&$networkDns===$oldDns&&array_sum(array_column($log->rows(),'hits'))===14,'Fresh local official range verifies subsequent requests without network');
    $verifier->run();check(array_sum(array_column($log->rows(),'hits'))===14,'Repeated completed-batch application is idempotent');
    $log->record(request('OAI-SearchBot','8.8.8.8'),Response::html(''));$verifier->run();
    $search=array_values(array_filter($log->rows(),static fn($r)=>$r['agent']==='OAI-SearchBot'));
    check(count($search)===1&&$search[0]['verification']==='source_not_verified','OpenAI agents cannot borrow another agent range cache');
    $log->record(request('GPTBot','1.1.1.1'),Response::html(''));
    $last=$log->rows();check(end($last)['verification']==='source_pending','Forged X-Forwarded-For never replaces REMOTE_ADDR');
    check($verifier->classify('GPTBot','127.0.0.1')['verification']==='source_unavailable','Proxy/private peers cannot be authenticated from forged headers');
    check($verifier->classify('Bytespider','8.8.8.8')['verification']==='source_unsupported','No guessed policy or success for unsupported crawlers');

    [$dnsRoot,$dnsSettings]=fixture();
    $confirmed=static fn($ip,$suffixes)=>Policy::verifyDns($ip,$suffixes,static fn($host,$type)=>$type===DNS_PTR?[['target'=>'petalbot-114-119-128-10.petalsearch.com']]:[['ip'=>'114.119.128.10']]);
    $dnsVerifier=new Verification($dnsRoot,$dnsSettings,static fn()=>null,$confirmed);$dnsLog=new CrawlerLog($dnsRoot,$dnsSettings,$dnsVerifier);
    $dnsLog->record(request('PetalBot','114.119.128.10'),Response::html(''));$dnsVerifier->run();
    check($dnsLog->rows()[0]['verification']==='source_verified_dns'&&$dnsLog->rows()[0]['verification_method']==='forward_confirmed_dns','Petal uses real forward-confirmed reverse-DNS logic in an isolated resolver fixture');
    $saved=file_get_contents($dnsRoot.'/storage/cache/seo-crawler-sources.json');
    check(!str_contains($saved,'114.119.128.10')&&!str_contains($saved,'petalbot-114'),'Neither IP nor an IP-bearing hostname survives in completed cache');

    [$expiryRoot,$expirySettings]=fixture();$now=time();$available=true;
    $expiry=new Verification($expiryRoot,$expirySettings,static function()use(&$available,$feed){return $available?$feed:null;},static fn()=>null,static function()use(&$now){return $now;});
    $expiry->classify('GPTBot','8.8.8.8');$expiry->run();$originalFeed=state($expiryRoot)['feeds']['openai_training'];
    $now+=86401;$available=false;
    check($expiry->classify('GPTBot','8.8.8.8')['verification']==='source_pending','Expired source and feed evidence cannot stay verified');
    check($expiry->run()['pending']===1&&state($expiryRoot)['feeds']['openai_training']['expires_at']===$originalFeed['expires_at'],'Network failure does not extend stale official IP evidence');
    $now+=Verification::QUEUE_TTL+1;$expiry->prune();
    check(!str_contains(file_get_contents($expiryRoot.'/storage/cache/seo-crawler-sources.json'),'ciphertext'),'Expired pending encrypted addresses are physically pruned by maintenance');
    $expiredRow=$legacy+['source_key'=>str_repeat('a',32),'verification_queued_at'=>time()-3601];$expiredRow['verification']='source_pending';
    mkdir($expiryRoot.'/storage/logs',0700,true);file_put_contents($expiryRoot.'/storage/logs/seo-crawlers.json',json_encode([$expiredRow]));
    check((new CrawlerLog($expiryRoot,$expirySettings))->rows()[0]['verification']==='source_unavailable','Abandoned pending rows become unavailable, not endlessly pending or verified');

    [$badRoot,$badSettings]=fixture();$calls=0;$bad=new Verification($badRoot,$badSettings,static function()use(&$calls,$feed){$calls++;return $feed;},static function()use(&$calls){$calls++;return true;});
    $bad->classify('Baiduspider','8.8.8.8');$data=state($badRoot);$key=array_key_first($data['subjects']);$data['subjects'][$key]['ciphertext']=base64_encode(str_repeat('x',40));putState($badRoot,$data);$bad->run();
    check($calls===0&&isset(state($badRoot)['subjects'][$key]['ciphertext']),'Tampered encrypted subjects cannot trigger network or verified results');
    $lock=fopen($badRoot.'/storage/cache/seo-crawler-sources.json','r+');flock($lock,LOCK_EX|LOCK_NB);$started=microtime(true);$locked=$bad->classify('GPTBot','1.1.1.1');
    check($locked['verification']==='source_unavailable'&&microtime(true)-$started<0.2,'Contended state lock degrades without blocking a request');flock($lock,LOCK_UN);fclose($lock);
    file_put_contents($badRoot.'/storage/cache/seo-crawler-sources.json','{broken');
    check($bad->classify('GPTBot','1.1.1.1')['verification']==='source_unavailable','Corrupt verification state fails closed');
    unlink($badRoot.'/storage/cache/seo-crawler-sources.json');file_put_contents($badRoot.'/sentinel','must remain');symlink($badRoot.'/sentinel',$badRoot.'/storage/cache/seo-crawler-sources.json');
    check($bad->classify('GPTBot','1.1.1.1')['verification']==='source_unavailable'&&file_get_contents($badRoot.'/sentinel')==='must remain','Symlink state cannot overwrite another file');
    [$capRoot,$capSettings]=fixture();$cap=new Verification($capRoot,$capSettings);
    for($i=0;$i<256;$i++){$cap->classify('Baiduspider','8.8.'.intdiv($i,254).'.'.($i%254+1));}
    check($cap->classify('Baiduspider','9.9.9.9')['verification']==='source_unavailable'&&count(state($capRoot)['subjects'])===256&&filesize($capRoot.'/storage/cache/seo-crawler-sources.json')<=524288,'Source queue has strict count and byte bounds');
    check(Verification::status($legacy+['verification_method'=>'official_ip','verification_checked_at'=>time()])==='source_unverified','Legacy records cannot gain verification from unrelated metadata');
    $forged=$legacy;$forged['verification']='source_verified_ip';
    check(Verification::status($forged)==='source_unverified','A fabricated verified string without evidence cannot create a green badge');

    [$noKeyRoot]=fixture();$noKeySettings=Settings::fromArray(['seo'=>['crawler_log_enabled'=>true]]);
    check((new Verification($noKeyRoot,$noKeySettings))->classify('GPTBot','8.8.8.8')['verification']==='source_unavailable'&&!str_contains(file_get_contents($noKeyRoot.'/storage/cache/seo-crawler-sources.json'),'ciphertext'),'Missing encryption key never queues a plaintext address');
    [$boundRoot,$boundSettings]=fixture();$attempts=[];$tick=time();
    $bounded=new Verification($boundRoot,$boundSettings,static fn()=>null,static function($ip)use(&$attempts){$attempts[]=$ip;return null;},static function()use(&$tick){return $tick;});
    for($i=1;$i<=5;$i++){$bounded->classify('Baiduspider','8.8.8.'.$i);}
    check($bounded->run(100)['processed']===4&&count($attempts)===4,'Worker enforces four-source cap even when caller requests more');
    $tick+=61;$bounded->run();
    check($attempts[4]==='8.8.8.5','Timed-out sources rotate behind untouched sources instead of starving the queue');
    $disabled=Settings::fromArray(['seo'=>['crawler_log_enabled'=>false],'security'=>['encryption_key'=>'synthetic-fixture-master-key']]);
    check((new Verification($boundRoot,$disabled,static fn()=>throw new RuntimeException('must not fetch'),static fn()=>throw new RuntimeException('must not resolve')))->run()['processed']===0&&!str_contains(file_get_contents($boundRoot.'/storage/cache/seo-crawler-sources.json'),'ciphertext'),'Disabled collection erases queued IPs without network access');
    [$bindingRoot,$bindingSettings]=fixture();$bindingCalls=0;
    $binding=new Verification($bindingRoot,$bindingSettings,static fn()=>null,static function()use(&$bindingCalls){$bindingCalls++;return true;});
    $binding->classify('Baiduspider','8.8.8.8');$bindingState=state($bindingRoot);$bindingKey=array_key_first($bindingState['subjects']);$bindingState['subjects'][$bindingKey]['batch']=str_repeat('f',32);putState($bindingRoot,$bindingState);$binding->run();
    check($bindingCalls===0,'Authenticated ciphertext cannot be rebound to a different source batch');

    $pdo=new PDO('sqlite:'.$base.'/scheduler.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new Cms\Core\Migration\MigrationRunner($pdo,array_map(static fn($f)=>require $f,glob($source.'/system/migrations/*.php'))))->run();
    $scheduled=Settings::fromArray(['database'=>['dsn'=>'sqlite:'.$base.'/scheduler.sqlite'],'seo'=>['crawler_log_enabled'=>true],'security'=>['encryption_key'=>'synthetic-fixture-master-key']]);
    Verification::register($base,$scheduled);$scheduler=new SchedulerService($pdo);$task=$scheduler->find(Verification::TASK_ID);
    check($task!==null&&(int)$task['enabled']===1&&(int)$task['interval_seconds']===60&&$task['payload_json']==='[]','Verification reuses the registered Core scheduler without source IP payloads');
    $pdo->exec("UPDATE cms_core_scheduled_tasks SET enabled=0 WHERE task_id='core.seo.crawlers.verify'");Verification::register($base,$scheduled);
    check((int)$scheduler->find(Verification::TASK_ID)['enabled']===0,'CLI registration preserves operator pause');
    check(is_callable(SchedulerTaskRegistry::get(Verification::TASK_ID)),'Existing scheduled-task runner can resolve the verification handler');
    SchedulerTaskRegistry::clear();Verification::register($base,$disabled);
    check(is_callable(SchedulerTaskRegistry::get(Verification::TASK_ID)),'Disabled collection still registers cleanup for an already scheduled task');
    echo 'Crawler source verification PASS: '.$checks.' checks; synthetic feeds/DNS; no external network or production writes'.PHP_EOL;
} finally {
    $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($entries as $entry){is_link($entry->getPathname())||!$entry->isDir()?unlink($entry->getPathname()):rmdir($entry->getPathname());}rmdir($base);
}
