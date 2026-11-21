<?php

declare(strict_types=1);

namespace Cms\Core\Seo;

/** Network operations are called only by the CLI worker, never by record()/render(). */
final class CrawlerVerificationNetwork
{
    public static function fetch(string $url): ?string
    {
        if (PHP_SAPI !== 'cli' || !in_array($url, CrawlerSourcePolicy::FEEDS, true) || !function_exists('curl_init')) { return null; }
        $body = ''; $handle = curl_init($url);
        if ($handle === false) { return null; }
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 750, CURLOPT_TIMEOUT_MS => 2000,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'Daiying-Crawler-Source-Verification/1.0',
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) { return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($handle);
            return $ok !== false && curl_getinfo($handle, CURLINFO_HTTP_CODE) === 200 ? $body : null;
        } finally { unset($handle); }
    }

    /** null means unavailable/timeout, false means completed without a matching source. */
    public static function dns(string $ip, array $suffixes): ?bool
    {
        if (PHP_SAPI !== 'cli' || CrawlerSourcePolicy::publicIp($ip) === null || $suffixes === [] || !function_exists('proc_open')) { return null; }
        // The system resolver runs in one bounded child. IPs go over stdin, never shell/argv/logs.
        $code = <<<'PHP'
ini_set('display_errors', '0');
try {
    $input = json_decode(stream_get_contents(STDIN, 4096), true, 8, JSON_THROW_ON_ERROR);
    echo json_encode(\Cms\Core\Seo\CrawlerSourcePolicy::verifyDns($input['ip'], $input['suffixes'], static fn ($host, $type) => @dns_get_record($host, $type)));
} catch (Throwable) { echo 'null'; }
PHP;
        $code = 'require ' . var_export(__DIR__ . '/CrawlerSourcePolicy.php', true) . ';' . $code;
        $process = @proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a']], $pipes);
        if (!is_resource($process)) { return null; }
        try {
            fwrite($pipes[0], json_encode(['ip' => $ip, 'suffixes' => $suffixes], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
            stream_set_blocking($pipes[1], false); $output = ''; $deadline = hrtime(true) + 1500000000;
            do {
                $output .= stream_get_contents($pipes[1], 16);
                $state = proc_get_status($process);
                if (!$state['running']) {
                    // The child can write between the first pipe read and this status check.
                    $output .= stream_get_contents($pipes[1], 17);
                    return strlen($output) <= 16 ? match (trim($output)) { 'true' => true, 'false' => false, default => null } : null;
                }
                if (strlen($output) > 16) { return null; }
                usleep(10000);
            } while (hrtime(true) < $deadline);
            return null;
        } finally {
            $state = proc_get_status($process);
            if ($state['running']) { proc_terminate($process, 9); }
            if (is_resource($pipes[1])) { fclose($pipes[1]); }
            proc_close($process);
        }
    }
}
