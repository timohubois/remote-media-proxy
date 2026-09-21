<?php

namespace RemoteMediaProxy;

use RemoteMediaProxy\Features\ApacheRouting;
use RemoteMediaProxy\Features\OptionsMedia;

defined('ABSPATH') || exit;

/** Start hook-registering features and coordinate activation and deactivation. */
final class Plugin
{
    /** @var boolean Whether startup has already run during this request. */
    private static bool $initialized = false;

    /**
     * Start features and integrations once, leaving media services and helpers lazy.
     *
     * @return void
     */
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

    /**
     * Instantiate the hook-registering classes immediately inside a startup directory.
     *
     * @param string $directory Trusted class directory name supplied by init().
     * @return void
     */
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

    /**
     * Initialize settings and synchronize routing for the activation scope.
     *
     * @param boolean $networkWide Whether activation applies to the current network.
     * @return void
     */
    public static function onPluginActivation(bool $networkWide = false): void
    {
        add_option(OptionsMedia::OPTION_NAME, [], '', false);
        ApacheRouting::getInstance()->syncSites($networkWide);
    }

    /**
     * Remove owned routing while retaining saved settings and media.
     *
     * @param boolean $networkWide Whether deactivation applies to the current network.
     * @return void
     */
    public static function onPluginDeactivation(bool $networkWide = false): void
    {
        ApacheRouting::getInstance()->syncSites($networkWide, true);
    }
}
