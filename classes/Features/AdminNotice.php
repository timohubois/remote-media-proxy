<?php

namespace RemoteMediaProxy\Features;

defined('ABSPATH') || exit;

final class AdminNotice
{
    public function __construct()
    {
        add_action('admin_notices', [$this, 'notice']);
    }

    public function notice(): void
    {
        if (!current_user_can('manage_options') || !OptionsMedia::getInstance()->getOptions()['enabled']) {
            return;
        }
        $message = sprintf(
            /* translators: %s: Change settings link. */
            esc_html__(
                'Remote Media Proxy is enabled. This site loads missing media from your source site. %s.',
                'remote-media-proxy'
            ),
            '<a href="' . esc_url(admin_url('options-media.php#remote_media_proxy_url')) . '">'
                . esc_html__('Change settings', 'remote-media-proxy') . '</a>'
        );
        wp_admin_notice($message, [
            'type' => 'info',
            'dismissible' => false,
            'id' => 'remote-media-proxy-enabled',
        ]);
    }
}
