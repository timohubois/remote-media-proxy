<?php

namespace RemoteMediaProxy\Media;

use RemoteMediaProxy\Features\OptionsMedia;

defined('ABSPATH') || exit;

final class RemoteMediaProxy
{
    private array $downloads = [];

    private array $metadata = [];

    private static ?RemoteMediaProxy $instance = null;

    private bool $fetching = false;

    private function __construct()
    {
    }

    public static function getInstance(): RemoteMediaProxy
    {
        if (!self::$instance instanceof RemoteMediaProxy) {
            self::$instance = new RemoteMediaProxy();
        }
        return self::$instance;
    }

    public function openUpload(string $relativePath): ?MediaFile
    {
        $file = $this->fetch($relativePath, false);
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

    public function statUpload(string $relativePath): ?array
    {
        return $this->fetch($relativePath, true);
    }

    private function fetch(string $relativePath, bool $metadataOnly): ?array
    {
        if ($this->fetching) {
            return null;
        }
        $this->fetching = true;
        try {
            return $this->requestUpload($relativePath, $metadataOnly);
        } finally {
            $this->fetching = false;
        }
    }

    private function requestUpload(string $relativePath, bool $metadataOnly): ?array
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
            return null;
        }
        // Negative results are scoped to this operation: a failed HEAD must not prevent a usable GET.
        if ($metadataOnly) {
            $this->metadata[$key] = null;
            return $this->metadata[$key] = $this->requestRemote($remoteUrl, $headers, $mimeType, true);
        }
        $this->downloads[$key] = null;
        $file = $this->requestRemote($remoteUrl, $headers, $mimeType, false);
        if ($file !== null) {
            $this->downloads[$key] = $file;
            $this->metadata[$key] = ['size' => $file['size'], 'type' => $file['type']];
        }
        return $file;
    }

    private function requestRemote(string $remoteUrl, array $headers, string $mimeType, bool $metadataOnly): ?array
    {
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
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
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

    public function isConfigured(): bool
    {
        return $this->getConfiguredOptions() !== null;
    }

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

    public function getMimeType(string $relativePath): ?string
    {
        $fileType = wp_check_filetype($relativePath, get_allowed_mime_types());
        return $fileType['type'] ?: null;
    }

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
