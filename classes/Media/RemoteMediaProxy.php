<?php

namespace RemoteMediaProxy\Media;

use RemoteMediaProxy\Features\OptionsMedia;

defined('ABSPATH') || exit;

/** Coordinate local-first media access, validated remote requests and request-local reuse. */
final class RemoteMediaProxy
{
    /** @var array<string, array{path: string, size: int, type: string}|null> Request-local downloads and failed GETs. */
    private array $downloads = [];

    /** @var array<string, array{size: int, type: string}|null> Request-local metadata and failed HEADs. */
    private array $metadata = [];

    /** @var array<string,true> Failed GET identities with a confirmed remote HTTP 404 response. */
    private array $missing = [];

    /** @var self|null Shared backend for the current PHP request. */
    private static ?RemoteMediaProxy $instance = null;

    /** @var boolean Guard against reentrant retrieval through hooks or stream callbacks. */
    private bool $fetching = false;

    /** Keep construction private so request-local caches have a single owner. */
    private function __construct()
    {
    }

    /**
     * Obtain the request's shared media backend.
     *
     * @return self Lazily created backend instance.
     */
    public static function getInstance(): RemoteMediaProxy
    {
        if (!self::$instance instanceof RemoteMediaProxy) {
            self::$instance = new RemoteMediaProxy();
        }
        return self::$instance;
    }

    /**
     * Open a local-first reader while retaining shared downloads until request shutdown.
     *
     * @param string  $relativePath Path relative to the current site's uploads directory.
     * @param boolean $missing      Receives true only for a confirmed remote HTTP 404.
     * @return MediaFile|null Independent reader owned by the caller, or null when unavailable.
     */
    public function openUpload(string $relativePath, bool &$missing = false): ?MediaFile
    {
        $file = $this->fetch($relativePath, false, $missing);
        if ($file === null) {
            return null;
        }
        $handle = MediaFile::open($file['path'], $file['type']);
        if ($handle !== null && $handle->size !== $file['size']) {
            $handle->close();
            return null;
        }
        return $handle;
    }

    /**
     * Inspect metadata without downloading a file body. Null means unavailable or rejected.
     *
     * @param string $relativePath Path relative to the current site's uploads directory.
     * @return array{size:int,type:string}|null Validated metadata, or null when unavailable.
     */
    public function statUpload(string $relativePath): ?array
    {
        return $this->fetch($relativePath, true);
    }

    /**
     * Guard a metadata lookup or file retrieval against reentrant requests.
     *
     * @param string  $relativePath Upload-relative path to validate and resolve.
     * @param boolean $metadataOnly Whether the caller needs metadata rather than file bytes.
     * @param boolean $missing      Receives whether a body request returned HTTP 404.
     * @return array{size:int,type:string,path?:string}|null Validated result; body retrieval includes a local path.
     */
    private function fetch(string $relativePath, bool $metadataOnly, bool &$missing = false): ?array
    {
        $missing = false;
        if ($this->fetching) {
            return null;
        }
        $this->fetching = true;
        try {
            return $this->requestUpload($relativePath, $metadataOnly, $missing);
        } finally {
            $this->fetching = false;
        }
    }

