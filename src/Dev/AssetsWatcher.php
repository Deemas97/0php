<?php
namespace Dev;

use Bootstrap\Config\DotEnv;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AssetsWatcher
{
    private static bool $enabled = false;
    private static array $watchedPaths = [];
    private static array $fileHashes = [];
    private static string $cacheFile;
    
    public static function init(): void
    {
        self::$enabled = DotEnv::getDataItem('DEV_ASSET_WATCHER', '0') === '1';
        self::$cacheFile = YADRO_PHP__ROOT_DIR . '/var/cache/dev/asset_hashes.json';
        
        if (self::$enabled) {
            self::loadHashes();
            self::setupWatchedPaths();
            
            if (self::hasChanges()) {
                self::clearOpCache();
                self::notifyBrowser();
            }
            
            self::saveHashes();
        }
    }
    
    private static function setupWatchedPaths(): void
    {
        self::$watchedPaths = [
            YADRO_PHP__ROOT_DIR . '/src/Domain' => ['php'],
            YADRO_PHP__ROOT_DIR . '/src/App' => ['php'],
            YADRO_PHP__ROOT_DIR . '/src/Infrastructure' => ['php'],
            YADRO_PHP__ROOT_DIR . '/src/Core' => ['php'],
            YADRO_PHP__ROOT_DIR . '/src/Bootstrap' => ['php'],
            YADRO_PHP__ROOT_DIR . '/configs' => ['php', 'json'],
            YADRO_PHP__ROOT_DIR . '/templates' => ['php', 'html'],
            YADRO_PHP__ROOT_DIR . '/public/assets' => ['js', 'css', 'scss'],
        ];
    }
    
    private static function loadHashes(): void
    {
        if (file_exists(self::$cacheFile)) {
            self::$fileHashes = json_decode(file_get_contents(self::$cacheFile), true) ?: [];
        }
    }
    
    private static function saveHashes(): void
    {
        $cacheDir = dirname(self::$cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        file_put_contents(self::$cacheFile, json_encode(self::$fileHashes));
    }
    
    public static function hasChanges(): bool
    {
        $hasChanges = false;
        
        foreach (self::$watchedPaths as $path => $extensions) {
            if (!is_dir($path)) {
                continue;
            }
            
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), $extensions)) {
                    $filepath = $file->getRealPath();
                    $currentHash = md5_file($filepath);
                    $previousHash = self::$fileHashes[$filepath] ?? null;
                    
                    if ($previousHash !== $currentHash) {
                        $hasChanges = true;
                        self::$fileHashes[$filepath] = $currentHash;
                        self::logChange($filepath);
                    }
                }
            }
        }
        
        return $hasChanges;
    }
    
    private static function logChange(string $filepath): void
    {
        $relativePath = str_replace(YADRO_PHP__ROOT_DIR . '/', '', $filepath);
        $logEntry = sprintf(
            "[%s] File changed: %s\n",
            date('Y-m-d H:i:s'),
            $relativePath
        );
        
        file_put_contents(
            YADRO_PHP__ROOT_DIR . '/var/log/dev/hot_reload.log',
            $logEntry,
            FILE_APPEND
        );
    }
    
    private static function clearOpCache(): void
    {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        $templateCache = YADRO_PHP__ROOT_DIR . '/var/cache/templates';
        if (is_dir($templateCache)) {
            array_map('unlink', glob($templateCache . '/*'));
        }
    }
    
    private static function notifyBrowser(): void
    {
        if (!headers_sent()) {
            setcookie('X-Asset-Change', time(), 0, '/', '', false, true);
        }
    }

    public static function getChangedFiles(): array
    {
        $changedFiles = [];
        
        foreach (self::$watchedPaths as $path => $extensions) {
            if (!is_dir($path)) {
                continue;
            }
            
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), $extensions)) {
                    $filepath = $file->getRealPath();
                    $currentHash = md5_file($filepath);
                    $previousHash = self::$fileHashes[$filepath] ?? null;
                    
                    if ($previousHash !== $currentHash) {
                        $relativePath = str_replace(YADRO_PHP__ROOT_DIR . '/', '', $filepath);
                        $changedFiles[] = [
                            'file' => $relativePath,
                            'size' => $file->getSize(),
                            'modified' => $file->getMTime(),
                            'hash' => $currentHash
                        ];
                    }
                }
            }
        }
        
        return $changedFiles;
    }
    
    public static function clearHashes(): void
    {
        self::$fileHashes = [];
        if (file_exists(self::$cacheFile)) {
            unlink(self::$cacheFile);
        }
    }
    
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
    
    public static function getClientScript(): string
    {
        if (!self::$enabled) {
            return '';
        }
        
        return <<<HTML
<script>
(function() {
    let lastChange = document.cookie.match(/X-Asset-Change=(\d+)/);
    lastChange = lastChange ? parseInt(lastChange[1]) : 0;
    
    function checkForUpdates() {
        fetch('/_dev/assets/check?last=' + lastChange)
            .then(r => r.json())
            .then(data => {
                if (data.changed && data.files.length > 0) {
                    console.log('Files changed:', data.files);
                    if (confirm('Code was updated. Reload page?')) {
                        location.reload();
                    }
                }
            })
            .catch(() => {});
    }
    
    setInterval(checkForUpdates, 10000);
    
    if ('WebSocket' in window) {
        const ws = new WebSocket('ws://' + window.location.host + '/_dev/ws');
        ws.onmessage = (e) => {
            const data = JSON.parse(e.data);
            if (data.type === 'reload') {
                location.reload();
            }
        };
    }
})();
</script>
HTML;
    }
}