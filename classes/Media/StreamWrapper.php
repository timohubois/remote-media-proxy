<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

// PHP defines these callback names; they are not ordinary application methods.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
final class StreamWrapper
{
    // PHP's main/php_streams.h defines this flag but does not expose it as a userland constant.
    private const OPEN_FOR_INCLUDE = 128;

    /** @var array<string, callable(string): (string|false|null)> */
    private static array $readers = [];

    /** @var resource|null Populated by PHP; cannot override the registered reader. */
    public $context;

    private string $body = '';
    private int $position = 0;

    /**
     * Register a read-only data source without replacing an existing protocol handler.
     *
     * @param string $scheme Protocol name (at least two characters), normalized to lowercase.
     * @param callable(string): (string|false|null) $reader Bytes for the URI; false/null means unavailable.
     * @param int $flags PHP registration flags; pass STREAM_IS_URL for remote sources.
     */
    public static function register(string $scheme, callable $reader, int $flags = 0): bool
    {
        $scheme = strtolower($scheme);
        if (
            !preg_match('/^[a-z0-9.+-]{2,}$/D', $scheme)
            || in_array($scheme, array_map('strtolower', stream_get_wrappers()), true)
            || !stream_wrapper_register($scheme, self::class, $flags)
        ) {
            return false;
        }
        self::$readers[$scheme] = $reader;
        return true;
    }

    private static function read(string $path): ?string
    {
        $scheme = strstr($path, '://', true);
        $reader = $scheme === false ? null : (self::$readers[strtolower($scheme)] ?? null);
        if ($reader === null) {
            return null;
        }
        $body = $reader($path);
        return is_string($body) ? $body : null;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!in_array($mode, ['r', 'rb', 'rt'], true) || ($options & self::OPEN_FOR_INCLUDE)) {
            self::reportFailure((bool) ($options & STREAM_REPORT_ERRORS));
            return false;
        }
        $body = self::read($path);
        if ($body === null) {
            self::reportFailure((bool) ($options & STREAM_REPORT_ERRORS));
            return false;
        }
        $this->body = $body;
        $this->position = 0;
        if ($options & STREAM_USE_PATH) {
            $openedPath = $path;
        }
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->body, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->body);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->body) + $offset,
            default => -1,
        };
        if (!is_int($position) || $position < 0) {
            return false;
        }
        $this->position = $position;
        return true;
    }

    public function stream_stat(): array
    {
        return self::fileStat($this->body);
    }

    public function url_stat(string $path, int $flags): array|false
    {
        $body = self::read($path);
        if ($body === null) {
            self::reportFailure(!($flags & STREAM_URL_STAT_QUIET));
            return false;
        }
        return self::fileStat($body);
    }

    private static function reportFailure(bool $report): void
    {
        if ($report) {
            // PHP's stream contract requires flag-controlled warnings. Never include request data or credentials.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
            trigger_error('Stream operation failed.', E_USER_WARNING);
        }
    }

    private static function fileStat(string $body): array
    {
        $keys = [
            'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks',
        ];
        $values = [0, 0, 0100444, 1, 0, 0, 0, strlen($body), 0, 0, 0, -1, -1];
        return array_combine($keys, $values) + $values;
    }

    public function stream_close(): void
    {
        $this->body = '';
        $this->position = 0;
    }

    // PHP passes null for arg2 when changing blocking mode, despite the manual's int synopsis.
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        // No adjustable transport, buffering or blocking options for this in-memory, read-only handle.
        return false;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_truncate(int $size): bool
    {
        return false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return false;
    }

    // Unsupported unlink/rename/mkdir/rmdir callbacks are intentionally absent, as required by PHP's manual.
}
