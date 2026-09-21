<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

/** Dispatch read-only PHP stream operations to registered media namespace handlers. */
final class StreamWrapper
{
    /** @var string Shared URL scheme for attachment reads and synthetic Timber metadata. */
    public const string SCHEME = 'remotemediaproxy';

    /** @var boolean Whether this request registered the shared stream protocol. */
    private static bool $registered = false;

    /** @var boolean Guard against recursive namespace-handler discovery. */
    private static bool $dispatching = false;

    /** @var integer Include-operation flag defined by PHP's main/php_streams.h but not exposed to userland. */
    private const int OPEN_FOR_INCLUDE = 128;

    /** @var resource|null Populated by PHP; cannot override attachment resolution. */
    public $context;

    /** @var MediaFile|null Independent reader owned by this wrapper instance. */
    private ?MediaFile $file = null;

    /**
     * Check whether URL streams are enabled and the scheme is available to this plugin.
     *
     * @return boolean Whether registration or use of the owned wrapper is possible.
     */
    public static function isAvailable(): bool
    {
        return ini_get('allow_url_fopen')
            && (self::$registered || !in_array(self::SCHEME, stream_get_wrappers(), true));
    }

    /**
     * Register the shared protocol without replacing any foreign wrapper.
     *
     * @return boolean Whether the owned protocol is registered and available.
     */
    public static function register(): bool
    {
        // Never replace a foreign or native wrapper, including after extensible options lookups.
        if (!self::isAvailable()) {
            return false;
        }
        if (self::$registered) {
            return in_array(self::SCHEME, stream_get_wrappers(), true);
        }
        return self::$registered = stream_wrapper_register(self::SCHEME, self::class, STREAM_IS_URL);
    }

    /**
     * Resolve an operation through filtered handlers for the URI namespace.
     *
     * @param string $path      Complete virtual URI supplied by PHP.
     * @param string $operation Handler operation name, either open or stat.
     * @return callable|null Namespace callback, or null for invalid or unsupported operations.
     */
    private static function handler(string $path, string $operation): ?callable
    {
        if (self::$dispatching || !preg_match('#^remotemediaproxy://([a-z][a-z0-9_-]*)/.+$#D', $path, $matches)) {
            return null;
        }
        self::$dispatching = true;
        try {
            /**
             * Filter namespace handlers. Each callback receives the complete URI.
             * open returns MediaFile|null; stat returns array{size: int, mtime?: int}|null.
             * Omitted operations are denied. Writes and PHP includes cannot be enabled here.
             */
            $handlers = apply_filters('remote_media_proxy_stream_handlers', [
                'attachment' => [
                    'open' => VirtualUploads::open(...),
                    'stat' => VirtualUploads::stat(...),
                ],
            ]);
            $handler = is_array($handlers) ? ($handlers[$matches[1]] ?? null) : null;
            $callback = is_array($handler) ? ($handler[$operation] ?? null) : null;
            return is_callable($callback) ? $callback : null;
        } finally {
            self::$dispatching = false;
        }
    }

    /**
     * Open an independent reader, rejecting writes and PHP inclusion before dispatch.
     *
     * @param string      $path       Complete virtual URI supplied by PHP.
     * @param string      $mode       Requested stream mode; only read modes are accepted.
     * @param integer     $options    PHP stream flags controlling errors, includes and path reporting.
     * @param string|null $openedPath Receives the resolved URI when PHP requests it.
     * @return boolean Whether a validated reader was opened.
     */
    public function stream_open( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath
    ): bool {
        if (!in_array($mode, ['r', 'rb', 'rt'], true) || ($options & self::OPEN_FOR_INCLUDE)) {
            self::reportFailure((bool) ($options & STREAM_REPORT_ERRORS));
            return false;
        }
        $open = self::handler($path, 'open');
        $file = $open === null ? null : $open($path);
        if (!$file instanceof MediaFile) {
            self::reportFailure((bool) ($options & STREAM_REPORT_ERRORS));
            return false;
        }
        $this->file = $file;
        if ($options & STREAM_USE_PATH) {
            $openedPath = $path;
        }
        return true;
    }

    /**
     * Read a bounded chunk without materializing the whole file in memory.
     *
     * @param integer $count Maximum number of bytes requested by PHP.
     * @return string|false Read bytes, an empty string at EOF, or false on failure.
     */
    public function stream_read( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        int $count
    ): string|false {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- PHP requires bounded reads from the existing native handle.
        return $this->file === null ? false : fread($this->file->stream, $count);
    }

    /**
     * Report the independent reader's current byte offset.
     *
     * @return integer|false Byte offset, or false when no reader is available.
     */
    public function stream_tell(): int|false // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
    {
        return $this->file === null ? false : ftell($this->file->stream);
    }

