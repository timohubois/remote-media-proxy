<?php

namespace RemoteMediaProxy\Compatibility;

use RemoteMediaProxy\Features\RemoteMediaProxy;
use RemoteMediaProxy\Features\VirtualUploads;

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
        if ($requestMethod !== 'GET' || !empty($_SERVER['HTTP_X_REMOTE_MEDIA_PROXY'])) {
            return $template;
        }
        $uploads = wp_get_upload_dir();
        $uploadsPath = rtrim((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH), '/');
        // Validate the decoded path rather than sanitizing traversal into a different, valid request.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $requestPath = rawurldecode(explode('?', wp_unslash($_SERVER['REQUEST_URI'] ?? ''), 2)[0]);
        if (!str_starts_with($requestPath, $uploadsPath . '/')) {
            return $template;
        }
        $relativePath = substr($requestPath, strlen($uploadsPath) + 1);
        $file = RemoteMediaProxy::getInstance()->fetchUpload($relativePath);
        if ($file === null) {
            return $template;
        }
        status_header(200);
        nocache_headers();
        header('Content-Type: ' . $file['type']);
        header('Content-Length: ' . strlen($file['body']));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
        // Binary media, not HTML. The remote-reading feature validates MIME and response bounds.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $file['body'];
        exit;
    }
}
