<?php

namespace RemoteMediaProxy\Features;

defined('ABSPATH') || exit;

final class ApacheRouting
{
    private static ?ApacheRouting $instance = null;
    private bool $failed = false;
    private bool $syncing = false;

    private function __construct()
    {
        add_action('admin_init', [$this, 'adminInit']);
        add_action('admin_notices', [$this, 'notice']);
        add_action('add_option_' . OptionsMedia::OPTION_NAME, [$this, 'sync'], 10, 0);
        add_action('update_option_' . OptionsMedia::OPTION_NAME, [$this, 'sync'], 10, 0);
        add_action('delete_option_' . OptionsMedia::OPTION_NAME, [self::class, 'remove'], 10, 0);
        add_action('wp_initialize_site', [$this, 'initializeSite'], 200);
        add_action('wp_uninitialize_site', [$this, 'uninitializeSite'], 0);
    }

    public static function getInstance(): ApacheRouting
    {
        return self::$instance ??= new ApacheRouting();
    }

    public function adminInit(): void
    {
        if (!wp_doing_ajax() && current_user_can('manage_options')) {
            $this->sync();
        }
    }

    public function sync(): bool
    {
        if ($this->syncing) {
            return false;
        }
        $this->syncing = true;
        try {
            self::loadHelpers();
            $enabled = RemoteMediaProxy::getInstance()->isConfigured();
            $result = true;
            if (!$enabled) {
                $result = self::remove();
            } elseif (got_mod_rewrite()) {
                $location = self::location();
                $rules = $location ? $this->rules($location['path']) : null;
                $result = $location !== null && $rules !== null
                    && is_file($location['front']) && self::update($location['file'], $rules);
            }
            $this->failed = !$result;
            return $result;
        } finally {
            $this->syncing = false;
        }
    }

