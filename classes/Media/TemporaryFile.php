<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

/** Own private staging files until deletion or transfer into the reusable cache. */
final class TemporaryFile
{
    /** @var array<string,boolean> Owned paths; true also owns native image-editor format variants of that random stem. */
    private static array $files = [];

    /** @var boolean Whether the shutdown cleanup callback has been registered. */
    private static bool $registered = false;

    /**
     * Resolve a canonical temporary directory outside known web roots; allocation checks write access.
     *
     * @param string|null $path Trusted private staging directory, or null for WordPress's temporary directory.
     * @return string|null Canonical directory with a trailing slash, or null when unsuitable.
     */
    public static function directory(?string $path = null): ?string
    {
        $directory = @realpath($path ?? get_temp_dir());
        if (
            $directory === false || !is_dir($directory)
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
        return $directory;
    }

    /**
     * Allocate a private empty staging file, preserving native image-editor filename extensions.
     *
     * @param string      $extension Filename extension required by the producer; tmp for ordinary downloads.
     * @param string|null $directory Trusted private cache directory, or null for request-only fallback storage.
     * @return string|null Owned file path, or null when allocation is rejected or fails.
     */
    public static function create(string $extension = 'tmp', ?string $directory = null): ?string
    {
        $directory = self::directory($directory);
        if ($directory === null || !preg_match('/^[a-z0-9]{1,16}$/D', $extension)) {
            return null;
        }
        $token = wp_generate_password(32, false);
        if (!is_string($token) || !preg_match('/^[a-zA-Z0-9]{32}$/D', $token)) {
            return null;
        }
        $file = $directory . 'remote-media-proxy-' . $token . '.' . $extension;
        $mask = umask(0077);
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive allocation proves ownership and permits exact editor extensions, unlike wp_tempnam().
            $handle = @fopen($file, 'x+b');
        } finally {
            umask($mask);
        }
        if (!is_resource($handle)) {
            return null;
        }
        $stat = fstat($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively allocated native handle before its producer writes.
        fclose($handle);
        self::$files[$file] = $extension !== 'tmp';
        if (!self::$registered) {
            register_shutdown_function([self::class, 'cleanup']);
            self::$registered = true;
        }
        if (
            !is_array($stat) || ($stat['mode'] & 0170077) !== 0100000
            || $stat['size'] !== 0 || $stat['nlink'] !== 1
        ) {
            self::remove($file);
            return null;
        }
        return $file;
    }

    /**
     * Check ownership of an exclusively allocated staging file.
     *
     * @param string $file Exact working-file path supplied by a producer.
     * @return boolean Whether this request owns the path and its cleanup.
     */
    public static function owns(string $file): bool
    {
        return isset(self::$files[$file]);
    }

    /**
     * Move an owned working file atomically and relinquish its request-local cleanup.
     *
     * @param string $file   Owned working file already validated by the cache.
     * @param string $target Destination already validated inside the private cache directory.
     * @return boolean Whether ownership transferred; failure leaves the working file request-owned.
     */
    public static function transfer(string $file, string $target): bool
    {
        if (!self::owns($file) || !is_file($file) || is_link($file)) {
            return false;
        }
        $source = @lstat($file);
        $destination = @stat(dirname($target));
        if (!is_array($source) || !is_array($destination) || $source['dev'] !== $destination['dev']) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem transfer is atomic and never copies into a visible cache entry.
        if (!@rename($file, $target)) {
            return false;
        }
        // Editors may also emit another format under the same random staging stem.
        self::remove($file);
        return true;
    }

    /**
     * Delete owned staging files, retaining failed or filtered attempts for retry.
     *
     * @param string $file Exact path previously allocated by this class.
     * @return void
     */
    public static function remove(string $file): void
    {
        if (!self::owns($file)) {
            return;
        }
        $paths = [$file];
        if (self::$files[$file]) {
            $stem = substr($file, 0, (int) strrpos($file, '.'));
            // Escape directory metacharacters; only this request's random editor stem is eligible for cleanup.
            $pattern = strtr($stem, ['[' => '[[]', ']' => '[]]', '*' => '[*]', '?' => '[?]']) . '.*';
            $paths = array_unique(array_merge($paths, glob($pattern) ?: []));
        }
        $remaining = false;
        foreach ($paths as $path) {
            wp_delete_file($path);
            clearstatcache(true, $path);
            $remaining = $remaining || file_exists($path) || is_link($path);
        }
        if (!$remaining) {
            unset(self::$files[$file]);
        }
    }

    /**
     * Close readers before retrying deletion of this request's remaining staging files.
     *
     * @return void
     */
    public static function cleanup(): void
    {
        MediaFile::closeAll();
        foreach (array_keys(self::$files) as $file) {
            self::remove($file);
        }
    }
}
