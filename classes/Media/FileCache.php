<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

/** Retain complete opaque files in private storage, with atomic replacement and consumer-defined freshness. */
final class FileCache
{
    /**
     * Isolate consumer identities inside the installation's shared per-site cache directory.
     *
     * @param string $namespace Consumer name included in entry identities.
     * @param string $extension Trusted filename extension needed by native file consumers.
     */
    public function __construct(
        /** @var string Consumer identity included in entry hashes. */
        private readonly string $namespace,
        /** @var string Safe filename suffix, without content-specific interpretation. */
        private readonly string $extension = 'tmp'
    ) {
    }

    /**
     * Obtain local filesystem operations without changing the global WordPress transport.
     *
     * @return \WP_Filesystem_Direct Local filesystem implementation.
     */
    private static function filesystem(): \WP_Filesystem_Direct
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        return new \WP_Filesystem_Direct(null);
    }

    /**
     * Resolve the private cache directory, accepting host removal as a normal miss.
     *
     * @param boolean $create Whether a missing directory may be recreated.
     * @return string|null Canonical private directory, or null when unavailable or unsafe.
     */
    private function directory(bool $create = true): ?string
    {
        $base = TemporaryFile::directory();
        if ($base === null || !preg_match('/^[a-z0-9]{1,16}$/D', $this->extension)) {
            return null;
        }
        $identity = hash_hmac('sha256', serialize([ABSPATH, home_url(), get_current_blog_id()]), wp_salt('auth'));
        $directory = $base . 'remote-media-proxy-' . substr($identity, 0, 32);
        if (!file_exists($directory) && $create) {
            $mask = umask(0077);
            try {
                @self::filesystem()->mkdir($directory, 0700);
            } finally {
                umask($mask);
            }
        }
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        return is_array($stat) && ($stat['mode'] & 0170000) === 0040000
            && ($stat['mode'] & 0077) === 0 && wp_normalize_path((string) realpath($directory)) === $directory
            && (!$create || wp_is_writable($directory)) ? $directory : null;
    }

    /**
     * Allocate one staging file inside the cache directory, without per-operation directories or locks.
     *
     * @param string $extension Filename extension required by the producer.
     * @return string|null Request-owned staging path, or null when storage is unavailable.
     */
    public function stage(string $extension = 'tmp'): ?string
    {
        $directory = $this->directory();
        return $directory === null ? null : TemporaryFile::create($extension, $directory);
    }

    /**
     * Open a fresh entry without extending its deadline or deleting expired content.
     *
     * @param string       $key       Consumer-defined content identity.
     * @param string       $type      MIME type established by the consumer.
     * @param integer      $maxAge    Consumer-selected maximum age in seconds.
     * @param integer|null $expiresAt Receives the absolute deadline, or null on a miss.
     * @return MediaFile|null Independent reader, or null for expired, removed or unsafe content.
     */
    public function open(string $key, string $type, int $maxAge, ?int &$expiresAt = null): ?MediaFile
    {
        $expiresAt = null;
        $directory = $this->directory(false);
        if ($maxAge <= 0 || $directory === null) {
            return null;
        }
        $path = $directory . '/' . $this->identity($key);
        if (self::entry($path) === null) {
            return null;
        }
        $file = MediaFile::open($path, $type);
        if ($file === null) {
            return null;
        }
        // Use the opened inode, not a path stat that may precede concurrent atomic replacement.
        $stat = fstat($file->stream);
        if (
            !is_array($stat) || ($stat['mode'] & 0170077) !== 0100000 || $stat['nlink'] > 1 || $stat['size'] < 1
            || $stat['mtime'] > time() || time() - $stat['mtime'] >= $maxAge
        ) {
            $file->close();
            return null;
        }
        $expiresAt = $stat['mtime'] + $maxAge;
        return $file;
    }

    /**
     * Adopt a completed request-owned file atomically, without copying its bytes.
     *
     * @param string       $key       Consumer-defined content identity.
     * @param string       $path      Complete private file owned by TemporaryFile.
     * @param integer|null $createdAt Earlier freshness origin for derived content, or null for publication time.
     * @return integer|null Freshness origin on success; failure leaves the working file request-owned.
     */
    public function publish(string $key, string $path, ?int $createdAt = null): ?int
    {
        if (!TemporaryFile::owns($path) || self::entry($path) === null) {
            return null;
        }
        $directory = $this->directory();
        $createdAt ??= time();
        if ($directory === null || $createdAt < 1 || $createdAt > time()) {
            return null;
        }
        $target = $directory . '/' . $this->identity($key);
        if (is_link($target) || (file_exists($target) && self::entry($target) === null)) {
            return null;
        }
        if (!@self::filesystem()->touch($path, $createdAt, $createdAt)) {
            return null;
        }
        return TemporaryFile::transfer($path, $target) ? $createdAt : null;
    }

    /**
     * Map opaque identities to filesystem-safe names without reading their contents.
     *
     * @param string $key Consumer-defined identity including source dependencies.
     * @return string Stable hashed filename, preserving the consumer's required suffix.
     */
    private function identity(string $key): string
    {
        return hash('sha256', serialize([$this->namespace, $key])) . '.' . $this->extension;
    }

    /**
     * Inspect a private regular entry without accepting symlinks or multiple links.
     *
     * @param string $path Cache entry to inspect.
     * @return array<array-key,integer>|null Metadata for a nonempty private file, or null when unsafe or absent.
     */
    private static function entry(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return is_array($stat) && ($stat['mode'] & 0170077) === 0100000
            && $stat['nlink'] === 1 && $stat['size'] > 0 ? $stat : null;
    }
}
