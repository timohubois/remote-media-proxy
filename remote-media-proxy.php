<?php

/**
 * Plugin Name:       Remote Media Proxy
 * Plugin URI:        https://github.com/timohubois/remote-media-proxy/
 * Description:       Use production media on local and staging WordPress sites without copying the uploads library.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Timo Hubois
 * Author URI:        https://pixelsaft.wtf
 * Text Domain:       remote-media-proxy
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace RemoteMediaProxy;

defined('ABSPATH') || exit;

if (!defined('REMOTE_MEDIA_PROXY_PLUGIN_FILE')) {
    define('REMOTE_MEDIA_PROXY_PLUGIN_FILE', __FILE__);
}

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $className): void {
        $prefix = __NAMESPACE__ . '\\';
        if (!str_starts_with($className, $prefix)) {
            return;
        }
        $relativeClass = substr($className, strlen($prefix));
        $file = __DIR__ . '/classes/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

register_activation_hook(REMOTE_MEDIA_PROXY_PLUGIN_FILE, [Plugin::class, 'onPluginActivation']);
register_deactivation_hook(REMOTE_MEDIA_PROXY_PLUGIN_FILE, [Plugin::class, 'onPluginDeactivation']);
add_action('plugins_loaded', [Plugin::class, 'init']);