    /**
     * Prefer local files, then reuse or fetch media isolated by site, source and credentials.
     *
     * @param string  $relativePath Upload-relative path to validate and resolve.
     * @param boolean $metadataOnly Whether to inspect metadata without downloading a body.
     * @param boolean $missing      Receives whether a body request returned HTTP 404.
     * @return array{size:int,type:string,path?:string}|null Validated result, or null when unavailable.
     */
    private function requestUpload(string $relativePath, bool $metadataOnly, bool &$missing): ?array
    {
        if (!$this->isValidPath($relativePath)) {
            return null;
        }
        $mimeType = $this->getMimeType($relativePath);
        if ($mimeType === null) {
            return null;
        }
        $uploads = wp_get_upload_dir();
        $local = $uploads['basedir'] . '/' . $relativePath;
        if (file_exists($local)) {
            $size = is_file($local) ? filesize($local) : false;
            if (!is_int($size) || $size < 0) {
                return null;
            }
            return $metadataOnly
                ? ['size' => $size, 'type' => $mimeType]
                : ['path' => $local, 'size' => $size, 'type' => $mimeType];
        }
        if (!empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY'])) {
            return null;
        }
        $options = $this->getConfiguredOptions();
        if ($options === null) {
            return null;
        }
        $uploadsPath = rtrim((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH), '/');
        $homePath = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        if ($homePath !== '' && str_starts_with($uploadsPath, $homePath . '/')) {
            $uploadsPath = substr($uploadsPath, strlen($homePath));
        }
        $remoteUrl = rtrim($options['url'], '/') . '/' . trim($uploadsPath, '/') . '/'
            . implode('/', array_map(rawurlencode(...), explode('/', $relativePath)));
        $headers = ['Accept-Encoding' => 'identity', 'X-Remote-Media-Proxy' => '1'];
        if ($options['username'] !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($options['username'] . ':' . $options['password']);
        }
        // Resolve configuration and local precedence before reuse. Never share across sites or credentials.
        $key = hash('sha256', serialize([get_current_blog_id(), $local, $remoteUrl, $headers, $mimeType]));
        if (isset($this->downloads[$key])) {
            $file = $this->downloads[$key];
            clearstatcache(true, $file['path']);
            if (!is_file($file['path']) || @filesize($file['path']) !== $file['size']) {
                // Do not reopen an externally removed or truncated request snapshot.
                return null;
            }
            return $metadataOnly ? ['size' => $file['size'], 'type' => $file['type']] : $file;
        }
        if ($metadataOnly && array_key_exists($key, $this->metadata)) {
            return $this->metadata[$key];
        }
        if (!$metadataOnly && array_key_exists($key, $this->downloads)) {
            $missing = isset($this->missing[$key]);
            return null;
        }
        // Negative results are scoped to this operation: a failed HEAD must not prevent a usable GET.
        if ($metadataOnly) {
            $this->metadata[$key] = null;
            return $this->metadata[$key] = $this->requestRemote($remoteUrl, $headers, $mimeType, true);
        }
        $this->downloads[$key] = null;
        $file = $this->requestRemote($remoteUrl, $headers, $mimeType, false, $missing);
        if ($missing) {
            $this->missing[$key] = true;
        }
        if ($file !== null) {
            $this->downloads[$key] = $file;
            $this->metadata[$key] = ['size' => $file['size'], 'type' => $file['type']];
        }
        return $file;
    }

