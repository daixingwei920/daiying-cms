<?php
declare(strict_types=1);

namespace Official\Restaurant;

use Cms\Core\Http\Request;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;

/** Form adapter only. Business settings remain owned by RestaurantRepository. */
final class RestaurantAdminSettings
{
    private const LANGUAGES = ['en-US'=>'English','zh-CN'=>'中文','es-US'=>'Español'];
    private const DAYS = ['mon'=>'周一','tue'=>'周二','wed'=>'周三','thu'=>'周四','fri'=>'周五','sat'=>'周六','sun'=>'周日'];
    private const TEXT = ['name'=>'餐馆名称','address'=>'地址','headline'=>'首页大标题','subtitle'=>'首页介绍','about_title'=>'关于我们标题','about_body'=>'餐馆介绍','status_reason'=>'暂停接单说明'];

    private static function e(mixed $value): string { return View::escape((string)$value); }

    private static function input(string $name, string $label, mixed $value='', string $type='text', string $attributes=''): string
    {
        return '<label class="ra-field"><span>'.self::e($label).'</span><input type="'.$type.'" name="'.self::e($name).'" value="'.self::e($value).'" '.$attributes.'></label>';
    }

    private static function select(string $name, string $label, array $options, string $current): string
    {
        $html='<label class="ra-field"><span>'.self::e($label).'</span><select name="'.self::e($name).'">';
        foreach($options as $value=>$text){$html.='<option value="'.self::e($value).'"'.((string)$value===$current?' selected':'').'>'.self::e($text).'</option>';}
        return $html.'</select></label>';
    }

    private static function toggle(string $name, string $label, bool $checked): string
    {
        return '<label class="ra-toggle"><input type="checkbox" name="'.$name.'" value="1"'.($checked?' checked':'').'><span>'.self::e($label).'</span></label>';
    }

    private static function section(string $id, string $title, string $intro, string $body): string
    {
        return '<section class="ra-panel" id="ra-'.$id.'" data-settings-panel="'.$id.'" aria-labelledby="ra-tab-'.$id.'"><header><span class="ra-eyebrow">店面与营业</span><h2>'.self::e($title).'</h2><p>'.self::e($intro).'</p></header>'.$body.'</section>';
    }

