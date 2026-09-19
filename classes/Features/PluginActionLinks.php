<?php

namespace RemoteMediaProxy\Features;

defined('ABSPATH') || exit;

final class PluginActionLinks
{
    public function __construct()
    {
        add_filter('plugin_action_links_' . plugin_basename(REMOTE_MEDIA_PROXY_PLUGIN_FILE), [$this, 'links']);
    }

    public function links(array $links): array
    {
        $links[] = '<a href="' . esc_url(admin_url('options-media.php#remote_media_proxy_url')) . '">'
            . esc_html__('Settings', 'remote-media-proxy') . '</a>';
        return $links;
    }
}
