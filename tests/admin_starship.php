<?php

declare(strict_types=1);

// Isolated native content rendering and unchanged action contracts; no signing/network.
define('TEST_SOURCE_ROOT', dirname(__DIR__));
define('CMS_ROOT', sys_get_temp_dir() . '/cms-starship-' . bin2hex(random_bytes(5)));
mkdir(CMS_ROOT, 0700);
require TEST_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
register_shutdown_function(static function (): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CMS_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? rmdir($p->getPathname()) : unlink($p->getPathname()); }
    rmdir(CMS_ROOT);
});
$settings = Settings::fromArray(['app'=>['env'=>'testing'], 'database'=>['dsn'=>'sqlite:'.CMS_ROOT.'/fixture.sqlite'], 'security'=>['encryption_key'=>'isolated-starship-fixture'], 'site'=>['url'=>'https://example.invalid']]);
$pdo = ConnectionFactory::make($settings);
$files = glob(TEST_SOURCE_ROOT.'/system/migrations/*.php'); sort($files, SORT_STRING);
(new MigrationRunner($pdo, array_map(static fn ($f) => require $f, $files)))->run();
$insert = $pdo->prepare('INSERT INTO cms_contents (content_type,title,slug,status,blocks_json,meta_json,created_at,updated_at,published_at) VALUES (?,?,?,?,?,?,?,?,?)');
$titles = ['新手入门：搭建你的第一个网站','媒体库使用指南','主题安装与切换','文章分类与标签设置','站点 SEO 基础配置','如何添加自定义页面','内容发布前的检查清单','官网更新日志'];
$attack = '<img src=x onerror=alert(1)> " &';
for ($i=1;$i<=53;$i++) {
    $status = ['published','draft','archived'][$i%3];
    $title = $i===53 ? $attack : $titles[($i-1)%count($titles)].' · '.$i;
    $insert->execute([$i%5===0?'page':'article',$title,'fixture-content-'.$i,$status,'[]','{}','2026-11-05T09:00:00+00:00','2026-11-05T09:30:00+00:00',$status==='published'?'2026-11-05T09:00:00+00:00':null]);
}
$controller = new AdminController($settings, new FileLogger(CMS_ROOT.'/fixture.log'), CMS_ROOT);
$_SERVER['REQUEST_URI']='/admin/content';
$_SESSION=['admin_user'=>['id'=>1,'email'=>'fixture@example.invalid','display_name'=>'隔离预览','capabilities'=>['*','admin.super']]];
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;echo '[PASS] '.$message.PHP_EOL;};
$xpath=static function(string $body):DOMXPath{$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$body);return new DOMXPath($dom);};
$before = hash('sha256',json_encode($pdo->query('SELECT * FROM cms_contents ORDER BY id')->fetchAll()));
$response=$controller->contentIndex(new Request('GET','/admin/content'));
$html=$response->body();$xp=$xpath($html);
$check($response->status()===200,'Native content page returns 200');
$check(str_contains($html,'admin-shell admin-starship'),'Shared authenticated shell uses the Starship theme');
$check($xp->query('//tr[@data-content-row]')->length===50,'Default native pagination remains bounded to 50 rows');
$check(str_contains($html,'共 53 条内容')&&str_contains($html,'第 1 / 2 页'),'Total count and current pagination remain truthful');
$check($xp->query('//form[starts-with(@action,"/admin/content/delete/")]')->length===50,'All existing delete actions remain POST forms');
$check($xp->query('//form[starts-with(@action,"/admin/content/delete/")][@method="post"]//input[@name="_csrf"]')->length===50,'Delete actions retain CSRF');
$check($xp->query('//form[starts-with(@action,"/admin/content/delete/")][contains(@onsubmit,"confirm(")]')->length===50,'Destructive confirmation remains present');
$check($xp->query('//a[@data-content-edit]')->length===50&&$xp->query('//a[@data-content-preview]')->length===50,'Native edit and preview links remain available without JS');
$check($xp->query('//*[@data-content-filters][@hidden]')->length===1,'Page-local filter UI is progressively enhanced');
$check(str_contains($html,'筛选仅作用于当前页'),'Filter scope explicitly says current page');
$check($xp->query('//dialog[@id="content-detail"][@aria-labelledby="content-detail-title"]')->length===1,'Details use one native accessible dialog');
$check($xp->query('//*[@data-content-detail][@hidden][@type="button"]')->length===50,'Details controls cannot submit or replace native actions');
$check(str_contains($html,View::escape($attack))&&!str_contains($html,$attack),'Untrusted titles remain escaped');
$check($xp->query('//tr[@data-content-row][1]//*[@data-content-title]')->item(0)->textContent===$attack,'Full title data survives safely for the drawer');
$check($xp->query('//tr[@data-content-row]//*[@data-content-updated]')->length===50,'Drawer and list reuse existing returned timestamps');
$check($xp->query('//div[@class="content-layout"]/div[@class="content-list-column"]')->length===1 && $xp->query('//div[@class="content-layout"]/dialog')->length===1,'Desktop list and details are sibling panels, not nested in the table');
$check($xp->query('//time[@data-content-updated][@datetime="2026-11-05T09:30:00+00:00"]')->length===50,'Readable dates retain the complete original timestamp');
$check($xp->query('//details[@class="content-row-menu"]//form[@method="post"]//input[@name="_csrf"]')->length===50,'More-actions disclosure retains protected delete forms');

