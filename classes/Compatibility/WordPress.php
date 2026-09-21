<?php

namespace RemoteMediaProxy\Compatibility;

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
        $file = $proxy->openUpload($relativePath);
        if ($file === null) {
            return $template;
        }
        try {
            // Avoid buffering a large response in a theme or plugin's HTML output buffer.
            while (ob_get_level() > 0) {
                $buffer = ob_get_status();
                if (!($buffer['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) || !ob_end_clean()) {
                    return $template;
                }
            }
            // Buffer finalizers may themselves emit output. Do not send bytes without our response headers.
            if (headers_sent()) {
                return $template;
            }
            status_header(200);
            // Clear WordPress's 404 cache headers and stale validators before allowing private reuse.
            nocache_headers();
            header_remove('Expires');
            header_remove('Pragma');
            header_remove('ETag');
            header('Cache-Control: private, max-age=300, stale-while-revalidate=60');
            header('Content-Type: ' . $file->type);
            header('Content-Length: ' . $file->size);
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
            // Binary media, not HTML. The download has already passed MIME and size validation.
            fpassthru($file->stream);
        } finally {
            $file->close();
        }
        exit;
    }
}
