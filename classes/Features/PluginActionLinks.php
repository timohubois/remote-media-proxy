<?php

namespace RemoteMediaProxy\Features;

defined('ABSPATH') || exit;

/** Add the Media settings shortcut to this plugin's action links. */
final class PluginActionLinks
{
    /** Register the action-link filter for this plugin only. */
    public function __construct()
    {
        add_filter('plugin_action_links_' . plugin_basename(REMOTE_MEDIA_PROXY_PLUGIN_FILE), [$this, 'links']);
    }

    /**
     * Append the settings link without replacing existing plugin actions.
     *
     * @param array<array-key,string> $links Existing action-link markup.
     * @return array<array-key,string> Action links including the escaped settings shortcut.
     */
    public function links(array $links): array
    {
        $links[] = '<a href="' . esc_url(admin_url('options-media.php#remote_media_proxy_url')) . '">'
            . esc_html__('Settings', 'remote-media-proxy') . '</a>';
        return $links;
    }
}
