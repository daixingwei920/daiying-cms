<?php
declare(strict_types=1);
namespace Official\Restaurant;

final class RestaurantPosException extends \RuntimeException
{
    public function __construct(public readonly string $reason,public readonly bool $uncertain=false){parent::__construct('收银系统操作未完成，请在连接页面查看并核对。');}
}

/** Official HTTPS endpoints only. Injected transports are restricted to isolated software tests. */
final class RestaurantPosGateway
{
    private readonly ?\Closure $transport;
    private readonly string $api;
    private readonly string $auth;
    public function __construct(public readonly string $brand,public readonly string $environment,private readonly string $appId,private readonly string $redirectUri,?callable $transport=null)
    {
        if(!in_array($brand,['square','clover'],true)||$environment!=='sandbox'){throw new \InvalidArgumentException('本版本仅开放 Square / Clover 测试环境，正式连接尚未开放。');}
        $this->transport=$transport===null?null:\Closure::fromCallable($transport);
        $this->api=$brand==='square'?'https://connect.squareupsandbox.com':'https://apisandbox.dev.clover.com';
        $this->auth=$brand==='square'?$this->api:'https://sandbox.dev.clover.com';
    }
    public static function id(string $id):string{if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/',$id)){throw new RestaurantPosException('invalid_identifier');}return rawurlencode($id);}
    public function authorize(string $state,string $verifier):string
    {
        $query=['client_id'=>$this->appId,'redirect_uri'=>$this->redirectUri,'response_type'=>'code','state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];
        if($this->brand==='square'){$query['scope']='MERCHANT_PROFILE_READ ITEMS_READ ORDERS_READ ORDERS_WRITE PAYMENTS_READ PAYMENTS_WRITE';$query['session']='false';}
        return $this->auth.($this->brand==='square'?'/oauth2/authorize':'/oauth/v2/authorize').'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }
    public function exchange(string $code,string $verifier,string $merchant=''):array
    {
        $body=['client_id'=>$this->appId,'code'=>$code,'code_verifier'=>$verifier];
        if($this->brand==='square'){$body+=['grant_type'=>'authorization_code','redirect_uri'=>$this->redirectUri];}
        $r=$this->request('POST',$this->brand==='square'?'/oauth2/token':'/oauth/v2/token','',$body);
        return $this->normalizeTokens($r,$merchant);
    }
    public function refresh(array $tokens):array
    {
        $body=['client_id'=>$this->appId,'refresh_token'=>$tokens['refresh_token']];
        if($this->brand==='square'){$body['grant_type']='refresh_token';}
        $r=$this->request('POST',$this->brand==='square'?'/oauth2/token':'/oauth/v2/refresh','',$body);
        return $this->normalizeTokens($r,(string)$tokens['account_id']);
    }
    private function normalizeTokens(array $r,string $account):array
    {
        $expires=$this->brand==='square'?strtotime((string)($r['expires_at']??'')):(int)($r['access_token_expiration']??0);
        $account=(string)($r['merchant_id']??$account);self::id($account);
        if(!is_string($r['access_token']??null)||$r['access_token']===''||!is_string($r['refresh_token']??null)||$r['refresh_token']===''||!$expires||$expires<=time()+15){throw new RestaurantPosException('invalid_token_response');}
        return ['access_token'=>$r['access_token'],'refresh_token'=>$r['refresh_token'],'expires_at'=>$expires,'refresh_expires_at'=>(int)($r['refresh_token_expiration']??0),'account_id'=>$account];
    }
    public function locations(array $tokens):array
    {
        if($this->brand==='square'){
            $r=$this->request('GET','/v2/locations',$tokens['access_token']);$out=[];
            foreach($r['locations']??[] as $v){if(($v['status']??'')!=='ACTIVE'){continue;}self::id((string)$v['id']);$out[]=['id'=>(string)$v['id'],'name'=>(string)($v['name']??$v['business_name']??'Square门店'),'currency'=>(string)($v['currency']??''),'address'=>$this->address($v['address']??[])];}return $out;
        }
        $r=$this->request('GET','/v3/merchants/'.self::id($tokens['account_id']).'?expand=address',$tokens['access_token']);
        if((string)($r['id']??'')!==$tokens['account_id']){throw new RestaurantPosException('merchant_mismatch');}
        return [['id'=>$tokens['account_id'],'name'=>(string)($r['name']??'Clover门店'),'currency'=>strtoupper((string)($r['currency']??'')),'address'=>$this->address($r['address']??[])]];
    }
    private function address(array $a):string{return implode(' ',array_filter(array_map(static fn($s):string=>is_string($s)?$s:'',[$a['address_line_1']??$a['address1']??'',$a['locality']??$a['city']??'',$a['administrative_district_level_1']??$a['state']??'',$a['postal_code']??$a['zip']??''])));}
    public function catalog(array $tokens,string $location):array
    {
        $out=[];$cursor='';$offset=0;
        for($page=0;$page<5;$page++){
            if($this->brand==='square'){
                $r=$this->request('GET','/v2/catalog/list?types=ITEM'.($cursor!==''?'&cursor='.rawurlencode($cursor):''),$tokens['access_token']);
                foreach($r['objects']??[] as $item){
                    if(!empty($item['is_deleted'])||!$this->present($item,$location)){continue;}
                    foreach($item['item_data']['variations']??[] as $v){if(!empty($v['is_deleted'])||!$this->present($v,$location)){continue;}$data=$v['item_variation_data']??[];if(($data['pricing_type']??'FIXED_PRICING')!=='FIXED_PRICING'){continue;}$money=$data['price_money']??[];$out[]=['id'=>(string)$v['id'],'name'=>(string)($item['item_data']['name']??'').' · '.(string)($data['name']??''),'price_minor'=>(int)($money['amount']??0),'currency'=>(string)($money['currency']??''),'parent_id'=>(string)$item['id']];}
                }
                $cursor=(string)($r['cursor']??'');if($cursor===''){return $this->validateCatalog($out);}
            }else{
                $r=$this->request('GET','/v3/merchants/'.self::id($location).'/items?limit=100&offset='.$offset,$tokens['access_token']);
                $items=$r['elements']??[];
                foreach($items as $v){if(!empty($v['deleted'])||!empty($v['hidden'])||($v['available']??true)===false){continue;}$out[]=['id'=>(string)$v['id'],'name'=>(string)($v['name']??''),'price_minor'=>(int)($v['price']??0),'currency'=>'','parent_id'=>''];}
                if(count($items)<100){return $this->validateCatalog($out);}$offset+=100;
            }
        }
        throw new RestaurantPosException('catalog_too_large');
    }
    private function present(array $v,string $location):bool{return !in_array($location,$v['absent_at_location_ids']??[],true)&&(($v['present_at_all_locations']??true)!==false||in_array($location,$v['present_at_location_ids']??[],true));}
    private function validateCatalog(array $items):array{foreach($items as $v){self::id($v['id']);if($v['name']===''||$v['price_minor']<0){throw new RestaurantPosException('invalid_catalog');}}return $items;}
    public function options(array $tokens,string $location):array
    {
        if($this->brand==='square'){return ['tenders'=>[],'tax_rates'=>[],'order_types'=>[]];}
        $prefix='/v3/merchants/'.self::id($location);$tenders=$this->request('GET',$prefix.'/tenders?limit=100',$tokens['access_token']);
        $tax=$this->request('GET',$prefix.'/tax_rates?limit=100',$tokens['access_token']);$types=$this->request('GET',$prefix.'/order_types?limit=100',$tokens['access_token']);
        $safe=[];
        foreach($tenders['elements']??[] as $t){if(($t['enabled']??true)!==false&&($t['editable']??false)===true&&!preg_match('/^(cash|credit|debit|visa|mastercard|amex|discover|gift card)$/i',trim((string)($t['label']??'')))){$safe[]=['id'=>(string)$t['id'],'name'=>(string)($t['label']??'外部已收款')];}}
        return ['tenders'=>$safe,'tax_rates'=>array_map(static fn($r):array=>['id'=>(string)$r['id'],'name'=>(string)($r['name']??'税项'),'rate'=>(int)($r['rate']??0)],$tax['elements']??[]),'order_types'=>array_map(static fn($r):array=>['id'=>(string)$r['id'],'name'=>(string)($r['label']??$r['name']??'订单类型')],$types['elements']??[])];
    }
    /** First mutation only; caller persists the returned id before further mutations. */
    public function createOrder(array $tokens,string $location,array $snapshot,string $reference,array $settings):array
    {
        if($this->brand==='clover'){
            $body=['title'=>($snapshot['is_test']?'TEST ONLY 不出餐 ':'网站订单 ').$snapshot['order_number'],'note'=>mb_substr($snapshot['note'].' · '.$snapshot['service_mode'].' · '.$snapshot['customer_name'].' · '.$snapshot['customer_phone'].' · '.$snapshot['due_at'].' · '.$snapshot['delivery_address'],0,2000),'externalReferenceId'=>$reference,'state'=>'OPEN','groupLineItems'=>false,'taxRemoved'=>(int)$snapshot['tax_minor']===0];
            $type=(string)($settings[$snapshot['service_mode']==='delivery'?'delivery_order_type':'pickup_order_type']??'');if($type!==''){$body['orderType']=['id'=>$type];}
            $r=$this->request('POST','/v3/merchants/'.self::id($location).'/orders',$tokens['access_token'],$body);
            return $this->normalizeOrder($r);
        }
        $lines=[];
        foreach($snapshot['items'] as $index=>$item){
            $line=['uid'=>'line-'.$index,'name'=>$item['name'],'quantity'=>(string)$item['quantity'],'base_price_money'=>['amount'=>$item['unit_price_minor'],'currency'=>$snapshot['currency']],'note'=>$item['note']];
            if(($item['catalog_id']??'')!==''){$line['catalog_object_id']=$item['catalog_id'];}
            if($snapshot['tax_minor']>0&&($item['taxable']??true)){$line['applied_taxes']=[['tax_uid'=>'website-tax']];}$lines[]=$line;
        }
        $order=['location_id'=>$location,'reference_id'=>$reference,'line_items'=>$lines,'state'=>'OPEN','pricing_options'=>['auto_apply_taxes'=>false,'auto_apply_discounts'=>false]];
        if($snapshot['tax_minor']>0){$order['taxes']=[['uid'=>'website-tax','name'=>'网站销售税','percentage'=>number_format($snapshot['tax_rate_basis_points']/100,2,'.',''),'scope'=>'LINE_ITEM','type'=>'ADDITIVE']];}
        if(($settings['print_mode']??'pos')==='pos'){
            $due=$snapshot['due_at'];
            $recipient=['display_name'=>$snapshot['customer_name']?:'网站顾客'];if($snapshot['customer_phone']!==''){$recipient['phone_number']=$snapshot['customer_phone'];}
            if($snapshot['service_mode']==='delivery'){$recipient['address']=['address_line_1'=>$snapshot['delivery_address'],'postal_code'=>$snapshot['delivery_postal_code'],'country'=>'US'];$order['fulfillments']=[['type'=>'DELIVERY','state'=>'PROPOSED','delivery_details'=>['recipient'=>$recipient,'note'=>$snapshot['note']]+($due!==''?['schedule_type'=>'SCHEDULED','deliver_at'=>$due]:['schedule_type'=>'ASAP','prep_time_duration'=>'PT'.(int)($settings['delivery_minutes']??45).'M'])]];}
            else{$order['fulfillments']=[['type'=>'PICKUP','state'=>'PROPOSED','pickup_details'=>['recipient'=>$recipient,'note'=>$snapshot['note']]+($due!==''?['schedule_type'=>'SCHEDULED','pickup_at'=>$due]:['schedule_type'=>'ASAP','prep_time_duration'=>'PT'.(int)($settings['prep_minutes']??30).'M'])]];}
        }
        $r=$this->request('POST','/v2/orders',$tokens['access_token'],['idempotency_key'=>$reference,'order'=>$order]);
        return $this->normalizeOrder($r['order']??[]);
    }
    public function populateOrder(array $tokens,string $location,string $order,array $snapshot,array $settings):void
    {
        if($this->brand==='square'){return;}
        $lines=[];$allocated=0;$taxableTotal=array_sum(array_map(static fn($i)=>!empty($i['taxable'])?$i['unit_price_minor']*$i['quantity']:0,$snapshot['items']));
        foreach($snapshot['items'] as $item){for($unit=0;$unit<$item['quantity'];$unit++){
            $line=['name'=>$item['name'],'price'=>$item['unit_price_minor'],'note'=>$item['note'],'isRevenue'=>true,'taxRates'=>[]];
            if(($item['catalog_id']??'')!==''){$line['item']=['id'=>$item['catalog_id']];}
            if($snapshot['tax_minor']>0&&!empty($item['taxable'])){
                $before=$allocated;$allocated+=$item['unit_price_minor'];
                $amount=intdiv($snapshot['tax_minor']*$allocated+intdiv($taxableTotal,2),$taxableTotal)-intdiv($snapshot['tax_minor']*$before+intdiv($taxableTotal,2),$taxableTotal);
                $line['taxRates']=[['id'=>(string)$settings['tax_id'],'name'=>'网站销售税','rate'=>0,'taxAmount'=>$amount]];
            }
            $lines[]=$line;if(count($lines)>250){throw new RestaurantPosException('order_too_large');}
        }}
        $this->request('POST','/v3/merchants/'.self::id($location).'/orders/'.self::id($order).'/bulk_line_items',$tokens['access_token'],['items'=>$lines]);
        // Clover custom orders require the integrator to persist the total. Read back all
        // actual lines first: do not merely copy our expected total and call it validation.
        $path='/v3/merchants/'.self::id($location).'/orders/'.self::id($order);$actual=[];
        for($page=0;$page<3;$page++){$r=$this->request('GET',$path.'/line_items?expand=taxRates,modifications,discounts&limit=100&offset='.($page*100),$tokens['access_token']);$batch=$r['elements']??[];$actual=array_merge($actual,$batch);if(count($batch)<100){break;}}
        if(count($actual)!==count($lines)){throw new RestaurantPosException('remote_line_mismatch');}
        $signature=static function(array $line):string{
            $rates=$line['taxRates']['elements']??$line['taxRates']??[];$tax=[];
            foreach($rates as $rate){if((int)($rate['rate']??0)!==0){throw new RestaurantPosException('remote_tax_mismatch');}$tax[]=[(string)($rate['id']??''),(int)($rate['taxAmount']??0)];}sort($tax);
            if(!empty($line['discounts']['elements'])||!empty($line['modifications']['elements'])||!empty($line['deleted'])||!empty($line['isVoided'])||isset($line['unitQty'])&&(int)$line['unitQty']!==1000){throw new RestaurantPosException('remote_line_mismatch');}
            return json_encode([(string)($line['name']??''),(int)($line['price']??-1),(string)($line['item']['id']??''),(string)($line['note']??''),$tax],JSON_THROW_ON_ERROR);
        };
        $expected=array_map($signature,$lines);$received=array_map($signature,$actual);sort($expected);sort($received);
        if($expected!==$received){throw new RestaurantPosException('remote_line_mismatch');}
        $this->request('POST',$path,$tokens['access_token'],['total'=>$snapshot['total_minor'],'currency'=>strtolower($snapshot['currency'])]);
    }
    public function getOrder(array $tokens,string $location,string $order):array
    {
        $r=$this->request('GET',$this->brand==='square'?'/v2/orders/'.self::id($order):'/v3/merchants/'.self::id($location).'/orders/'.self::id($order).'?expand=payments,lineItems',$tokens['access_token']);
        $o=$this->brand==='square'?($r['order']??[]):$r;$result=$this->normalizeOrder($o);$result['raw']=$o;return $result;
    }
    private function normalizeOrder(array $o):array
    {
        $id=(string)($o['id']??'');self::id($id);
        return ['id'=>$id,'reference'=>(string)($o['reference_id']??$o['externalReferenceId']??''),'total_minor'=>isset($o['total_money']['amount'])?(int)$o['total_money']['amount']:(isset($o['total'])?(int)$o['total']:-1),'currency'=>strtoupper((string)($o['total_money']['currency']??$o['currency']??'')),'location_id'=>(string)($o['location_id']??''),'state'=>strtoupper((string)($o['state']??''))];
    }
    public function recordExternalPayment(array $tokens,string $location,string $order,array $snapshot,string $reference,array $settings):array
    {
        if((int)$snapshot['total_minor']<=0){throw new RestaurantPosException('invalid_payment_amount');}
        if($this->brand==='square'){
            $r=$this->request('POST','/v2/payments',$tokens['access_token'],['idempotency_key'=>$reference,'source_id'=>'EXTERNAL','location_id'=>$location,'order_id'=>$order,'reference_id'=>$reference,'amount_money'=>['amount'=>$snapshot['total_minor'],'currency'=>$snapshot['currency']],'external_details'=>['type'=>'OTHER','source'=>'Daiying website'],'autocomplete'=>true]);
            $r=$r['payment']??[];
        }else{
            $r=$this->request('POST','/v3/merchants/'.self::id($location).'/orders/'.self::id($order).'/payments',$tokens['access_token'],['amount'=>$snapshot['total_minor'],'taxAmount'=>$snapshot['tax_minor'],'tender'=>['id'=>$settings['tender_id']],'externalPaymentId'=>$reference,'result'=>'SUCCESS']);
        }
        $id=(string)($r['id']??'');self::id($id);return ['id'=>$id];
    }
    public function matchingPayment(array $tokens,string $location,array $order,array $snapshot,string $reference,array $settings):?string
    {
        if($this->brand==='square'){
            if(count($order['raw']['tenders']??[])>10){throw new RestaurantPosException('payment_record_unverified');}
            foreach($order['raw']['tenders']??[] as $t){$id=(string)($t['payment_id']??'');if($id===''){continue;}$p=$this->request('GET','/v2/payments/'.self::id($id),$tokens['access_token'])['payment']??[];
                if(($p['reference_id']??'')===$reference&&($p['order_id']??'')===$order['id']&&($p['location_id']??'')===$location&&($p['source_type']??'')==='EXTERNAL'&&($p['status']??'')==='COMPLETED'&&(int)($p['amount_money']['amount']??-1)===$snapshot['total_minor']&&($p['amount_money']['currency']??'')===$snapshot['currency']){return $id;}}
            return null;
        }
        $r=$this->request('GET','/v3/merchants/'.self::id($location).'/orders/'.self::id($order['id']).'/payments?expand=tender',$tokens['access_token']);
        foreach($r['elements']??[] as $p){if(($p['externalPaymentId']??'')===$reference&&(int)($p['amount']??-1)===$snapshot['total_minor']&&($p['tender']['id']??'')===$settings['tender_id']&&strtoupper((string)($p['result']??''))==='SUCCESS'){return (string)$p['id'];}}return null;
    }
    public function finishProbe(array $tokens,string $location,string $order,string $reference):void
    {
        if($this->brand==='square'){$this->request('POST','/v2/orders/'.self::id($order).'/pay',$tokens['access_token'],['idempotency_key'=>$reference,'payment_ids'=>[]]);}
    }
    public function requestPrint(array $tokens,string $location,string $order):void
    {
        if($this->brand==='clover'){$this->request('POST','/v3/merchants/'.self::id($location).'/print_event',$tokens['access_token'],['orderRef'=>['id'=>$order]]);}
        // Square fulfillment orders follow the merchant's own POS/KDS/printer configuration.
    }
    private function request(string $method,string $path,string $token='',?array $body=null):array
    {
        if(!str_starts_with($path,'/')||str_contains($path,"\r")||str_contains($path,"\n")){throw new RestaurantPosException('invalid_request');}
        $url=$this->api.$path;$headers=['Content-Type'=>'application/json','Accept'=>'application/json','User-Agent'=>'Daiying-Restaurant/POS-candidate'];
        if($this->brand==='square'){$headers['Square-Version']='2026-09-16';}
        if($token!==''){$headers['Authorization']='Bearer '.$token;}
        if($this->transport){$response=($this->transport)($method,$url,$headers,$body);}
        else{
            if(!function_exists('curl_init')){throw new RestaurantPosException('curl_unavailable');}
            $curl=curl_init($url);$buffer='';
            curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>array_map(static fn($key,$value)=>$key.': '.$value,array_keys($headers),$headers),CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$buffer):int{if(strlen($buffer)+strlen($chunk)>2097152){return 0;}$buffer.=$chunk;return strlen($chunk);}]);
            if($body!==null){curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
            if($ok===false){throw new RestaurantPosException('network',$method!=='GET');}
            $data=json_decode($buffer,true);if(!is_array($data)){throw new RestaurantPosException('invalid_response',$method!=='GET');}$response=['status'=>$status,'data'=>$data];
        }
        $status=(int)($response['status']??0);$data=$response['data']??null;
        if($status===401||$status===403){throw new RestaurantPosException('authorization');}
        if($status<200||$status>=300||!is_array($data)||!empty($data['errors'])){throw new RestaurantPosException($status===429?'rate_limited':'provider_rejected',$method!=='GET');}
        return $data;
    }
}
