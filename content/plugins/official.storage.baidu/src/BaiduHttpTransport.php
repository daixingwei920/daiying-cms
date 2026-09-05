<?php

declare(strict_types=1);

namespace Official\Storage\Baidu;

class BaiduHttpTransport
{
    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string,url:string} */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 20): array
    {
        $this->assertApiUrl($url);

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

    public function downloadTo(string $url, string $targetPath, int $maxBytes, int $timeout = 60): int
    {
        if (!BaiduApiClient::isSafeBaiduDownloadUrl($url)) {
            throw new \RuntimeException('百度网盘下载地址不在允许列表中。');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('当前 PHP 未启用 curl，无法下载百度网盘文件。');
        }

        $out = fopen($targetPath, 'wb');
        if (!is_resource($out)) {
            throw new \RuntimeException('无法写入临时文件。');
        }
        $bytes = 0;
        $tooLarge = false;
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($out);
            throw new \RuntimeException('无法初始化百度网盘下载。');
        }

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => max(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'DaiyingCMS-BaiduStorage/1.0',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($out, &$bytes, &$tooLarge, $maxBytes): int {
                $length = strlen($chunk);
                $bytes += $length;
                if ($bytes > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                fwrite($out, $chunk);
                return $length;
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($out);

        if ($tooLarge) {
            @unlink($targetPath);
            throw new \RuntimeException('百度网盘文件超过 CMS 允许大小。');
        }
        if ($ok !== true || $status >= 400) {
            @unlink($targetPath);
            throw new \RuntimeException($error !== '' ? '百度网盘文件下载失败。' : '百度网盘文件暂不可下载。');
        }

        return $bytes;
    }

    private function assertApiUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new \RuntimeException('百度网盘接口必须使用 HTTPS。');
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || preg_match('/[\x00-\x20]/', $host) || !in_array($host, BaiduApiClient::ALLOWED_API_HOSTS, true)) {
            throw new \RuntimeException('百度网盘接口地址不在允许列表中。');
        }
        $port = (int) ($parts['port'] ?? 443);
        if ($port !== 443) {
            throw new \RuntimeException('百度网盘接口必须使用 HTTPS 标准端口。');
        }
    }
}
