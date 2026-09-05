<?php

declare(strict_types=1);

namespace Official\Storage\Baidu;

class BaiduHttpTransport
{
    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string,url:string} */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 20): array
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if (!in_array($host, BaiduApiClient::ALLOWED_API_HOSTS, true)) {
            throw new \RuntimeException('百度网盘接口地址不在允许列表中。');
        }
        if ((string) (parse_url($url, PHP_URL_SCHEME) ?: '') !== 'https') {
            throw new \RuntimeException('百度网盘接口必须使用 HTTPS。');
        }

        if (!extension_loaded('curl')) {
            throw new \RuntimeException('当前 PHP 未启用 curl，无法连接百度网盘。');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('无法初始化百度网盘请求。');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => max(5, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'DaiyingCMS-BaiduStorage/1.0',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            throw new \RuntimeException($error !== '' ? '百度网盘接口请求失败。' : '百度网盘接口没有返回内容。');
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw, 'url' => $url];
    }
}
