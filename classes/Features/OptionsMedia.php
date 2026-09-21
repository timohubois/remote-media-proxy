<?php

namespace RemoteMediaProxy\Features;

defined('ABSPATH') || exit;

final class OptionsMedia
{
    public const OPTION_NAME = 'remote_media_proxy';

    private static ?OptionsMedia $instance = null;

    public function __construct()
    {
        add_action('admin_init', [$this, 'addSettings']);
    }

    public static function getInstance(): OptionsMedia
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getOptions(): array
    {
        $options = apply_filters('remote_media_proxy_options', get_option(self::OPTION_NAME, []));
        $options = is_array($options) ? $options : [];
        $options = wp_parse_args($options, ['enabled' => false, 'url' => '', 'username' => '', 'password' => '']);
        $options['enabled'] = in_array($options['enabled'], [true, 1, '1'], true);
        return $options;
    }

    public function addSettings(): void
    {
        register_setting('media', self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeOptions'],
        ]);
        add_settings_field(
            self::OPTION_NAME,
            __('Remote Media Proxy', 'remote-media-proxy'),
            [$this, 'renderSettings'],
            'media'
        );
    }

    public function sanitizeOptions(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $previousOptions = get_option(self::OPTION_NAME, []);
        $previousOptions = is_array($previousOptions) ? $previousOptions : [];
        $url = isset($input['url']) && is_string($input['url']) ? trim($input['url']) : '';
        if ($url !== '' && !$this->isValidUrl($url)) {
            add_settings_error(
                self::OPTION_NAME,
                'remote_media_proxy_url',
                __('Use an HTTPS site URL without credentials, query or fragment.', 'remote-media-proxy')
            );
            $url = is_string($previousOptions['url'] ?? null) ? $previousOptions['url'] : '';
        }
        $username = isset($input['username']) && is_string($input['username'])
            ? sanitize_text_field($input['username']) : '';
        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
        return [
            'enabled' => in_array($input['enabled'] ?? false, [true, 1, '1'], true),
            'url' => rtrim($url, '/'),
            'username' => $username,
            'password' => $password,
        ];
    }

    public function isValidUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function renderSettings(): void
    {
        $options = $this->getOptions();
        $fields = [
            'url' => __('Remote site URL', 'remote-media-proxy'),
            'username' => __('Basic Auth username', 'remote-media-proxy'),
            'password' => __('Basic Auth password', 'remote-media-proxy'),
        ];
        ?>
        <p>
            <label for="remote_media_proxy_enabled">
                <input
                    type="checkbox"
                    id="remote_media_proxy_enabled"
                    name="remote_media_proxy[enabled]"
                    value="1"
                    <?php checked($options['enabled']); ?>
                >
                <?php esc_html_e('Enable Remote Media Proxy', 'remote-media-proxy'); ?>
            </label>
        </p>
        <p class="description">
            <?php
            esc_html_e(
                'Fetch missing media without local copies. Apache routing is managed automatically where supported.',
                'remote-media-proxy'
            );
            ?>
        </p>
        <?php foreach ($fields as $name => $label) : ?>
            <p>
                <label for="<?php echo esc_attr('remote_media_proxy_' . $name); ?>">
                    <?php echo esc_html($label); ?>
                </label><br>
                <input
                    class="regular-text"
                    id="<?php echo esc_attr('remote_media_proxy_' . $name); ?>"
                    name="<?php echo esc_attr(self::OPTION_NAME . '[' . $name . ']'); ?>"
                    type="<?php echo esc_attr($name === 'password' ? 'password' : 'text'); ?>"
                    value="<?php echo esc_attr($options[$name] ?? ''); ?>"
                    autocomplete="off"
                >
            </p>
        <?php endforeach; ?>
        <p class="description">
            <?php
            esc_html_e(
                'Credentials are stored unencrypted. Clear the password field to remove it.',
                'remote-media-proxy'
            );
            ?>
        </p>
        <?php
    }

    public static function deleteOptions(): void
    {
        delete_option(self::OPTION_NAME);
    }
}
