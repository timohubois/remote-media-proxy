<?php

namespace RemoteMediaProxy\Compatibility;

use RemoteMediaProxy\Media\StreamWrapper;
use RemoteMediaProxy\Media\RemoteMediaProxy;
use Timber\Image\Operation\Resize;
use Timber\ImageHelper;
use WeakMap;

defined('ABSPATH') || exit;

/** Adapt missing-image dimensions and resize URLs without probes, generation or persistent metadata. */
final class Timber
{
    /**
     * Synthetic file metadata lives only as long as its Resize operation, never across requests.
     *
     * @var WeakMap<Resize, array{
     *     root: string, source: string, sourceUri: string, basedir: string, blog: int,
     *     paths: array<string, true>, lifetime: object
     * }>
     */
    private WeakMap $operations;
    /** @var integer Request-local identifier for synthetic filesystem scopes. */
    private int $sequence = 0;

    /** @var boolean Guard against recursive uploads-directory mapping. */
    private bool $mapping = false;

    /** Defer dependency detection until themes have registered their autoloaders. */
    public function __construct()
    {
        // Themes may register Timber's Composer autoloader after plugins_loaded.
        add_action('after_setup_theme', [$this, 'registerTimberHooks'], PHP_INT_MAX);
    }

    /**
     * Register the integration once, and only when Timber is available.
     *
     * @return void
     */
    public function registerTimberHooks(): void
    {
        if (isset($this->operations) || !class_exists(\Timber\Timber::class)) {
            return;
        }
        $this->operations = new WeakMap();
        add_filter('wp_get_attachment_metadata', [$this, 'imageDimensions'], 10, 2);
        add_filter('remote_media_proxy_stream_handlers', [$this, 'handlers']);
        add_filter('upload_dir', [$this, 'mapDirectory'], PHP_INT_MAX);
        add_filter('timber/url/schemes-whitelist', [$this, 'schemes']);
        add_filter('timber/image/new_path', [$this, 'physicalPath'], PHP_INT_MIN);
        add_filter('timber/image/new_path', [$this, 'prepareFiles'], PHP_INT_MAX);
    }

