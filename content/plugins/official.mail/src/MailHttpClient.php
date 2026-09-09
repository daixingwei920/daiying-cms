<?php

declare(strict_types=1);

namespace Official\Mail;

use RuntimeException;

final class MailHttpClient
{
    private const ALLOWED_HOSTS = [
        'accounts.google.com',
        'oauth2.googleapis.com',
        'gmail.googleapis.com',
        'login.microsoftonline.com',
        'graph.microsoft.com',
    ];

    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string,final_url:string} */
    public function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return $this->request('GET', $url, $headers, null, $timeout);
    }

    /** @param array<string,string> $headers @param array<string,mixed>|string|null $body @return array{status:int,headers:array<string,string>,body:string,final_url:string} */
    public function post(string $url, array $headers = [], array|string|null $body = null, int $timeout = 20): array
    {
        return $this->request('POST', $url, $headers, $body, $timeout);
    }

    /** @param array<string,string> $headers @param array<string,mixed>|string|null $body @return array{status:int,headers:array<string,string>,body:string,final_url:string} */
    public function patch(string $url, array $headers = [], array|string|null $body = null, int $timeout = 20): array
    {
        return $this->request('PATCH', $url, $headers, $body, $timeout);
    }

    /** @param array<string,string> $headers @param array<string,mixed>|string|null $body @return array{status:int,headers:array<string,string>,body:string,final_url:string} */
    private function request(string $method, string $url, array $headers, array|string|null $body, int $timeout): array
    {
        $this->assertAllowedUrl($url);
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for OAuth mail providers.');
        }
        $responseHeaders = [];
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize HTTP request.');
        }
        $payload = null;
        if (is_array($body)) {
            $payload = http_build_query($body);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/x-www-form-urlencoded';
        } elseif (is_string($body)) {
            $payload = $body;
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(max($timeout, 3), 60),
            CURLOPT_TIMEOUT => min(max($timeout, 3), 60),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                unset($curl);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($curl);
        if (!is_string($raw)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException($this->redact($error !== '' ? $error : 'Mail provider request failed.'));
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        curl_close($curl);
        $this->assertAllowedUrl($final !== '' ? $final : $url);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $raw,
            'final_url' => $final !== '' ? $final : $url,
        ];
    }

    public function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = (int) ($parts['port'] ?? 443);
        if ($scheme !== 'https' || $host === '' || $port !== 443 || !in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException('Mail provider URL is not allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Mail provider URL userinfo is not allowed.');
        }
    }

    private function redact(string $message): string
    {
        return preg_replace('/(access_token|refresh_token|client_secret|Authorization|Bearer)\s*[=:]\s*[^&\s]+/i', '$1=[redacted]', $message) ?? 'Mail provider request failed.';
    }
}