    /** Native home path, evaluated on the network's main site rather than a virtual subsite. */
    private static function location(): ?array
    {
        $switched = is_multisite() && get_current_blog_id() !== get_main_site_id();
        if ($switched) {
            switch_to_blog(get_main_site_id());
        }
        try {
            $root = get_home_path();
            if ($root === '/' && get_option('home') !== get_option('siteurl')) {
                return null; // Core cannot infer separated-core paths in some CLI contexts.
            }
            return ['file' => rtrim(WP_CONTENT_DIR, '/\\') . '/.htaccess', 'front' => $root . 'index.php',
                'path' => rtrim((string) wp_parse_url((string) get_option('home'), PHP_URL_PATH), '/') . '/'];
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private function rules(string $rootPath): ?array
    {
        $home = wp_parse_url((string) get_option('home'));
        $uploadDir = wp_get_upload_dir();
        $uploads = wp_parse_url($uploadDir['baseurl']);
        $resolvedUploads = realpath($uploadDir['basedir']);
        $resolvedContent = realpath(WP_CONTENT_DIR);
        $bothResolved = $resolvedUploads !== false && $resolvedContent !== false;
        $basedir = wp_normalize_path($bothResolved ? $resolvedUploads : $uploadDir['basedir']);
        $contentDir = rtrim(wp_normalize_path($bothResolved ? $resolvedContent : WP_CONTENT_DIR), '/') . '/';
        $content = wp_parse_url(content_url());
        $contentPath = rtrim($content['path'] ?? '', '/') . '/';
        $host = $home['host'] ?? '';
        $path = rtrim($uploads['path'] ?? '', '/') . '/';
        $port = $uploads['port'] ?? null;
        $homePort = $home['port'] ?? null;
        $defaultPorts = [null, 80, 443];
        if (
            !str_starts_with($basedir . '/', $contentDir) || preg_match('#/(?:\.|\.\.)(?:/|$)#', $basedir)
            || strcasecmp($host, $uploads['host'] ?? '') !== 0
            || strcasecmp($host, $content['host'] ?? '') !== 0
            || !str_starts_with($path, $contentPath)
            || !preg_match('/^[a-z0-9.\-\[\]:]+$/iD', $host)
            || !preg_match('#^/[a-z0-9/_~.\-]*$#iD', $path . $rootPath)
            || preg_match('#/(?:\.|\.\.)(?:/|$)#', $path . $rootPath) || !str_starts_with($path, $rootPath)
            || ($port !== $homePort
                && !(in_array($port, $defaultPorts, true) && in_array($homePort, $defaultPorts, true)))
            || ($port !== ($content['port'] ?? null)
                && !(in_array($port, $defaultPorts, true)
                    && in_array($content['port'] ?? null, $defaultPorts, true)))
        ) {
            return null;
        }
        $domain = $host . (in_array($port, $defaultPorts, true) ? '' : ':' . $port);
        if (is_multisite()) {
            $segments = 1;
            if (defined('DOMAIN_CURRENT_SITE') && defined('PATH_CURRENT_SITE')) {
                if (
                    PATH_CURRENT_SITE !== '/' && strcasecmp(DOMAIN_CURRENT_SITE, $domain) === 0
                    && stripos($path, PATH_CURRENT_SITE) === 0
                ) {
                    $segments += count(explode('/', trim(PATH_CURRENT_SITE, '/')));
                }
            } elseif (!is_subdomain_install()) {
                $segments = substr_count(get_network()->path, '/');
            }
            $site = get_site_by_path($domain, $path, $segments);
            if (!$site || (int) $site->blog_id !== get_current_blog_id()) {
                return null;
            }
        }
        $authority = preg_quote($host, '#')
            . (in_array($port, $defaultPorts, true) ? '(?::80|:443)?' : ':' . $port);
        return ['<IfModule mod_rewrite.c>', 'RewriteEngine On',
            'RewriteCond %{HTTP_HOST} ^' . $authority . '$ [NC]',
            'RewriteCond %{REQUEST_URI} ^' . preg_quote($path, '#'),
            'RewriteCond %{REQUEST_METHOD} =GET',
            'RewriteCond %{REQUEST_FILENAME} !-f', 'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteRule ^ ' . $rootPath . 'index.php [L]', '</IfModule>'];
    }

    public function syncSites(bool $networkWide = false, bool $remove = false): void
    {
        if (!is_multisite() || !$networkWide) {
            $remove ? self::removeOrFail() : $this->sync();
            return;
        }
        $offset = 0;
        do {
            $sites = get_sites(['network_id' => get_current_network_id(), 'fields' => 'ids',
                'number' => 100, 'offset' => $offset]);
            foreach ($sites as $siteId) {
                switch_to_blog((int) $siteId);
                try {
                    $remove ? self::removeOrFail() : $this->sync();
                } finally {
                    restore_current_blog();
                }
            }
            $offset += 100;
        } while (count($sites) === 100);
    }

    public function initializeSite(object $site): void
    {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (
            (int) $site->network_id === get_current_network_id()
            && is_plugin_active_for_network(plugin_basename(REMOTE_MEDIA_PROXY_PLUGIN_FILE))
        ) {
            switch_to_blog((int) $site->blog_id);
            try {
                $this->sync();
            } finally {
                restore_current_blog();
            }
        }
    }

    public function uninitializeSite(object $site): void
    {
        switch_to_blog((int) $site->blog_id);
        try {
            self::removeOrFail();
        } finally {
            restore_current_blog();
        }
    }

    public static function remove(): bool
    {
        self::loadHelpers();
        return self::update(rtrim(WP_CONTENT_DIR, '/\\') . '/.htaccess', []);
    }

    public static function removeOrFail(): void
    {
        if (!self::remove()) {
            wp_die(esc_html__(
                'Remote Media Proxy could not clear its .htaccess rules. Check permissions and markers.',
                'remote-media-proxy'
            ));
        }
    }

    public function notice(): void
    {
        if ($this->failed && current_user_can('manage_options')) {
            wp_admin_notice(esc_html__(
                'Remote Media Proxy routing failed. Check the server layout, .htaccess permissions and markers.',
                'remote-media-proxy'
            ), ['type' => 'warning']);
        }
    }

    private static function loadHelpers(): void
    {
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
    }

    private static function marker(): string
    {
        $root = rtrim(wp_normalize_path(realpath(ABSPATH) ?: ABSPATH), '/') . '/';
        return 'Remote Media Proxy ' . substr(hash('sha256', $root), 0, 12)
            . ' (site ' . get_current_blog_id() . ')';
    }

    /** Validate ownership before handing replacement to core's substring-based marker helper. */
    private static function block(string $contents): array|false
    {
        $marker = preg_quote(self::marker(), '/');
        $count = preg_match_all(
            '/^# BEGIN ' . $marker . '\r?\n.*?^# END ' . $marker . '(?:\r?\n|\z)/ms',
            $contents,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if (
            $count > 1 || preg_match_all('/# (?:BEGIN|END) ' . $marker . '/', $contents) !== $count * 2
            || ($count === 1 && preg_match_all('/# (?:BEGIN|END) /', $matches[0][0][0]) !== 2)
        ) {
            return false;
        }
        return $matches[0][0] ?? [];
    }

    private static function update(string $file, array $rules): bool
    {
        if (file_exists($file) && (!is_file($file) || !is_readable($file))) {
            return false;
        }
        $contents = file_exists($file) ? file_get_contents($file) : '';
        if (!is_string($contents) || ($block = self::block($contents)) === false) {
            return false;
        }
        if ($rules === []) {
            return $block === [] || extract_from_markers($file, self::marker()) === []
                || insert_with_markers($file, self::marker(), []);
        }
        return ($block !== [] && extract_from_markers($file, self::marker()) === $rules)
            || insert_with_markers($file, self::marker(), $rules);
    }
}
