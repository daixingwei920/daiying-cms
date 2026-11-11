<?php
declare(strict_types=1);
define('CMS_ROOT',getenv('RESTAURANT_TEST_ROOT')?:dirname(__DIR__));
require CMS_ROOT.'/system/core/Bootstrap/autoload.php';
require CMS_ROOT.'/content/plugins/official.commerce/src/CommerceRepository.php';
foreach(['RestaurantRepository','RestaurantPrintService','RestaurantTicketService','RestaurantCheckoutService','RestaurantPosRepository','RestaurantPosGateway','RestaurantPosService','RestaurantPosController'] as $class){require CMS_ROOT.'/content/plugins/official.restaurant/src/'.$class.'.php';}
use Official\Restaurant\{RestaurantRepository,RestaurantPrintService,RestaurantTicketService,RestaurantCheckoutService,RestaurantPosRepository,RestaurantPosGateway,RestaurantPosService,RestaurantPosController};
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Http\Request;
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
session_start();$_SESSION['admin_user']=['id'=>7];
$count=0;$check=static function(bool $ok,string $message)use(&$count):void{if(!$ok){throw new RuntimeException($message);}++$count;echo '[PASS] '.$message.PHP_EOL;};
$reject=static function(callable $fn,string $message)use($check):void{try{$fn();}catch(Throwable){$check(true,$message);return;}$check(false,$message);};

