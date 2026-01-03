<?php
namespace Dev;

use Bootstrap\Config\DotEnv;
use Core\Logger\LoggerInterface;
use Infrastructure\DataBase\DBConnectorInterface;

class DBLogger implements LoggerInterface
{
    private static array $queries = [];
    private static float $totalTime = 0.0;
    private static int $queryCount = 0;
    private static bool $enabled = false;
    private static string $logFile;
    
    public static function init(): void
    {
        self::$enabled = DotEnv::getDataItem('DEV_SQL_LOGGING', '0') === '1';
        self::$logFile = YADRO_PHP__ROOT_DIR . '/var/log/dev/queries_' . date('Y-m-d') . '.log';
    }
    
    public static function logQuery(
        string $sql, 
        float $duration, 
        ?array $params = null, 
        ?string $error = null,
        ?int $affectedRows = null
    ): void {
        if (!self::$enabled) {
            return;
        }
        
        $query = [
            'id' => ++self::$queryCount,
            'sql' => self::normalizeSql($sql),
            'duration' => round($duration * 1000, 2),
            'params' => $params,
            'error' => $error,
            'affected_rows' => $affectedRows,
            'timestamp' => microtime(true),
            'trace' => self::getSafeTrace(),
            'memory_before' => memory_get_usage(),
            'memory_after' => memory_get_usage(),
        ];
        
        self::$queries[] = $query;
        self::$totalTime += $duration;
    }
    
    private static function normalizeSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql);
        $sql = trim($sql);
        
        if (strlen($sql) > 1000) {
            $sql = substr($sql, 0, 997) . '...';
        }
        
        return $sql;
    }
    
    private static function getSafeTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        $safeTrace = [];
        
        foreach ($trace as $frame) {
            if (isset($frame['file'])) {
                $file = str_replace(
                    [YADRO_PHP__ROOT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? ''],
                    ['[ROOT]', '[DOCROOT]'],
                    $frame['file']
                );
                
                $safeTrace[] = [
                    'file' => $file,
                    'line' => $frame['line'] ?? 0,
                    'function' => $frame['function'] ?? '',
                    'class' => $frame['class'] ?? '',
                ];
            }
        }
        
        return $safeTrace;
    }
    
    private static function writeToLog(array $query): void
    {
        $logEntry = sprintf(
            "[%s] Query #%d (%s ms): %s\nParams: %s\nError: %s\nTrace: %s\n\n",
            date('H:i:s'),
            $query['id'],
            $query['duration'],
            $query['sql'],
            json_encode($query['params']),
            $query['error'] ?? 'null',
            json_encode($query['trace'])
        );
        
        file_put_contents(self::$logFile, $logEntry, FILE_APPEND);
    }
    
    public static function getStats(): array
    {
        return [
            'total_queries' => self::$queryCount,
            'total_time_ms' => round(self::$totalTime * 1000, 2),
            'avg_time_ms' => self::$queryCount > 0 ? 
                round((self::$totalTime * 1000) / self::$queryCount, 2) : 0,
            'queries' => self::$queries,
            'slow_queries' => array_filter(self::$queries, fn($q) => $q['duration'] > 100),
        ];
    }
    
    public static function wrapDatabase(DBConnectorInterface $db): DBConnectorInterface
    {
        return new class($db) implements DBConnectorInterface
        {
            private DBConnectorInterface $realDb;
            
            public function __construct(DBConnectorInterface $db)
            {
                $this->realDb = $db;
            }
            
            public function query(string $sql, array $params = []): array|bool
            {
                $start = microtime(true);
                
                try {
                    $result = $this->realDb->query($sql, $params);
                    $duration = microtime(true) - $start;
                    
                    DBLogger::logQuery(
                        $sql,
                        $duration,
                        $params,
                        $this->realDb->getLastError(),
                        is_array($result) ? count($result) : null
                    );
                    
                    return $result;
                } catch (\Exception $e) {
                    $duration = microtime(true) - $start;
                    DBLogger::logQuery($sql, $duration, $params, $e->getMessage());
                    throw $e;
                }
            }
            
            public function __call($method, $args)
            {
                return $this->realDb->$method(...$args);
            }
        };
    }
}