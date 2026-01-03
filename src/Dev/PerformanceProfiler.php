<?php
namespace Dev;

use Bootstrap\Config\DotEnv;

class PerformanceProfiler
{
    private static array $sections = [];
    private static array $marks = [];
    private static bool $enabled = false;
    private static string $profileDir;
    
    public static function init(): void
    {
        self::$enabled = DotEnv::getDataItem('DEV_PERFORMANCE_PROFILING', '0') === '1';
        self::$profileDir = YADRO_PHP__ROOT_DIR . '/var/log/dev/profiles/';
        
        if (self::$enabled && !is_dir(self::$profileDir)) {
            mkdir(self::$profileDir, 0755, true);
        }
        
        if (self::$enabled) {
            self::startSection('total');
            register_shutdown_function([self::class, 'saveProfile']);
        }
    }
    
    public static function startSection(string $name): void
    {
        if (!self::$enabled) {
            return;
        }
        
        self::$sections[$name] = [
            'start' => microtime(true),
            'start_memory' => memory_get_usage(),
            'children' => [],
            'marks' => [],
        ];
    }
    
    public static function endSection(string $name): array
    {
        if (!self::$enabled || !isset(self::$sections[$name])) {
            return [];
        }
        
        $section = &self::$sections[$name];
        $section['end'] = microtime(true);
        $section['end_memory'] = memory_get_usage();
        $section['duration'] = $section['end'] - $section['start'];
        $section['memory_diff'] = $section['end_memory'] - $section['start_memory'];
        $section['peak_memory'] = memory_get_peak_usage();
        
        return $section;
    }
    
    public static function mark(string $label): void
    {
        if (!self::$enabled) {
            return;
        }
        
        self::$marks[] = [
            'label' => $label,
            'time' => microtime(true),
            'memory' => memory_get_usage(),
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ];
    }
    
    public static function measure(string $name, callable $callback): mixed
    {
        if (!self::$enabled) {
            return $callback();
        }
        
        self::startSection($name);
        $result = $callback();
        $section = self::endSection($name);
        
        if ($section['duration'] > 0.1) {
            self::logSlowOperation($name, $section);
        }
        
        return $result;
    }
    
    private static function logSlowOperation(string $name, array $section): void
    {
        $logEntry = sprintf(
            "[%s] SLOW: %s took %.2f ms, memory: %s\n",
            date('Y-m-d H:i:s'),
            $name,
            $section['duration'] * 1000,
            self::formatBytes($section['memory_diff'])
        );
        
        file_put_contents(
            self::$profileDir . 'slow_operations.log',
            $logEntry,
            FILE_APPEND
        );
    }
    
    public static function saveProfile(): void
    {
        if (!self::$enabled) {
            return;
        }
        
        self::endSection('total');
        
        $profile = [
            'timestamp' => date('Y-m-d H:i:s'),
            'request_id' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
            'url' => $_SERVER['REQUEST_URI'] ?? 'cli',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'sections' => self::$sections,
            'marks' => self::$marks,
            'totals' => [
                'duration' => self::$sections['total']['duration'] ?? 0,
                'memory_peak' => memory_get_peak_usage(),
                'included_files' => count(get_included_files()),
                'database_queries' => DBLogger::getStats()['total_queries'] ?? 0,
            ],
        ];
        
        $filename = self::$profileDir . 'profile_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json';
        file_put_contents($filename, json_encode($profile, JSON_PRETTY_PRINT));
    }
    
    public static function getFlameGraphData(): array
    {
        $flameData = [];
        
        foreach (self::$sections as $name => $section) {
            if (isset($section['start'], $section['end'])) {
                $flameData[] = [
                    'name' => $name,
                    'value' => round($section['duration'] * 1000, 2),
                    'start' => $section['start'],
                    'end' => $section['end'],
                    'children' => $section['children'] ?? [],
                ];
            }
        }
        
        return $flameData;
    }
    
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        
        return round($bytes, 2) . ' ' . $units[$index];
    }
    
    public static function getRecommendations(): array
    {
        $recommendations = [];
        $totals = self::$sections['total'] ?? [];
        
        if (($totals['duration'] ?? 0) > 1.0) {
            $recommendations[] = "Total execution time > 1s. Consider optimizing.";
        }
        
        if (memory_get_peak_usage() > 50 * 1024 * 1024) {
            $recommendations[] = "High memory usage (> 50MB). Check for memory leaks.";
        }
        
        return $recommendations;
    }
}