<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/classes', __DIR__ . '/remote-media-proxy.php', __DIR__ . '/uninstall.php'])
    ->withAutoloadPaths([__DIR__ . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php'])
    ->withPhpSets(php83: true);
