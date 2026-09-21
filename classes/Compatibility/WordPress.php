<?php

namespace RemoteMediaProxy\Compatibility;

use RemoteMediaProxy\Media\RemoteMediaProxy;
use RemoteMediaProxy\Media\VirtualUploads;

defined('ABSPATH') || exit;

final class WordPress
{
    public function __construct()
    {
        add_filter('404_template', [$this, 'proxyUpload']);
        add_filter('get_attached_file', [$this, 'proxyAttachedFile'], 20, 2);
    }

    public function proxyAttachedFile(mixed $file, mixed $attachmentId): mixed
    {
        if (!is_string($file) || !is_numeric($attachmentId) || !empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY'])) {
            return $file;
        }
        return VirtualUploads::resolve($file, (int) $attachmentId) ?? $file;
    }

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
            nocache_headers();
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
