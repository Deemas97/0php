<?php
namespace Bootstrap\Config;

use RuntimeException;
use InvalidArgumentException;

class DotEnv
{
    private static array $data = [];
    private static string $cacheFile = '';
    private const CACHE_TTL = 3600;
    private const FORBIDDEN_KEYS = [
        'disable_functions', 'safe_mode', 'open_basedir',
        'allow_url_fopen', 'allow_url_include'
    ];
    
    private const REQUIRED_KEYS = ['APP_ENV', 'HOST_NAME'];

    public static function init(string $rootDir): void
    {
        self::$cacheFile = $rootDir . '/var/cache/.env.cache.php';
        
        if (self::loadFromCache()) {
            return;
        }
        
        $data = self::parseEnvFiles($rootDir);
        self::validateData($data);
        $data = self::filterForbiddenKeys($data);
        
        self::$data = $data;
        self::saveToCache($data);
    }

    private static function parseEnvFiles(string $rootDir): array
    {
        $data = [];
        
        $envFiles = [$rootDir . '/.env', $rootDir . '/.env.local'];
        
        foreach ($envFiles as $filePath) {
            if (file_exists($filePath)) {
                $fileData = self::parseEnvFile($filePath);
                $data = array_merge($data, $fileData);
            }
        }
        
        return $data;
    }

    private static function parseEnvFile(string $filePath): array
    {
        $data = [];
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (strpos($line, '#') === 0 || strpos($line, ';') === 0) {
                continue;
            }
            
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                
                if (preg_match('/^"(.*)"$/s', $value, $matches)) {
                    $value = $matches[1];
                    $value = str_replace('\\n', "\n", $value);
                } elseif (preg_match('/^\'(.*)\'$/s', $value, $matches)) {
                    $value = $matches[1];
                }
                
                $value = str_replace(['\\"', "\\'"], ['"', "'"], $value);
                
                $data[$name] = $value;
            }
        }
        
        return $data;
    }

    private static function validateData(array $data): void
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!isset($data[$key]) || trim($data[$key]) === '') {
                throw new InvalidArgumentException(
                    "Required environment variable $key is missing or empty"
                );
            }
        }
        
        if (isset($data['APP_ENV'])) {
            $allowedEnvs = ['local', 'dev', 'development', 'test', 'staging', 'production'];
            if (!in_array($data['APP_ENV'], $allowedEnvs)) {
                throw new InvalidArgumentException(
                    "Invalid APP_ENV value: {$data['APP_ENV']}. Allowed: " . implode(', ', $allowedEnvs)
                );
            }
        }
        
        foreach ($data as $key => $value) {
            if (preg_match('/[;\r\n]/', $value)) {
                throw new InvalidArgumentException(
                    "Potential injection detected in $key"
                );
            }
        }
    }

    private static function filterForbiddenKeys(array $data): array
    {
        foreach (self::FORBIDDEN_KEYS as $forbiddenKey) {
            if (isset($data[$forbiddenKey])) {
                unset($data[$forbiddenKey]);
                error_log("Warning: Forbidden environment key '$forbiddenKey' was removed");
            }
        }
        return $data;
    }

    private static function loadFromCache(): bool
    {
        if (!file_exists(self::$cacheFile)) {
            return false;
        }
        
        if (time() - filemtime(self::$cacheFile) > self::CACHE_TTL) {
            return false;
        }
        
        $cachedData = include self::$cacheFile;
        
        if (!is_array($cachedData)) {
            return false;
        }
        
        foreach (self::REQUIRED_KEYS as $key) {
            if (!isset($cachedData[$key])) {
                return false;
            }
        }
        
        self::$data = $cachedData;
        return true;
    }

    private static function saveToCache(array $data): void
    {
        $cacheDir = dirname(self::$cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $cacheContent = "<?php\nreturn " . var_export($data, true) . ";\n";
        
        $tmpFile = self::$cacheFile . '.tmp';
        if (file_put_contents($tmpFile, $cacheContent, LOCK_EX) !== false) {
            rename($tmpFile, self::$cacheFile);
        }
    }

    public static function clearCache(): bool
    {
        if (file_exists(self::$cacheFile)) {
            return unlink(self::$cacheFile);
        }
        return true;
    }

    public static function getData(): array
    {
        if (empty(self::$data)) {
            throw new RuntimeException('DotEnv not initialized. Call init() first.');
        }
        return self::$data;
    }

    public static function getDataItem(string $key, string $default = ''): string
    {
        return self::$data[$key] ?? $default;
    }

    public static function update(string $key, string $value): void
    {
        if (in_array($key, self::FORBIDDEN_KEYS)) {
            throw new InvalidArgumentException("Cannot update forbidden key: $key");
        }
        
        self::$data[$key] = $value;
        self::saveToCache(self::$data);
        self::updateEnvFile($key, $value);
    }

    private static function updateEnvFile(string $key, string $value): void
    {
        $envFile = YADRO_PHP__ROOT_DIR . '/.env';
        if (!file_exists($envFile)) {
            return;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $updated = false;
        
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if (strpos($line, '#') === 0 || strpos($line, ';') === 0) {
                continue;
            }
            
            if (strpos($line, '=') !== false) {
                list($lineKey) = explode('=', $line, 2);
                $lineKey = trim($lineKey);
                if ($lineKey === $key) {
                    $lines[$i] = $key . '=' . $value;
                    $updated = true;
                    break;
                }
            }
        }
        
        if (!$updated) {
            $lines[] = $key . '=' . $value;
        }
        
        file_put_contents($envFile, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    }
}