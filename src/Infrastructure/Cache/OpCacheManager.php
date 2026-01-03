<?php
namespace Infrastructure\Cache;

use Bootstrap\Config\DotEnv;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Exception;
use RuntimeException;

class OpCacheManager
{
    private const PRELOAD_LOCK_DIR = '/var/cache/';
    private static string $lockFile = '';
    private static $lockHandle = null;
    private static ?array $cachedFileList = null;
    private const PRELOAD_MAX_ATTEMPTS = 3;
    
    public static function preloadDirectory(string $directory): void
    {
        if (!function_exists('opcache_compile_file')) {
            return;
        }
        
        self::initPaths();
        
        $status = @opcache_get_status(false);
        if ($status && isset($status['memory_usage']) && 
            isset($status['memory_usage']['used_memory']) &&
            isset($status['memory_usage']['total_memory']) &&
            $status['memory_usage']['total_memory'] > 0) {
            
            $memoryRatio = $status['memory_usage']['used_memory'] / 
                          $status['memory_usage']['total_memory'];
            
            if ($memoryRatio > 0.9) {
                self::logDebug('OpCache memory almost full (' . 
                    round($memoryRatio * 100, 1) . '%), skipping preload');
                return;
            }
        }

        if (!self::shouldPreload()) {
            return;
        }
        
        if (!self::acquireLock()) {
            self::logDebug('OpCache preload: Another process is already running');
            return;
        }

        try {
            $files = self::getFilesToPreload($directory);
            
            if (empty($files)) {
                self::logDebug('No files found for preloading');
                self::markAsPreloaded();
                return;
            }
            
            $sortedFiles = self::sortFilesByPriority($files);
            $compiled = 0;
            $limit = (int)DotEnv::getDataItem('OPCACHE_PRELOAD_LIMIT', '1000');
            $limit = $limit > 0 ? $limit : 1000;
            
            foreach ($sortedFiles as $i => $file) {
                if ($i >= $limit) {
                    self::logDebug("Reached preload limit of {$limit} files");
                    break;
                }
                
                if (self::isFileCached($file)) {
                    continue;
                }
                
                if (!self::isValidPath($file, $directory)) {
                    continue;
                }
                
                if (@opcache_compile_file($file)) {
                    $compiled++;
                }
                
                if ($compiled > 0 && $compiled % 100 === 0) {
                    usleep(10000);
                }
            }
            
            self::markAsPreloaded();
            
            if ($compiled > 0) {
                self::logDebug("OpCache preload completed: $compiled files compiled");
            } else {
                self::logDebug("OpCache preload completed: no new files compiled");
            }
            
        } catch (Exception $e) {
            self::logError("OpCache preload error: " . $e->getMessage(), $e);
        } finally {
            self::releaseLock();
        }
    }

    private static function initPaths(): void
    {
        if (empty(self::$lockFile)) {
            $lockDir = YADRO_PHP__ROOT_DIR . self::PRELOAD_LOCK_DIR;
            
            if (!is_dir($lockDir)) {
                if (!mkdir($lockDir, 0755, true)) {
                    throw new RuntimeException(
                        "Cannot create lock directory: " . $lockDir
                    );
                }
            }
            
            self::$lockFile = $lockDir . 'preload.lock';
            
            self::cleanupStaleLocks();
        }
    }
    
    private static function shouldPreload(): bool
    {
        $preloadOnRequest = DotEnv::getDataItem('OPCACHE_PRELOAD_ENABLED', '0');
        if ($preloadOnRequest !== '1') {
            return false;
        }
        
        if (!file_exists(self::$lockFile)) {
            return true;
        }
        
        $lockAge = time() - filemtime(self::$lockFile);
        $maxAge = (int)DotEnv::getDataItem('OPCACHE_PRELOAD_MAX_AGE', '3600');
        
        return $lockAge > $maxAge;
    }
    
