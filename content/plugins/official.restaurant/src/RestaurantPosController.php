<?php
declare(strict_types=1);
namespace Official\Restaurant;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;

final class RestaurantPosController
{
    public function __construct(private readonly RestaurantPosService $service,private readonly RestaurantRepository $restaurant,private readonly string $style=''){}
    private static function e(mixed $v):string{return View::escape((string)$v);}
    private function page(string $body,int $status=200):Response{return Response::html(View::page('连接收银系统','<link rel="stylesheet" href="'.self::e($this->style).'"><div class="restaurant-admin"><nav class="ra-main-nav"><a href="/admin/restaurant">订单看板</a><a href="/admin/restaurant/storefront">店面与营业</a><a href="/admin/restaurant/pos">收银系统</a></nav><h1>连接收银系统</h1>'.$body.'</div>'),$status);}
    private static function error(string $reason):string{return ['mapping_missing'=>'这张订单含未对应的菜品，请人工接单并补齐菜品对应。','tax_mapping_missing'=>'请先为网站销售税选择收银税项。','remote_amount_mismatch'=>'收银金额与网站成交金额不同，请核对税费，勿再次收款。','remote_line_mismatch'=>'收银菜品与网站订单不一致，请核对后人工处理。','remote_tax_mismatch'=>'收银税费与网站订单不同，请核对税费。','network'=>'暂时未收到收银系统回复，请先在收银机核实是否已经收到订单。','operation_failed'=>'操作未完成，请核对收银机和连接资料。','authorization'=>'登录授权已失效，请重新连接收银账号。','reauthorize'=>'需要重新登录收银账号。','rate_limited'=>'收银系统暂时繁忙，请人工接单并稍后核对。','connection_changed'=>'连接或门店已改变，这张订单请人工处理。','payment_record_unverified'=>'收银付款登记尚未核实，勿再次向顾客收费。','interrupted'=>'传单中断，结果尚不确定，请先核对收银机。'][$reason]??'请核对收银机及本网站订单，勿重复出单。';}
    private function form(string $action,int $id,string $fields,string $label):string{return '<form method="post" action="/admin/restaurant/pos/action">'.CsrfToken::field().'<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="id" value="'.$id.'">'.$fields.'<button type="submit">'.$label.'</button></form>';}
    private function select(string $name,string $label,array $options,string $selected=''):string{$s='<label class="ra-field"><span>'.self::e($label).'</span><select name="'.self::e($name).'"><option value="">请选择</option>';foreach($options as $o){$s.='<option value="'.self::e($o['id']).'"'.($o['id']===$selected?' selected':'').'>'.self::e($o['name']).'</option>';}return $s.'</select></label>';}
    private static function catalogLabels(array $catalog):array
    {
        $out=[];foreach(array_values($catalog) as $i=>$item){$out[$item['id']]=$item['name'].' · $'.number_format((int)$item['price_minor']/100,2,'.','').' · '.($i+1);}return $out;
    }
    public function index(Request $request):Response
    {
        $html='<p class="ra-lead">本版仅开放 Square / Clover 测试连接。请选择测试站、测试商户和测试付款资料，正式营业连接尚未开放。</p><p>网站原有收款、订单与打印方式保持不变。测试收银系统只登记已付款的测试订单，不会再次向顾客扣款。</p><p class="ra-notice">尚无开发应用时，需要管理员先开通。连接成功只表示账号授权完成，须收到测试单后才能开启自动接单。</p><p><a href="/admin/restaurant/pos">刷新连接及传单进度</a></p>';
        foreach(['square'=>'Square','clover'=>'Clover'] as $brand=>$name){$app=$this->service->app($brand);$html.='<section class="admin-card"><h2>'.$name.'</h2>';
            if(!$app){$html.='<p>等待管理员开通</p>';}else{$html.='<p>'.($app['environment']==='sandbox'?'测试环境 · 不用于营业':'正式环境').'</p>'.$this->form('connect',0,'<input type="hidden" name="brand" value="'.$brand.'">','登录 '.$name.' 并连接');}
            $html.='<details><summary>管理员首次开通</summary><p>在品牌开发者后台创建支持安全登录授权的应用，登记下方回到本网站的网址。Clover 请选择低信任应用并开启安全授权。这里不需要填写密码或支付密钥。</p><p><code>'.self::e($this->service->callbackUrl($brand)).'</code></p>'.$this->form('configure',0,'<input type="hidden" name="brand" value="'.$brand.'"><label class="ra-field">应用编号<input name="app_id" value="'.self::e($app['app_id']??'').'" required maxlength="128"></label>'.$this->select('environment','使用环境',[['id'=>'sandbox','name'=>'测试环境（本版仅此项）']],$app['environment']??'sandbox').'<p>重新开通会暂停该品牌现有连接，需要重新授权。</p>','保存开通资料').'</details></section>';
        }
        foreach($this->service->pos->connections() as $c){if($c['site_hash']!==$this->service->siteHash){continue;}$id=(int)$c['id'];$html.='<section class="admin-card"><h2>'.self::e(ucfirst($c['brand'])).' · '.self::e($c['location_name']?:'尚未选择门店').'</h2>';
            if($c['status']!=='connected'){$html.='<p>需要重新登录连接</p></section>';continue;}
            $html.='<p>'.((int)$c['auto_send']?'已开启测试传单':'测试传单已暂停').'</p>';
            if($c['last_error']!==''){$html.='<p role="alert">'.self::e(self::error($c['last_error'])).'</p>';}
            $locationOptions=array_map(static fn($o)=>['id'=>$o['id'],'name'=>$o['name'].' · '.$o['address']],$c['locations']);
            $html.='<h3>1. 选择门店</h3>'.$this->form('location',$id,$this->select('location_id','把网站订单送到哪家门店',$locationOptions,$c['location_id']),'选择门店并读取菜单');
            if($c['location_id']!==''){$labels=self::catalogLabels($c['catalog']);$fields='<datalist id="pos-catalog-'.$id.'">';foreach($labels as $label){$fields.='<option value="'.self::e($label).'"></option>';}$fields.='</datalist><p>输入收银菜单中的菜名，再选择建议结果；留空表示尚未对应。</p>';foreach($this->restaurant->menuItems() as $item){$selected=$labels[$c['mapping'][(string)$item['id']]??'']??'';$fields.='<label class="ra-field">'.self::e($item['name']).'<input name="mapping_label['.(int)$item['id'].']" list="pos-catalog-'.$id.'" value="'.self::e($selected).'" placeholder="输入收银菜名查找" autocomplete="off"></label>';}
                $fields.=$this->select('print_mode','如何测试收银出单',[['id'=>'website','name'=>'网站打印助手（收银系统只记账）'],['id'=>'pos','name'=>'同时验证测试收银出单（网站原打印方式保留）']],$c['settings']['print_mode']??'website');
                if($c['brand']==='square'){$fields.='<label class="ra-field">即时自取预计备餐分钟<input type="number" name="prep_minutes" min="1" max="360" value="'.(int)($c['settings']['prep_minutes']??30).'" required></label><label class="ra-field">即时送餐预计备餐和送达分钟<input type="number" name="delivery_minutes" min="1" max="360" value="'.(int)($c['settings']['delivery_minutes']??45).'" required></label><p>供收银机显示预计时间；顾客已选的预约时间优先。</p>';}
                if($c['brand']==='clover'){foreach(['tender_id'=>['tenders','网站已付款记在哪种外部收款方式'],'tax_id'=>['tax_rates','网站销售税对应哪项税'],'pickup_order_type'=>['order_types','到店自取对应哪种订单'],'delivery_order_type'=>['order_types','送餐对应哪种订单']] as $key=>[$list,$label]){$fields.=$this->select($key,$label,$c['settings']['options'][$list]??[],$c['settings'][$key]??'');}}
                $html.='<h3>2. 核对菜品与出单方式</h3><p>只对应菜品，不覆盖收银菜单。传送的网站订单保留成交价格、加料、每道菜备注、税费及自取或配送时间。未对应菜品的订单会提示人工处理。</p>'.$this->form('mapping',$id,$fields,'保存菜品对应');
                $html.='<h3>3. 发送测试单</h3><p>会向所选门店发送零金额、明确写有“不出餐”的测试单。请在现场确认可见、文字及出单方式正确；接口回复不能代表已经出纸。</p>'.$this->form('probe',$id,'<label><input type="checkbox" name="confirmed" value="1" required>已获现场允许，可发送不出餐测试单</label>','发送测试单').$this->form('confirm',$id,'<label><input type="checkbox" name="confirmed" value="1" required>已在收银机看到测试单并核对出单方式</label>','确认测试单已收到');
                $html.='<h3>4. 开启接单</h3><p>只接入开启以后新建并付款的订单，不自动补发历史订单。取消或退款后请人工通知厨房；这里不会自动退款。</p>'.$this->form('activate',$id,'<label><input type="checkbox" name="test_site_confirmed" value="1" required>我确认这是测试站，仅使用测试资料与测试付款，不用于营业订单</label>','开启新测试订单接入').$this->form('pause',$id,'','暂停自动接入');
            }
            $html.=$this->form('disconnect',$id,'<p>仅断开本网站；如要撤销品牌授权，请同时在品牌账号中移除此应用。</p>','断开本网站连接');
            $html.='<h3>最近传单记录</h3><div class="ra-table-wrap"><table><thead><tr><th>网站订单</th><th>传送情况</th><th>收银订单号</th></tr></thead><tbody>';
            foreach($this->service->pos->jobs($id) as $job){$label=['queued'=>'等待传送','sending'=>'正在传送','delivered'=>'接口已确认收到（出纸请现场核对）','needs_review'=>'请核对收银机，勿重复出单'][$job['status']]??'请核对';$html.='<tr><td>'.($job['is_test']?'不出餐测试单':'#'.(int)$job['commerce_order_id']).'</td><td>'.self::e($label).($job['last_error']!==''?'<br>'.self::e(self::error($job['last_error'])):'').'</td><td>'.self::e($job['remote_order_id']).'</td></tr>';}$html.='</tbody></table></div></section>';
        }
        return $this->page($html);
    }
    public function action(Request $r):Response
    {
        $actor=(int)($_SESSION['admin_user']['id']??0);if($actor<=0){return $this->page('<p>请先登录后台。</p>',403);}
        $id=(int)$r->input('id',0);$brand=(string)$r->input('brand','');$a=(string)$r->input('action','');
        try{switch($a){
            case 'configure':$this->service->configure($brand,(string)$r->input('environment',''),(string)$r->input('app_id',''),$actor);break;
            case 'connect':$_SESSION['restaurant_pos_pending']??=[];return Response::redirect($this->service->begin($brand,$actor,session_id(),$_SESSION['restaurant_pos_pending']));
            case 'location':$this->service->selectLocation($id,(string)$r->input('location_id',''),$actor);break;
            case 'mapping':$map=$r->input('mapping',[]);$choices=$r->input('mapping_label');if($choices!==null){if(!is_array($choices)){throw new \InvalidArgumentException('请重新选择菜品。');}$c=$this->service->pos->connection($id);$allowed=array_flip(self::catalogLabels($c['catalog']??[]));$map=[];foreach($choices as $key=>$label){if(!is_string($label)||$label!==''&&!isset($allowed[$label])){throw new \InvalidArgumentException('请从菜名建议中选择，不要自行输入收银菜单没有的内容。');}$map[$key]=$label===''?'':$allowed[$label];}}if(!is_array($map)){throw new \InvalidArgumentException('请重新选择菜品。');}$this->service->saveMapping($id,$map,$r->body,$actor);break;
            case 'probe':case 'confirm':if($r->input('confirmed')!=='1'){throw new \RuntimeException('请先勾选现场确认。');}if($a==='probe'){$this->service->probe($id,$actor);}else{$this->service->confirm($id,$actor);}break;
            case 'activate':$this->service->activate($id,$actor,$r->input('test_site_confirmed')==='1');break;
            case 'pause':case 'disconnect':$this->service->pause($id,$actor,$a==='disconnect');break;
            default:throw new \InvalidArgumentException('请选择页面上的操作。');
        }return Response::redirect('/admin/restaurant/pos');}
        catch(\Throwable $e){$message=$e instanceof \RuntimeException||$e instanceof \InvalidArgumentException?$e->getMessage():'操作未完成，请检查连接资料。';if($e instanceof RestaurantPosException){$message=self::error($e->reason);}if($e instanceof \PDOException||$e instanceof \Cms\Core\Plugin\PluginException){$message='暂时无法保存安全连接，请联系管理员检查。';}return $this->page('<p role="alert">'.self::e($message).'</p><a href="/admin/restaurant/pos">返回连接设置</a>',400);}
    }
    public function callback(Request $r,string $brand):Response
    {
        $actor=(int)($_SESSION['admin_user']['id']??0);if($actor<=0){return $this->page('<p>请使用发起连接的后台账号登录。</p>',403);}
        try{$_SESSION['restaurant_pos_pending']??=[];$this->service->callback($brand,$r->query,$actor,session_id(),$_SESSION['restaurant_pos_pending']);return Response::redirect('/admin/restaurant/pos');}
        catch(\Throwable){return $this->page('<p>连接未完成或已过期，请重新登录收银账号并授权。</p><a href="/admin/restaurant/pos">返回重试</a>',400);}
    }
}
