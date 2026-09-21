<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

/** Bind virtual upload URIs to native attachment identities and their site context. */
final class VirtualUploads
{
    /** @var boolean Guard against recursive attachment-path resolution. */
    private static bool $resolving = false;

    /**
     * Resolve a missing native attachment path without probing the source.
     *
     * @param string  $file         Candidate physical attachment path.
     * @param integer $attachmentId Attachment ID in the current site.
     * @return string|null Canonical virtual URI, or null when the native path should remain unchanged.
     */
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

    /**
     * Open an attachment in its own site's context with local-first precedence.
     *
     * @param string $uri Canonical attachment namespace URI.
     * @return MediaFile|null Independent reader owned by the caller, or null on failure.
     */
    public static function open(string $uri): ?MediaFile
    {
        return self::access($uri, false);
    }

    /**
     * Inspect attachment size without downloading a remote body.
     *
     * @param string $uri Canonical attachment namespace URI.
     * @return array{size:int}|null Validated size, or null when unavailable.
     */
    public static function stat(string $uri): ?array
    {
        $metadata = self::access($uri, true);
        return $metadata === null ? null : ['size' => $metadata['size']];
    }

    /**
     * Resolve the attachment in its own site context and always restore the caller's site afterward.
     *
     * @param string  $uri          Attachment namespace URI to validate and resolve.
     * @param boolean $metadataOnly Whether to inspect size rather than open a reader.
     * @return MediaFile|array{size:int,type:string}|null The caller owns any returned reader.
     */
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

    /**
     * Require a valid relative path beneath the current site's uploads directory.
     *
     * @param string $file Native absolute attachment path.
     * @return string|null Validated relative path, or null for paths outside the allowed scope.
     */
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
