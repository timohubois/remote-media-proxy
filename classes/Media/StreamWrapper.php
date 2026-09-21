<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

final class StreamWrapper
{
    public const string SCHEME = 'remotemediaproxy';

    private static bool $registered = false;

    private static bool $dispatching = false;

    private const int OPEN_FOR_INCLUDE = 128;

    public $context;

    private ?MediaFile $file = null;

    public static function isAvailable(): bool
    {
        return ini_get('allow_url_fopen')
            && (self::$registered || !in_array(self::SCHEME, stream_get_wrappers(), true));
    }

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

    private static function handler(string $path, string $operation): ?callable
    {
        if (self::$dispatching || !preg_match('#^remotemediaproxy://([a-z][a-z0-9_-]*)/.+$#D', $path, $matches)) {
            return null;
        }
        self::$dispatching = true;
        try {
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

    public function stream_read( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        int $count
    ): string|false {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- PHP requires bounded reads from the existing native handle.
        return $this->file === null ? false : fread($this->file->stream, $count);
    }

    public function stream_tell(): int|false // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
    {
        return $this->file === null ? false : ftell($this->file->stream);
    }

    public function stream_eof(): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
    {
        return $this->file === null || feof($this->file->stream);
    }

    public function stream_seek( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        int $offset,
        int $whence = SEEK_SET
    ): bool {
        return $this->file !== null && fseek($this->file->stream, $offset, $whence) === 0;
    }

    public function stream_stat(): array|false // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
    {
        return $this->file === null ? false : self::fileStat($this->file->size);
    }

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

    private static function reportFailure(bool $report): void
    {
        if ($report) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- PHP requires flag-controlled stream warnings; disclose no request data.
            trigger_error('Stream operation failed.', E_USER_WARNING);
        }
    }

    private static function fileStat(int $size, int $modified = 0): array
    {
        $keys = [
            'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks',
        ];
        $values = [0, 0, 0100444, 1, 0, 0, 0, $size, 0, $modified, 0, -1, -1];
        return array_combine($keys, $values) + $values;
    }

    public function stream_close(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
    {
        $this->file?->close();
        $this->file = null;
    }

    public function stream_set_option( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        int $option,
        int $arg1,
        ?int $arg2
    ): bool {
        return false;
    }

    public function stream_write( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        string $data
    ): int {
        return 0;
    }

    public function stream_truncate( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        int $size
    ): bool {
        return false;
    }

    public function stream_metadata( // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP API.
        string $path,
        int $option,
        mixed $value
    ): bool {
        return false;
    }

    // Unsupported unlink/rename/mkdir/rmdir callbacks are intentionally absent, as required by PHP's manual.
}
