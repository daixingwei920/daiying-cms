<?php

declare(strict_types=1);
namespace Cms\Core\Ai;

/** Shared transport used by the production adapter and local timeout acceptance. */
final class AiImageHttpTransport
{
    public static function post(string $url,array $headers,string|array $body,int $timeout): array
    {
        if($timeout<1||$timeout>120)throw new AiException('图像 Timeout 无效。','request_invalid');
        $loopback=str_starts_with($url,'http://127.0.0.1:');
        if(!str_starts_with($url,'https://')&&!$loopback)throw new AiException('图像端点必须使用 HTTPS。','request_invalid');
        $ch=curl_init($url);$bytes='';
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>$loopback?CURLPROTO_HTTP:CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>min(10,$timeout),CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$bytes):int{
                if(strlen($bytes)+strlen($chunk)>40*1024*1024)return 0;
                $bytes.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);unset($ch);
        if($ok===false)throw new AiException('图像请求结果未知，请勿自动重发；需人工核对服务商用量。','image_result_unknown');
        return ['status'=>$status,'body'=>$bytes];
    }
}
