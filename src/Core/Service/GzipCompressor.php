<?php
namespace Core\Service;

use InvalidArgumentException;
use RuntimeException;

class GzipCompressor implements CoreServiceInterface
{
    private static ?int $compressionLevel = null;
    private static bool $enabled = false;

    public static function init(?int $compressionLevel = null): void
    {
        if (!extension_loaded('zlib')) {
            throw new RuntimeException('Zlib extension is not loaded. Gzip compression cannot be used.');
        }

        self::$enabled = true;
        
        if ($compressionLevel !== null) {
            self::setCompressionLevel($compressionLevel);
        }
    }

    public static function setCompressionLevel(int $level): void
    {
        if ($level < -1 || $level > 9) {
            throw new InvalidArgumentException('Compression level must be between -1 and 9');
        }

        self::$compressionLevel = $level;
        
        if (self::$enabled) {
            ini_set('zlib.output_compression_level', $level);
        }
    }

    public static function enableOutputCompression(): void
    {
        if (!self::$enabled) {
            self::init();
        }
        
        if (ob_get_level() > 0 && ob_get_length() > 0) {
            throw new RuntimeException('Cannot enable output compression after output has been sent');
        }
        
        ini_set('zlib.output_compression', 'On');
    }

    public static function disableOutputCompression(): void
    {
        ini_set('zlib.output_compression', 'Off');
        self::$enabled = false;
    }

    public static function compress(string $data, ?int $level = null): string|false
    {
        if (!self::$enabled) {
            return $data;
        }

        $compressionLevel = $level ?? self::$compressionLevel ?? -1;
        return gzencode($data, $compressionLevel, FORCE_GZIP);
    }

    public static function decompress(string $compressedData): string|false
    {
        return gzdecode($compressedData);
    }

    public static function isCompressed(string $data): bool
    {
        if (strlen($data) < 2) {
            return false;
        }

        return (ord($data[0]) === 0x1f && ord($data[1]) === 0x8b);
    }

    public static function compressIfNeeded(string $data, string $contentType = ''): string
    {
        $compressibleTypes = [
            'text/html',
            'text/plain',
            'text/css',
            'text/javascript',
            'application/javascript',
            'application/json',
            'application/xml',
            'application/xhtml+xml',
            'image/svg+xml',
        ];

        $shouldCompress = false;
        foreach ($compressibleTypes as $type) {
            if (strpos($contentType, $type) !== false) {
                $shouldCompress = true;
                break;
            }
        }

        if (strlen($data) < 1024) {
            $shouldCompress = false;
        }

        if ($shouldCompress && self::$enabled) {
            $compressed = self::compress($data);
            if ($compressed !== false && strlen($compressed) < strlen($data)) {
                return $compressed;
            }
        }

        return $data;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function getCompressionLevel(): ?int
    {
        return self::$compressionLevel;
    }
}