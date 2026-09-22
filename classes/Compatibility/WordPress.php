<?php

namespace RemoteMediaProxy\Compatibility;

use RemoteMediaProxy\Media\MediaFile;
use RemoteMediaProxy\Media\RemoteMediaProxy;
use RemoteMediaProxy\Media\VirtualUploads;

defined('ABSPATH') || exit;

/** Adapt browser media requests and native attachment reads to the shared backend. */
final class WordPress
{
    /** Register missing-upload delivery and attachment-path filters. */
    public function __construct()
    {
        add_filter('404_template', [$this, 'proxyUpload']);
        add_filter('get_attached_file', [$this, 'proxyAttachedFile'], 20, 2);
    }

    /**
     * Substitute a virtual URI only for a supported, missing local attachment.
     *
     * @param mixed $file         Attachment path supplied by WordPress or another filter.
     * @param mixed $attachmentId Attachment identity supplied to the filter.
     * @return mixed Read-only virtual URI or the unchanged input when proxying is unavailable.
     */
    public function proxyAttachedFile(mixed $file, mixed $attachmentId): mixed
    {
        if (!is_string($file) || !is_numeric($attachmentId) || !empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY'])) {
            return $file;
        }
        return VirtualUploads::resolve($file, (int) $attachmentId) ?? $file;
    }

    /**
     * Deliver a validated missing upload and terminate, or leave native 404 handling unchanged.
     *
     * @param string $template WordPress's selected 404 template.
     * @return string Original template when proxying is rejected or unavailable; success exits the request.
     */
    public function proxyUpload(string $template): string
    {
        $requestMethod = sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? ''));
        if ($requestMethod !== 'GET' || headers_sent() || !empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY'])) {
            return $template;
        }
        $uploads = wp_get_upload_dir();
        $uploadsPath = rtrim((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH), '/');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve exact paths; isValidPath() rejects traversal and controls before use.
        $requestPath = rawurldecode(explode('?', wp_unslash($_SERVER['REQUEST_URI'] ?? ''), 2)[0]);
        if (!str_starts_with($requestPath, $uploadsPath . '/')) {
            return $template;
        }
        $relativePath = substr($requestPath, strlen($uploadsPath) + 1);
        $proxy = RemoteMediaProxy::getInstance();
        if (!$proxy->isValidPath($relativePath) || file_exists($uploads['basedir'] . '/' . $relativePath)) {
            return $template;
        }
        $missing = false;
        $file = $proxy->openUpload($relativePath, $missing, $expiresAt);
        if ($file === null) {
            return $template;
        }
        if (self::sendFile($file, $expiresAt)) {
            exit;
        }
        return $template;
    }

    /**
     * Deliver validated bytes with only their remaining freshness, never stale-while-revalidate.
     *
     * @param MediaFile    $file      Reader consumed and closed by this response adapter.
     * @param integer|null $expiresAt Absolute remote or generated deadline, or null for physical local bytes.
     * @return boolean Whether headers and bytes were sent; false leaves normal template handling intact.
     */
    public static function sendFile(MediaFile $file, ?int $expiresAt = null): bool
    {
        try {
            if (headers_sent()) {
                return false;
            }
            // Avoid buffering a large response in a theme or plugin's HTML output buffer.
            while (ob_get_level() > 0) {
                $buffer = ob_get_status();
                if (!($buffer['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) || !ob_end_clean()) {
                    return false;
                }
            }
            // Buffer finalizers may themselves emit output. Do not send bytes without our response headers.
            if (headers_sent()) {
                return false;
            }
            status_header(200);
            // Clear WordPress's 404 cache headers and stale validators before allowing private reuse.
            nocache_headers();
            header_remove('Expires');
            header_remove('Pragma');
            header_remove('ETag');
            header('Cache-Control: ' . self::cacheControl($expiresAt));
            header('Content-Type: ' . $file->type);
            header('Content-Length: ' . $file->size);
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
            // Binary media, not HTML. The download has already passed MIME and size validation.
            fpassthru($file->stream);
            return true;
        } finally {
            $file->close();
        }
    }

    /**
     * Avoid stacking browser freshness on top of the age of cached media.
     *
     * @param integer|null $expiresAt Cached bytes' absolute deadline, or null for physical local bytes.
     * @return string Private response policy with mandatory revalidation after expiration.
     */
    private static function cacheControl(?int $expiresAt): string
    {
        return 'private, max-age=' . ($expiresAt === null ? 300 : max(0, $expiresAt - time())) . ', must-revalidate';
    }
}
