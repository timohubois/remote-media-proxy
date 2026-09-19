<?php

namespace RemoteMediaProxy\Features;

defined('ABSPATH') || exit;

final class VirtualUploads
{
    public const SCHEME = 'remotemediaproxy';

    private static bool $registered = false;
    private static bool $resolving = false;

    public function __construct()
    {
        if (self::isAvailable()) {
            add_filter('get_attached_file', [self::class, 'getAttachedFile'], 20, 2);
        }
    }

    public static function getAttachedFile(mixed $file, mixed $attachmentId): mixed
    {
        if (
            self::$resolving || !is_numeric($attachmentId) || !is_string($file) || str_contains($file, '://')
            || !empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY']) || !self::isAvailable()
        ) {
            return $file;
        }
        self::$resolving = true;
        try {
            if (file_exists($file)) {
                return $file;
            }
            $relativePath = self::relativePath($file);
            $attachmentId = (int) $attachmentId;
            if (
                $attachmentId < 1 || $relativePath === null
                || get_post_type($attachmentId) !== 'attachment'
                || RemoteMediaProxy::getInstance()->getMimeType($relativePath) === null
            ) {
                return $file;
            }
            // Preserve another filter's path mapping; our identity resolves only native attachment metadata.
            if (wp_normalize_path($file) !== wp_normalize_path((string) get_attached_file($attachmentId, true))) {
                return $file;
            }
            if (!RemoteMediaProxy::getInstance()->isConfigured() || !self::registerWrapper()) {
                return $file;
            }
            return self::SCHEME . '://' . get_current_blog_id() . '/' . $attachmentId . '/'
                . rawurlencode(wp_basename($relativePath));
        } finally {
            self::$resolving = false;
        }
    }

    /** Read an attachment in its own site's context, even after switch_to_blog(). */
    public static function read(string $uri): ?string
    {
        if (!preg_match('#^remotemediaproxy://([1-9][0-9]*)/([1-9][0-9]*)/([^/?\#]+)$#D', $uri, $matches)) {
            return null;
        }
        $blogId = (int) $matches[1];
        $attachmentId = (int) $matches[2];
        $switched = $blogId !== get_current_blog_id();
        if ($switched) {
            if (!is_multisite() || !get_site($blogId)) {
                return null;
            }
            switch_to_blog($blogId);
        }
        try {
            // The unfiltered native path prevents recursion and binds the URI to a real attachment record.
            $file = get_attached_file($attachmentId, true);
            if (self::getAttachedFile($file, $attachmentId) !== $uri) {
                return null;
            }
            $relativePath = self::relativePath($file);
            $upload = $relativePath === null ? null : RemoteMediaProxy::getInstance()->fetchUpload($relativePath);
            return $upload['body'] ?? null;
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private static function isAvailable(): bool
    {
        return ini_get('allow_url_fopen')
            && (self::$registered || !in_array(self::SCHEME, stream_get_wrappers(), true));
    }

    private static function registerWrapper(): bool
    {
        // Recheck after extensible metadata/options lookups; never replace a foreign or native wrapper.
        if (!self::isAvailable()) {
            return false;
        }
        if (!self::$registered) {
            self::$registered = StreamWrapper::register(self::SCHEME, [self::class, 'read'], STREAM_IS_URL);
        }
        return self::$registered;
    }

    private static function relativePath(mixed $file): ?string
    {
        if (!is_string($file)) {
            return null;
        }
        $uploads = wp_get_upload_dir();
        $base = rtrim(wp_normalize_path($uploads['basedir']), '/') . '/';
        $file = wp_normalize_path($file);
        if (!str_starts_with($file, $base)) {
            return null;
        }
        $relativePath = substr($file, strlen($base));
        return RemoteMediaProxy::getInstance()->isValidPath($relativePath) ? $relativePath : null;
    }
}
