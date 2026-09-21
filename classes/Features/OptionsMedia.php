<?php

namespace RemoteMediaProxy\Features;

use RemoteMediaProxy\Helpers\PasswordEncryption;

defined('ABSPATH') || exit;

final class OptionsMedia
{
    public const string OPTION_NAME = 'remote_media_proxy';

    private const array CONFIG_CONSTANTS = [
        'enabled' => 'REMOTE_MEDIA_PROXY_ENABLED',
        'url' => 'REMOTE_MEDIA_PROXY_URL',
        'username' => 'REMOTE_MEDIA_PROXY_USERNAME',
        'password' => 'REMOTE_MEDIA_PROXY_PASSWORD',
    ];

    private static ?OptionsMedia $instance = null;

    private function __construct()
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
        $options = get_option(self::OPTION_NAME, []);
        $options = is_array($options) ? $options : [];
        if (!defined('REMOTE_MEDIA_PROXY_PASSWORD') && array_key_exists('password', $options)) {
            $options['password'] = PasswordEncryption::decrypt($options['password']);
        }
        foreach (self::CONFIG_CONSTANTS as $name => $constant) {
            if (defined($constant)) {
                $options[$name] = constant($constant);
            }
        }
        $options = apply_filters('remote_media_proxy_options', $options);
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
        add_settings_section(
            self::OPTION_NAME,
            __('Remote Media Proxy', 'remote-media-proxy'),
            $this->renderDescription(...),
            'media'
        );
        $fields = [
            'enabled' => [
                'label' => __('Status', 'remote-media-proxy'),
                'type' => 'checkbox',
            ],
            'url' => [
                'label' => __('Remote site URL', 'remote-media-proxy'),
                'type' => 'url',
                'placeholder' => preg_replace('#^(https?://)#i', '$1example.', home_url()),
            ],
            'username' => [
                'label' => __('Basic Auth username', 'remote-media-proxy'),
                'type' => 'text',
                'placeholder' => __('Optional username', 'remote-media-proxy'),
            ],
            'password' => [
                'label' => __('Basic Auth password', 'remote-media-proxy'),
                'type' => 'password',
                'placeholder' => __('Optional password', 'remote-media-proxy'),
            ],
        ];
        foreach ($fields as $name => $field) {
            $id = self::OPTION_NAME . '_' . $name;
            $args = array_merge($field, ['name' => $name]);
            if ($name !== 'enabled') {
                $args['label_for'] = $id;
            }
            add_settings_field($id, $field['label'], $this->renderField(...), 'media', self::OPTION_NAME, $args);
        }
    }

    public function sanitizeOptions(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $previousOptions = get_option(self::OPTION_NAME, []);
        $previousOptions = is_array($previousOptions) ? $previousOptions : [];
        foreach (self::CONFIG_CONSTANTS as $name => $constant) {
            if (defined($constant)) {
                unset($input[$name]); // Ignore locked fields even in a crafted form submission.
            }
        }
        $url = isset($input['url']) && is_string($input['url']) ? trim($input['url']) : '';
        if ($url !== '' && !$this->isValidUrl($url)) {
            add_settings_error(
                self::OPTION_NAME,
                'remote_media_proxy_url',
                __('Use an HTTP or HTTPS site URL without credentials, query or fragment.', 'remote-media-proxy')
            );
            $url = is_string($previousOptions['url'] ?? null) ? $previousOptions['url'] : '';
        }
        $username = isset($input['username']) && is_string($input['username'])
            ? sanitize_text_field($input['username']) : '';
        try {
            $password = $this->sanitizePassword($input, $previousOptions['password'] ?? null);
        } catch (\Throwable) {
            add_settings_error(
                self::OPTION_NAME,
                'remote_media_proxy_password',
                __(
                    'Password encryption failed. Check WordPress keys and Sodium; plugin settings were not changed.',
                    'remote-media-proxy'
                )
            );
            return $previousOptions;
        }
        $options = [
            'enabled' => in_array($input['enabled'] ?? false, [true, 1, '1'], true),
            'url' => rtrim($url, '/'),
            'username' => $username,
            'password' => $password,
        ];
        foreach (self::CONFIG_CONSTANTS as $name => $constant) {
            if (defined($constant)) {
                if (!array_key_exists($name, $previousOptions)) {
                    unset($options[$name]);
                } elseif ($name !== 'password') {
                    $options[$name] = $previousOptions[$name];
                }
            }
        }
        return $options;
    }

    private function sanitizePassword(array $input, mixed $previous): mixed
    {
        if (!defined('REMOTE_MEDIA_PROXY_PASSWORD') && array_key_exists('password', $input)) {
            $password = $input['password'];
            if ($password === '' || $password === null) {
                return null;
            }
            if (is_array($password) && PasswordEncryption::decrypt($password) !== null) {
                // Core may sanitize an already encrypted result again when adding the option.
                return ['version' => 1, 'ciphertext' => $password['ciphertext']];
            }
            if (!is_string($password)) {
                throw new \InvalidArgumentException('Invalid password input.');
            }
            return $password === PasswordEncryption::decrypt($previous)
                ? $previous : PasswordEncryption::encrypt($password);
        }
        return $previous;
    }

    public function isValidUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        return is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function renderDescription(): void
    {
        ?>
        <p>
            <?php
            esc_html_e(
                'Load missing media from your source site without copying files locally.',
                'remote-media-proxy'
            );
            ?>
        </p>
        <?php
    }

    public function renderField(array $field): void
    {
        $name = $field['name'];
        $options = $this->getOptions();
        $constant = self::CONFIG_CONSTANTS[$name];
        $locked = defined($constant);
        $value = is_string($options[$name] ?? null) ? $options[$name] : '';
        if ($name === 'password') {
            $stored = get_option(self::OPTION_NAME, []);
            $savedPassword = is_array($stored) ? ($stored['password'] ?? null) : null;
            $value = $locked ? '' : PasswordEncryption::decrypt($savedPassword);
        }
        ?>
        <?php if ($name === 'enabled') : ?>
            <label for="remote_media_proxy_enabled">
                <input
                    type="checkbox"
                    id="remote_media_proxy_enabled"
                    name="remote_media_proxy[enabled]"
                    value="1"
                    <?php checked($options['enabled']); ?>
                    <?php disabled($locked); ?>
                >
                <?php esc_html_e('Enable Remote Media Proxy', 'remote-media-proxy'); ?>
            </label>
        <?php else : ?>
            <input
                class="regular-text"
                id="<?php echo esc_attr(self::OPTION_NAME . '_' . $name); ?>"
                name="<?php echo esc_attr(self::OPTION_NAME . '[' . $name . ']'); ?>"
                type="<?php echo esc_attr($field['type']); ?>"
                value="<?php echo esc_attr($value ?? ''); ?>"
                placeholder="<?php echo esc_attr($field['placeholder']); ?>"
                autocomplete="<?php echo esc_attr($name === 'password' ? 'new-password' : 'off'); ?>"
                <?php disabled($locked); ?>
            >
        <?php endif; ?>
        <?php if ($locked) : ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: Configuration constant name. */
                    esc_html__('Configured by %s.', 'remote-media-proxy'),
                    esc_html($constant)
                );
                ?>
            </p>
        <?php endif; ?>
        <?php if ($name === 'password' && $value === null) : ?>
            <p class="description">
                <?php
                esc_html_e(
                    'The saved password cannot be read. Re-enter it; saving an empty field removes it.',
                    'remote-media-proxy'
                );
                ?>
            </p>
        <?php endif; ?>
        <?php
    }

    public static function deleteOptions(): void
    {
        delete_option(self::OPTION_NAME);
    }
}
