<?php
namespace Infrastructure\Service;

use Bootstrap\Config\DotEnv;
use Bootstrap\Config\ProjectMode;
use Core\Service\InfrastructureServiceInterface;
use Dev\ApiDocGenerator;
use Dev\AssetsWatcher;
use Dev\DBLogger;
use Dev\ResourcesMonitor;
use Dev\Dumper;
use Dev\HttpInspector;
use Dev\PerformanceProfiler;
use Exception;
use Infrastructure\Jit\JitManager;

class DevModeManager implements InfrastructureServiceInterface
{
    private static bool $initialized = false;
    private static array $allowedIps = [];
    private static bool $enabled = false;
    
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        
        $mode = ProjectMode::getCurrentMode();
        self::$enabled = in_array($mode, ['dev', 'development', 'test']);
        
        $allowedIps = DotEnv::getDataItem('DEV_ALLOWED_IPS', '');
        self::$allowedIps = $allowedIps ? explode(',', $allowedIps) : ['127.0.0.1', '::1'];
        
        if (self::$enabled && self::isAccessAllowed()) {
            if (DotEnv::getDataItem('DEV_SQL_LOGGING', '0') === '1') {
                DBLogger::init();
            }
            
            if (DotEnv::getDataItem('DEV_PERFORMANCE_PROFILING', '0') === '1') {
                PerformanceProfiler::init();
            }

            if (DotEnv::getDataItem('DEV_ASSET_WATCHER', '0') === '1') {
                AssetsWatcher::init();
            }

            if (DotEnv::getDataItem('DEV_API_DOCS', '0') === '1') {
                ApiDocGenerator::init();
            }
            
            if (DotEnv::getDataItem('DEV_HTTP_LOGGING', '0') === '1') {
                HttpInspector::init();
                HttpInspector::captureRequest();
            }

            if (DotEnv::getDataItem('DEV_METRICS_AUTOSAVE', '0') === '1') {
                ResourcesMonitor::init();
                ResourcesMonitor::enableAutoSave(YADRO_PHP__ROOT_DIR . '/var/log/dev/metrics_' . date('Y-m-d') . '.json');
            }
        }
        
