<?php

namespace RemoteMediaProxy\Features;

defined('ABSPATH') || exit;

final class OptionsMedia
{
    public const OPTION_NAME = 'remote_media_proxy';

    private const CONFIG_CONSTANTS = [
        'enabled' => 'REMOTE_MEDIA_PROXY_ENABLED',
        'url' => 'REMOTE_MEDIA_PROXY_URL',
        'username' => 'REMOTE_MEDIA_PROXY_USERNAME',
        'password' => 'REMOTE_MEDIA_PROXY_PASSWORD',
    ];

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
                __('Use an HTTPS site URL without credentials, query or fragment.', 'remote-media-proxy')
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
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function renderSettings(): void
    {
        $options = $this->getOptions();
        $locked = array_filter(self::CONFIG_CONSTANTS, 'defined');
        $stored = get_option(self::OPTION_NAME, []);
        $savedPassword = is_array($stored) ? ($stored['password'] ?? null) : null;
        $passwordValue = isset($locked['password']) ? '' : PasswordEncryption::decrypt($savedPassword);
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
                    <?php disabled(isset($locked['enabled'])); ?>
                >
                <?php esc_html_e('Enable Remote Media Proxy', 'remote-media-proxy'); ?>
            </label>
            <?php if (isset($locked['enabled'])) : ?>
                <span class="description">
                    <?php
                    printf(
                        /* translators: %s: Configuration constant name. */
                        esc_html__('Configured by %s.', 'remote-media-proxy'),
                        esc_html($locked['enabled'])
                    );
                    ?>
                </span>
            <?php endif; ?>
        </p>
        <p class="description">
            <?php
            esc_html_e(
                'Fetch missing media without local copies. Apache routing is managed automatically where supported.',
                'remote-media-proxy'
            );
            ?>
        </p>
        <?php foreach ($fields as $name => $label) :
            $value = is_string($options[$name] ?? null) ? $options[$name] : '';
            if ($name === 'password') {
                $value = $passwordValue;
            }
            ?>
            <p>
                <label for="<?php echo esc_attr('remote_media_proxy_' . $name); ?>">
                    <?php echo esc_html($label); ?>
                </label><br>
                <input
                    class="regular-text"
                    id="<?php echo esc_attr('remote_media_proxy_' . $name); ?>"
                    name="<?php echo esc_attr(self::OPTION_NAME . '[' . $name . ']'); ?>"
                    type="<?php echo esc_attr($name === 'password' ? 'password' : 'text'); ?>"
                    value="<?php echo esc_attr($value ?? ''); ?>"
                    autocomplete="off"
                    <?php disabled(isset($locked[$name])); ?>
                >
                <?php if (isset($locked[$name])) : ?>
                    <span class="description">
                        <?php
                        printf(
                            /* translators: %s: Configuration constant name. */
                            esc_html__('Configured by %s.', 'remote-media-proxy'),
                            esc_html($locked[$name])
                        );
                        ?>
                    </span>
                <?php endif; ?>
                <?php if ($name === 'password' && $passwordValue === null) : ?>
                    <span class="description">
                        <?php
                        esc_html_e(
                            'The saved password cannot be read. Re-enter it; saving an empty field removes it.',
                            'remote-media-proxy'
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </p>
        <?php endforeach; ?>
        <p class="description">
            <?php
            esc_html_e(
                'Passwords are stored encrypted. Clear the password field to remove it.',
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
