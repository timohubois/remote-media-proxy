<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

/** Own private request-local downloads and coordinate their shutdown cleanup. */
final class TemporaryFile
{
    /** @var array<string, true> Owned paths, retained until deletion is confirmed. */
    private static array $files = [];
    /** @var boolean Whether the shutdown cleanup callback has been registered. */
    private static bool $registered = false;

    /**
     * Allocate and verify a private empty file outside known web roots.
     *
     * @return string|null Owned file path, or null when allocation is rejected or fails.
     */
    public static function create(): ?string
    {
        $directory = @realpath(get_temp_dir());
        if (
            $directory === false || !is_dir($directory) || !wp_is_writable($directory)
            || !function_exists('umask')
        ) {
            return null;
        }
        $directory = rtrim(wp_normalize_path($directory), '/') . '/';
        $uploads = wp_get_upload_dir();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate the exclusion root with realpath(); sanitizing could change it.
        $documentRoot = wp_unslash($_SERVER['DOCUMENT_ROOT'] ?? '');
        $roots = [ABSPATH, WP_CONTENT_DIR, dirname(__DIR__, 2), $uploads['basedir'], $documentRoot];
        foreach ($roots as $root) {
            if (!is_string($root) || $root === '') {
                continue;
            }
            $root = @realpath($root);
            if ($root === false) {
                continue;
            }
            $root = rtrim(wp_normalize_path($root), '/') . '/';
            if (str_starts_with(strtolower($directory), strtolower($root))) {
                return null;
            }
        }
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        // Restrict access at creation, before the HTTP transport writes any protected media.
        $mask = umask(0077);
        try {
            $file = wp_tempnam('remote-media-proxy', $directory);
        } finally {
            umask($mask);
        }
        if (!is_string($file) || $file === '') {
            return null;
        }
        // Core can return a filename even when its exclusive fopen() failed.
        // Never let the transport create an unallocated file after the private umask was restored.
        $stat = @lstat($file);
        if (
            !is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || $stat['size'] !== 0
            || wp_normalize_path((string) realpath(dirname($file))) . '/' !== $directory
        ) {
            return null;
        }
        self::$files[$file] = true;
        if (!self::$registered) {
            register_shutdown_function([self::class, 'cleanup']);
            self::$registered = true;
        }
        return $file;
    }

    /**
     * Request deletion of an owned file, retaining failed or filtered attempts for retry.
     *
     * @param string $file Exact path previously allocated by this class.
     * @return void
     */
    public static function remove(string $file): void
    {
        if (!isset(self::$files[$file])) {
            return;
        }
        // Request deletion only for owned paths, and retain failed or filtered deletions for a later retry.
        wp_delete_file($file);
        clearstatcache(true, $file);
        if (!file_exists($file) && !is_link($file)) {
            unset(self::$files[$file]);
        }
    }

    /**
     * Close all media readers before retrying deletion of this request's remaining files.
     *
     * @return void
     */
    public static function cleanup(): void
    {
        // Windows cannot unlink an open file. Release readers before deleting the request's downloads.
        MediaFile::closeAll();
        foreach (array_keys(self::$files) as $file) {
            self::remove($file);
        }
    }
}