$p2=$controller->contentIndex(new Request('GET','/admin/content',['page'=>2]))->body();
$check($xpath($p2)->query('//tr[@data-content-row]')->length===3,'Next page still renders only remaining records');
$max=$controller->contentIndex(new Request('GET','/admin/content',['per_page'=>10000]))->body();
$check($xpath($max)->query('//tr[@data-content-row]')->length===53&&str_contains($max,'每页 100 条'),'Existing 100-row cap remains enforced');
$_SESSION['admin_user']['capabilities']=[];
$limited=$controller->contentIndex(new Request('GET','/admin/content'))->body();
$check($xpath($limited)->query('//form[starts-with(@action,"/admin/content/delete/")]')->length===0,'Read-only rendering does not add unauthorized deletion');
$_SESSION['admin_user']['capabilities']=['*','admin.super'];
$check($before===hash('sha256',json_encode($pdo->query('SELECT * FROM cms_contents ORDER BY id')->fetchAll())),'Content listing and details preparation do not mutate records');
View::setBasePath('/cms');$_SERVER['REQUEST_URI']='/cms/admin/content';
$nested=View::page('子目录检查','<p>隔离</p>');
$check(str_contains($nested,'/cms/assets/admin/admin.css?v=20261105-admin-starship')&&str_contains($nested,'href="/cms/admin/content"'),'Shared theme assets and navigation preserve subdirectory paths');
View::setBasePath('');$_SERVER['REQUEST_URI']='/admin/login';
$login=View::page('登录','<p>登录</p>');
$check(!str_contains($login,'admin-starship')&&!str_contains($login,'/assets/admin/admin.css'),'Login and front pages remain outside the authenticated redesign');
$_SERVER['REQUEST_URI']='/admin/content';
if(($argv[1]??'')==='--preview'){
    $out=$argv[2];if(!is_dir($out))mkdir($out,0755,true);
    $standalone=static function(string $body)use($out):string{
        $body=preg_replace('~<link rel="stylesheet" href="[^"]*admin\.css[^"]*">~','<link rel="stylesheet" href="admin.css">',$body);
        $body=preg_replace('~<script src="[^"]*admin\.js[^"]*" defer></script>~','<script src="admin.js" defer></script>',$body);
        $body=str_replace('<section class="panel">','<section class="panel"><p class="preview-fixture-label">隔离开发预览 · 示例数据 · <a href="index.html">预览目录</a></p>',$body);
        return str_replace('</body>','<script>document.addEventListener("submit",e=>e.preventDefault());document.addEventListener("click",e=>{const a=e.target.closest("a");if(a&&!a.getAttribute("href")?.startsWith("#")&&a.getAttribute("href")!=="index.html")e.preventDefault()});</script></body>',$body);
    };
    // A separate safe display fixture keeps the default preview readable; escaping is tested above.
    $pdo->prepare("UPDATE cms_contents SET title=?,status='published',published_at='2026-11-05T09:00:00+00:00' WHERE id=53")->execute(['新手入门：搭建你的第一个网站']);
    $previewHtml=$controller->contentIndex(new Request('GET','/admin/content'))->body();
    file_put_contents($out.'/content.html',$standalone($previewHtml));
    copy(TEST_SOURCE_ROOT.'/public/assets/admin/admin.css',$out.'/admin.css');copy(TEST_SOURCE_ROOT.'/public/assets/admin/admin.js',$out.'/admin.js');
}
unset($_SESSION['admin_user']);
$check($controller->contentIndex(new Request('GET','/admin/content'))->status()===302,'Anonymous access still redirects to login');
echo "Starship admin PASS ($checks checks; isolated native rendering, no signing or production writes)\n";
