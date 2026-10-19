<?php

declare(strict_types=1);
namespace Cms\Core\Media;

/** Stored under protected CMS storage, never content/uploads. */
final class PrivateMediaStorageProvider implements MediaStorageProviderInterface
{
    private LocalMediaStorageProvider $local;
    public function __construct(string $root) { $this->local = new LocalMediaStorageProvider($root); }
    public function id(): string { return 'private'; }
    public function put(string $sourcePath, string $storageKey, bool $move): void {
        $this->local->put($sourcePath, $storageKey, $move); chmod($this->local->path($storageKey), 0600);
    }
    public function delete(string $storageKey): void { $this->local->delete($storageKey); }
    public function exists(string $storageKey): bool { return $this->local->exists($storageKey); }
    public function path(string $storageKey): string { return $this->local->path($storageKey); }
}
