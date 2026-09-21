<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

final class MediaFile
{
    private static array $streams = [];

    private function __construct(
        public readonly mixed $stream,
        public readonly string $type,
        public readonly int $size
    ) {
    }

    public static function open(string $path, string $type): ?self
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles support bounded reads and seeking.
        $stream = is_file($path) ? @fopen($path, 'rb') : false;
        $stat = is_resource($stream) ? fstat($stream) : false;
        if (!is_array($stat) || !is_int($stat['size']) || $stat['size'] < 0) {
            if (is_resource($stream)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native handle owned by this reader.
                fclose($stream);
            }
            return null;
        }
        self::$streams[(int) $stream] = $stream;
        return new self($stream, $type, $stat['size']);
    }

    public function close(): void
    {
        unset(self::$streams[(int) $this->stream]);
        if (is_resource($this->stream)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native handle owned by this reader.
            fclose($this->stream);
        }
    }

    public static function closeAll(): void
    {
        foreach (self::$streams as $stream) {
            if (is_resource($stream)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release native handles before deleting temporary files.
                fclose($stream);
            }
        }
        self::$streams = [];
    }

    private function __clone()
    {
    }

    public function __destruct()
    {
        $this->close();
    }
}
