<?php

declare(strict_types=1);

namespace Local\Storage\Baidu;

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

        if (!is_string($raw)) {
            throw new \RuntimeException($error !== '' ? '百度网盘接口请求失败。' : '百度网盘接口没有返回内容。');
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw, 'url' => $url];
    }

    public function downloadTo(string $url, string $targetPath, int $maxBytes, int $timeout = 60): int
    {
        $download = $this->downloadBytes($url, null, $maxBytes, $timeout);
        $bytes = file_put_contents($targetPath, $download['body']);
        if (!is_int($bytes)) {
            throw new \RuntimeException('无法写入临时文件。');
        }

        return $bytes;
    }

    /** @param array{0:int,1:int}|null $range @return array{status:int,headers:array<string,string>,body:string,final_url:string} */
    public function downloadBytes(string $url, ?array $range, int $maxBytes, int $timeout = 60): array
    {
        if (!BaiduApiClient::isSafeBaiduDownloadUrl($url)) {
            throw new \RuntimeException('百度网盘下载地址不在允许列表中。');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('当前 PHP 未启用 curl，无法下载百度网盘文件。');
        }

        $currentUrl = $url;
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $response = $this->downloadRequest($currentUrl, $range, $maxBytes, $timeout);
            if (!in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                if ($response['status'] >= 400 || $this->looksLikeBaiduError($response)) {
                    throw new \RuntimeException('百度网盘文件暂不可下载。');
                }

                return $response;
            }

            $location = (string) ($response['headers']['location'] ?? '');
            if ($location === '') {
                throw new \RuntimeException('百度网盘下载跳转缺少目标地址。');
            }
            $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
            if (!BaiduApiClient::isSafeBaiduDownloadUrl($currentUrl)) {
                throw new \RuntimeException('百度网盘下载跳转地址不在允许列表中。');
            }
        }

        throw new \RuntimeException('百度网盘下载跳转次数过多。');
    }

    /** @param array{0:int,1:int}|null $range @return array{status:int,headers:array<string,string>,body:string,final_url:string} */
    private function downloadRequest(string $url, ?array $range, int $maxBytes, int $timeout): array
    {
        if (!BaiduApiClient::isSafeBaiduDownloadUrl($url)) {
            throw new \RuntimeException('百度网盘下载地址不在允许列表中。');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('当前 PHP 未启用 curl，无法下载百度网盘文件。');
        }

        $body = '';
        $bytes = 0;
        $tooLarge = false;
        $responseHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('无法初始化百度网盘下载。');
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => max(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'DaiyingCMS-BaiduStorage/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$bytes, &$tooLarge, $maxBytes): int {
                $length = strlen($chunk);
                $bytes += $length;
                if ($bytes > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return $length;
            },
        ];
        if ($range !== null) {
            $options[CURLOPT_RANGE] = $range[0] . '-' . $range[1];
        }
        curl_setopt_array($ch, $options);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($tooLarge) {
            throw new \RuntimeException('百度网盘文件超过 CMS 允许大小。');
        }
        if ($ok !== true) {
            throw new \RuntimeException($error !== '' ? '百度网盘文件下载失败。' : '百度网盘文件暂不可下载。');
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body, 'final_url' => $url];
    }

    /** @param array{status:int,headers:array<string,string>,body:string,final_url:string} $response */
    private function looksLikeBaiduError(array $response): bool
    {
        $contentType = strtolower((string) ($response['headers']['content-type'] ?? ''));
        if (!str_contains($contentType, 'json') && !str_starts_with(ltrim($response['body']), '{')) {
            return false;
        }
        $decoded = json_decode($response['body'], true);

        return is_array($decoded) && isset($decoded['error_code']);
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $parts = parse_url($location);
        if (is_array($parts) && isset($parts['scheme'])) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new \RuntimeException('百度网盘下载跳转地址无效。');
        }
        if (str_starts_with($location, '//')) {
            return (string) $base['scheme'] . ':' . $location;
        }
        $origin = (string) $base['scheme'] . '://' . (string) $base['host'] . (isset($base['port']) ? ':' . (string) $base['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string) ($base['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $origin . ($dir === '' ? '/' : $dir . '/') . $location;
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
