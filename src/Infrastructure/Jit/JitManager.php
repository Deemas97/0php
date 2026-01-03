<?php
namespace Infrastructure\Jit;

use Bootstrap\Config\DotEnv;
use RuntimeException;

class JitManager
{
    private const JIT_CONFIGS = [
        'disabled' => [
            'opcache.jit' => 'disable'
        ],
        'tracing' => [
            'opcache.jit' => 'tracing',
            'opcache.jit_debug' => '0'
        ],
        'function' => [
            'opcache.jit' => 'function',
            'opcache.jit_debug' => '0'
        ],
        'experimental' => [
            'opcache.jit' => 'tracing',
            'opcache.jit_debug' => '1',
            'opcache.jit_hot_func' => '7',
            'opcache.jit_hot_loop' => '16',
            'opcache.jit_hot_return' => '5',
            'opcache.jit_hot_side_exit' => '7'
        ]
    ];

    public static function initFromEnv(): string
    {
        if (!self::isSupported()) {
            return 'unsupported';
        }
        
        $enabled = DotEnv::getDataItem('JIT_ENABLED', '1') === '1';
        
        if (!$enabled) {
            self::disable();
            return 'disabled';
        }
        
        $autoOptimize = DotEnv::getDataItem('JIT_AUTO_OPTIMIZE', '1') === '1';
        
        if ($autoOptimize) {
            return self::autoConfigure();
        }
        
        $profile = DotEnv::getDataItem('JIT_PROFILE', 'tracing');
        $bufferSize = DotEnv::getDataItem('JIT_BUFFER_SIZE', '256M');
        
        if ($bufferSize) {
            ini_set('opcache.jit_buffer_size', $bufferSize);
        }
        
        $optimizationLevel = DotEnv::getDataItem('JIT_OPTIMIZATION_LEVEL', '');
        if ($optimizationLevel !== '') {
            ini_set('opcache.jit_opt_level', $optimizationLevel);
        }
        
        $hotFunc = DotEnv::getDataItem('JIT_HOT_FUNC', '');
        if ($hotFunc !== '') {
            ini_set('opcache.jit_hot_func', $hotFunc);
        }
        
        $hotLoop = DotEnv::getDataItem('JIT_HOT_LOOP', '');
        if ($hotLoop !== '') {
            ini_set('opcache.jit_hot_loop', $hotLoop);
        }
        
        return self::enable($profile);
    }
    
    public static function getEnvConfig(): array
    {
        return [
            'enabled' => DotEnv::getDataItem('JIT_ENABLED', '1') === '1',
            'profile' => DotEnv::getDataItem('JIT_PROFILE', 'tracing'),
            'buffer_size' => DotEnv::getDataItem('JIT_BUFFER_SIZE', '256M'),
            'auto_optimize' => DotEnv::getDataItem('JIT_AUTO_OPTIMIZE', '1') === '1',
            'optimization_level' => DotEnv::getDataItem('JIT_OPTIMIZATION_LEVEL', ''),
            'hot_func' => DotEnv::getDataItem('JIT_HOT_FUNC', ''),
            'hot_loop' => DotEnv::getDataItem('JIT_HOT_LOOP', ''),
            'logging' => DotEnv::getDataItem('JIT_LOGGING', '1') === '1',
            'cron_enabled' => DotEnv::getDataItem('JIT_CRON_ENABLED', '1') === '1',
        ];
    }
    
    public static function logStats(): void
    {
        $logging = DotEnv::getDataItem('JIT_LOGGING', '1') === '1';
        
        if (!$logging) {
            return;
        }
        
        $logDir = YADRO_PHP__ROOT_DIR . '/var/log/jit/';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . 'jit_stats_' . date('Y-m-d') . '.json';
        
        $stats = self::getStats();
        $config = self::getConfig();
        $envConfig = self::getEnvConfig();
        
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'config' => $config,
            'stats' => $stats,
            'env_config' => $envConfig,
            'memory_usage' => [
                'current' => memory_get_usage(),
                'peak' => memory_get_peak_usage(),
                'limit' => ini_get('memory_limit')
            ]
        ];
        
        $existing = [];
        if (file_exists($logFile)) {
            $existing = json_decode(file_get_contents($logFile), true) ?: [];
        }
        