    public static function render(array $location, array $tax, string $defaultLocale): string
    {
        $s=json_decode((string)($location['settings_json']??'{}'),true);
        $s=is_array($s)?$s:[];
        $tabs=['basic'=>'基本资料','hours'=>'营业时间','fulfillment'=>'自取与配送','tax'=>'税费与收款','appearance'=>'门头与照片','content'=>'三语介绍','pos'=>'收银系统'];
        $html='<p class="ra-lead">把门店资料填好，顾客就能按你的营业安排下单。修改后记得保存。</p><form class="ra-settings" method="post" action="/admin/restaurant/storefront/save" data-settings-form>'.CsrfToken::field().'<input type="hidden" name="settings_form_present" value="1"><input type="hidden" name="settings_section" value="basic" data-current-section><input type="hidden" name="default_locale_present" value="1"><input type="hidden" name="tax_fields_present" value="1"><nav class="ra-tabs" aria-label="店面设置分组">';
        foreach($tabs as $id=>$label){$html.='<a id="ra-tab-'.$id.'" href="#ra-'.$id.'" data-settings-tab="'.$id.'">'.$label.'</a>';}
        $html.='</nav>';
        $zone=(string)($location['timezone']??'America/New_York');
        $zones=['America/New_York'=>'美国东部（纽约）','America/Chicago'=>'美国中部（芝加哥）','America/Denver'=>'美国山地（丹佛）','America/Phoenix'=>'美国亚利桑那（凤凰城）','America/Los_Angeles'=>'美国太平洋（洛杉矶）','America/Anchorage'=>'美国阿拉斯加','Pacific/Honolulu'=>'美国夏威夷','Asia/Shanghai'=>'中国（北京时间）'];
        if(!isset($zones[$zone])){$zones[$zone]=$zone;}
        $body='<div class="ra-grid">'.self::input('name','餐馆名称',$location['name']??'','text','required maxlength="200"').self::select('default_locale','顾客首次看到的语言',self::LANGUAGES,$defaultLocale).self::input('phone','联系电话',$location['phone']??'','tel','maxlength="80"').self::input('address','门店地址',$location['address']??'','text','maxlength="500"').self::select('timezone','门店所在时区',$zones,$zone).'</div><p class="ra-help">顾客自己切换过语言时，保留顾客的选择。地址和电话会显示在网站上。</p>';
        $html.=self::section('basic','先让顾客认识你的餐馆','填写名称、地址和联系方式。',$body);
        $status=(string)($s['status']??'closed');
        $statuses=['open'=>'正常接单（按下方营业时间）','paused'=>'暂时停止接单','closed'=>'停止接单','closing_soon'=>'即将打烊'];
        if(!isset($statuses[$status])){$statuses[$status]='保留现有营业安排';}
        $body=self::select('store_status','现在是否接单',$statuses,$status).'<p class="ra-help">选择“正常接单”后按每周时间开关门；尚未设置时间时保持营业。暂停和停止接单会覆盖时间安排。</p><div class="ra-hours">';
        foreach(self::DAYS as $day=>$label){
            $periods=is_array($s['hours'][$day]??null)?$s['hours'][$day]:[];
            $body.='<fieldset data-hours-day="'.$day.'"><legend>'.$label.'</legend>'.self::toggle('hours_enabled['.$day.']','这天营业',$periods!==[]).'<div data-period-list>';
            foreach($periods!==[]?$periods:[['open'=>'','close'=>'']] as $index=>$period){$body.=self::period($day,(string)$index,(string)($period['open']??''),(string)($period['close']??''));}
            $body.='</div><button type="button" class="ra-secondary" data-add-period="'.$day.'">＋ 增加一个时段</button><template data-period-template>'.self::period($day,'__index__','','');
            $body.='</template></fieldset>';
        }
        $body.='</div><p class="ra-help">可填写午餐、晚餐两个时段，也可连续营业。结束时间早于开始时间表示营业到次日；这不会限制午餐或儿童餐的购买时间。</p>';
        $html.=self::section('hours','什么时候接单','每周营业安排一眼看清，休息日不勾选即可。',$body);
        $body='<div class="ra-grid"><fieldset><legend>自取</legend>'.self::toggle('pickup_available','允许顾客到店自取',(bool)($s['pickup_available']??false)).'</fieldset><fieldset><legend>配送</legend>'.self::toggle('delivery_available','允许配送到家',(bool)($s['delivery_available']??false)).self::input('delivery_fee','每单配送费',isset($s['delivery_fee_minor'])?number_format((int)$s['delivery_fee_minor']/100,2,'.',''):'','text','inputmode="decimal" placeholder="例如 3.50" maxlength="12"').'<label class="ra-field"><span>可配送的邮编</span><textarea name="delivery_postal_codes" rows="3" maxlength="4000" placeholder="例如 29204, 29206">'.self::e(implode(', ',is_array($s['delivery_postal_codes']??null)?$s['delivery_postal_codes']:[])).'</textarea></label><p class="ra-help">多个邮编用逗号或换行分开。配送费为0也请明确填写。</p></fieldset><fieldset><legend>提前预约</legend>'.self::toggle('preorder_available','允许顾客预约取餐或配送',(bool)($s['preorder_available']??false)).self::input('preorder_lead_minutes','至少提前多少分钟',$s['preorder_lead_minutes']??30,'number','min="15" max="1440"').self::input('preorder_max_days','最多提前多少天',$s['preorder_max_days']??7,'number','min="1" max="30"').'<p class="ra-help">预约须有营业时间，并按门店所在时区安排。</p></fieldset></div>';
        $html.=self::section('fulfillment','顾客怎么取餐','自取、配送和预约可以分别开启。',$body);
        $countries=[''=>'请选择','US'=>'美国','CA'=>'加拿大','MX'=>'墨西哥','CN'=>'中国','GB'=>'英国','AU'=>'澳大利亚'];
        $country=(string)($s['merchant_region']??'');if($country!==''&&!isset($countries[$country])){$countries[$country]=$country;}
        $body='<div class="ra-grid">'.self::input('tax_rate','销售税率（%）',$tax['rate_percent']??'','text','inputmode="decimal" placeholder="例如 10.00；无税请填0"').self::select('delivery_taxable','配送费是否收税',[''=>'请选择','no'=>'不收税','yes'=>'按同一税率收税'],!empty($tax['configured'])?(!empty($tax['delivery_taxable'])?'yes':'no'):'').self::select('merchant_region','餐馆所在国家',$countries,$country).self::select('transaction_region','收款适用地区',[''=>'请选择','international'=>'美国或其他海外地区','cn_domestic'=>'中国境内','cross_border'=>'跨境收款'],(string)($s['transaction_region']??'')).'</div><p class="ra-help">税率请按门店实际情况填写。菜品和收费加料参与计税；保存不会修改旧订单的金额。收款方式仍在原有支付设置中管理。</p>';
        $html.=self::section('tax','税费与收款地区','金额直接填写，不需要换算。',$body);
        $body='<div class="ra-grid">'.self::input('storefront_image_url','门头照片地址',$s['storefront_image_url']??'','text','data-image-url="hero" placeholder="从媒体库复制图片地址" maxlength="2000"').self::input('logo_url','餐馆标志图片地址（可留空）',$s['logo_url']??'','text','maxlength="2000"').'</div><p class="ra-help">使用媒体库中的真实门店照片，先确认招牌完整，再调整手机上的显示位置。</p><div class="ra-photo-preview" data-image-preview="hero"><img alt="门头显示预览" hidden><p>填写照片地址后，在这里查看门头</p></div><div class="ra-grid">';
        foreach(['desktop'=>'电脑','mobile'=>'手机'] as $device=>$label){
            [$x,$y]=self::focus((string)($s[$device.'_focal_point']??($device==='mobile'?'50% 45%':'50% 50%')));
            $body.='<fieldset><legend>'.$label.'照片重点位置</legend>'.self::input($device.'_focus_x','左右位置',$x,'range','min="0" max="100" data-focus="'.$device.'-x"').self::input($device.'_focus_y','上下位置',$y,'range','min="0" max="100" data-focus="'.$device.'-y"').'</fieldset>';
        }
        $body.='</div><fieldset><legend>餐馆照片</legend><div data-gallery-list>';
        $gallery=is_array($s['gallery']??null)?$s['gallery']:[];
        foreach($gallery!==[]?$gallery:[[]] as $index=>$item){$body.=self::galleryRow((string)$index,is_array($item)?$item:[]);}
        $body.='</div><button type="button" class="ra-secondary" data-add-gallery>＋ 添加照片</button><template data-gallery-template>'.self::galleryRow('__index__',[]).'</template></fieldset>';
        $html.=self::section('appearance','门头和餐馆照片','让顾客一眼认出餐馆。',$body);
        $body='<p class="ra-help">填写你需要的语言。留空时沿用已有介绍或餐馆名称；不会自动编造翻译。</p>';
        foreach(self::LANGUAGES as $locale=>$label){$body.='<details class="ra-language"'.($locale===$defaultLocale?' open':'').'><summary>'.$label.'</summary><div class="ra-grid">';foreach(self::TEXT as $key=>$name){$value=self::localized($s[$key]??'', $locale);$body.='<label class="ra-field"><span>'.self::e($name).'</span><textarea name="localized['.$key.']['.$locale.']" rows="'.($key==='about_body'?4:2).'" maxlength="6000">'.self::e($value).'</textarea></label>';}$body.='</div></details>';}
        $html.=self::section('content','用顾客熟悉的语言介绍餐馆','名称、门头文案、地址和介绍都可以分别填写。',$body);
        $body='<div class="ra-notice"><strong>目前使用网站订单看板接单</strong><p>Square 和 Clover 目前仅可在测试环境连接，正式营业连接尚未开放；可在独立连接页面设置；需要管理员先开通品牌应用。</p></div><p>连接步骤：选择收银品牌 → 登录并允许连接 → 选择门店 → 核对菜品 → 确认接单方式。</p><div class="ra-pos-grid">';
        foreach(['Toast'=>'需要先由服务商开通合作应用和网站接单权限。','Lightspeed'=>'需要先确认产品系列及接入资格。','Aloha / NCR Voyix'=>'需要先由服务商确认门店版本及接入方式。','Oracle MICROS'=>'需要先确认门店产品和合作应用资格。'] as $brand=>$hint){$body.='<details class="ra-pos-card"><summary>'.self::e($brand).'<span>尚未开放</span></summary><p>'.self::e($hint).'</p><p>完成官方接入并实测后，才会提供连接按钮。</p></details>';}
        $body.='</div><p><a href="/admin/restaurant/pos">设置 Square / Clover 连接 →</a></p><p class="ra-help">连接收银系统不等于连接打印机。上线前还需核对收银机是否收到订单、厨房是否出单；网站已经收过款的订单不应再次收费。</p>';
        $html.=self::section('pos','网站订单送到你的收银系统','保留现有收银设备，按支持的品牌逐步接入。',$body);
        return $html.'<div class="ra-savebar"><span data-save-state role="status" aria-live="polite">修改后点击保存</span><button type="submit">保存店面设置</button><a href="/" target="_blank" rel="noopener">查看网站 ↗</a></div></form>';
    }