        self::$initialized = true;
    }
    
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
    
    public static function isAccessAllowed(): bool
    {
        if (!self::$enabled) {
            return false;
        }
        
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        
        if (in_array($clientIp, self::$allowedIps)) {
            return true;
        }
        
        foreach (self::$allowedIps as $ipRange) {
            if (self::ipInRange($clientIp, $ipRange)) {
                return true;
            }
        }
        
        return false;
    }
    
    private static function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') !== false) {
            list($subnet, $mask) = explode('/', $range);
            $subnet = ip2long($subnet);
            $ip = ip2long($ip);
            $mask = -1 << (32 - (int)$mask);
            return ($ip & $mask) === ($subnet & $mask);
        }
        
        return $ip === $range;
    }
    
    public static function dump(mixed $var, bool $withTrace = false): void
    {
        if (!self::isAccessAllowed()) {
            return;
        }
        
        Dumper::dump($var, $withTrace);
    }
    
    public static function trace(int $limit = 5): void
    {
        if (!self::isAccessAllowed()) {
            return;
        }
        
        Dumper::trace($limit);
    }
    
    public static function log(mixed $var, string $file = 'debug.log'): void
    {
        if (!self::$enabled) {
            return;
        }
        
        Dumper::log($var, YADRO_PHP__ROOT_DIR . '/var/log/dev' . $file);
    }
    
    public static function getMetrics(): ?array
    {
        if (!self::isAccessAllowed()) {
            return null;
        }
        
        return ResourcesMonitor::getMetrics();
    }
    
    public static function measure(string $name, callable $operation): mixed
    {
        if (!self::isAccessAllowed()) {
            return $operation();
        }
        
        ResourcesMonitor::startTimer($name);
        $result = $operation();
        $duration = ResourcesMonitor::stopTimer($name);
        
        self::log([
            'measurement' => $name,
            'duration' => $duration,
            'memory' => memory_get_usage()
        ], 'performance.log');
        
        return $result;
    }

    public static function collectToolbarData(): array
    {
        $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);

        $opcacheData = self::getOpCacheData();
        $jitData = self::getJitData();
        $gzipData = self::getGzipData();
        
        $recommendations = array_merge(
            PerformanceProfiler::getRecommendations(),
            $opcacheData['recommendations'] ?? [],
            $jitData['recommendations'] ?? [],
            $gzipData['recommendations'] ?? []
        );
        
        return [
            'performance' => [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(),
                'memory_peak' => memory_get_peak_usage(),
                'memory_limit' => self::parseMemoryLimit(ini_get('memory_limit')),
                'query_count' => DBLogger::getStats()['total_queries'] ?? 0,
                'query_time' => DBLogger::getStats()['total_time_ms'] ?? 0,
                'included_files' => count(get_included_files()),
                'opcache_enabled' => $opcacheData['enabled'] ?? false,
                'opcache_cached_scripts' => $opcacheData['cached_scripts'] ?? 0,
                'opcache_hit_rate' => $opcacheData['hit_rate'] ?? 0,
                'jit_enabled' => $jitData['enabled'] ?? false,
                'jit_mode' => $jitData['mode'] ?? 'disabled',
                'jit_buffer_usage' => $jitData['buffer_usage_percent'] ?? 0,
                'gzip_enabled' => $gzipData['enabled'] ?? false,
                'gzip_compression_level' => $gzipData['compression_level'] ?? 0,
                'gzip_compression_ratio' => $gzipData['compression_ratio'] ?? 0,
            ],
            'opcache' => $opcacheData,
            'jit' => $jitData,
            'gzip' => $gzipData,
            'queries' => DBLogger::getStats()['queries'] ?? [],
            'request' => [
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'url' => ($_SERVER['REQUEST_URI'] ?? '') . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'get_params' => $_GET,
                'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? 'none',
            ],
            'environment' => [
                'mode' => ProjectMode::getCurrentMode(),
                'php_version' => PHP_VERSION,
                'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'os' => PHP_OS,
                'timezone' => date_default_timezone_get(),
                'app_name' => DotEnv::getDataItem('APP_NAME', 'Zero-PHP'),
                'app_env' => DotEnv::getDataItem('APP_ENV', 'production'),
            ],
            'recommendations' => $recommendations,
        ];
    }

    private static function getGzipData(): array
    {
        $data = [
            'enabled' => false,
            'compression_level' => 0,
            'compression_ratio' => 0,
            'recommendations' => [],
            'statistics' => [
                'requests_compressed' => 0,
                'bytes_saved' => 0,
                'average_ratio' => 0,
            ]
        ];
        
        if (!extension_loaded('zlib')) {
            $data['recommendations'][] = 'Zlib extension is not loaded. Gzip compression cannot be used.';
            return $data;
        }
        
        $outputCompression = ini_get('zlib.output_compression');
        $data['enabled'] = $outputCompression === '1' || $outputCompression === 'On';
        
        if ($data['enabled']) {
            $compressionLevel = ini_get('zlib.output_compression_level');
            $data['compression_level'] = $compressionLevel ? (int)$compressionLevel : 6;
            
            $data['compression_ratio'] = self::calculateEstimatedCompressionRatio();
            
            if ($data['compression_level'] < 6) {
                $data['recommendations'][] = sprintf(
                    'Gzip compression level is low (%d). Consider increasing to 6 for better compression.',
                    $data['compression_level']
                );
            }
            
            $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
            if (strpos($acceptEncoding, 'gzip') === false && strpos($acceptEncoding, 'deflate') === false) {
                $data['recommendations'][] = 'Client does not support gzip/deflate compression. Consider adding Brotli support.';
            }
        } else {
            $data['recommendations'][] = 'Gzip output compression is disabled. Enable for bandwidth savings (typically 60-80% reduction).';
        }
        
        if (self::isAccessAllowed() && DotEnv::getDataItem('DEV_GZIP_STATS', '0') === '1') {
            $data['statistics'] = self::collectGzipStatistics();
        }
        
        return $data;
    }

    private static function collectGzipStatistics(): array
    {
        $logFile = YADRO_PHP__ROOT_DIR . '/var/log/dev/gzip_stats.json';
        $stats = [
            'requests_compressed' => 0,
            'bytes_saved' => 0,
            'average_ratio' => 0,
            'last_requests' => []
        ];
        
        if (file_exists($logFile)) {
            try {
                $data = json_decode(file_get_contents($logFile), true);
                if (is_array($data)) {
                    $stats = array_merge($stats, $data);
                    
                    if (isset($stats['last_requests']) && is_array($stats['last_requests'])) {
                        $stats['last_requests'] = array_slice($stats['last_requests'], -10);
                    }
                }
            } catch (Exception $e) {}
        }
        
        return $stats;
    }
    
    private static function calculateEstimatedCompressionRatio(): float
    {
        $compressionLevel = ini_get('zlib.output_compression_level') ?: 6;
        
        $ratios = [
            1 => 60,
            2 => 65,
            3 => 70,
            4 => 73,
            5 => 75,
            6 => 77,
            7 => 79,
            8 => 80,
            9 => 81
        ];
        
        return $ratios[$compressionLevel] ?? 75;
    }
    
    public static function logGzipStats(string $url, int $originalSize, int $compressedSize, string $contentType): void
    {
        if (!self::isAccessAllowed()) {
            return;
        }
        
        $logFile = YADRO_PHP__ROOT_DIR . '/var/log/dev/gzip_stats.json';
        $stats = self::collectGzipStatistics();
        
        $ratio = $originalSize > 0 ? (1 - ($compressedSize / $originalSize)) * 100 : 0;
        
        $stats['last_requests'][] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'url' => $url,
            'content_type' => $contentType,
            'original_size' => $originalSize,
            'compressed_size' => $compressedSize,
            'saved_bytes' => $originalSize - $compressedSize,
            'compression_ratio' => round($ratio, 1),
            'level' => ini_get('zlib.output_compression_level') ?: 6
        ];
        
        $stats['requests_compressed']++;
        $stats['bytes_saved'] += max(0, $originalSize - $compressedSize);
        
        if (count($stats['last_requests']) > 0) {
            $totalRatio = 0;
            $count = 0;
            foreach ($stats['last_requests'] as $request) {
                if (isset($request['compression_ratio'])) {
                    $totalRatio += $request['compression_ratio'];
                    $count++;
                }
            }
            $stats['average_ratio'] = $count > 0 ? round($totalRatio / $count, 1) : 0;
        }
        
        try {
            file_put_contents($logFile, json_encode($stats, JSON_PRETTY_PRINT));
        } catch (Exception $e) {}
    }

    private static function getOpCacheData(): array
    {
        $data = [
            'enabled' => false,
            'recommendations' => []
        ];
        
        if (!function_exists('opcache_get_status')) {
            $data['recommendations'][] = 'OpCache extension is not loaded';
            return $data;
        }
        
        $status = @opcache_get_status(false);
        if (!$status) {
            $data['recommendations'][] = 'OpCache is disabled';
            return $data;
        }
        
        $data['enabled'] = $status['opcache_enabled'] ?? false;
        
        if ($data['enabled']) {
            $stats = $status['opcache_statistics'] ?? [];
            $memory = $status['memory_usage'] ?? [];
            
            $hits = $stats['hits'] ?? 0;
            $misses = $stats['misses'] ?? 0;
            $total = $hits + $misses;
            $hitRate = $total > 0 ? ($hits / $total) * 100 : 0;
            
            $data['cached_scripts'] = $stats['num_cached_scripts'] ?? 0;
            $data['hit_rate'] = round($hitRate, 2);
            $data['hits'] = $hits;
            $data['misses'] = $misses;
            $data['memory_used_mb'] = isset($memory['used_memory']) ? 
                round($memory['used_memory'] / 1024 / 1024, 2) : 0;
            $data['memory_free_mb'] = isset($memory['free_memory']) ? 
                round($memory['free_memory'] / 1024 / 1024, 2) : 0;
            $data['memory_total_mb'] = $data['memory_used_mb'] + $data['memory_free_mb'];
            
            if ($data['hit_rate'] < 80) {
                $data['recommendations'][] = sprintf(
                    'OpCache hit rate is low (%.1f%%). Consider increasing opcache.revalidate_freq',
                    $data['hit_rate']
                );
            }
            
            if ($data['memory_used_mb'] > 0 && $data['memory_total_mb'] > 0) {
                $memoryUsagePercent = ($data['memory_used_mb'] / $data['memory_total_mb']) * 100;
                if ($memoryUsagePercent > 90) {
                    $data['recommendations'][] = sprintf(
                        'OpCache memory usage is high (%.1f%%). Consider increasing opcache.memory_consumption',
                        $memoryUsagePercent
                    );
                }
            }
            
            $maxFiles = ini_get('opcache.max_accelerated_files');
            if ($maxFiles && $data['cached_scripts'] > ($maxFiles * 0.9)) {
                $data['recommendations'][] = sprintf(
                    'OpCache file limit almost reached (%d/%d). Consider increasing opcache.max_accelerated_files',
                    $data['cached_scripts'],
                    $maxFiles
                );
            }
        }
        
        return $data;
    }

    private static function getJitData(): array
    {
        $data = [
            'enabled' => false,
            'recommendations' => []
        ];
        
        try {
            if (!JitManager::isSupported()) {
                $data['recommendations'][] = 'JIT requires PHP 8.0+ with OpCache enabled';
                return $data;
            }
            
            $data['enabled'] = JitManager::isEnabled();
            $data['config'] = JitManager::getConfig();
            
            if ($data['enabled']) {
                $stats = JitManager::getStats();
                
                if (isset($stats['error'])) {
                    $data['recommendations'][] = 'JIT stats error: ' . $stats['error'];
                } else {
                    $data = array_merge($data, $stats);
                    $data['mode'] = $data['config']['jit_mode'] ?? 'unknown';
                    
                    if (isset($stats['buffer_usage_percent']) && $stats['buffer_usage_percent'] > 90) {
                        $data['recommendations'][] = sprintf(
                            'JIT buffer usage is high (%.1f%%). Consider increasing opcache.jit_buffer_size',
                            $stats['buffer_usage_percent']
                        );
                    }
                    
                    if (($stats['compiled_functions'] ?? 0) < 10) {
                        $data['recommendations'][] = 'JIT has low compilation count. Ensure hot code paths are being executed';
                    }
                }
            } else {
                $data['recommendations'][] = 'JIT is disabled. Enable for performance improvement (10-50% speedup)';
            }
        } catch (Exception $e) {
            $data['recommendations'][] = 'JIT error: ' . $e->getMessage();
        }
        
        return $data;
    }

    private static function parseMemoryLimit(string $limit): int
    {
        $unit = strtoupper(substr($limit, -1));
        $value = (int)substr($limit, 0, -1);
        
        switch ($unit) {
            case 'G': return $value * 1024 * 1024 * 1024;
            case 'M': return $value * 1024 * 1024;
            case 'K': return $value * 1024;
            default: return (int)$limit;
        }
    }
}