        $existing[] = $logData;
        
        if (count($existing) > 1000) {
            $existing = array_slice($existing, -1000);
        }
        
        file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));
    }

    public static function maintenance(): array
    {
        $actions = [];
        $config = self::getConfig();
        $stats = self::getStats();
        
        if (isset($stats['buffer_usage_percent']) && $stats['buffer_usage_percent'] > 95) {
            self::reset();
            $actions[] = 'Buffer reset (usage: ' . $stats['buffer_usage_percent'] . '%)';
        }
        
        if (isset($stats['compiled_functions']) && $stats['compiled_functions'] < 10 && $config['enabled']) {
            $actions[] = 'Low JIT compilation count: ' . $stats['compiled_functions'];
        }
        
        self::logStats();
        
        return [
            'actions' => $actions,
            'config' => $config,
            'stats' => $stats,
            'timestamp' => time()
        ];
    }

    public static function enable(string $profile = 'tracing'): bool
    {
        if (!self::isSupported()) {
            throw new RuntimeException('JIT is not supported in this PHP version');
        }

        if (!isset(self::JIT_CONFIGS[$profile])) {
            throw new RuntimeException("Unknown JIT profile: $profile. Available: " . 
                implode(', ', array_keys(self::JIT_CONFIGS)));
        }

        $config = self::JIT_CONFIGS[$profile];
        
        foreach ($config as $key => $value) {
            if (ini_set($key, $value) === false) {
                error_log("Failed to set JIT setting: $key = $value");
            }
        }

        return self::isEnabled();
    }

    public static function disable(): bool
    {
        ini_set('opcache.jit_buffer_size', '0');
        ini_set('opcache.jit', 'disable');
        
        return !self::isEnabled();
    }

    public static function isSupported(): bool
    {
        if (PHP_VERSION_ID < 80000) {
            return false;
        }

        if (ini_get('opcache.enable') !== '1') {
            return false;
        }

        $status = @opcache_get_status(false);
        if (!$status) {
            return false;
        }

        if (!($status['opcache_enabled'] ?? false)) {
            return false;
        }

        return isset($status['jit']);
    }

    public static function isEnabled(): bool
    {
        if (!self::isSupported()) {
            return false;
        }

        $bufferSize = ini_get('opcache.jit_buffer_size');
        if (empty($bufferSize) || $bufferSize === '0') {
            return false;
        }

        $jitMode = ini_get('opcache.jit');
        if (empty($jitMode) || $jitMode === 'disable' || $jitMode === '0') {
            return false;
        }

        return true;
    }

    public static function getConfig(): array
    {
        return [
            'enabled' => self::isEnabled(),
            'supported' => self::isSupported(),
            'buffer_size' => ini_get('opcache.jit_buffer_size'),
            'jit_mode' => ini_get('opcache.jit'),
            'debug_level' => (int)ini_get('opcache.jit_debug'),
            'hot_func' => (int)ini_get('opcache.jit_hot_func'),
            'hot_loop' => (int)ini_get('opcache.jit_hot_loop'),
            'max_polymorphic_calls' => (int)ini_get('opcache.jit_max_polymorphic_calls'),
            'php_version' => PHP_VERSION,
        ];
    }

    public static function autoConfigure(): string
    {
        if (!self::isSupported()) {
            return 'unsupported';
        }

        $memoryLimit = self::getMemoryLimit();
        $isCli = PHP_SAPI === 'cli';
        $isProduction = DotEnv::getDataItem('APP_ENV', 'development') === 'production';

        if ($isProduction) {
            $profile = 'tracing';
            
            if ($memoryLimit >= 4096) {
                ini_set('opcache.jit_buffer_size', '512M');
            } elseif ($memoryLimit >= 2048) {
                ini_set('opcache.jit_buffer_size', '256M');
            } else {
                ini_set('opcache.jit_buffer_size', '128M');
            }
        } elseif ($isCli) {
            $profile = 'experimental';
        } else {
            $profile = 'function';
        }

        self::enable($profile);
        return $profile;
    }

    public static function getStats(): array
    {
        if (!self::isEnabled()) {
            return ['error' => 'JIT is not enabled'];
        }

        $status = opcache_get_status(false);
        
        if (!isset($status['jit'])) {
            return ['error' => 'JIT stats not available'];
        }

        $jit = $status['jit'];
        
        $totalArea = $jit['buffer_size'] ?? 0;
        $usedArea = $jit['buffer_free'] ?? 0;
        $usedBytes = $totalArea - $usedArea;
        
        return [
            'buffer_size_mb' => round($totalArea / 1024 / 1024, 2),
            'buffer_used_mb' => round($usedBytes / 1024 / 1024, 2),
            'buffer_usage_percent' => $totalArea > 0 ? round(($usedBytes / $totalArea) * 100, 2) : 0,
            'mode' => ini_get('opcache.jit'),
            'compiled_functions' => $jit['num_compiled_functions'] ?? 0,
            'compiled_traces' => $jit['num_compiled_traces'] ?? 0,
            'compiled_side_traces' => $jit['num_compiled_side_traces'] ?? 0,
            'optimization_level' => $jit['optimization_level'] ?? 0,
            'ready_for_jit' => $jit['ready_for_jit'] ?? 0,
            'performance_impact' => self::calculatePerformanceImpact($jit),
        ];
    }

    public static function reset(): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $currentMode = ini_get('opcache.jit');
        self::disable();
        
        usleep(100000);
        
        return self::enable($currentMode);
    }

    public static function benchmark(callable $testFunction, int $iterations = 10000): array
    {
        $results = [];
        
        self::disable();
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $testFunction();
        }
        $results['without_jit'] = microtime(true) - $start;
        
        usleep(500000);
        
        self::enable('tracing');
        
        for ($i = 0; $i < 1000; $i++) {
            $testFunction();
        }
        
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $testFunction();
        }
        $results['with_jit_tracing'] = microtime(true) - $start;
        
        self::enable('function');
        
        for ($i = 0; $i < 1000; $i++) {
            $testFunction();
        }
        
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $testFunction();
        }
        $results['with_jit_function'] = microtime(true) - $start;
        
        self::autoConfigure();
        
        $results['tracing_speedup'] = $results['without_jit'] > 0 ? 
            round(($results['without_jit'] - $results['with_jit_tracing']) / $results['without_jit'] * 100, 1) : 0;
            
        $results['function_speedup'] = $results['without_jit'] > 0 ? 
            round(($results['without_jit'] - $results['with_jit_function']) / $results['without_jit'] * 100, 1) : 0;
        
        return $results;
    }

    public static function getRecommendations(): array
    {
        $recommendations = [];
        
        if (!self::isSupported()) {
            $recommendations[] = 'JIT requires PHP 8.0+ with OpCache enabled';
            return $recommendations;
        }
        
        $config = self::getConfig();
        $stats = self::getStats();
        
        if (!$config['enabled']) {
            $recommendations[] = 'Enable JIT for performance improvement (10-50% speedup)';
        } elseif (isset($stats['buffer_usage_percent']) && $stats['buffer_usage_percent'] > 90) {
            $recommendations[] = sprintf(
                'JIT buffer usage is high (%.1f%%). Increase opcache.jit_buffer_size',
                $stats['buffer_usage_percent']
            );
        }
        
        if ($config['enabled'] && $config['jit_mode'] === 'function' && 
            DotEnv::getDataItem('APP_ENV') === 'production') {
            $recommendations[] = 'Consider switching to "tracing" mode for better production performance';
        }
        
        return $recommendations;
    }

    private static function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        
        if ($limit === '-1') {
            return 1024 * 1024;
        }
        
        $unit = strtoupper(substr($limit, -1));
        $value = (int)substr($limit, 0, -1);
        
        switch ($unit) {
            case 'G': return $value * 1024;
            case 'M': return $value;
            case 'K': return $value / 1024;
            default: return (int)$limit / 1024 / 1024;
        }
    }

    private static function calculatePerformanceImpact(array $jitStats): string
    {
        $compiled = ($jitStats['num_compiled_functions'] ?? 0) + 
                    ($jitStats['num_compiled_traces'] ?? 0);
        
        if ($compiled === 0) {
            return 'none';
        } elseif ($compiled < 100) {
            return 'low';
        } elseif ($compiled < 1000) {
            return 'moderate';
        } else {
            return 'high';
        }
    }
}