    /**
     * Successful body downloads remain owned by TemporaryFile until request shutdown.
     *
     * @param string               $remoteUrl    Source URL with individually encoded upload path segments.
     * @param array<string,string> $headers      Source request headers, including configured authentication.
     * @param string               $mimeType     Expected MIME type allowed by the current WordPress context.
     * @param boolean              $metadataOnly Whether to send HEAD instead of downloading with GET.
     * @param boolean              $missing      Receives whether the remote returned HTTP 404, never a transport error.
     * @return array{size:int,type:string,path?:string}|null Validated result, or null on rejection or failure.
     */
    private function requestRemote(
        string $remoteUrl,
        array $headers,
        string $mimeType,
        bool $metadataOnly,
        bool &$missing = false
    ): ?array {
        $requestArgs = [
            'redirection' => 0,
            'sslverify' => true,
            'decompress' => false,
            'headers' => $headers,
        ];
        // Use the host's configured limit as a fetch policy, not a remaining-time estimate.
        $timeout = (int) ini_get('max_execution_time');
        if ($timeout > 0) {
            $requestArgs['timeout'] = $timeout;
        }
        $temporary = $metadataOnly ? null : TemporaryFile::create();
        if (!$metadataOnly && $temporary === null) {
            return null;
        }
        $complete = false;
        try {
            if ($temporary !== null) {
                $requestArgs['stream'] = true;
                $requestArgs['filename'] = $temporary;
            }
            $response = $metadataOnly
                ? wp_safe_remote_head($remoteUrl, $requestArgs)
                : wp_safe_remote_get($remoteUrl, $requestArgs);
            if (is_wp_error($response)) {
                return null;
            }
            $status = wp_remote_retrieve_response_code($response);
            $missing = $status === 404;
            if ($status !== 200) {
                return null;
            }
            $contentType = wp_remote_retrieve_header($response, 'content-type');
            $contentLength = wp_remote_retrieve_header($response, 'content-length');
            $encoding = wp_remote_retrieve_header($response, 'content-encoding');
            if (!is_string($contentType) || !is_string($contentLength) || !is_string($encoding)) {
                return null;
            }
            $contentType = strtolower(trim(explode(';', $contentType)[0]));
            if (
                !in_array($contentType, [$mimeType, 'application/octet-stream'], true)
                || !in_array($encoding, ['', 'identity'], true)
            ) {
                return null;
            }
            if ($metadataOnly) {
                if (
                    !ctype_digit($contentLength) || (int) $contentLength < 1
                    || (string) (int) $contentLength !== ltrim($contentLength, '0')
                ) {
                    return null;
                }
                return ['size' => (int) $contentLength, 'type' => $mimeType];
            }
            clearstatcache(true, $temporary);
            $size = @filesize($temporary);
            if (
                !is_int($size) || $size < 1
                || ($contentLength !== '' && ltrim($contentLength, '0') !== (string) $size)
            ) {
                return null;
            }
            $complete = true;
            return ['path' => $temporary, 'size' => $size, 'type' => $mimeType];
        } finally {
            if ($temporary !== null && !$complete) {
                TemporaryFile::remove($temporary);
            }
        }
    }

    /**
     * Check local configuration only, without probing the remote source.
     *
     * @return boolean Whether enabled, usable configuration exists for a different source hostname.
     */
    public function isConfigured(): bool
    {
        return $this->getConfiguredOptions() !== null;
    }

    /**
     * Require enabled settings, readable credentials and a valid, different source hostname.
     *
     * @return array{enabled:true,url:string,username:string,password:string,...}|null Usable configuration.
     */
    private function getConfiguredOptions(): ?array
    {
        $optionsMedia = OptionsMedia::getInstance();
        $options = $optionsMedia->getOptions();
        if (
            !$options['enabled'] || !isset($options['url'], $options['username'], $options['password'])
            || !is_string($options['url']) || !is_string($options['username']) || !is_string($options['password'])
            || !$optionsMedia->isValidUrl($options['url'])
            || strtolower((string) wp_parse_url($options['url'], PHP_URL_HOST))
                === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))
        ) {
            return null;
        }
        return $options;
    }

    /**
     * Determine file type using WordPress's current allowed MIME policy.
     *
     * @param string $relativePath Upload-relative filename whose extension determines the type.
     * @return string|null Allowed MIME type, or null for unsupported extensions.
     */
    public function getMimeType(string $relativePath): ?string
    {
        $fileType = wp_check_filetype($relativePath, get_allowed_mime_types());
        return $fileType['type'] ?: null;
    }

    /**
     * Reject unsafe paths without sanitizing them into different valid filenames.
     *
     * @param string $relativePath Decoded upload-relative path to check.
     * @return boolean Whether the path contains only permitted segments and bytes.
     */
    public function isValidPath(string $relativePath): bool
    {
        if (
            $relativePath === '' || strlen($relativePath) > 2048
            || preg_match('/[\x00-\x1f\x7f\\\\%?#]/', $relativePath)
        ) {
            return false;
        }
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false;
            }
        }
        return true;
    }
}