    private static function acquireLock(): bool
    {
        $attempts = 0;
        
        while ($attempts < self::PRELOAD_MAX_ATTEMPTS) {
            $lockHandle = @fopen(self::$lockFile, 'c+');

            if ($lockHandle === false) {
                $attempts++;
                usleep(100000);
                continue;
            }
            
            if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
                ftruncate($lockHandle, 0);
                fwrite($lockHandle, (string)time());
                fflush($lockHandle);
                
                self::$lockHandle = $lockHandle;
                return true;
            }
            
            fclose($lockHandle);
            
            if (file_exists(self::$lockFile)) {
                $content = @file_get_contents(self::$lockFile);
                $lockTime = $content ? (int)trim($content) : 0;
                
                if ($lockTime > 0 && (time() - $lockTime) > 300) {
                    @unlink(self::$lockFile);
                }
            }
            
            $attempts++;
            usleep(100000);
        }
        
        return false;
    }
    
    private static function releaseLock(): void
    {
        if (self::$lockHandle !== null) {
            flock(self::$lockHandle, LOCK_UN);
            fclose(self::$lockHandle);
            self::$lockHandle = null;
            
            if (file_exists(self::$lockFile)) {
                @unlink(self::$lockFile);
            }
        }
    }
    
    private static function markAsPreloaded(): void
    {
        $markerFile = YADRO_PHP__ROOT_DIR . self::PRELOAD_LOCK_DIR . 'preload_completed.time';
        file_put_contents($markerFile, time());
    }
    
    private static function getFilesToPreload(string $directory): array
    {
        $cacheFile = YADRO_PHP__ROOT_DIR . self::PRELOAD_LOCK_DIR . 'preload_files.cache';
        
        if (file_exists($cacheFile) && 
            filemtime($cacheFile) > time() - 3600) {
            
            $cachedData = @file_get_contents($cacheFile);
            if ($cachedData !== false) {
                $data = @unserialize($cachedData);
                if (is_array($data) && isset($data['time']) && 
                    $data['time'] > time() - 3600) {
                    self::$cachedFileList = $data['files'] ?? [];
                    return self::$cachedFileList;
                }
            }
        }
        
        $files = self::scanDirectory($directory);
        
        $cacheData = [
            'time' => time(),
            'files' => $files
        ];
        @file_put_contents($cacheFile, serialize($cacheData));
        
        self::$cachedFileList = $files;
        return $files;
    }
    
    private static function scanDirectory(string $directory): array
    {
        $files = [];
        
        if (!is_dir($directory)) {
            self::logError("Directory does not exist: $directory");
            return [];
        }
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS | 
                    FilesystemIterator::FOLLOW_SYMLINKS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                
                $realPath = $file->getRealPath();
                if ($realPath === false) {
                    continue;
                }
                
                if (!self::isValidPath($realPath, $directory)) {
                    continue;
                }
                
                $files[] = $realPath;
                
                if (count($files) > 5000) {
                    self::logDebug('Reached maximum file scan limit of 5000 files');
                    break;
                }
            }
        } catch (Exception $e) {
            self::logError('OpCache scan error: ' . $e->getMessage(), $e);
        }
        
        return $files;
    }
    
    private static function isValidPath(string $filePath, string $baseDir): bool
    {
        $realPath = realpath($filePath);
        $realBaseDir = realpath($baseDir);
        
        if ($realPath === false || $realBaseDir === false) {
            return false;
        }
        
        return strpos($realPath, $realBaseDir) === 0;
    }
    
    private static function sortFilesByPriority(array $files): array
    {
        if (empty($files)) {
            return [];
        }
        
        $priorityMap = [];
        
        foreach ($files as $file) {
            $priority = self::estimatePriority($file);
            $priorityMap[$priority][] = $file;
        }
        
        krsort($priorityMap);
        
        $result = [];
        foreach ($priorityMap as $priorityFiles) {
            shuffle($priorityFiles);
            $result = array_merge($result, $priorityFiles);
        }
        
        return $result;
    }
    
    private static function estimatePriority(string $filePath): int
    {
        $filename = basename($filePath);
        $path = dirname($filePath);
        
        if (str_contains($filename, 'Interface')) return 100;
        if (str_contains($filename, 'Abstract')) return 90;
        if (str_contains($filename, 'Trait')) return 85;
        if (str_contains($path, DIRECTORY_SEPARATOR . 'Core')) return 80;
        if (str_contains($path, DIRECTORY_SEPARATOR . 'Infrastructure')) return 70;
        if (str_contains($path, DIRECTORY_SEPARATOR . 'App')) return 60;
        if (str_contains($path, DIRECTORY_SEPARATOR . 'Domain')) return 50;
        
        return 40;
    }
    
    private static function isFileCached(string $file): bool
    {
        $status = @opcache_get_status(false);
        if (!$status || !isset($status['scripts'])) {
            return false;
        }
        
        $scriptKey = self::getScriptKey($file);
        return isset($status['scripts'][$scriptKey]);
    }
    
    private static function getScriptKey(string $file): string
    {
        $realPath = realpath($file);
        return $realPath ?: $file;
    }
    
    private static function cleanupStaleLocks(): void
    {
        $lockDir = YADRO_PHP__ROOT_DIR . self::PRELOAD_LOCK_DIR;
        
        if (!is_dir($lockDir)) {
            return;
        }
        
        $files = glob($lockDir . '*.lock');
        
        foreach ($files as $file) {
            if (time() - filemtime($file) > 600) {
                @unlink($file);
            }
        }
    }
    
    private static function logDebug(string $message): void
    {
        if (DotEnv::getDataItem('APP_DEBUG', '0') === '1') {
            error_log('[OpCache Debug] ' . $message);
        }
    }
    
    private static function logError(string $message, ?Exception $e = null): void
    {
        $logMessage = '[OpCache Error] ' . $message;
        
        if ($e !== null && DotEnv::getDataItem('APP_DEBUG', '0') === '1') {
            $logMessage .= ' in ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        
        error_log($logMessage);
    }

    public static function reset(): bool
    {
        if (function_exists('opcache_reset')) {
            return opcache_reset();
        }
        return false;
    }
    
    public static function getStats(): array
    {
        $status = function_exists('opcache_get_status') ? opcache_get_status(false) : [];
        $config = function_exists('opcache_get_configuration') ? opcache_get_configuration() : [];
        
        $recommendations = [];
        
        if (isset($status['memory_usage'])) {
            $memoryUsage = $status['memory_usage'];
            $used = $memoryUsage['used_memory'] ?? 0;
            $free = $memoryUsage['free_memory'] ?? 0;
            $total = $used + $free;
            
            if ($total > 0 && ($used / $total) > 0.9) {
                $recommendations[] = "OpCache memory usage is high (" . 
                    round($used/1024/1024, 1) . "MB/" . 
                    round($total/1024/1024, 1) . 
                    "MB). Consider increasing opcache.memory_consumption";
            }
        }
        
        if (isset($status['opcache_statistics']['num_cached_scripts'])) {
            $cachedScripts = $status['opcache_statistics']['num_cached_scripts'];
            $maxFiles = $config['directives']['opcache.max_accelerated_files'] ?? 0;
            
            if ($maxFiles > 0 && $cachedScripts > ($maxFiles * 0.9)) {
                $recommendations[] = "OpCache file limit almost reached ($cachedScripts/$maxFiles). " .
                    "Consider increasing opcache.max_accelerated_files";
            }
        }
        
        if (isset($status['opcache_statistics']['opcache_statistics'])) {
            $hits = $status['opcache_statistics']['hits'] ?? 0;
            $misses = $status['opcache_statistics']['misses'] ?? 0;
            $total = $hits + $misses;
            
            if ($total > 0 && ($hits / $total) < 0.8) {
                $recommendations[] = "OpCache hit rate is low (" . 
                    round($hits / $total * 100, 1) . 
                    "%). Consider increasing opcache.revalidate_freq";
            }
        }
        
        return [
            'status' => $status,
            'config' => $config,
            'recommendations' => $recommendations
        ];
    }

    public static function getCacheEfficiency(): array
    {
        $status = @opcache_get_status(false);

        if (!$status || !isset($status['opcache_statistics'])) {
            return ['error' => 'OpCache not available'];
        }

        $stats = $status['opcache_statistics'];
        $hits = $stats['hits'] ?? 0;
        $misses = $stats['misses'] ?? 0;
        $total = $hits + $misses;

        return [
            'hit_rate' => $total > 0 ? round($hits / $total * 100, 2) : 0,
            'hits' => $hits,
            'misses' => $misses,
            'cached_scripts' => $stats['num_cached_scripts'] ?? 0,
            'cache_full' => $stats['cache_full'] ?? false,
            'restarts' => $stats['oom_restarts'] ?? 0,
            'memory_used_mb' => isset($status['memory_usage']['used_memory']) ? 
                round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) : 0,
            'memory_free_mb' => isset($status['memory_usage']['free_memory']) ? 
                round($status['memory_usage']['free_memory'] / 1024 / 1024, 2) : 0,
        ];
    }

    public static function autoOptimize(): array
    {
        $actions = [];
        $efficiency = self::getCacheEfficiency();

        if (isset($efficiency['hit_rate']) && $efficiency['hit_rate'] < 90) {
            $actions[] = 'Consider increasing opcache.memory_consumption or opcache.max_accelerated_files';

            if (($efficiency['restarts'] ?? 0) > 0) {
                $actions[] = 'OpCache restarted due to OOM. Increase memory_consumption immediately!';
            }
        }

        if (($efficiency['cache_full'] ?? false)) {
            $actions[] = 'Cache is full. Increase opcache.max_accelerated_files';
        }

        return $actions;
    }

    public static function monitor(): array
    {
        return [
            'performance' => [
                'preload_time' => self::getLastPreloadTime() ? 
                    date('Y-m-d H:i:s', self::getLastPreloadTime()) : 'Never',
                'preload_age_seconds' => self::getLastPreloadTime() ? 
                    time() - self::getLastPreloadTime() : null,
                'opcache_enabled' => self::isPreloadEnabled(),
            ],
            'efficiency' => self::getCacheEfficiency(),
            'recommendations' => array_merge(
                self::getStats()['recommendations'],
                self::autoOptimize()
            ),
        ];
    }

    public static function watchChanges(string $directory): void
    {
        $hashFile = YADRO_PHP__ROOT_DIR . self::PRELOAD_LOCK_DIR . 'directory_hash.md5';
        
        $currentHash = self::generateDirectoryHash($directory);
        
        if (file_exists($hashFile)) {
            $previousHash = file_get_contents($hashFile);
            
            if ($currentHash !== $previousHash) {
                self::reset();
                self::logDebug('OpCache reset due to file changes');
            }
        }
        
        file_put_contents($hashFile, $currentHash);
    }
    
    private static function generateDirectoryHash(string $directory): string
    {
        $files = self::getFilesToPreload($directory);
        $content = '';
        
        foreach ($files as $file) {
            $content .= $file . filemtime($file);
        }
        
        return md5($content);
    }
    
    public static function invalidate(string $file): bool
    {
        if (function_exists('opcache_invalidate')) {
            return opcache_invalidate($file, true);
        }
        return false;
    }
    
    public static function isPreloadEnabled(): bool
    {
        $status = @opcache_get_status(false);
        return $status !== false && 
               isset($status['opcache_enabled']) && 
               $status['opcache_enabled'];
    }
    
    public static function getLastPreloadTime(): ?int
    {
        $markerFile = YADRO_PHP__ROOT_DIR . self::PRELOAD_LOCK_DIR . 'preload_completed.time';
        
        if (file_exists($markerFile)) {
            $time = @file_get_contents($markerFile);
            return $time ? (int)$time : null;
        }
        
        return null;
    }
}