<?php
declare(strict_types=1);
namespace Official\Restaurant;

use Cms\Core\Plugin\PluginSecretStore;

/** No card charge/refund API is used here. Payments are verified by the existing checkout. */
final class RestaurantPosService
{
    private readonly \Closure $receipt;
    private readonly ?\Closure $transport;
    public readonly string $siteHash;
    public function __construct(public readonly RestaurantPosRepository $pos,private readonly RestaurantRepository $restaurant,private readonly PluginSecretStore $secrets,private readonly string $siteUrl,callable $receipt,?callable $transport=null)
    {
        $this->receipt=\Closure::fromCallable($receipt);$this->transport=$transport===null?null:\Closure::fromCallable($transport);$this->siteHash=hash('sha256',rtrim($siteUrl,'/'));
    }
    private function read(string $key):array{return json_decode($this->secrets->get('official.restaurant',$key)??'[]',true,512,JSON_THROW_ON_ERROR)?:[];}
    public function registerSchedule(\Cms\Core\Plugin\PluginContext $context):void
    {
        $context->registerScheduledTask('restaurant.pos_sync',60,function(array $payload):void{$this->run(10);});
    }
    private function write(string $key,array $value):void{$this->secrets->set('official.restaurant',$key,json_encode($value,JSON_THROW_ON_ERROR));}
    public function app(string $brand):array{if(!in_array($brand,['square','clover'],true)){throw new \InvalidArgumentException('请选择 Square 或 Clover。');}return $this->read('pos_app_'.$brand);}
    public function callbackUrl(string $brand):string{return rtrim($this->siteUrl,'/').'/admin/restaurant/pos/'.$brand.'/callback';}
    public function configure(string $brand,string $environment,string $appId,int $actor):void
    {
        $this->app($brand);
        if($environment!=='sandbox'||!preg_match('/^[A-Za-z0-9_-]{3,128}$/',$appId)){throw new \InvalidArgumentException('请核对应用编号和测试或正式环境。');}
        $url=parse_url($this->siteUrl);$loopback=in_array($url['host']??'',['127.0.0.1','localhost','::1'],true);
        if(($url['scheme']??'')!=='https'&&!($environment==='sandbox'&&$loopback)){throw new \RuntimeException('请先为网站配置安全网址。');}
        if(isset($url['user'])||isset($url['query'])||isset($url['fragment'])||!empty($url['path'])&&$url['path']!=='/'){throw new \RuntimeException('请使用网站主域名配置连接。');}
        foreach($this->pos->connections() as $c){if($c['brand']===$brand){$lease=$this->pos->acquire((int)$c['id']);try{$this->pos->update((int)$c['id'],['auto_send'=>0,'status'=>'disconnected','revision'=>(int)$c['revision']+1,'probe_confirmed'=>0]);$this->write('pos_tokens_'.$c['id'],[]);}finally{$this->pos->release((int)$c['id'],$lease);}}}
        $this->write('pos_app_'.$brand,['app_id'=>$appId,'environment'=>$environment]);$this->pos->audit(null,null,$actor,'application_configured',$brand);
    }
    private function gateway(string $brand,array $app):RestaurantPosGateway{return new RestaurantPosGateway($brand,$app['environment'],$app['app_id'],$this->callbackUrl($brand),$this->transport);}
    public function begin(string $brand,int $actor,string $session,array &$pending):string
    {
        $app=$this->app($brand);if(!$app||($app['environment']??'')!=='sandbox'||$actor<=0||$session===''){throw new \RuntimeException('请管理员先开通连接，之后登录收银账号即可。');}
        $state=bin2hex(random_bytes(32));$verifier=bin2hex(random_bytes(32));
        $this->pos->addState($state,$brand,$app['environment'],$actor,hash('sha256',$session),hash('sha256',json_encode($app)));
        $pending[$brand]=['state'=>$state,'verifier'=>$verifier,'at'=>time()];return $this->gateway($brand,$app)->authorize($state,$verifier);
    }
    public function callback(string $brand,array $query,int $actor,string $session,array &$pending):int
    {
        $app=$this->app($brand);$p=$pending[$brand]??[];unset($pending[$brand]);
        $state=(string)($query['state']??($brand==='clover'?($p['state']??''):''));
        if(!$app||($app['environment']??'')!=='sandbox'||!isset($p['state'],$p['verifier'])||$state===''||!hash_equals($p['state'],$state)||!is_string($query['code']??null)||strlen($query['code'])>2048||$query['code']===''){throw new \RuntimeException('连接未获授权，请重新连接。');}
        $this->pos->consumeState($state,$brand,$app['environment'],$actor,hash('sha256',$session),hash('sha256',json_encode($app)));
        $c=$this->pos->create($brand,$app['environment'],$this->siteHash);$id=(int)$c['id'];$lease=$this->pos->acquire($id);
        try{
            // Invalidate old credentials before exchanging a one-use authorization code.
            $this->pos->update($id,['auto_send'=>0,'status'=>'disconnected','revision'=>(int)$c['revision']+1,'probe_confirmed'=>0]);$this->write('pos_tokens_'.$id,[]);
            $gateway=$this->gateway($brand,$app);$tokens=$gateway->exchange($query['code'],$p['verifier'],(string)($query['merchant_id']??''));
            $this->write('pos_tokens_'.$id,$tokens);$locations=$gateway->locations($tokens);
            $this->pos->update($id,['site_hash'=>$this->siteHash,'status'=>'connected','account_id'=>$tokens['account_id'],'locations'=>$locations,'location_id'=>'','location_name'=>'','mapping'=>[],'catalog'=>[],'settings'=>[],'last_error'=>'']);
            $this->pos->audit($id,null,$actor,'connected');return $id;
        }finally{$this->pos->release($id,$lease);}
    }
    private function connected(int $id):array
    {
        $c=$this->pos->connection($id);if(!$c||$c['environment']!=='sandbox'||!hash_equals($this->siteHash,$c['site_hash'])||$c['status']!=='connected'){throw new \RuntimeException('请先重新连接这家门店。');}
        $app=$this->app($c['brand']);if(($app['environment']??'')!==$c['environment']){throw new \RuntimeException('连接环境已改变，请重新连接。');}return $c;
    }
    private function access(array $c):array
    {
        $tokens=$this->read('pos_tokens_'.$c['id']);if(($tokens['account_id']??'')!==$c['account_id']){throw new RestaurantPosException('account_mismatch');}
        $gateway=$this->gateway($c['brand'],$this->app($c['brand']));
        if((int)($tokens['expires_at']??0)<=time()+120){
            // Refresh tokens may be single-use: invalidate locally before sending, and never retry an uncertain refresh.
            $this->write('pos_tokens_'.$c['id'],[]);
            try{$new=$gateway->refresh($tokens);if($new['account_id']!==$c['account_id']){throw new RestaurantPosException('account_mismatch');}$this->write('pos_tokens_'.$c['id'],$new);$tokens=$new;}
            catch(\Throwable $e){$this->pos->update((int)$c['id'],['auto_send'=>0,'status'=>'disconnected','last_error'=>'reauthorize']);throw $e;}
        }
        return [$gateway,$tokens];
    }
    private function locked(int $id,callable $fn):mixed{$lease=$this->pos->acquire($id);try{return $fn($this->connected($id));}finally{$this->pos->release($id,$lease);}}
    public function selectLocation(int $id,string $location,int $actor):void
    {
        $this->locked($id,function($c)use($id,$location,$actor){[$g,$t]=$this->access($c);$locations=$g->locations($t);$selected=null;foreach($locations as $v){if($v['id']===$location){$selected=$v;}}
            if(!$selected||$selected['currency']!=='USD'){throw new \RuntimeException('请选择账号内以美元结算的门店。');}
            $catalog=$g->catalog($t,$location);$options=$g->options($t,$location);
            $this->pos->update($id,['revision'=>(int)$c['revision']+1,'locations'=>$locations,'location_id'=>$location,'location_name'=>$selected['name'],'currency'=>'USD','catalog'=>$catalog,'mapping'=>[],'settings'=>['options'=>$options,'print_mode'=>'website'],'auto_send'=>0,'probe_confirmed'=>0,'probe_job_id'=>null]);$this->pos->audit($id,null,$actor,'location_selected');
        });
    }
    public function saveMapping(int $id,array $mapping,array $settings,int $actor):void
    {
        $this->locked($id,function($c)use($id,$mapping,$settings,$actor){if($c['location_id']===''){throw new \RuntimeException('请先选择门店。');}
            [$g,$t]=$this->access($c);$catalog=$g->catalog($t,$c['location_id']);$options=$g->options($t,$c['location_id']);$valid=array_column($catalog,null,'id');$clean=[];
            foreach($this->restaurant->menuItems() as $item){$key=(string)$item['id'];$target=(string)($mapping[$key]??'');if($target===''){continue;}if(!isset($valid[$target])||!in_array($valid[$target]['currency'],['','USD'],true)){throw new \RuntimeException('有菜品已从收银菜单移除，请重新选择。');}$clean[$key]=$target;}
            $mode=(string)($settings['print_mode']??'website');if(!in_array($mode,['website','pos'],true)){throw new \RuntimeException('请选择出单方式。');}$cleanSettings=['print_mode'=>$mode,'options'=>$options];
            if($c['brand']==='square'){foreach(['prep_minutes'=>30,'delivery_minutes'=>45] as $field=>$default){$minutes=filter_var($settings[$field]??$default,FILTER_VALIDATE_INT);if($minutes===false||$minutes<1||$minutes>360){throw new \RuntimeException('预计备餐或送达时间须为1至360分钟。');}$cleanSettings[$field]=$minutes;}}
            if($c['brand']==='clover'){foreach(['tender_id'=>'tenders','tax_id'=>'tax_rates','pickup_order_type'=>'order_types','delivery_order_type'=>'order_types'] as $field=>$list){$value=(string)($settings[$field]??'');if($value!==''&&!in_array($value,array_column($options[$list],'id'),true)){throw new \RuntimeException('收银选项已改变，请重新选择。');}$cleanSettings[$field]=$value;}if($cleanSettings['tender_id']===''){throw new \RuntimeException('请在 Clover 开通并选择网站已付款的外部收款方式。');}}
            $this->pos->update($id,['revision'=>(int)$c['revision']+1,'catalog'=>$catalog,'mapping'=>$clean,'settings'=>$cleanSettings,'auto_send'=>0,'probe_confirmed'=>0,'probe_job_id'=>null]);$this->pos->audit($id,null,$actor,'mapping_saved');
        });
    }
    private function snapshot(array $c,int $order):array
    {
        $cart=$this->restaurant->cartByCommerceOrder($order);$receipt=$cart?($this->receipt)((string)$cart['token']):null;
        if(!$cart||($receipt['payment_status']??'')!=='paid'||in_array($receipt['ticket_status']??'',['cancelled','completed'],true)){throw new RestaurantPosException('payment_not_confirmed');}
        $summary=$this->restaurant->cartSummary((string)$cart['token']);$payload=json_decode($cart['payload_json']??'{}',true)?:[];$items=[];$subtotal=0;
        foreach($summary['items'] as $item){$target=$c['mapping'][(string)$item['menu_item_id']]??'';if($target===''){throw new RestaurantPosException('mapping_missing');}$unit=(int)$item['unit_price_minor']+(int)$item['modifiers_total_minor'];$qty=(int)$item['quantity'];if($unit<0||$qty<1||$unit*$qty!==(int)$item['line_total_minor']){throw new RestaurantPosException('amount_mismatch');}$subtotal+=$unit*$qty;
            $items[]=['name'=>(string)$item['item_name'],'quantity'=>$qty,'unit_price_minor'=>$unit,'catalog_id'=>$target,'note'=>mb_substr((string)$item['notes'].' · '.implode(' / ',array_map(static fn($m)=>(string)($m['name']??''),$item['modifiers'])),0,1000),'taxable'=>true];}
        if(array_sum(array_column($items,'quantity'))>250){throw new RestaurantPosException('order_too_large');}
        if($items===[]||(int)$summary['total_minor']<=0||$subtotal!==(int)$summary['subtotal_minor']||$summary['currency']!=='USD'||$subtotal+(int)$summary['fee_minor']+(int)$summary['tax_minor']!==(int)$summary['total_minor']){throw new RestaurantPosException('amount_mismatch');}
        if((int)$summary['fee_minor']>0){$items[]=['name'=>'配送费 / Delivery','quantity'=>1,'unit_price_minor'=>(int)$summary['fee_minor'],'catalog_id'=>'','note'=>'','taxable'=>(bool)$summary['tax']['delivery_taxable']];}
        if($c['brand']==='clover'&&$summary['tax_minor']>0&&empty($c['settings']['tax_id'])){throw new RestaurantPosException('tax_mapping_missing');}
        return ['is_test'=>false,'order_number'=>(string)$order,'note'=>'网站已付款。请勿再次收费。','items'=>$items,'currency'=>'USD','total_minor'=>(int)$summary['total_minor'],'tax_minor'=>(int)$summary['tax_minor'],'tax_rate_basis_points'=>(int)$summary['tax']['rate_basis_points'],'service_mode'=>$cart['service_mode'],'customer_name'=>(string)($cart['customer_name']??''),'customer_phone'=>(string)($cart['customer_phone']??''),'delivery_address'=>(string)($cart['delivery_address']??''),'delivery_postal_code'=>(string)($payload['delivery_postal_code']??''),'due_at'=>empty($payload['fulfillment_at'])?'':gmdate('Y-m-d\TH:i:s\Z',strtotime($payload['fulfillment_at'].' UTC'))];
    }
    public function probe(int $id,int $actor):int
    {
        return $this->locked($id,function($c)use($id,$actor){if($c['location_id']===''){throw new \RuntimeException('请先选择门店并保存菜品对应。');}
            if($c['probe_job_id']){throw new \RuntimeException('测试单已发送或待核实，请先查看收银机。重新保存设置后才能再测试。');}
            $snapshot=['is_test'=>true,'order_number'=>'TEST','note'=>'TEST ONLY / 不出餐 / SOLO PRUEBA','items'=>[['name'=>'TEST ONLY 不出餐','quantity'=>1,'unit_price_minor'=>0,'catalog_id'=>'','note'=>'米饭 / Rice / Arroz — 不要葱 / No onion / Sin cebolla','taxable'=>false]],'currency'=>'USD','total_minor'=>0,'tax_minor'=>0,'tax_rate_basis_points'=>0,'service_mode'=>'pickup','customer_name'=>'TEST ONLY','customer_phone'=>'','delivery_address'=>'','delivery_postal_code'=>'','due_at'=>''];
            $job=$this->pos->enqueue($id,(int)$c['revision'],null,$snapshot,true,$id.':'.$c['revision'].':probe');$this->pos->update($id,['probe_job_id'=>(int)$job['id'],'probe_confirmed'=>0]);$this->pos->audit($id,(int)$job['id'],$actor,'probe_queued');return (int)$job['id'];
        });
    }
    public function confirm(int $id,int $actor):void{$this->locked($id,function($c)use($id,$actor){$job=$this->pos->job((int)$c['probe_job_id']);if(!$job||$job['status']!=='delivered'||(int)$job['revision']!==(int)$c['revision']){throw new \RuntimeException('测试单尚未传送成功，请先核对收银机。');}$this->pos->update($id,['probe_confirmed'=>1]);$this->pos->audit($id,(int)$job['id'],$actor,'probe_owner_confirmed');});}
    public function activate(int $id,int $actor,bool $testSiteConfirmed=false):void{if(!$testSiteConfirmed){throw new \RuntimeException('请确认这是使用测试资料与测试付款的测试站，不要在营业站开启测试传单。');}$this->locked($id,function($c)use($id,$actor){if((int)$c['worker_seen_at']<time()-180){throw new \RuntimeException('自动传单服务尚未就绪，请管理员先完成安装。');}if(!(int)$c['probe_confirmed']||!$c['mapping']){throw new \RuntimeException('请先核对菜品并确认收银机收到测试单。');}$settings=$c['settings'];$settings['sandbox_site_confirmed']=true;$this->pos->update($id,['settings'=>$settings]);$this->pos->activate($id,$this->pos->maxRestaurantOrderId());$this->pos->audit($id,null,$actor,'enabled');});}
    public function pause(int $id,int $actor,bool $disconnect=false):void{$this->locked($id,function($c)use($id,$actor,$disconnect){$values=['auto_send'=>0];if($disconnect){$values+=['status'=>'disconnected','revision'=>(int)$c['revision']+1,'probe_confirmed'=>0];$this->write('pos_tokens_'.$id,[]);}$this->pos->update($id,$values);$this->pos->audit($id,null,$actor,$disconnect?'disconnected':'paused');});}
    public function run(int $limit=10):array
    {
        $this->pos->recoverInterrupted();$result=['queued'=>0,'delivered'=>0,'needs_review'=>0];
        foreach($this->pos->connections() as $c){if($c['site_hash']===$this->siteHash){$this->pos->update((int)$c['id'],['worker_seen_at'=>time()]);}if($c['environment']!=='sandbox'||empty($c['settings']['sandbox_site_confirmed'])||!(int)$c['auto_send']||$c['site_hash']!==$this->siteHash){continue;}
            $ids=$this->pos->candidateOrders(max((int)$c['scan_cursor'],(int)$c['start_after_order_id']),50);
            foreach($ids as $order){try{$snapshot=$this->snapshot($c,$order);$this->pos->enqueue((int)$c['id'],(int)$c['revision'],$order,$snapshot,false,$c['id'].':'.$c['revision'].':'.$order);$result['queued']++;}catch(RestaurantPosException $e){if($e->reason!=='payment_not_confirmed'){$failed=$this->pos->enqueue((int)$c['id'],(int)$c['revision'],$order,[],false,$c['id'].':'.$c['revision'].':'.$order);$this->pos->hold((int)$failed['id'],$e->reason);$this->pos->update((int)$c['id'],['last_error'=>$e->reason]);}}}
            $this->pos->update((int)$c['id'],['scan_cursor'=>count($ids)<50?(int)$c['start_after_order_id']:(int)end($ids)]);
        }
        foreach($this->pos->queued($limit) as $job){try{$this->send((int)$job['id']);$result['delivered']++;}catch(\Throwable){$result['needs_review']++;}}return $result;
    }
    private function send(int $id):void
    {
        $job=$this->pos->job($id);if(!$job){return;}$connection=(int)$job['connection_id'];$lease=$this->pos->acquire($connection);$nonce='';
        try{$nonce=$this->pos->claim($id);$c=$this->connected($connection);
            if((int)$c['revision']!==(int)$job['revision']||(!$job['is_test']&&(empty($c['settings']['sandbox_site_confirmed'])||!(int)$c['auto_send']||(int)$job['commerce_order_id']<=(int)$c['start_after_order_id']))){throw new RestaurantPosException('connection_changed');}
            if(!$job['is_test']){$current=$this->snapshot($c,(int)$job['commerce_order_id']);if($current!==$job['snapshot']){throw new RestaurantPosException('order_changed');}}
            [$g,$t]=$this->access($c);$s=$job['snapshot'];$remote=$g->createOrder($t,$c['location_id'],$s,$job['reference'],$c['settings']);
            $this->pos->checkpoint($id,$nonce,['remote_order_id'=>$remote['id'],'stage'=>'created']);
            $g->populateOrder($t,$c['location_id'],$remote['id'],$s,$c['settings']);
            $remote=$g->getOrder($t,$c['location_id'],$remote['id']);
            if($remote['reference']!==$job['reference']||$remote['total_minor']!==$s['total_minor']||($c['brand']==='square'&&($remote['location_id']!==$c['location_id']||$remote['currency']!==$s['currency']))||in_array($remote['state'],['CANCELED','CANCELLED','LOCKED'],true)){throw new RestaurantPosException('remote_amount_mismatch');}
            if(!$job['is_test']){
                $this->snapshot($c,(int)$job['commerce_order_id']); // Recheck cancellation/refund before external bookkeeping.
                $payment=$g->recordExternalPayment($t,$c['location_id'],$remote['id'],$s,$job['reference'],$c['settings']);$this->pos->checkpoint($id,$nonce,['remote_payment_id'=>$payment['id'],'stage'=>'payment_recorded']);
                $remote=$g->getOrder($t,$c['location_id'],$remote['id']);if($g->matchingPayment($t,$c['location_id'],$remote,$s,$job['reference'],$c['settings'])===null){throw new RestaurantPosException('payment_record_unverified');}
            }else{$g->finishProbe($t,$c['location_id'],$remote['id'],$job['reference']);}
            if(($c['settings']['print_mode']??'website')==='pos'){$g->requestPrint($t,$c['location_id'],$remote['id']);}
            $this->pos->checkpoint($id,$nonce,['status'=>'delivered','stage'=>'complete','last_error'=>'']);$this->pos->audit($connection,$id,0,'delivered');
        }catch(\Throwable $e){$reason=$e instanceof RestaurantPosException?$e->reason:'operation_failed';if($nonce!==''){$this->pos->checkpoint($id,$nonce,['status'=>'needs_review','last_error'=>$reason]);}$this->pos->update($connection,['last_error'=>$reason]);throw $e;}
        finally{$this->pos->release($connection,$lease);}
    }
}
