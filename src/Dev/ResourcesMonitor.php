<?php
namespace Dev;

use Bootstrap\Config\ProjectMode;
use Infrastructure\DataBase\MySQLConnector;
use Throwable;

class ResourcesMonitor
{
    private static array $timers = [];
    private static array $networkStats = ['http' => [], 'curl' => []];
    private static array $sqlStats = [];
    private static array $diskStats = [];
    private static array $includedFiles = [];
    private static array $oopStats = [];
    private static array $systemMetrics = [];
    private static bool $isWindows = false;

    public static function init(): void
    {
        if (!self::isDevMode()) {
            return;
        }
        
        self::$isWindows = stripos(PHP_OS, 'WIN') === 0;
        self::$includedFiles = get_included_files();
    }

    private static function isDevMode(): bool
    {
        return in_array(
            ProjectMode::getCurrentMode(), 
            ['dev', 'development', 'test']
        );
    }

    public static function startTimer(string $name): void
    {
        self::$timers[$name] = microtime(true);
    }

    public static function stopTimer(string $name): float
    {
        return microtime(true) - (self::$timers[$name] ?? throw new \RuntimeException("Timer '$name' not started"));
    }

    public static function getMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(),
            'peak'    => memory_get_peak_usage(),
            'real_usage' => memory_get_usage(true),
        ];
    }

    public static function measureHttpRequest(callable $request): mixed
    {
        $start = microtime(true);
        $result = $request();
        $duration = microtime(true) - $start;

        self::$networkStats['http'][] = [
            'duration' => $duration,
            'memory'   => memory_get_usage(),
            'trace'    => self::getBacktrace(),
        ];

        return $result;
    }

    public static function measureCurl(callable $curlExec): mixed
    {
        return self::measureHttpRequest(function () use ($curlExec) {
            $result = $curlExec();
            self::$networkStats['curl'][] = end(self::$networkStats['http']);
            return $result;
        });
    }

    public static function measureQuery(MySQLConnector $db, string $sql): array|bool
    {
        $result = self::measureHttpRequest(fn() => $db->query($sql));

        self::$sqlStats[] = [
            'query'    => $sql,
            'duration' => end(self::$networkStats['http'])['duration'],
            'success'  => $result !== false,
            'error'    => $db->getLastError(),
        ];

        return $result;
    }

    public static function measureDiskOperation(callable $operation): mixed
    {
        $start = microtime(true);
        $result = $operation();
        $duration = microtime(true) - $start;

        self::$diskStats[] = [
            'duration' => $duration,
            'memory'   => memory_get_usage(),
            'trace'    => self::getBacktrace(),
        ];

        return $result;
    }

    public static function captureOopMetrics(): void
    {
        self::$oopStats = [
            'classes' => count(get_declared_classes()),
            'objects' => count(array_filter(get_defined_vars(), fn($v) => is_object($v))),
            'interfaces' => count(get_declared_interfaces()),
            'traits' => count(get_declared_traits()),
        ];
    }

    public static function captureSystemMetrics(): void
    {
        if (!self::isDevMode()) {
            return;
        }

        try {
            $metrics = ['cpu' => [], 'memory' => [], 'disk' => []];

            if (self::$isWindows) {
                $memory = shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value');
                preg_match('/FreePhysicalMemory=(\d+)/', $memory, $free);
                preg_match('/TotalVisibleMemorySize=(\d+)/', $memory, $total);
                $metrics['memory'] = [
                    'total' => ($total[1] ?? 0) * 1024,
                    'free'  => ($free[1] ?? 0) * 1024,
                ];
            } else {
                $memInfo = @file_get_contents('/proc/meminfo');
                preg_match('/MemTotal:\s+(\d+)/', $memInfo, $total);
                preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $free);
                $metrics['memory'] = [
                    'total' => ($total[1] ?? 0) * 1024,
                    'free'  => ($free[1] ?? 0) * 1024,
                ];
            }

            if (self::$isWindows) {
                $cpu = shell_exec('wmic cpu get LoadPercentage /Value');
                preg_match('/LoadPercentage=(\d+)/', $cpu, $load);
                $metrics['cpu']['load'] = $load[1] ?? 0;
            } else {
                $stat1 = file('/proc/stat');
                sleep(1);
                $stat2 = file('/proc/stat');
                $info1 = explode(' ', preg_replace('/\s+/', ' ', trim($stat1[0])));
                $info2 = explode(' ', preg_replace('/\s+/', ' ', trim($stat2[0])));
                $metrics['cpu']['usage'] = 
                    ($info2[1] + $info2[3] - $info1[1] - $info1[3]) / 
                    ($info2[1] + $info2[3] + $info2[4] - $info1[1] - $info1[3] - $info1[4]) * 100;
            }

            if (!self::$isWindows && is_readable('/proc/diskstats')) {
                $diskStats = file('/proc/diskstats');
                $metrics['disk']['io'] = array_slice(explode(' ', preg_replace('/\s+/', ' ', $diskStats[0])), 2, 4);
            }

            self::$systemMetrics = $metrics;
        } catch (Throwable $e) {
            self::logError("System metrics error: " . $e->getMessage());
        }
    }

    private static function logError(string $message): void
    {
        error_log("[ResourcesMonitor] " . $message);
    }

    public static function getWebServerMetrics(): ?array
    {
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $server = $_SERVER['SERVER_SOFTWARE'];

            if (strpos($server, 'Apache') !== false) {
                return ['type' => 'Apache', 'metrics' => self::parseApacheStatus()];
            } elseif (strpos($server, 'nginx') !== false) {
                return ['type' => 'Nginx', 'metrics' => self::parseNginxStatus()];
            }
        }

        return null;
    }

    private static function parseApacheStatus(): array
    {
        $status = @file_get_contents('http://localhost/server-status?auto');
        return $status ? [
            'requests' => preg_match('/Total Accesses:\s+(\d+)/', $status, $m) ? $m[1] : 0,
            'workers'  => preg_match('/BusyWorkers:\s+(\d+)/', $status, $m) ? $m[1] : 0,
        ] : [];
    }

    private static function parseNginxStatus(): array
    {
        $status = @file_get_contents('http://localhost/nginx_status');
        return $status ? [
            'connections' => preg_match('/Active connections:\s+(\d+)/', $status, $m) ? $m[1] : 0,
            'requests'    => preg_match('/\s+(\d+)\s+(\d+)\s+(\d+)/', $status, $m) ? $m[3] : 0,
        ] : [];
    }

    private static function getBacktrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        
        return array_map(function($frame) {
            if (isset($frame['file'])) {
                $frame['file'] = str_replace(
                    [YADRO_PHP__ROOT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? ''],
                    ['[ROOT]', '[DOCROOT]'],
                    $frame['file']
                );
            }
            return $frame;
        }, $trace);
    }

    public static function getMetrics(): array
    {
        self::captureOopMetrics();
        self::captureSystemMetrics();

        return [
            'timers'    => self::$timers,
            'memory'    => self::getMemoryUsage(),
            'network'   => [
                'http' => self::$networkStats['http'],
                'curl' => self::$networkStats['curl'],
                'total_requests' => count(self::$networkStats['http']) + count(self::$networkStats['curl']),
            ],
            'sql'       => self::$sqlStats,
            'disk'      => self::$diskStats,
            'files'     => [
                'included' => self::$includedFiles,
                'count'    => count(self::$includedFiles),
            ],
            'oop'       => self::$oopStats,
            'system'    => self::$systemMetrics,
            'webserver' => self::getWebServerMetrics(),
        ];
    }

    public static function enableAutoSave(string $file = 'metrics.json'): void
    {
        register_shutdown_function(function () use ($file) {
            file_put_contents($file, json_encode(self::getMetrics(), JSON_PRETTY_PRINT));
        });
    }
}