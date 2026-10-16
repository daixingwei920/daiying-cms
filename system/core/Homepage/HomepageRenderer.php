<?php
declare(strict_types=1);
namespace Cms\Core\Homepage;
use Cms\Core\Http\{Request,Response};
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Logging\FileLogger;
final class HomepageRenderer {
 public function __construct(private readonly HomepageSelection $selection,private readonly PluginRuntimeRegistry $runtime,private readonly FileLogger $logger){}
 /** Null delegates to Core's existing theme/safe-theme path and security response processing. */
 public function render(Request $request):?Response {
  try{$source=$this->selection->snapshot();$visited=[];
   for($n=0;$n<8&&$source['provider']!=='core.theme';$n++){
    if(isset($visited[$source['provider']]))break;$visited[$source['provider']]=true;
    $provider=$this->runtime->homepages()[$source['provider']]??null;
    if($provider!==null&&$provider['owner']===$source['owner']){
     try{$response=($provider['renderer'])($request);if(!$response instanceof Response||$response->status()!==200||!str_starts_with(strtolower($response->headers()['Content-Type']??''),'text/html'))throw new \RuntimeException('Invalid homepage response');return $response;}
     catch(\Throwable $e){$this->logger->error('Homepage renderer failed',['source'=>'Core','provider'=>$source['provider'],'error'=>$e::class]);}
    }
    $lease=$this->selection->lease($source['owner']);
    if(!$lease||$lease['token']!==$source['token'])break;$source=$lease['previous'];
   }
  }catch(\Throwable $e){$this->logger->error('Homepage selection unavailable',['source'=>'Core','error'=>$e::class]);}
  return null;
 }
}