foreach(['square','clover'] as $brand){
 $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 foreach(glob(CMS_ROOT.'/system/migrations/*payment*.php') as $file){(require $file)->up($pdo);}
 $pdo->exec('CREATE TABLE cms_plugins (id INTEGER PRIMARY KEY)');(require CMS_ROOT.'/system/migrations/2026_08_15_000001_plugin_public_contract_schema.php')->up($pdo);
 foreach(['official.commerce','official.restaurant'] as $plugin){foreach(glob(CMS_ROOT.'/content/plugins/'.$plugin.'/migrations/*.php') as $file){(require $file)['up']($pdo);}}
 $repo=new RestaurantRepository($pdo);$pos=new RestaurantPosRepository($pdo);$secrets=new PluginSecretStore($pdo,'ISOLATED SYMMETRIC TEST SECRET');
 $printer=new RestaurantPrintService($repo,null,$pos,hash('sha256','https://example.test'));$tickets=new RestaurantTicketService($repo,$printer);$commerce=new Daiying\Commerce\CommerceRepository($pdo,'isolated-pos-test');$payments=new PaymentRepository($pdo);$checkout=new RestaurantCheckoutService($repo,$tickets,$commerce,$pdo,'isolated-pos-test');
 $repo->saveStorefrontSettings(['name'=>'LOCAL FIXTURE NOT A MERCHANT','timezone'=>'America/New_York','settings'=>$repo->withTaxSettings(['status'=>'open','pickup_available'=>true,'delivery_available'=>true,'delivery_fee_minor'=>350,'delivery_postal_codes'=>['29204'],'merchant_region'=>'US','transaction_region'=>'international'],'10','no')]);
 $meta=$repo->withPaidAddonRows([],[['kind'=>'rice','price'=>'1.50']]);$addon=$meta['modifiers'][0]['options'][0]['id'];
 $item=$repo->saveMenuItem(['name'=>'宫保鸡 / Chicken / Pollo','currency'=>'USD','price'=>'10.00','metadata'=>$meta]);
 $calls=[];$orders=[];$next=0;$failCreate=false;$wrongTotal=false;
 $transport=static function($method,$url,$headers,$body)use(&$calls,&$orders,&$next,&$failCreate,&$wrongTotal,$brand):array{
  $path=parse_url($url,PHP_URL_PATH);$calls[]=[$method,$path,$body];$data=[];
  if(str_contains($path,'token')||str_ends_with($path,'/refresh')){$data=['access_token'=>'fixture-access','refresh_token'=>'fixture-refresh-rotated','merchant_id'=>'MERCHANT','expires_at'=>gmdate('c',time()+3600),'access_token_expiration'=>time()+3600,'refresh_token_expiration'=>time()+86400];}
  elseif($method==='GET'&&$path==='/v2/locations'){$data=['locations'=>[['id'=>'LOCATION','name'=>'FIXTURE STORE','status'=>'ACTIVE','currency'=>'USD']]];}
  elseif($path==='/v3/merchants/MERCHANT'){$data=['id'=>'MERCHANT','name'=>'FIXTURE STORE','currency'=>'USD'];}
  elseif($path==='/v2/catalog/list'){$data=['objects'=>[['id'=>'ITEM','item_data'=>['name'=>'Fixture dish','variations'=>[['id'=>'VARIATION','item_variation_data'=>['name'=>'Regular','pricing_type'=>'FIXED_PRICING','price_money'=>['amount'=>1000,'currency'=>'USD']]]]]]]];}
  elseif(str_ends_with($path,'/items')){$data=['elements'=>[['id'=>'VARIATION','name'=>'Fixture dish','price'=>1000]]];}
  elseif(str_ends_with($path,'/tenders')){$data=['elements'=>[['id'=>'EXTERNAL','label'=>'Website paid','editable'=>true,'enabled'=>true],['id'=>'CASH','label'=>'Cash','editable'=>true]]];}
  elseif(str_ends_with($path,'/tax_rates')){$data=['elements'=>[['id'=>'TAX','name'=>'Fixture tax','rate'=>1000000]]];}
  elseif(str_ends_with($path,'/order_types')){$data=['elements'=>[['id'=>'PICKUP','label'=>'Pickup']]];}
  elseif($method==='POST'&&($path==='/v2/orders'||$path==='/v3/merchants/MERCHANT/orders')){
   if($failCreate){throw new RuntimeException('SIMULATED TIMEOUT — NOT REAL VENDOR');}
   $id='REMOTE'.++$next;$o=$brand==='square'?$body['order']:$body;$o['id']=$id;$o['state']='OPEN';$o['total_money']=['amount'=>0,'currency'=>'USD'];$o['total']=0;$o['payments']=[];$o['tenders']=[];
   if($brand==='square'){$subtotal=0;foreach($o['line_items'] as $line){$subtotal+=$line['base_price_money']['amount']*(int)$line['quantity'];}$taxable=0;foreach($o['line_items'] as $line){if(!empty($line['applied_taxes'])){$taxable+=$line['base_price_money']['amount']*(int)$line['quantity'];}}$tax=isset($o['taxes'])?intdiv($taxable*1000+5000,10000):0;$o['total_money']['amount']=$subtotal+$tax;}
   if($brand==='clover'){unset($o['total_money']);}$orders[$id]=$o;$data=$brand==='square'?['order'=>$o]:$o;
  }
  elseif(str_ends_with($path,'/bulk_line_items')){$parts=explode('/',$path);$id=$parts[count($parts)-2];$total=0;foreach($body['items'] as $line){$total+=$line['price'];foreach($line['taxRates'] as $tax){$total+=$tax['taxAmount'];}}$orders[$id]['total']=$total;$orders[$id]['lineItems']=$body['items'];}
  elseif($method==='POST'&&($path==='/v2/payments'||str_ends_with($path,'/payments'))){$id=$body['order_id']??explode('/',$path)[5];$payment=$body+['id'=>'PAYMENT'.$id,'source_type'=>'EXTERNAL','status'=>'COMPLETED'];$orders[$id]['payments'][]=$payment;$orders[$id]['tenders'][]=['payment_id'=>$payment['id']];$data=$brand==='square'?['payment'=>$payment]:$payment;}
  elseif($method==='GET'&&str_starts_with($path,'/v2/payments/')){$id=substr(basename($path),7);$data=['payment'=>$orders[$id]['payments'][0]];}
  elseif($method==='GET'&&str_ends_with($path,'/payments')){$id=explode('/',$path)[5];$data=['elements'=>$orders[$id]['payments']];}
  elseif($method==='GET'&&str_ends_with($path,'/line_items')){$id=explode('/',$path)[5];$data=['elements'=>$orders[$id]['lineItems']];}
  elseif($method==='POST'&&preg_match('~/orders/REMOTE[0-9]+$~',$path)){$id=basename($path);$orders[$id]['total']=$body['total'];$orders[$id]['currency']=$body['currency'];$data=$orders[$id];}
  elseif($method==='GET'&&str_contains($path,'/orders/')){$id=basename($path);$o=$orders[$id];if($wrongTotal){if($brand==='square'){$o['total_money']['amount']++;}else{$o['total']++;}}$data=$brand==='square'?['order'=>$o]:$o;}
  elseif(str_ends_with($path,'/pay')||str_ends_with($path,'/print_event')){$data=['id'=>'ACK'];}
  else{throw new RuntimeException('Uncovered fixture route: '.$method.' '.$path);}
  return ['status'=>200,'data'=>$data];
 };
 $service=new RestaurantPosService($pos,$repo,$secrets,'https://example.test',[$checkout,'orderReceiptForCart'],$transport);
 (require CMS_ROOT.'/system/migrations/2026_09_08_000005_core_scheduler_foundation.php')->up($pdo);
 $scheduler=new Cms\Core\Scheduler\SchedulerService($pdo);
 $context=new Cms\Core\Plugin\PluginContext(Cms\Core\Plugin\PluginManifest::fromArray(json_decode(file_get_contents(CMS_ROOT.'/content/plugins/official.restaurant/plugin.json'),true)),new Cms\Core\Events\EventDispatcher(),new Cms\Core\Plugin\BlockRegistry(),new Cms\Core\Plugin\PluginDataStore($pdo,'official.restaurant'),$pdo,scheduler:$scheduler);
 $service->registerSchedule($context);$task=$scheduler->find('restaurant.pos_sync');
 $check($task['owner']==='official.restaurant'&&(int)$task['interval_seconds']===60,$brand.': native public scheduler registers real recurring handler');
 $pdo->exec("UPDATE cms_core_scheduled_tasks SET next_run_at='2000-01-01T00:00:00+00:00'");$scheduled=$scheduler->runDue();$check($scheduled['succeeded']===1&&$scheduled['missing']===0,$brand.': native scheduler actually invokes registered POS service');
 $controller=new RestaurantPosController($service,$repo,'/admin.css');
 $check(str_contains($controller->index(new Request('GET','/'))->body(),'等待管理员开通'),$brand.': unconfigured owner sees accurate setup state');
 $reject(fn()=>$service->configure($brand,'production','bad<script>',7),$brand.': invalid application ID rejected');
 $reject(fn()=>$service->configure($brand,'production','VALID_APP',7),$brand.': production configuration rejected server-side');$reject(fn()=>new RestaurantPosGateway($brand,'production','VALID_APP','https://example.test/callback',$transport),$brand.': production gateway rejected before any network request');
 $service->configure($brand,'sandbox','FIXTURE_APP',7);$pending=[];$url=$service->begin($brand,7,session_id(),$pending);parse_str(parse_url($url,PHP_URL_QUERY),$q);
 $check($q['code_challenge_method']==='S256'&&strlen($q['state'])===64,$brand.': real authorization URL has PKCE and unpredictable state');
 $bad=$pending;$reject(fn()=>$service->callback($brand,['code'=>'FIXTURE','state'=>'wrong'],7,session_id(),$bad),$brand.': wrong state rejected');
 $bad=$pending;$reject(fn()=>$service->callback($brand,['code'=>'FIXTURE','state'=>$q['state']],8,session_id(),$bad),$brand.': other admin cannot consume authorization');
 $bad=$pending;$reject(fn()=>$service->callback($brand,['code'=>'FIXTURE','state'=>$q['state']],7,'different-browser',$bad),$brand.': different browser cannot consume authorization');
 $query=['code'=>'FIXTURE','state'=>$q['state'],'merchant_id'=>'MERCHANT'];if($brand==='clover'){unset($query['state']);}$id=$service->callback($brand,$query,7,session_id(),$pending);
 $reject(fn()=>$service->callback($brand,$query,7,session_id(),$pending),$brand.': callback replay rejected');
 $check($pos->connection($id)['status']==='connected',$brand.': callback persisted a fixture merchant, not live acceptance');
 $check(!str_contains(implode(' ', $pdo->query('SELECT ciphertext FROM cms_plugin_secrets')->fetchAll(PDO::FETCH_COLUMN)),'fixture-access'),$brand.': token is encrypted by native secret store');
 $reject(fn()=>$service->selectLocation($id,'FOREIGN',7),$brand.': foreign merchant location rejected');
 $service->selectLocation($id,$brand==='square'?'LOCATION':'MERCHANT',7);
 $reject(fn()=>$service->saveMapping($id,[$item=>'FORGED'],[],7),$brand.': forged catalog mapping rejected');
 $settings=['print_mode'=>'pos','tender_id'=>'EXTERNAL','tax_id'=>'TAX','pickup_order_type'=>'PICKUP','delivery_order_type'=>'PICKUP'];
 $choice=$brand==='square'?'Fixture dish · Regular · $10.00 · 1':'Fixture dish · $10.00 · 1';$saved=$controller->action(new Request('POST','/',[],['action'=>'mapping','id'=>(string)$id,'mapping_label'=>[(string)$item=>$choice]]+$settings));$check($saved->status()===302&&$pos->connection($id)['mapping'][(string)$item]==='VARIATION',$brand.': searchable human dish names save through actual controller');
 $mappingHtml=$controller->index(new Request('GET','/'))->body();$check(substr_count($mappingHtml,'<datalist ')===1&&str_contains($mappingHtml,'name="mapping_label['),$brand.': one shared searchable catalog avoids repeating all POS options for every dish');
 $reject(fn()=>$service->activate($id,7,true),$brand.': cannot activate before received-test confirmation');
 $probe=$service->probe($id,7);$result=$service->run();$check($pos->job($probe)['status']==='delivered',$brand.': zero-dollar fixture order completed through real adapter');
 $check(count(array_filter($calls,fn($r)=>$r[0]==='POST'&&str_ends_with($r[1],'/payments')))===0,$brand.': test order never records a positive payment');
 $service->confirm($id,7);$reject(fn()=>$service->activate($id,7),$brand.': test-site declaration is required');$pdo->exec('UPDATE restaurant_pos_connections SET worker_seen_at=0');$reject(fn()=>$service->activate($id,7,true),$brand.': cannot enable automatic orders before worker is running');$service->run();$service->activate($id,7,true);
 $check(!$pos->isPrimaryPrinterForOrder(1,hash('sha256','https://example.test'))&&!$pos->isPrimaryPrinterForOrder(1,hash('sha256','https://other.test')),$brand.': sandbox POS never replaces website printing');
 $create=static function(bool $paid=true,bool $extraDelivery=false)use($repo,$item,$addon,$checkout,$commerce,$payments):array{$cart=$repo->createCart(['currency'=>'USD']);$repo->addCartItem($cart['token'],$item,2,$extraDelivery?[['group_id'=>'paid_addons','option_id'=>$addon]]:[],'不要葱 / No onion / Sin cebolla');$h=$checkout->prepareCommerceOrder($cart['token'],'test.provider',['service_mode'=>$extraDelivery?'delivery':'pickup','customer_name'=>'LOCAL TEST','customer_phone'=>'+1 555 0100','delivery_address'=>$extraDelivery?'123 Example Street':'','delivery_postal_code'=>$extraDelivery?'29204':'']);$order=(int)$h['order']['id'];if($paid){$commerce->markOrderPaid($order);$payment=$payments->insertPayment(['subject_type'=>'commerce_order','subject_id'=>'order:'.$order,'provider_id'=>'test.provider','remote_id'=>'fixture-'.$order,'reference'=>'fixture-'.$order,'status'=>'paid','amount_minor'=>(int)$h['order']['amount_minor'],'currency'=>'USD','idempotency_key'=>'fixture-'.$order,'request_hash'=>hash('sha256','fixture-'.$order)]);$commerce->attachPayment($order,$payment);$checkout->reconcileOrder($order);}return [$order,$cart];};
 [$unpaid]=$create(false);$before=count($orders);$service->run();$check(count($orders)===$before,$brand.': unconfirmed payment never reaches vendor');
 [$paid,$cart]=$create();$result=$service->run();$job=$pos->jobs($id)[0];$check($job['commerce_order_id']==$paid&&$job['status']==='delivered',$brand.': authoritative Core payment plus restaurant cart drives external paid bookkeeping');
 $check($job['snapshot']['total_minor']===2200&&$job['snapshot']['tax_minor']===200&&str_contains($job['snapshot']['items'][0]['note'],'Sin cebolla'),$brand.': frozen price, tax and multilingual dish note preserved');
 $remote=$orders[$job['remote_order_id']];$line=$brand==='square'?$remote['line_items'][0]:array_values(array_filter($calls,fn($r)=>str_ends_with($r[1],'/bulk_line_items')))[1][2]['items'][0];
 $check(($brand==='square'?$line['catalog_object_id']:$line['item']['id'])==='VARIATION',$brand.': saved menu correspondence reaches vendor payload');
 $before=count($orders);$service->run();$check(count($orders)===$before,$brand.': repeated reconciliation cannot create a duplicate order');
 $check(count($repo->printJobs())>0,$brand.': sandbox POS test mode preserves existing website print jobs');
 [$cancelled]=$create();$repo->updateTicketStatus((int)$repo->ticketIdForCommerceOrder($cancelled),'cancelled');$before=count($orders);$service->run();$check(count($orders)===$before,$brand.': cancelled kitchen order is not sent');
 [$refunded]=$create();$pid=$pdo->query('SELECT payment_id FROM commerce_orders WHERE id='.(int)$refunded)->fetchColumn();$payments->insertRefund(['payment_id'=>(int)$pid,'provider_id'=>'test.provider','remote_id'=>'refund-'.$refunded,'status'=>'completed','amount_minor'=>100,'currency'=>'USD','reason'=>'ISOLATED TEST REFUND LEDGER ONLY','idempotency_key'=>'refund-'.$refunded,'request_hash'=>hash('sha256','refund-'.$refunded)]);$before=count($orders);$service->run();$check(count($orders)===$before,$brand.': partial refund prevents dispatch without issuing any refund API request');
 [$delivery]=$create(true,true);$service->run();$deliveryJob=$pos->jobs($id)[0];$check($deliveryJob['status']==='delivered'&&$deliveryJob['snapshot']['total_minor']===2880&&$deliveryJob['snapshot']['items'][0]['unit_price_minor']===1150&&$deliveryJob['snapshot']['items'][1]['unit_price_minor']===350&&$deliveryJob['snapshot']['service_mode']==='delivery',$brand.': priced extra rice, untaxed delivery fee and trusted 28.80 total reach POS together');
 $failCreate=true;[$uncertain]=$create();$service->run();$uncertainJob=$pos->jobs($id)[0];$check($uncertainJob['status']==='needs_review',$brand.': mutation timeout held for human review');$before=count($calls);$service->run();$check(count($calls)===$before,$brand.': uncertain mutation is never blindly retried');$failCreate=false;
 $wrongTotal=true;[$mismatch]=$create();$before=count(array_filter($calls,fn($r)=>str_ends_with($r[1],'/payments')&&$r[0]==='POST'));$service->run();$job=$pos->jobs($id)[0];$check($job['status']==='needs_review'&&$job['remote_order_id']!==''&&count(array_filter($calls,fn($r)=>str_ends_with($r[1],'/payments')&&$r[0]==='POST'))===$before,$brand.': total mismatch stops bookkeeping and retains remote ID');$wrongTotal=false;
 $lease=$pos->acquire($id);$reject(fn()=>$service->pause($id,7),$brand.': concurrent disconnect/pause cannot change in-flight account');$pos->release($id,$lease);
 $tokens=json_decode($secrets->get('official.restaurant','pos_tokens_'.$id),true);$tokens['expires_at']=time()-1;$secrets->set('official.restaurant','pos_tokens_'.$id,json_encode($tokens));$service->selectLocation($id,$brand==='square'?'LOCATION':'MERCHANT',7);
 $check(json_decode($secrets->get('official.restaurant','pos_tokens_'.$id),true)['expires_at']>time(),$brand.': expired access token rotates through encrypted storage');
 $settings['print_mode']='website';$service->saveMapping($id,[$item=>'VARIATION'],$settings,7);$printCalls=count(array_filter($calls,fn($r)=>str_ends_with($r[1],'/print_event')));$service->probe($id,7);$service->run();$service->confirm($id,7);$service->activate($id,7,true);[$websitePrinted]=$create();$service->run();$check($pos->jobs($id)[0]['status']==='delivered'&&count($repo->printJobs())>0&&count(array_filter($calls,fn($r)=>str_ends_with($r[1],'/print_event')))===$printCalls,$brand.': website printing mode retains bridge queue and does not request POS printing');
 $beforeRows=$pdo->query('SELECT * FROM restaurant_carts ORDER BY id')->fetchAll();(require CMS_ROOT.'/content/plugins/official.restaurant/migrations/005_pos_connections.php')['up']($pdo);$check($beforeRows===$pdo->query('SELECT * FROM restaurant_carts ORDER BY id')->fetchAll(),$brand.': POS migration is repeatable and preserves existing restaurant carts');
 $html=$controller->index(new Request('GET','/'))->body();$check(!str_contains($html,'fixture-access')&&!str_contains($html,'fixture-refresh')&&str_contains($html,'name="_csrf"'),$brand.': owner page excludes secrets and posts include CSRF fields');
 $otherSite=new RestaurantPosService($pos,$repo,$secrets,'https://other.test',[$checkout,'orderReceiptForCart'],$transport);$reject(fn()=>$otherSite->selectLocation($id,$brand==='square'?'LOCATION':'MERCHANT',7),$brand.': copied credentials cannot operate another site');
 $service->pause($id,7,true);$check($pos->connection($id)['status']==='disconnected'&&json_decode($secrets->get('official.restaurant','pos_tokens_'.$id),true)===[],$brand.': disconnect removes only this connection credentials');
}
echo 'POS isolated software tests: '.$count.' assertions passed. No vendor sandbox or physical POS was used.'.PHP_EOL;
