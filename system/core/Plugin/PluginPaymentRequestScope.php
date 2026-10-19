<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Bootstrap\Application;

final class PluginPaymentRequestScope
{
    /** @var array{plugin_id:string,method:string,csrf:bool,actor_id:?int}|null */
    private static ?array $current = null;

    /** @template T @param callable():T $callback @return T */
    public static function run(string $pluginId, string $method, bool $csrfVerified, ?int $actorId, callable $callback, ?int $adminId = null): mixed
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? '';
        if ($caller !== Application::class) {
            throw new PluginException('capability_denied');
        }
        $previous = self::$current;
        self::$current = ['plugin_id' => $pluginId, 'method' => $method, 'csrf' => $csrfVerified, 'actor_id' => $actorId, 'admin_id' => $adminId];
        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    /** @return array{plugin_id:string,method:string,csrf:bool,actor_id:?int}|null */
    public static function current(): ?array
    {
        return self::$current;
    }
}
