<?php

/** Remove owned routing and settings, never uploads or attachment data. */

namespace RemoteMediaProxy;

use RemoteMediaProxy\Features\ApacheRouting;
use RemoteMediaProxy\Features\OptionsMedia;

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/classes/Features/OptionsMedia.php';
require_once __DIR__ . '/classes/Features/ApacheRouting.php';

if (is_multisite()) {
    $remoteMediaProxyOffset = 0;
    do {
        $remoteMediaProxySites = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $remoteMediaProxyOffset]);
        foreach ($remoteMediaProxySites as $remoteMediaProxySiteId) {
            switch_to_blog((int) $remoteMediaProxySiteId);
            try {
                ApacheRouting::removeOrFail();
                OptionsMedia::deleteOptions();
            } finally {
                restore_current_blog();
            }
        }
        $remoteMediaProxyOffset += 100;
    } while (count($remoteMediaProxySites) === 100);
} else {
    ApacheRouting::removeOrFail();
    OptionsMedia::deleteOptions();
}