    /**
     * Report end-of-file, treating an unavailable reader as exhausted.
     *
     * @return boolean Whether further bytes are unavailable.
     */
    public function stream_eof(): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
    {
        return $this->file === null || feof($this->file->stream);
    }

    /**
     * Move this reader without changing other readers of the shared download.
     *
     * @param integer $offset Byte offset relative to the selected origin.
     * @param integer $whence Native SEEK_SET, SEEK_CUR or SEEK_END origin.
     * @return boolean Whether seeking succeeded.
     */
    public function stream_seek( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        int $offset,
        int $whence = SEEK_SET
    ): bool {
        return $this->file !== null && fseek($this->file->stream, $offset, $whence) === 0;
    }

    /**
     * Describe the opened reader as a read-only regular file.
     *
     * @return array<array-key,int>|false PHP stat fields, or false without an open reader.
     */
    public function stream_stat(): array|false // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
    {
        return $this->file === null ? false : self::fileStat($this->file->size);
    }

    /**
     * Validate namespace metadata and normalize it into PHP stat fields.
     *
     * @param string  $path  Complete virtual URI supplied by PHP.
     * @param integer $flags PHP stat flags, including quiet failure handling.
     * @return array<array-key,int>|false Read-only stat fields, or false when unavailable.
     */
    public function url_stat( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        string $path,
        int $flags
    ): array|false {
        $stat = self::handler($path, 'stat');
        $metadata = $stat === null ? null : $stat($path);
        if (
            !is_array($metadata) || !is_int($metadata['size'] ?? null) || $metadata['size'] < 0
            || !is_int($metadata['mtime'] ?? 0) || ($metadata['mtime'] ?? 0) < 0
        ) {
            self::reportFailure(!($flags & STREAM_URL_STAT_QUIET));
            return false;
        }
        return self::fileStat($metadata['size'], $metadata['mtime'] ?? 0);
    }

    /**
     * Emit only generic, flag-controlled stream warnings without request data.
     *
     * @param boolean $report Whether PHP requested a warning for the failed operation.
     * @return void
     */
    private static function reportFailure(bool $report): void
    {
        if ($report) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- PHP requires flag-controlled stream warnings; disclose no request data.
            trigger_error('Stream operation failed.', E_USER_WARNING);
        }
    }

    /**
     * Build numeric and named stat fields representing a read-only regular file.
     *
     * @param integer $size     Validated nonnegative byte length.
     * @param integer $modified Nonnegative modification time, or zero when unspecified.
     * @return array<array-key,int> PHP-compatible numeric and named stat entries.
     */
    private static function fileStat(int $size, int $modified = 0): array
    {
        $keys = [
            'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks',
        ];
        $values = [0, 0, 0100444, 1, 0, 0, 0, $size, 0, $modified, 0, -1, -1];
        return array_combine($keys, $values) + $values;
    }

    /**
     * Release this wrapper's reader without deleting the shared file.
     *
     * @return void
     */
    public function stream_close(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
    {
        $this->file?->close();
        $this->file = null;
    }

    /**
     * Reject stream-option changes rather than modifying the underlying reader.
     *
     * @param integer      $option PHP stream option identifier.
     * @param integer      $arg1   First option argument, unused for this read-only wrapper.
     * @param integer|null $arg2   Second argument; PHP passes null for blocking-mode changes.
     * @return boolean Always false because option changes are unsupported.
     */
    public function stream_set_option( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        int $option,
        int $arg1,
        ?int $arg2
    ): bool {
        return false;
    }

    /**
     * Deny writes even if PHP calls this callback directly.
     *
     * @param string $data Bytes that must not be written.
     * @return integer Always zero bytes written.
     */
    public function stream_write( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        string $data
    ): int {
        return 0;
    }

    /**
     * Deny changes to the underlying file length.
     *
     * @param integer $size Requested length, ignored by this read-only wrapper.
     * @return boolean Always false because truncation is forbidden.
     */
    public function stream_truncate( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        int $size
    ): bool {
        return false;
    }

    /**
     * Deny ownership, permission and timestamp changes through the virtual namespace.
     *
     * @param string  $path   Target URI supplied by PHP.
     * @param integer $option Requested metadata operation.
     * @param mixed   $value  Requested metadata value, never applied.
     * @return boolean Always false because metadata changes are forbidden.
     */
    public function stream_metadata( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        string $path,
        int $option,
        mixed $value
    ): bool {
        return false;
    }

    // Unsupported unlink/rename/mkdir/rmdir callbacks are intentionally absent, as required by PHP's manual.
}
