<?php

declare(strict_types=1);

namespace Local\Storage\Baidu;

final class BaiduTokenRefreshLock
{
    /** @template T @param callable():T $callback @return T */
    public function run(callable $callback): mixed
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'daiying-baidu-storage-refresh.lock';
        $handle = fopen($path, 'c');
        if (!is_resource($handle)) {
            return $callback();
        }

        $locked = false;
        $deadline = microtime(true) + 8.0;
        do {
            $locked = flock($handle, LOCK_EX | LOCK_NB);
            if ($locked) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        if (!$locked) {
            fclose($handle);
            throw new \RuntimeException('百度 Token 正在刷新，请稍后重试。');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
