<?php

declare(strict_types=1);
namespace Cms\Core\Plugin;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Media\MediaLibrary;
use PDO;

/** Explicit administrator-only publication of generated site branding. */
final class PluginBrandingService
{
    public function __construct(private readonly PluginManifest $manifest,private readonly PDO $pdo,private readonly string $root) {}
    public function apply(string $logoPng,string $iconPng,string $name,string $key): array
    {
        $scope=PluginPaymentRequestScope::current();$admin=(int)($scope['admin_id']??0);
        $caps=$_SESSION['admin_user']['capabilities']??[];
        if(!in_array('site.brand.write',$this->manifest->capabilities,true)||$scope===null||$scope['plugin_id']!==$this->manifest->id||$scope['method']!=='POST'||!$scope['csrf']||$admin<1||$admin!==(int)($_SESSION['admin_user']['id']??0)
            ||!array_intersect(['*','admin.super','admin.settings.manage'],$caps))throw new PluginException('capability_denied');
        $q=$this->pdo->prepare("SELECT version FROM cms_plugins WHERE plugin_id=? AND status='Enabled'");$q->execute([$this->manifest->id]);
        if($q->fetchColumn()!==$this->manifest->version)throw new PluginException('capability_denied');
        $q=$this->pdo->prepare('SELECT id FROM cms_admin_users WHERE id=?');$q->execute([$admin]);if(!$q->fetchColumn())throw new PluginException('capability_denied');
        if($name===''||mb_strlen($name)>120||!preg_match('/^[a-zA-Z0-9_.-]{8,96}$/D',$key))throw new PluginException('invalid_request');
        foreach([$logoPng,$iconPng]as$png){$s=@getimagesizefromstring($png);if(strlen($png)>4194304||!$s||$s[2]!==IMAGETYPE_PNG||$s[0]*$s[1]>4000000)throw new PluginException('image_invalid');}
        $hash=hash('sha256',$logoPng.$iconPng.$name);$data=new PluginDataStore($this->pdo,$this->manifest->id);
        $state=$data->mutate('branding',$key,static function(?array $old)use($hash){if($old!==null&&$old['hash']!==$hash)throw new PluginException('idempotency_conflict');return $old??['hash'=>$hash,'status'=>'prepared'];});
        if($state['status']==='completed')return $state['result'];
        $lib=new MediaLibrary($this->pdo,$this->root.'/public/uploads');$ids=[];
        foreach(['logo'=>$logoPng,'icon'=>$iconPng]as$type=>$png){$tmp=tempnam(sys_get_temp_dir(),'cms-brand-');try{file_put_contents($tmp,$png);chmod($tmp,0600);$ids[$type]=$lib->registerLocalFile($tmp,'brand-'.$type.'.png');}finally{if(is_file($tmp))unlink($tmp);}}
        $result=['logo_url'=>$lib->viewModel($ids['logo'])['url'],'favicon_url'=>$lib->viewModel($ids['icon'])['url'],'name'=>$name];
        if(!is_string($result['logo_url'])||$result['logo_url']===''||!is_string($result['favicon_url'])||$result['favicon_url']==='')throw new PluginException('media_unavailable');
        $path=$this->root.'/config/app.php';$lock=fopen($this->root.'/config/.branding.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new PluginException('config_unavailable');
        try{
            $current=$data->document('branding',$key);if(($current['status']??'')==='completed')return $current['result'];
            $original=file_get_contents($path);$config=(static fn($p)=>require $p)($path);if(!is_array($config))throw new PluginException('config_invalid');
            $backupDir=$this->root.'/storage/backups/branding';if(!is_dir($backupDir)&&!mkdir($backupDir,0700,true))throw new PluginException('backup_failed');
            $backup=gmdate('YmdHis').'-'.bin2hex(random_bytes(6)).'.php';
            if(file_put_contents($backupDir.'/'.$backup,$original)===false)throw new PluginException('backup_failed');chmod($backupDir.'/'.$backup,0600);
            $config['site']['name']=$name;$config['site']['logo_url']=$result['logo_url'];$config['site']['favicon_url']=$result['favicon_url'];
            $tmp=tempnam(dirname($path),'.branding-');try{chmod($tmp,0600);if(file_put_contents($tmp,"<?php\n\nreturn ".var_export($config,true).";\n")===false||!rename($tmp,$path))throw new PluginException('config_write_failed');}finally{if(is_file($tmp))unlink($tmp);}
            if(function_exists('opcache_invalidate'))opcache_invalidate($path,true);
            $result['backup_id']=$backup;
            (new AuditLogger($this->pdo))->record('admin',$admin,'plugin.site_brand.apply',['plugin_id'=>$this->manifest->id,'media_ids'=>$ids,'idempotency_key'=>$key]);
            $data->mutate('branding',$key,static fn(?array $old)=>['hash'=>$hash,'status'=>'completed','result'=>$result]);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        return $result;
    }
}