    /**
     * Seed Timber 1's in-memory dimensions only while it imports a missing raster attachment.
     *
     * @param mixed $metadata     Native attachment metadata supplied through WordPress's filter.
     * @param mixed $attachmentId Attachment identity used to bind metadata to its original file.
     * @return mixed Metadata with validated dimensions for Timber's importer, or the unchanged input.
     */
    public function imageDimensions(mixed $metadata, mixed $attachmentId): mixed
    {
        if (
            !is_array($metadata) || !is_int($attachmentId) || $attachmentId <= 0
            || !is_string($metadata['file'] ?? null) || !$this->isImage($metadata['file'])
            || isset($metadata['_dimensions']) || !empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY'])
        ) {
            return $metadata;
        }
        $width = $metadata['width'] ?? null;
        $height = $metadata['height'] ?? null;
        if ((!is_int($width) && !is_string($width)) || (!is_int($height) && !is_string($height))) {
            return $metadata;
        }
        $width = filter_var($width, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $height = filter_var($height, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($width === false || $height === false) {
            return $metadata;
        }
        // Only Timber 1's image constructor imports this private field. Ordinary metadata stays unchanged.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Scope metadata to Timber's importer; never log the trace.
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        $caller = $frames[4] ?? [];
        if (
            ($caller['class'] ?? null) !== 'Timber\\Image' || ($caller['function'] ?? null) !== 'get_image_info'
            || !property_exists('Timber\\Image', '_dimensions')
            || !RemoteMediaProxy::getInstance()->isConfigured()
            || get_post_meta($attachmentId, '_wp_attached_file', true) !== $metadata['file']
        ) {
            return $metadata;
        }
        $uploads = wp_get_upload_dir();
        if (!is_string($uploads['basedir'] ?? null) || str_contains($uploads['basedir'], '://')) {
            return $metadata;
        }
        if (!file_exists($uploads['basedir'] . '/' . $metadata['file'])) {
            // Core::import() seeds the image object's existing in-memory dimension cache.
            $metadata['_dimensions'] = [$width, $height];
        }
        return $metadata;
    }

    /**
     * Add a metadata-only namespace without granting file reads or image generation.
     *
     * @param array<string,mixed> $handlers Existing namespace handler definitions.
     * @return array<string,mixed> Handler definitions including the scoped Timber stat callback.
     */
    public function handlers(array $handlers): array
    {
        // No open handler: this namespace must never expose source bytes or allow image generation.
        $handlers['timber'] = ['stat' => $this->stat(...)];
        return $handlers;
    }

    /**
     * Allow Timber to recognize the shared virtual URL scheme.
     *
     * @param array<int,string> $schemes Schemes already accepted by Timber.
     * @return array<int,string> Deduplicated schemes including this plugin's protocol.
     */
    public function schemes(array $schemes): array
    {
        $schemes[] = StreamWrapper::SCHEME;
        return array_unique($schemes);
    }

    /**
     * Expose synthetic paths only inside eligible missing-source Resize operations.
     *
     * @param array<string,mixed> $uploads WordPress uploads-directory information.
     * @return array<string,mixed> Scoped virtual basedir or unchanged uploads information.
     */
    public function mapDirectory(array $uploads): array
    {
        if (
            $this->mapping || !class_exists(ImageHelper::class, false) || !ini_get('allow_url_fopen')
            || !empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY'])
        ) {
            return $uploads;
        }
        $call = $this->operation(true);
        if ($call === null || !is_string($uploads['basedir'] ?? null) || !is_string($uploads['baseurl'] ?? null)) {
            return $uploads;
        }
        $this->mapping = true;
        try {
            $base = rtrim($uploads['baseurl'], '/') . '/';
            $source = $call['src'];
            if (str_starts_with($source, '/') && !str_starts_with($source, '//')) {
                $base = (string) wp_parse_url($base, PHP_URL_PATH);
            }
            $relative = $base !== '' && str_starts_with($source, $base) ? substr($source, strlen($base)) : '';
            $decoded = rawurldecode($relative);
            if (
                str_contains($uploads['basedir'], '://') || !$this->isImage($decoded)
                || file_exists($uploads['basedir'] . '/' . $decoded)
                || !RemoteMediaProxy::getInstance()->isConfigured()
            ) {
                return $uploads;
            }
            if (!StreamWrapper::register()) {
                return $uploads;
            }
            if (!isset($this->operations[$call['op']])) {
                $root = StreamWrapper::SCHEME . '://timber/' . ++$this->sequence;
                $this->operations[$call['op']] = [
                    'root' => $root,
                    'source' => $decoded,
                    'sourceUri' => $root . '/' . $relative,
                    'basedir' => rtrim(wp_normalize_path($uploads['basedir']), '/'),
                    'blog' => get_current_blog_id(),
                    'paths' => [],
                    // A weak-map value dies with the Resize object. PHP otherwise retains its last successful stat.
                    'lifetime' => new class {
                        /** Expire PHP's stat cache when the weakly held Resize operation ends. */
                        public function __destruct()
                        {
                            clearstatcache();
                        }
                    },
                ];
            }
            $uploads['basedir'] = $this->operations[$call['op']]['root'];
            return $uploads;
        } finally {
            $this->mapping = false;
        }
    }

    /**
     * Restore physical paths before Flynt and other destination-path filters run.
     *
     * @param mixed $path Destination path emitted by Timber's image operation.
     * @return mixed Physical uploads path within this scope, or the unchanged value.
     */
    public function physicalPath(mixed $path): mixed
    {
        $call = $this->operation();
        $view = $call === null ? null : ($this->operations[$call['op']] ?? null);
        if ($view === null || !is_string($path) || !str_starts_with($path, $view['root'] . '/')) {
            return $path;
        }
        // Flynt and other path filters must receive ordinary physical paths before we remap the result.
        return $view['basedir'] . substr($path, strlen($view['root']));
    }

    /**
     * Approve synthetic source and destination metadata after all destination-path filters.
     *
     * @param mixed $path Final physical derivative path supplied by the filter chain.
     * @return mixed Scoped virtual destination or unchanged input for ineligible operations.
     */
    public function prepareFiles(mixed $path): mixed
    {
        $call = $this->operation();
        $view = $call === null ? null : ($this->operations[$call['op']] ?? null);
        if ($view === null) {
            return $path;
        }
        $view['paths'] = [];
        $this->operations[$call['op']] = $view;
        clearstatcache(true, $view['sourceUri']);
        if (
            !is_string($path) || $view['blog'] !== get_current_blog_id()
            || file_exists($view['basedir'] . '/' . $view['source'])
        ) {
            return $path;
        }
        $base = $view['basedir'] . '/';
        $path = wp_normalize_path($path);
        $relative = str_starts_with($path, $base) ? rawurldecode(substr($path, strlen($base))) : '';
        if (!$this->isImage($relative) || $relative === $view['source']) {
            return $path;
        }
        $uri = $view['root'] . '/' . $relative;
        // Report a cache hit without probing the source. The browser gets this exact derivative or a 404.
        $view['paths'] = [$view['sourceUri'] => true, $uri => true];
        $this->operations[$call['op']] = $view;
        return $uri;
    }

    /**
     * Report a synthetic cache hit only within the operation that approved the URI.
     *
     * @param string $uri Synthetic Timber namespace URI requested by PHP.
     * @return array{size:1,mtime:0}|null Synthetic metadata, or null outside the approved scope.
     */
    public function stat(string $uri): ?array
    {
        $call = $this->operation();
        $view = $call === null ? null : ($this->operations[$call['op']] ?? null);
        if ($view === null || $view['blog'] !== get_current_blog_id() || !isset($view['paths'][$uri])) {
            return null;
        }
        // Synthetic cache-hit metadata, not the remote file's size or timestamps.
        return ['size' => 1, 'mtime' => 0];
    }

    /**
     * Require a safe raster-image path accepted by the current WordPress MIME policy.
     *
     * @param string $relative Decoded path relative to uploads.
     * @return boolean Whether the path is eligible for the synthetic filesystem adapter.
     */
    private function isImage(string $relative): bool
    {
        $proxy = RemoteMediaProxy::getInstance();
        if (!$proxy->isValidPath($relative)) {
            return false;
        }
        $mime = $proxy->getMimeType($relative);
        return $mime !== null && str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';
    }

    /**
     * Identify the active non-forced Resize operation through Timber's private call flow.
     *
     * @param boolean $pathLookup Whether to additionally require Timber's direct uploads path lookup.
     * @return array{src:string,op:Resize}|null Source and operation identity, or null outside the supported flow.
     */
    private function operation(bool $pathLookup = false): ?array
    {
        // Only Timber's direct path lookup within a non-forced Resize may see the synthetic filesystem.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Identify the Resize operation and source; never log the trace.
        $trace = debug_backtrace(0, 24);
        foreach ($trace as $index => $frame) {
            if ($pathLookup && ($frame['function'] ?? '') === 'wp_upload_dir') {
                $caller = $trace[$index + 1] ?? [];
                if (
                    ($caller['class'] ?? '') !== ImageHelper::class
                    || ($caller['function'] ?? '') !== '_get_file_path'
                ) {
                    return null;
                }
                $pathLookup = false;
            }
            if (($frame['class'] ?? '') === ImageHelper::class && ($frame['function'] ?? '') === '_operate') {
                $args = $frame['args'] ?? [];
                if (
                    $pathLookup || !is_string($args[0] ?? null) || !(($args[1] ?? null) instanceof Resize)
                    || !empty($args[2])
                ) {
                    return null;
                }
                return ['src' => $args[0], 'op' => $args[1]];
            }
        }
        return null;
    }
}
