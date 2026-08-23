<?php

declare(strict_types=1);

namespace Cms\Core\Media;

use Throwable;

final class LocalMediaStorageProvider implements MediaStorageProviderInterface
{
    public function __construct(private readonly string $root)
    {
    }

    public function id(): string
    {
        return 'local';
    }

    public function put(string $sourcePath, string $storageKey, bool $move): void
    {
        $target = $this->path($storageKey);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new MediaException('Unable to create media storage directory.');
        }
        $staged = $target . '.part';

        try {
            if ($move) {
                if (!rename($sourcePath, $staged)) {
                    throw new MediaException('Unable to stage upload.');
                }
            } elseif (!copy($sourcePath, $staged)) {
                throw new MediaException('Unable to stage media file.');
            }
            if (!rename($staged, $target)) {
                throw new MediaException('Unable to store upload.');
            }
        } catch (Throwable $exception) {
            if (is_file($staged)) {
                unlink($staged);
            }
            if ($move && is_file($sourcePath)) {
                unlink($sourcePath);
            }
            throw $exception;
        }
    }

    public function delete(string $storageKey): void
    {
        $path = $this->path($storageKey);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function exists(string $storageKey): bool
    {
        return is_file($this->path($storageKey));
    }

    public function path(string $storageKey): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('\\', '/', ltrim($storageKey, '/'));
    }
}
