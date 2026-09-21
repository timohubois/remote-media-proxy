<?php

namespace RemoteMediaProxy;

use RemoteMediaProxy\Features\ApacheRouting;
use RemoteMediaProxy\Features\OptionsMedia;

defined('ABSPATH') || exit;

final class Plugin
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        foreach (['Features', 'Compatibility'] as $directory) {
            self::createInstances($directory);
        }
    }

    private static function createInstances(string $directory): void
    {
        $namespace = __NAMESPACE__ . '\\' . $directory;
        foreach (glob(__DIR__ . '/' . $directory . '/*.php') ?: [] as $filename) {
            $className = $namespace . '\\' . basename($filename, '.php');
            if (!class_exists($className)) {
                continue;
            }
            if (is_callable([$className, 'getInstance'])) {
                $className::getInstance();
            } else {
                new $className();
            }
        }
    }

    public static function onPluginActivation(bool $networkWide = false): void
    {
        add_option(OptionsMedia::OPTION_NAME, [], '', false);
        ApacheRouting::getInstance()->syncSites($networkWide);
    }

    public static function onPluginDeactivation(bool $networkWide = false): void
    {
        ApacheRouting::getInstance()->syncSites($networkWide, true);
    }
}
