<?php

namespace Queuewatch\Laravel;

use Composer\InstalledVersions;

class Queuewatch
{
    public const VERSION = '1.0.0';

    public static function version(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('queuewatch/laravel')) {
            return InstalledVersions::getPrettyVersion('queuewatch/laravel') ?? self::VERSION;
        }

        return self::VERSION;
    }
}
