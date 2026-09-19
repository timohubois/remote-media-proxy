<?php

namespace RemoteMediaProxy;

use RemoteMediaProxy\Features\ApacheRouting;
use RemoteMediaProxy\Features\OptionsMedia;

defined('ABSPATH') || exit;

final class Plugin
{
    public static function init(): void
    {
        foreach (['Features', 'Compatibility'] as $directory) {
            self::createInstances($directory);
        }
    }

    private static function createInstances(string $directory): array
    {
        $namespace = __NAMESPACE__ . '\\' . $directory;
        $instances = [];
        foreach (glob(__DIR__ . '/' . $directory . '/*.php') ?: [] as $filename) {
            $className = $namespace . '\\' . basename($filename, '.php');
            if (class_exists($className)) {
                $instances[] = is_callable([$className, 'getInstance']) ? $className::getInstance() : new $className();
            }
        }
        return $instances;
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
