<?php

namespace Queuewatch\Laravel;

use Composer\InstalledVersions;

class Queuewatch
{
    /**
     * Fallback version reported when the real installed version cannot be
     * resolved from Composer's runtime metadata. Deliberately not a real
     * release number — a hardcoded version here would go stale at every
     * release and silently corrupt the package_version signal QueueWatch
     * stores for each worker run.
     */
    public const VERSION = 'unknown';

    public static function version(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('queuewatch/laravel')) {
            return InstalledVersions::getPrettyVersion('queuewatch/laravel') ?? self::VERSION;
        }

        return self::VERSION;
    }
}
