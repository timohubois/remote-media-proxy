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
        if (!current_user_can('manage_options')) {
            return;
        }
        $optionsMedia = OptionsMedia::getInstance();
        $options = $optionsMedia->getOptions();
        if (!$options['enabled']) {
            return;
        }
        $message = esc_html__('Remote Media Proxy is enabled.', 'remote-media-proxy');
        if (is_string($options['url']) && $optionsMedia->isValidUrl($options['url'])) {
            $message .= ' ' . sprintf(
                /* translators: %s: Validated remote site base URL wrapped in a code element. */
                esc_html__('This site loads missing media from %s.', 'remote-media-proxy'),
                '<code>' . esc_html($options['url']) . '</code>'
            );
        }
        $message .= ' <a href="' . esc_url(admin_url('options-media.php#remote_media_proxy_url')) . '">'
            . esc_html__('Change settings', 'remote-media-proxy') . '</a>.';
        wp_admin_notice($message, [
            'type' => 'info',
            'dismissible' => false,
            'id' => 'remote-media-proxy-enabled',
        ]);
    }
}
