<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

final class VirtualUploads
{
    private static bool $resolving = false;

    public static function resolve(string $file, int $attachmentId): ?string
    {
        if (self::$resolving || str_contains($file, '://') || !StreamWrapper::isAvailable()) {
            return null;
        }
        self::$resolving = true;
        try {
            if (file_exists($file)) {
                return null;
            }
            $relativePath = self::relativePath($file);
            if (
                $attachmentId < 1 || $relativePath === null
                || get_post_type($attachmentId) !== 'attachment'
                || RemoteMediaProxy::getInstance()->getMimeType($relativePath) === null
            ) {
                return null;
            }
            // Bind the virtual identity to native attachment metadata, never to an alternate path.
            if (wp_normalize_path($file) !== wp_normalize_path((string) get_attached_file($attachmentId, true))) {
                return null;
            }
            if (!RemoteMediaProxy::getInstance()->isConfigured() || !StreamWrapper::register()) {
                return null;
            }
            return StreamWrapper::SCHEME . '://attachment/' . get_current_blog_id() . '/' . $attachmentId . '/'
                . rawurlencode(wp_basename($relativePath));
        } finally {
            self::$resolving = false;
        }
    }

    public static function open(string $uri): ?MediaFile
    {
        return self::access($uri, false);
    }

    public static function stat(string $uri): ?array
    {
        $metadata = self::access($uri, true);
        return $metadata === null ? null : ['size' => $metadata['size']];
    }

    private static function access(string $uri, bool $metadataOnly): MediaFile|array|null
    {
        if (!preg_match('#^remotemediaproxy://attachment/([1-9][0-9]*)/([1-9][0-9]*)/([^/?\#]+)$#D', $uri, $matches)) {
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
            if (!is_string($file) || get_post_type($attachmentId) !== 'attachment') {
                return null;
            }
            $relativePath = self::relativePath($file);
            $proxy = RemoteMediaProxy::getInstance();
            if (
                $relativePath === null || !$proxy->isConfigured()
                || !StreamWrapper::isAvailable()
                || $uri !== StreamWrapper::SCHEME . '://attachment/' . $blogId . '/' . $attachmentId . '/'
                    . rawurlencode(wp_basename($relativePath))
            ) {
                return null;
            }
            // A valid existing URI remains usable when its original appears locally after resolution.
            // The backend enforces MIME policy and local-first access for both reads and metadata.
            return $metadataOnly ? $proxy->statUpload($relativePath) : $proxy->openUpload($relativePath);
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private static function relativePath(string $file): ?string
    {
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