    private static function period(string $day,string $index,string $open,string $close): string
    {
        return '<div class="ra-period" data-period>'.self::input('hours['.$day.']['.$index.'][open]','开始',$open,'time').'<span aria-hidden="true">—</span>'.self::input('hours['.$day.']['.$index.'][close]','结束',$close,'time').'<button type="button" class="ra-remove" data-remove-row aria-label="移除此营业时段">移除</button></div>';
    }

    private static function galleryRow(string $index,array $item): string
    {
        $row='<div class="ra-gallery-row" data-gallery-row>'.self::input('gallery['.$index.'][url]','照片地址',$item['url']??$item['image_url']??'','text','maxlength="2000"');
        foreach(self::LANGUAGES as $locale=>$label){$row.=self::input('gallery['.$index.'][alt]['.$locale.']',$label.'照片说明',self::localized($item['alt']??'', $locale),'text','maxlength="500"');}
        return $row.'<button type="button" class="ra-remove" data-remove-row>移除此照片</button></div>';
    }

    private static function localized(mixed $value,string $locale): string { return is_array($value)?(string)($value[$locale]??''):(is_string($value)?$value:''); }

    private static function focus(string $value): array
    {
        return preg_match('/^(\d+(?:\.\d+)?)%\s+(\d+(?:\.\d+)?)%$/',$value,$m)?[(int)$m[1],(int)$m[2]]:[50,50];
    }

