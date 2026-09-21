<?php

namespace RemoteMediaProxy\Media;

defined('ABSPATH') || exit;

/** Own an independent read-only handle without owning the underlying file. */
final class MediaFile
{
    /**
     * Track handles without retaining readers, so dropping a reader still closes its handle.
     *
     * @var array<int, resource>
     */
    private static array $streams = [];

    /**
     * Wrap an already validated native reader.
     *
     * @param resource $stream Owned handle, closed when this reader is released.
     * @param string   $type   Allowed media MIME type.
     * @param integer  $size   Validated byte length of the opened file.
     */
    private function __construct(
        /** @var resource Owned native reader handle. */
        public readonly mixed $stream,
        /** @var string Allowed media MIME type. */
        public readonly string $type,
        /** @var integer Validated file length in bytes. */
        public readonly int $size
    ) {
    }

    /**
     * Open an independent reader for an existing local file.
     *
     * @param string $path Physical file path.
     * @param string $type Allowed media MIME type supplied by the backend.
     * @return self|null Reader owned by the caller, or null when opening or validation fails.
     */
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

    /**
     * Release this reader's handle, never the underlying local or shared temporary file.
     *
     * @return void
     */
    public function close(): void
    {
        unset(self::$streams[(int) $this->stream]);
        if (is_resource($this->stream)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native handle owned by this reader.
            fclose($this->stream);
        }
    }

    /**
     * Close all tracked handles before request-owned temporary files are deleted.
     *
     * @return void
     */
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

    /**
     * Prevent two reader objects from sharing ownership of the same native handle.
     *
     * @return void
     */
    private function __clone()
    {
    }

    /** Release the handle when its reader is no longer referenced. */
    public function __destruct()
    {
        $this->close();
    }
}
