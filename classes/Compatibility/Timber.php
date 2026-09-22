<?php

namespace RemoteMediaProxy\Compatibility;

use RemoteMediaProxy\Media\StreamWrapper;
use RemoteMediaProxy\Media\RemoteMediaProxy;
use RemoteMediaProxy\Media\TemporaryFile;
use RemoteMediaProxy\Media\MediaFile;
use RemoteMediaProxy\Features\OptionsMedia;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use Timber\Image\Operation\Resize;
use Timber\ImageHelper;
use WeakMap;

defined('ABSPATH') || exit;

/** Defer missing-original Timber resizes to signed image requests while preserving native local-file behavior. */
final class Timber
{
    /** Shared API namespace; each integration registers its own explicit routes. */
    private const string REST_NAMESPACE = 'remote-media-proxy/v1';

    /** Integration-specific operation, also bound into the recipe signature. */
    private const string REST_ROUTE = 'timber/resize';

    /**
     * Synthetic file metadata lives only as long as its Resize operation, never across requests.
     *
     * @var WeakMap<Resize, array{
     *     root: string, source: string, sourceUri: string, basedir: string, blog: int,
     *     paths: array<string, true>, lifetime: object, target?: string
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
        add_filter('timber/image/new_url', [$this, 'deferResize'], PHP_INT_MAX);
        add_action('rest_api_init', [$this, 'registerRoute']);
        add_filter('rest_pre_serve_request', [$this, 'serveImage'], 20, 3);
    }

    /**
     * Register the signed image route; WordPress still applies its normal REST authentication policy.
     *
     * @return void
     */
    public function registerRoute(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_ROUTE . '/(?P<source>.+)', [
                'methods' => ['GET', 'HEAD'],
                'permission_callback' => [$this, 'authorizeResize'],
                'callback' => [$this, 'resizeResponse'],
            ]);
    }

    /**
     * Replace an eligible missing derivative URL with deterministic, signed resize instructions.
     *
     * @param mixed $url Native derivative URL after theme URL filters have run.
     * @return mixed Signed REST URL, or the unchanged URL for local or unsupported operations.
     */
    public function deferResize(mixed $url): mixed
    {
        $call = $this->operation();
        $view = $call === null ? null : ($this->operations[$call['op']] ?? null);
        if ($view === null || !is_string($url)) {
            return $url;
        }
        $uploads = wp_get_upload_dir();
        $base = rtrim($uploads['baseurl'], '/') . '/';
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $base = (string) wp_parse_url($base, PHP_URL_PATH);
        }
        if ($base === '' || !str_starts_with($url, $base)) {
            return $url;
        }
        $target = rawurldecode(substr($url, strlen($base)));
        if (!$this->isImage($target) || file_exists($view['basedir'] . '/' . $target)) {
            return $url;
        }
        $recipe = ['v' => 1, 'source' => $view['source'], 'target' => $target];
        try {
            // Timber exposes neither normalized resize arguments nor an operation-result interception hook.
            foreach (['w' => 'width', 'h' => 'height', 'crop' => 'crop'] as $property => $name) {
                $recipe[$name] = (new \ReflectionProperty(Resize::class, $property))->getValue($call['op']);
            }
        } catch (\ReflectionException) {
            return $url;
        }
        if (!$this->validRecipe($recipe)) {
            return $url;
        }
        // Query parameters are strings on receipt; sign that same representation without changing numeric spelling.
        $recipe['width'] = (string) $recipe['width'];
        $recipe['height'] = (string) $recipe['height'];
        $parameters = [
            'width' => $recipe['width'],
            'height' => $recipe['height'],
            'crop' => $recipe['crop'] === false ? 'false' : $recipe['crop'],
            'target' => $target,
            'sig' => $this->signature($recipe),
        ];
        $view['target'] = $target;
        $this->operations[$call['op']] = $view;
        $source = implode('/', array_map(rawurlencode(...), explode('/', $recipe['source'])));
        return add_query_arg(array_map(rawurlencode(...), $parameters), rest_url(
            self::REST_NAMESPACE . '/' . self::REST_ROUTE . '/' . $source
        ));
    }

    /**
     * Authorize only instructions issued by this installation for its current source configuration.
     *
     * @param WP_REST_Request $request Incoming REST image request.
     * @return boolean|WP_Error True when authorized, or a non-sensitive rejection without retrieving media.
     */
    public function authorizeResize(WP_REST_Request $request): bool|WP_Error
    {
        return $this->readRecipe($request) !== null ? true : new WP_Error(
            'remote_media_proxy_invalid_resize',
            __('Invalid or unavailable image request.', 'remote-media-proxy'),
            ['status' => 403]
        );
    }

    /**
     * Prepare a binary response through the shared cache, generating only after a remote 404.
     *
     * @param WP_REST_Request $request Signature-validated REST request.
     * @return WP_REST_Response|WP_Error A reader for binary serving, or a controlled failure without original fallback.
     */
    public function resizeResponse(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $recipe = $this->readRecipe($request);
        if ($recipe === null) {
            return new WP_Error(
                'remote_media_proxy_invalid_resize',
                __('Invalid image request.', 'remote-media-proxy'),
                ['status' => 403]
            );
        }
        $expiresAt = null;
        $file = $this->openDerivative($recipe, $expiresAt);
        if ($file === null) {
            return new WP_Error(
                'remote_media_proxy_unavailable',
                __('The image is unavailable.', 'remote-media-proxy'),
                ['status' => 502]
            );
        }
        // The route-specific serving hook consumes this reader instead of serializing it as JSON.
        return new WP_REST_Response(['file' => $file, 'expires' => $expiresAt]);
    }

    /**
     * Emit only this route's binary results, allowing browser-only stale-while-revalidate.
     *
     * @param boolean          $served  Whether another REST serving hook already handled the response.
     * @param WP_HTTP_Response $result  Final response after WordPress REST dispatch filters.
     * @param WP_REST_Request  $request Current REST request, including its effective GET or HEAD method.
     * @return boolean Whether normal REST JSON output must be skipped.
     */
    public function serveImage(bool $served, WP_HTTP_Response $result, WP_REST_Request $request): bool
    {
        $prefix = '/' . self::REST_NAMESPACE . '/' . self::REST_ROUTE . '/';
        if ($served || strncasecmp($request->get_route(), $prefix, strlen($prefix)) !== 0) {
            return $served;
        }
        $data = $result->get_data();
        if (!is_array($data) || !(($data['file'] ?? null) instanceof MediaFile)) {
            nocache_headers();
            return false;
        }
        if (WordPress::sendFile($data['file'], $data['expires'], true, $request->get_method() === 'HEAD')) {
            return true;
        }
        // Never let REST serialize a reader when buffers or prior output prevent binary delivery.
        $result->set_status(500);
        $result->set_data([
            'code' => 'remote_media_proxy_delivery_failed',
            'message' => __('The image could not be delivered.', 'remote-media-proxy'),
            'data' => ['status' => 500],
        ]);
        if (!headers_sent()) {
            status_header(500);
            nocache_headers();
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        }
        return false;
    }

    /**
     * Validate explicit route/query instructions and their signature before accessing media.
     *
     * @param WP_REST_Request $request Request using a route-only source and query-only operation parameters.
     * @return array<string,mixed>|null Validated instructions, or null for disabled, malformed or unsigned requests.
     */
    private function readRecipe(WP_REST_Request $request): ?array
    {
        if (!empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY']) || !RemoteMediaProxy::getInstance()->isConfigured()) {
            return null;
        }
        // Binary responses do not support REST's JSON shaping or envelope mechanisms.
        foreach (['_fields', '_embed', '_envelope', '_jsonp'] as $parameter) {
            if ($request->has_param($parameter)) {
                return null;
            }
        }
        $source = $request->get_url_params()['source'] ?? null;
        $params = $request->get_query_params();
        if (!is_string($source)) {
            return null;
        }
        foreach (['width', 'height', 'crop', 'target', 'sig'] as $name) {
            if (!isset($params[$name]) || !is_string($params[$name])) {
                return null;
            }
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $params['sig'])) {
            return null;
        }
        $recipe = [
            'v' => 1,
            'source' => rawurldecode($source),
            'target' => $params['target'],
            'width' => $params['width'],
            'height' => $params['height'],
            'crop' => $params['crop'] === 'false' ? false : $params['crop'],
        ];
        return $this->validRecipe($recipe) && hash_equals($this->signature($recipe), $params['sig']) ? $recipe : null;
    }

    /**
     * Restrict recipes to safe upload paths and the native Resize constructor's supported scalar parameters.
     *
     * @param mixed $recipe Decoded instructions or candidate render-time parameters.
     * @return boolean Whether instructions have the exact supported schema and raster-image policy.
     */
    private function validRecipe(mixed $recipe): bool
    {
        if (
            !is_array($recipe) || array_keys($recipe) !== ['v', 'source', 'target', 'width', 'height', 'crop']
            || $recipe['v'] !== 1 || !is_string($recipe['source']) || !is_string($recipe['target'])
            || $recipe['source'] === $recipe['target']
            || !$this->isImage($recipe['source']) || !$this->isImage($recipe['target'])
        ) {
            return false;
        }
        foreach (['width', 'height'] as $dimension) {
            $value = $recipe[$dimension];
            if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > PHP_INT_MAX) {
                return false;
            }
        }
        $proxy = RemoteMediaProxy::getInstance();
        return ($recipe['width'] > 0 || $recipe['height'] > 0)
            && $proxy->getMimeType($recipe['source']) === $proxy->getMimeType($recipe['target'])
            && in_array($recipe['crop'], [
                false, 'default', 'center', 'top', 'bottom', 'left', 'right', 'top-center', 'bottom-center',
            ], true);
    }

    /**
     * Bind stable public instructions to this site and source without exposing source credentials in the URL.
     *
     * @param array<string,mixed> $recipe Canonical source, target and operation parameters in a fixed field order.
     * @return string URL-safe encoding of the full SHA-256 HMAC, without truncating its security strength.
     */
    private function signature(array $recipe): string
    {
        $options = OptionsMedia::getInstance()->getOptions();
        $digest = hash_hmac('sha256', serialize([
            self::REST_NAMESPACE . '/' . self::REST_ROUTE, ABSPATH, get_current_blog_id(), get_option('home'),
            $options['url'], $options['username'], $options['password'], $recipe,
        ]), wp_salt('auth'), true);
        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
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
        // The virtual namespace exposes metadata only. Native operations receive real temporary files separately.
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
        if (
            !$this->isImage($relative) || $relative === $view['source']
            || (isset($view['target']) && $view['target'] !== $relative)
        ) {
            return $path;
        }
        $uri = $view['root'] . '/' . $relative;
        // Return the selected URL without any render-time retrieval or image processing.
        $view['paths'] = [$view['sourceUri'] => true, $uri => true];
        $this->operations[$call['op']] = $view;
        return $uri;
    }

    /**
     * Retrieve a derivative or run a validated native resize after a confirmed remote 404.
     *
     * @param array<string,mixed> $recipe    Signed, validated source, target and native resize parameters.
     * @param integer|null        $expiresAt Receives the returned bytes' absolute freshness deadline.
     * @return MediaFile|null Independent result reader, or null when retrieval or generation fails.
     */
    private function openDerivative(array $recipe, ?int &$expiresAt): ?MediaFile
    {
        $source = $recipe['source'];
        $target = $recipe['target'];
        $proxy = RemoteMediaProxy::getInstance();
        $type = $proxy->getMimeType($target);
        if ($type === null || $type !== $proxy->getMimeType($source)) {
            return null;
        }
        $missing = false;
        $remote = $proxy->openUpload($target, $missing, $expiresAt);
        if ($remote !== null || !$missing) {
            return $remote;
        }
        // Only signature-validated instructions reach native image processing.
        $reader = $proxy->openUpload($source, $missing, $sourceExpiresAt);
        if ($reader === null) {
            return null;
        }
        $output = null;
        try {
            $input = stream_get_meta_data($reader->stream)['uri'] ?? null;
            $output = $proxy->stageUpload($target);
            if ($output === null || !is_string($input)) {
                return null;
            }
            $original = wp_getimagesize($input);
            if (
                !is_array($original) || ($original['mime'] ?? null) !== $type
                || min($original[0], $original[1]) < 1
            ) {
                return null;
            }
            $operation = new Resize($recipe['width'], $recipe['height'], $recipe['crop']);
            if (!$operation->run($input, $output)) {
                return null;
            }
            // Native Timber can ignore a crop error and save the original despite reporting success.
            $image = wp_getimagesize($output);
            if (!is_array($image) || ($image['mime'] ?? null) !== $type || min($image[0], $image[1]) < 1) {
                return null;
            }
            $animated = $type === 'image/gif' && ImageHelper::is_animated_gif($output);
            foreach (['width' => 0, 'height' => 1] as $dimension => $axis) {
                $requested = (float) $recipe[$dimension];
                // Leave zero-axis inference native. Animated GIFs pass dimensions directly to Imagick's integer API.
                $expected = $animated ? (int) $requested : (int) round($requested);
                if ($requested > 0 && $image[$axis] !== $expected) {
                    return null;
                }
            }
            if ($proxy->storeUpload($target, $output, $sourceExpiresAt)) {
                return $proxy->openUpload($target, $missing, $expiresAt);
            }
        } catch (\Throwable) {
            // Return a controlled image error, never original bytes or an exception's internal details.
        } finally {
            $reader->close();
            if ($output !== null) {
                TemporaryFile::remove($output);
            }
        }
        return null;
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
                    || $args[1]::class !== Resize::class || !empty($args[2])
                ) {
                    return null;
                }
                return ['src' => $args[0], 'op' => $args[1]];
            }
        }
        return null;
    }
}