    /** Merge only fields presented by this form, retaining extensions and unknown languages. */
    public static function merge(Request $request,array $current): array
    {
        $s=$current;
        $scalar=static function(string $name,string $default='')use($request):string{$value=$request->input($name,$default);if(!is_string($value)){throw new \InvalidArgumentException('请检查表单中的文字和数字。');}return trim($value);};
        $zone=$scalar('timezone','America/New_York');
        try{new \DateTimeZone($zone);}catch(\Exception){throw new \InvalidArgumentException('请选择有效的门店时区。');}
        $status=$scalar('store_status',(string)($s['status']??'closed'));
        if(!in_array($status,['open','paused','closed','closing_soon'],true)&&$status!==($s['status']??null)){throw new \InvalidArgumentException('请选择是否接单。');}
        $s['status']=$status;
        foreach(['pickup_available','delivery_available','preorder_available'] as $key){$s[$key]=$request->input($key,'')==='1';}
        $fee=$scalar('delivery_fee');
        if($fee!==''){
            if(!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/',$fee)){throw new \InvalidArgumentException('配送费请填写不小于0的金额，最多两位小数。');}
            $parts=explode('.',$fee);$s['delivery_fee_minor']=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
        }else{unset($s['delivery_fee_minor']);}
        $postals=$scalar('delivery_postal_codes');
        if(strlen($postals)>4000){throw new \InvalidArgumentException('配送邮编过多，请缩短列表。');}
        $s['delivery_postal_codes']=array_values(array_unique(array_filter(array_map(static fn(string $v):string=>strtoupper(trim($v)),preg_split('/[,，;；\r\n]+/u',$postals)?:[]))));
        foreach($s['delivery_postal_codes'] as $code){if(!preg_match('/^[A-Z0-9 -]{3,10}$/',$code)){throw new \InvalidArgumentException('请检查配送邮编，多个邮编用逗号或换行分开。');}}
        if($s['delivery_available']&&(!isset($s['delivery_fee_minor'])||$s['delivery_postal_codes']===[])){throw new \InvalidArgumentException('开启配送前，请填写配送费和可配送邮编。');}
        foreach(['preorder_lead_minutes'=>[15,1440,30],'preorder_max_days'=>[1,30,7]] as $key=>[$min,$max,$default]){
            $value=filter_var($scalar($key,(string)($s[$key]??$default)),FILTER_VALIDATE_INT);
            if($value===false||$value<$min||$value>$max){throw new \InvalidArgumentException('预约的提前时间或天数超出可选范围。');}$s[$key]=$value;
        }
        $hours=$request->input('hours',[]);$enabled=$request->input('hours_enabled',[]);
        if(!is_array($hours)||!is_array($enabled)){throw new \InvalidArgumentException('请检查营业时间。');}
        $schedule=is_array($s['hours']??null)?$s['hours']:[];
        foreach(self::DAYS as $day=>$label){
            $schedule[$day]=[];
            if(($enabled[$day]??'')!=='1'){continue;}
            $periods=$hours[$day]??[];if(!is_array($periods)||count($periods)>8){throw new \InvalidArgumentException($label.'最多填写8个营业时段。');}
            foreach($periods as $index=>$period){
                if(!is_array($period)||!is_string($period['open']??'')||!is_string($period['close']??'')){throw new \InvalidArgumentException('请检查'.$label.'的开始和结束时间。');}
                $open=trim($period['open']??'');$close=trim($period['close']??'');
                if($open===''&&$close===''){continue;}
                if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$open)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$close)||$open===$close){throw new \InvalidArgumentException('请为'.$label.'填写不同的开始和结束时间。');}
                $old=is_array($s['hours'][$day][$index]??null)?$s['hours'][$day][$index]:[];
                $schedule[$day][]=array_replace($old,['open'=>$open,'close'=>$close]);
            }
            if($schedule[$day]===[]){throw new \InvalidArgumentException('已选择'.$label.'营业，请至少填写一个时段。');}
        }
        // No weekly schedule remains a supported legacy/manual-open mode.
        if(array_filter(array_intersect_key($schedule,self::DAYS))===[]){foreach(array_keys(self::DAYS) as $day){unset($schedule[$day]);}}
        $s['hours']=$schedule;
        if($s['preorder_available']&&array_filter(array_intersect_key($schedule,self::DAYS))===[]){throw new \InvalidArgumentException('开启预约前，请先填写营业时间。');}
        $country=strtoupper($scalar('merchant_region'));
        if($country!==''&&!preg_match('/^[A-Z]{2}$/',$country)){throw new \InvalidArgumentException('请选择餐馆所在国家。');}$s['merchant_region']=$country;
        $region=$scalar('transaction_region');
        if(!in_array($region,['','international','cn_domestic','cross_border'],true)){throw new \InvalidArgumentException('请选择收款适用地区。');}$s['transaction_region']=$region;
        foreach(['storefront_image_url','logo_url'] as $key){$s[$key]=self::imageUrl($scalar($key));}
        foreach(['desktop','mobile'] as $device){
            $key=$device.'_focal_point';$old=(string)($s[$key]??($device==='mobile'?'50% 45%':'50% 50%'));[$oldX,$oldY]=self::focus($old);
            $x=filter_var($scalar($device.'_focus_x',(string)$oldX),FILTER_VALIDATE_INT);$y=filter_var($scalar($device.'_focus_y',(string)$oldY),FILTER_VALIDATE_INT);
            if($x===false||$y===false||$x<0||$x>100||$y<0||$y>100){throw new \InvalidArgumentException('照片重点位置须在0到100之间。');}
            if($x!==$oldX||$y!==$oldY){$s[$key]=$x.'% '.$y.'%';}
        }
        $localized=$request->input('localized',[]);if(!is_array($localized)){throw new \InvalidArgumentException('请检查三语介绍。');}
        foreach(self::TEXT as $key=>$label){if(isset($localized[$key])){$s[$key]=self::mergeLocalized($s[$key]??'', $localized[$key],6000);}}
        $gallery=$request->input('gallery',[]);if(!is_array($gallery)||count($gallery)>40){throw new \InvalidArgumentException('餐馆照片最多40张。');}
        $photos=[];
        foreach($gallery as $index=>$row){
            if(!is_array($row)||!is_string($row['url']??'')){throw new \InvalidArgumentException('请检查照片地址。');}
            $url=self::imageUrl(trim($row['url']??''));if($url===''){continue;}
            $old=is_array($s['gallery'][$index]??null)?$s['gallery'][$index]:[];
            $old['url']=$url;$old['alt']=self::mergeLocalized($old['alt']??'', $row['alt']??[],500);$photos[]=$old;
        }
        $s['gallery']=$photos;
        return $s;
    }

    private static function mergeLocalized(mixed $current,mixed $input,int $max): mixed
    {
        if(!is_array($input)){throw new \InvalidArgumentException('请检查多语言内容。');}
        $same=true;$values=[];
        foreach(self::LANGUAGES as $locale=>$unused){$value=$input[$locale]??self::localized($current,$locale);if(!is_string($value)||mb_strlen($value)>$max){throw new \InvalidArgumentException('文字过长或格式不正确。');}$values[$locale]=trim($value);if($values[$locale]!==self::localized($current,$locale)){$same=false;}}
        if($same){return $current;}
        $result=is_array($current)?$current:[];
        foreach($values as $locale=>$value){if($value===''){unset($result[$locale]);}else{$result[$locale]=$value;}}
        return $result===[]?'':$result;
    }

    private static function imageUrl(string $value): string
    {
        if($value===''||strlen($value)<=2000&&(preg_match('~^https?://[^\s<>]+$~i',$value)||preg_match('~^/(?!/)[^\s<>\\\\]*$~',$value))){return $value;}
        throw new \InvalidArgumentException('照片地址请使用媒体库地址，或以 https:// 开头的图片地址。');
    }
}
