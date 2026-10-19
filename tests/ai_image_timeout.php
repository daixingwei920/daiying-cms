<?php
/** Invoke with a local HTTP server which delays its response >120s. */
declare(strict_types=1);
require __DIR__.'/../system/core/Bootstrap/autoload.php';
$start=microtime(true);
try { Cms\Core\Ai\AiImageHttpTransport::post('http://127.0.0.1:18976/slow',[],'{}',120);throw new RuntimeException('Expected timeout.'); }
catch(Cms\Core\Ai\AiException $e){$elapsed=microtime(true)-$start;if($e->reason()!=='image_result_unknown'||$elapsed<119||$elapsed>124)throw new RuntimeException('Unexpected timeout result.');echo json_encode(['status'=>'PASS','elapsed_seconds'=>round($elapsed,2),'configured_timeout'=>120,'reason'=>$e->reason(),'scope'=>'real curl wall clock against isolated slow HTTP server']),PHP_EOL;}
