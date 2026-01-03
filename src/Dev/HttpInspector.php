<?php
namespace Dev;

use Bootstrap\Config\DotEnv;

class HttpInspector
{
    private static array $requests = [];
    private static bool $enabled = false;
    private static string $logDir;
    
    public static function init(): void
    {
        self::$enabled = DotEnv::getDataItem('DEV_HTTP_LOGGING', '0') === '1';
        self::$logDir = YADRO_PHP__ROOT_DIR . '/var/log/dev/http/';
        
        if (self::$enabled && !is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }
    
    public static function captureRequest(): void
    {
        if (!self::$enabled) {
            return;
        }
        
        $requestId = uniqid('req_', true);
        $timestamp = microtime(true);
        
        $request = [
            'id' => $requestId,
            'timestamp' => $timestamp,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'url' => ($_SERVER['REQUEST_URI'] ?? '') . ($_SERVER['QUERY_STRING'] ?? ''),
            'headers' => self::getHeaders(),
            'get' => $_GET,
            'post' => self::filterSensitiveData($_POST),
            'files' => $_FILES,
            'cookies' => self::filterSensitiveData($_COOKIE),
            'session' => isset($_SESSION) ? self::filterSensitiveData($_SESSION) : null,
            'server' => self::filterServerInfo($_SERVER),
            'raw_input' => self::getRawInput(),
        ];
        
        self::$requests[$requestId] = [
            'request' => $request,
            'response' => null,
            'start_time' => $timestamp,
        ];
        
        self::saveRequestToFile($requestId, $request);
        
        register_shutdown_function([self::class, 'captureResponse'], $requestId);
    }
    
    public static function captureResponse(string $requestId): void
    {
        if (!self::$enabled || !isset(self::$requests[$requestId])) {
            return;
        }
        
        $response = [
            'timestamp' => microtime(true),
            'headers' => headers_list(),
            'status_code' => http_response_code(),
            'content_type' => self::getContentType(),
            'content_length' => ob_get_length() ?: 0,
            'execution_time' => microtime(true) - self::$requests[$requestId]['start_time'],
            'memory_peak' => memory_get_peak_usage(),
        ];
        
        self::$requests[$requestId]['response'] = $response;
        
        self::saveResponseToFile($requestId, $response);
    }
    
    private static function getHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }
    
    private static function filterSensitiveData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'credit', 'cvv', 'ssn'];
        
        return array_map(function($value) use ($sensitiveKeys) {
            if (is_array($value)) {
                return self::filterSensitiveData($value);
            }
            
            if (is_string($value)) {
                foreach ($sensitiveKeys as $key) {
                    if (stripos($value, $key) !== false) {
                        return '[FILTERED]';
                    }
                }
            }
            
            return $value;
        }, $data);
    }
    
    private static function filterServerInfo(array $server): array
    {
        $filtered = [];
        $allowed = ['REQUEST_METHOD', 'REQUEST_URI', 'QUERY_STRING', 'REMOTE_ADDR', 
                   'HTTP_USER_AGENT', 'HTTP_REFERER', 'SERVER_NAME'];
        
        foreach ($server as $key => $value) {
            if (in_array($key, $allowed)) {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }
    
    private static function getRawInput(): ?string
    {
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            return null;
        }
        
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($input, true);
            if ($decoded !== null) {
                return json_encode(self::filterSensitiveData($decoded), JSON_PRETTY_PRINT);
            }
        }
        
        return strlen($input) > 1000 ? substr($input, 0, 1000) . '...' : $input;
    }
    
    private static function getContentType(): string
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return trim(substr($header, 13));
            }
        }
        return 'unknown';
    }
    
    private static function saveRequestToFile(string $requestId, array $request): void
    {
        $filename = self::$logDir . $requestId . '_request.json';
        file_put_contents($filename, json_encode($request, JSON_PRETTY_PRINT));
    }
    
    private static function saveResponseToFile(string $requestId, array $response): void
    {
        $filename = self::$logDir . $requestId . '_response.json';
        file_put_contents($filename, json_encode($response, JSON_PRETTY_PRINT));
    }
    
    public static function getRecentRequests(int $limit = 10): array
    {
        return array_slice(self::$requests, -$limit, $limit, true);
    }
    
    public static function findRequest(string $requestId): ?array
    {
        return self::$requests[$requestId] ?? null;
    }
}