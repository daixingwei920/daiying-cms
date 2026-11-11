<?php
declare(strict_types=1);
define('CMS_ROOT',getenv('RESTAURANT_TEST_ROOT')?:dirname(__DIR__));
require CMS_ROOT.'/system/core/Bootstrap/autoload.php';
foreach(['RestaurantRepository','RestaurantPrintService','RestaurantTicketService','RestaurantController'] as $class){require CMS_ROOT.'/content/plugins/official.restaurant/src/'.$class.'.php';}
use Cms\Core\Http\Request;
use Official\Restaurant\RestaurantRepository;
use Official\Restaurant\RestaurantPrintService;
use Official\Restaurant\RestaurantTicketService;
use Official\Restaurant\RestaurantController;
session_start();
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(glob(CMS_ROOT.'/content/plugins/official.restaurant/migrations/*.php') as $file){(require $file)['up']($pdo);}
$repo=new RestaurantRepository($pdo);$printer=new RestaurantPrintService($repo);
$controller=new RestaurantController($repo,new RestaurantTicketService($repo,$printer),$printer,null,null,null,'',false,'/assets/admin.css','/assets/admin.js');
$count=0;$check=static function(bool $condition,string $message)use(&$count):void{if(!$condition){throw new RuntimeException($message);}echo '[PASS] '.$message.PHP_EOL;$count++;};
$settings=['status'=>'open','default_locale'=>'en-US','merchant_region'=>'US','transaction_region'=>'international','tax_rate_basis_points'=>1000,'delivery_taxable'=>false,'pickup_available'=>true,'custom_future'=>['preserve'=>true],'headline'=>['en-US'=>'Hello','fr-FR'=>'Bonjour'],'about_body'=>'Legacy body','gallery'=>[['url'=>'/media/store.jpg','alt'=>['en-US'=>'Store','fr-FR'=>'Restaurant'],'custom'=>'keep']],'hours'=>['mon'=>[['open'=>'11:00','close'=>'21:00','extra'=>'keep']]],'desktop_focal_point'=>'48% 35%','mobile_focal_point'=>'42% 30%'];
$repo->saveStorefrontSettings(['name'=>'ISOLATED ADMIN TEST','phone'=>'555-0100','address'=>'LOCAL EXAMPLE','timezone'=>'America/New_York','settings'=>$settings]);
$html=$controller->storefrontSettings()->body();
$check(!str_contains($html,'name="settings_json"')&&!str_contains($html,'JSON'),'Storefront form has no raw JSON editor or engineering instructions.');
$check(str_contains($html,'name="_csrf"')&&str_contains($html,'/admin/restaurant/storefront/save'),'Existing CSRF form and native save route are retained.');
$check(str_contains($html,'name="delivery_fee"')&&str_contains($html,'data-add-period="mon"')&&str_contains($html,'data-add-gallery'),'Delivery amounts, split hours and photo rows have direct controls.');
$check(str_contains($html,'尚未开放')&&!str_contains($html,'连接成功'),'POS plans never claim a working connection.');
$body=['settings_form_present'=>'1','name'=>'ISOLATED ADMIN TEST','phone'=>'555-0100','address'=>'LOCAL EXAMPLE','timezone'=>'America/New_York','default_locale_present'=>'1','default_locale'=>'es-US','tax_fields_present'=>'1','tax_rate'=>'10.00','delivery_taxable'=>'no','store_status'=>'open','pickup_available'=>'1','delivery_available'=>'1','delivery_fee'=>'3.50','delivery_postal_codes'=>"29204,29206\n29204",'preorder_available'=>'1','preorder_lead_minutes'=>'45','preorder_max_days'=>'10','hours_enabled'=>['mon'=>'1'],'hours'=>['mon'=>[['open'=>'11:00','close'=>'14:30'],['open'=>'17:00','close'=>'22:00']]],'merchant_region'=>'US','transaction_region'=>'international','storefront_image_url'=>'/media/storefront.jpg','logo_url'=>'https://example.invalid/logo.svg','desktop_focus_x'=>'48','desktop_focus_y'=>'35','mobile_focus_x'=>'30','mobile_focus_y'=>'25','localized'=>['headline'=>['en-US'=>'Hello','zh-CN'=>'你好','es-US'=>'Hola'],'about_body'=>['en-US'=>'Legacy body','zh-CN'=>'Legacy body','es-US'=>'Legacy body']],'gallery'=>[['url'=>'/media/store.jpg','alt'=>['en-US'=>'Store','zh-CN'=>'','es-US'=>'']]]];
$response=$controller->saveStorefrontSettings(new Request('POST','/admin/restaurant/storefront/save',[],$body));
$check($response->status()===302,'Structured form saves through the actual controller.');
$stored=json_decode($repo->storefrontSettings()['settings_json'],true);
$check($stored['default_locale']==='es-US'&&$repo->storefrontPayload()['locale']==='es-US','Preferred language updates the public storefront.');
$check($stored['delivery_fee_minor']===350&&$stored['delivery_postal_codes']===['29204','29206'],'Display amount converts exactly to cents and postal codes deduplicate.');
$check(count($stored['hours']['mon'])===2&&$stored['hours']['mon'][0]['extra']==='keep','Lunch/dinner periods save without losing extra period properties.');
$check($stored['custom_future']===$settings['custom_future']&&$stored['headline']['fr-FR']==='Bonjour','Unknown future settings and other language content survive.');
$check($stored['about_body']==='Legacy body'&&$stored['gallery'][0]['custom']==='keep'&&$stored['gallery'][0]['alt']['fr-FR']==='Restaurant','Unedited scalar text and existing photo metadata survive.');
$check($stored['desktop_focal_point']==='48% 35%'&&$stored['mobile_focal_point']==='30% 25%','Desktop focus preserves existing value and mobile focus changes independently.');
$check($stored['tax_rate_basis_points']===1000&&$stored['delivery_taxable']===false,'Existing tax service contract is reused.');
$badCases=[['delivery_fee'=>'-1'],['delivery_fee'=>'3.509'],['delivery_postal_codes'=>''],['timezone'=>'Invalid/Zone'],['merchant_region'=>'<script>'],['transaction_region'=>'unknown'],['store_status'=>'invalid'],['mobile_focus_x'=>'101'],['storefront_image_url'=>'javascript:alert(1)'],['logo_url'=>'//tracker.example/a.jpg'],['preorder_max_days'=>'0'],['hours_enabled'=>['mon'=>'1'],'hours'=>['mon'=>[['open'=>'25:00','close'=>'22:00']]]],['hours_enabled'=>[]],['localized'=>['headline'=>'not-an-array']],['gallery'=>[['url'=>'javascript:alert(1)']]],['default_locale'=>'fr-FR']];
foreach($badCases as $index=>$bad){$before=$repo->storefrontSettings();$result=$controller->saveStorefrontSettings(new Request('POST','/admin/restaurant/storefront/save',[],array_replace($body,$bad)));$check($result->status()===400&&$repo->storefrontSettings()===$before,'Invalid structured input rejected without writes: '.$index);}
$item=$repo->saveMenuItem(['name'=>'Soup','category'=>'Soups','price'=>'4.00','currency'=>'USD','metadata'=>['custom'=>'preserve','modifiers'=>['size'=>['small'=>0,'large'=>150]]]]);
$menuBody=['id'=>(string)$item,'menu_form_present'=>'1','name'=>'Soup','category'=>'Soups','price'=>'4.50','currency'=>'USD','menu_section'=>'all_day','image_url'=>'/media/soup.jpg','featured'=>'1','featured_order'=>'2'];
$result=$controller->saveMenuItem(new Request('POST','/admin/restaurant/menu/save',[],$menuBody));
$metadata=json_decode($repo->menuItem($item)['modifiers_json'],true);
$check($result->status()===302&&$metadata['custom']==='preserve'&&isset($metadata['modifiers']['size']),'Menu edits preserve existing specifications without JSON input.');
$check($metadata['featured']===true&&$metadata['image_url']==='/media/soup.jpg','Photo and House Favorites controls save.');
$menu=$controller->menu(new Request('GET','/admin/restaurant/menu',['edit'=>(string)$item]))->body();
$check(!str_contains($menu,'name="metadata_json"')&&str_contains($menu,'list="restaurant-categories"'),'Menu form has category suggestions and no raw metadata editor.');
echo 'Restaurant admin settings: '.$count.' assertions passed.'.PHP_EOL;
