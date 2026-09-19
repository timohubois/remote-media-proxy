<?php

namespace RemoteMediaProxy\Features;

defined('ABSPATH') || exit;

final class RemoteMediaProxy
{
    private static ?RemoteMediaProxy $instance = null;

    private bool $fetching = false;

    public static function getInstance(): RemoteMediaProxy
    {
        if (!self::$instance instanceof RemoteMediaProxy) {
            self::$instance = new RemoteMediaProxy();
        }
        return self::$instance;
    }

    /** Fetch a missing upload relative to the current site's uploads directory. No output or disk writes. */
    public function fetchUpload(string $relativePath): ?array
    {
        if ($this->fetching || !empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY'])) {
            return null;
        }
        $this->fetching = true;
        try {
            return $this->requestUpload($relativePath);
        } finally {
            $this->fetching = false;
        }
    }

    private function requestUpload(string $relativePath): ?array
    {
        if (!$this->isValidPath($relativePath)) {
            return null;
        }
        $options = $this->getConfiguredOptions();
        if ($options === null) {
            return null;
        }
        $uploads = wp_get_upload_dir();
        if (file_exists($uploads['basedir'] . '/' . $relativePath)) {
            return null;
        }
        $mimeType = $this->getMimeType($relativePath);
        if ($mimeType === null) {
            return null;
        }
        $uploadsPath = rtrim((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH), '/');
        $homePath = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        if ($homePath !== '' && str_starts_with($uploadsPath, $homePath . '/')) {
            $uploadsPath = substr($uploadsPath, strlen($homePath));
        }
        $remoteUrl = rtrim($options['url'], '/') . '/' . trim($uploadsPath, '/') . '/'
            . implode('/', array_map('rawurlencode', explode('/', $relativePath)));
        $maxResponseSize = wp_max_upload_size();
        if (!is_int($maxResponseSize) || $maxResponseSize < 1 || $maxResponseSize === PHP_INT_MAX) {
            return null;
        }
        $headers = ['Accept-Encoding' => 'identity', 'X-Remote-Media-Proxy' => '1'];
        if ($options['username'] !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($options['username'] . ':' . $options['password']);
        }
        $requestArgs = [
            'redirection' => 0,
            'sslverify' => true,
            'decompress' => false,
            'limit_response_size' => $maxResponseSize + 1,
            'headers' => $headers,
        ];
        // Use the host's configured limit as a fetch policy, not a remaining-time estimate.
        $timeout = (int) ini_get('max_execution_time');
        if ($timeout > 0) {
            $requestArgs['timeout'] = $timeout;
        }
        $response = wp_safe_remote_get($remoteUrl, $requestArgs);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        $contentType = wp_remote_retrieve_header($response, 'content-type');
        $contentLength = wp_remote_retrieve_header($response, 'content-length');
        $encoding = wp_remote_retrieve_header($response, 'content-encoding');
        if (!is_string($contentType) || !is_string($contentLength) || !is_string($encoding)) {
            return null;
        }
        $contentType = strtolower(trim(explode(';', $contentType)[0]));
        if (
            $body === '' || strlen($body) > $maxResponseSize
            || !in_array($contentType, [$mimeType, 'application/octet-stream'], true)
            || !in_array($encoding, ['', 'identity'], true)
            || ($contentLength !== '' && (!ctype_digit($contentLength) || (int) $contentLength !== strlen($body)))
        ) {
            return null;
        }
        return ['body' => $body, 'type' => $mimeType];
    }

    /** Check local configuration only; never probe the remote while resolving an attachment path. */
    public function isConfigured(): bool
    {
        return $this->getConfiguredOptions() !== null;
    }

    private function getConfiguredOptions(): ?array
    {
        $optionsMedia = OptionsMedia::getInstance();
        $options = $optionsMedia->getOptions();
        if (
            empty($options['enabled']) || !isset($options['url'], $options['username'], $options['password'])
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
        $fileType = wp_check_filetype($relativePath, array_merge(wp_get_mime_types(), ['svg' => 'image/svg+xml']));
        $type = $fileType['type'];
        return $type && (preg_match('#^(image|audio|video|font)/[a-z0-9.+-]+$#i', $type) || $type === 'application/pdf')
            ? $type : null;
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
