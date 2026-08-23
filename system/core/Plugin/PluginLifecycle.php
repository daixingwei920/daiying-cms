<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class PluginLifecycle
{
    public const INSTALLED = 'Installed';
    public const ENABLED = 'Enabled';
    public const DISABLED = 'Disabled';
    public const QUARANTINED = 'Quarantined';
    public const UPDATE_PENDING = 'Update Pending';
    public const REMOVED = 'Removed';
    public const DORMANT = 'Dormant';
    public const INSTALL_FAILED_RECOVERABLE = 'InstallFailedRecoverable';

    public const ALL = [
        self::INSTALLED,
        self::ENABLED,
        self::DISABLED,
        self::QUARANTINED,
        self::UPDATE_PENDING,
        self::REMOVED,
        self::DORMANT,
        self::INSTALL_FAILED_RECOVERABLE,
    ];
}
