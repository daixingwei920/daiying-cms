<?php

declare(strict_types=1);

namespace Cms\Core\Events;

final class EventDispatcher
{
    /** @var array<string, list<callable(object): void>> */
    private array $listeners = [];

    /** @param callable(object): void $listener */
    public function listen(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    public function dispatch(object $event): void
    {
        $eventName = $event::class;
        foreach ($this->listeners[$eventName] ?? [] as $listener) {
            $listener($event);
        }
    }
}
