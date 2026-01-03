<?php
namespace Infrastructure\Http;

class ServerData
{
    readonly private array $data;
    
    readonly private array $input;
    readonly private array $files;
    readonly private array $cookies;
    readonly private array $session;
    readonly private array $json;

    public function __construct()
    {
        $this->data = $this->filterSensitiveData($_SERVER);
        $this->input = $_REQUEST;
        $this->files = $_FILES;
        $this->cookies = $_COOKIE;
        $this->session = $_SESSION ?? [];
        $this->json = $this->parseJsonInput();
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function merge(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }

    public function filter(callable $callback): array
    {
        return array_filter($this->data, $callback, ARRAY_FILTER_USE_BOTH);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    public function get(string $key, $default = null)
    {
        $value = ($this->data[$key] ?? $default);
        
        if (is_string($value)) {
            $value = trim($value);
        }
        
        return $value;
    }

    public function getMethod(): string
    {
        return $this->data['REQUEST_METHOD'] ?? 'GET';
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->getMethod();
    }

    public function getUri(): string
    {
        return $this->data['REQUEST_URI'] ?? '/';
    }

    public function getQueryString(): string
    {
        return $this->data['QUERY_STRING'] ?? '';
    }

    public function getProtocol(): string
    {
        return $this->data['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
    }

    public function getHost(): string
    {
        if (isset($this->data['HTTP_HOST'])) {
            return strtolower($this->data['HTTP_HOST']);
        }

        return $this->data['SERVER_NAME'] ?? $this->data['SERVER_ADDR'] ?? 'localhost';
    }

    public function getPort(): int
    {
        return (int) ($this->data['SERVER_PORT'] ?? 80);
    }

    public function isSecure(): bool
    {
        return isset($this->data['HTTPS']) && strtolower($this->data['HTTPS']) !== 'off';
    }

    public function getUserAgent(): string
    {
        return $this->data['HTTP_USER_AGENT'] ?? '';
    }

    public function getIp(): string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($this->data[$key])) {
                $ip = trim(explode(',', $this->data[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    public function getReferer(): string
    {
        return $this->data['HTTP_REFERER'] ?? '';
    }

    public function getAcceptLanguage(): string
    {
        return $this->data['HTTP_ACCEPT_LANGUAGE'] ?? '';
    }

    public function getAcceptEncoding(): string
    {
        return $this->data['HTTP_ACCEPT_ENCODING'] ?? '';
    }

    public function getAccept(): string
    {
        return $this->data['HTTP_ACCEPT'] ?? '';
    }

    public function getAuthorization(): string
    {
        return $this->data['HTTP_AUTHORIZATION'] ?? $this->data['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }

    public function isAjax(): bool
    {
        return isset($this->data['HTTP_X_REQUESTED_WITH']) 
            && strtolower($this->data['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    public function isCli(): bool
    {
        return PHP_SAPI === 'cli' || empty($this->data['REMOTE_ADDR']);
    }

    public function input(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->input;
        }
        return $this->input[$key] ?? $default;
    }

    public function file(?string $key = null)
    {
        if ($key === null) {
            return $this->files;
        }
        return $this->files[$key] ?? null;
    }

    public function cookie(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->cookies;
        }
        return $this->cookies[$key] ?? $default;
    }

    public function session(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->session;
        }
        return $this->session[$key] ?? $default;
    }

    public function json(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->json;
        }
        return $this->json[$key] ?? $default;
    }

    public function getRawInput(): ?string
    {
        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return file_get_contents('php://input') ?? '';
        }

        return null;
    }

    public function getContentType(): string
    {
        return $this->data['CONTENT_TYPE'] ?? '';
    }

    public function isJson(): bool
    {
        return strpos($this->getContentType(), 'application/json') !== false;
    }

    public function isMultipart(): bool
    {
        return strpos($this->getContentType(), 'multipart/form-data') !== false;
    }

    public function isFormUrlencoded(): bool
    {
        return strpos($this->getContentType(), 'application/x-www-form-urlencoded') !== false;
    }

    public function getServerSoftware(): string
    {
        return $this->data['SERVER_SOFTWARE'] ?? '';
    }

    public function isApache(): bool
    {
        return stripos($this->getServerSoftware(), 'Apache') !== false;
    }

    public function isNginx(): bool
    {
        return stripos($this->getServerSoftware(), 'nginx') !== false;
    }


    public function isCgi(): bool
    {
        return stripos(PHP_SAPI, 'cgi') !== false;
    }

    public function isFpm(): bool
    {
        return stripos(PHP_SAPI, 'fpm') !== false;
    }

    private function filterSensitiveData(array $server): array
    {
        $sensitiveKeys = [
            'HTTP_AUTHORIZATION', 'HTTP_PROXY_AUTHORIZATION', 'PHP_AUTH_USER', 
            'PHP_AUTH_PW', 'DB_PASSWORD', 'DATABASE_URL', 'MAIL_PASSWORD',
            'REDIS_PASSWORD', 'API_KEY', 'SECRET_KEY'
        ];
        
        $filtered = [];
        foreach ($server as $key => $value) {
            if (in_array($key, $sensitiveKeys)) {
                $filtered[$key] = '***HIDDEN***';
            } else {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }

    private function parseJsonInput(): array
    {
        if (!$this->isJson()) {
            return [];
        }

        $json = json_decode($this->getRawInput(), true);
        return is_array($json) ? $json : [];
    }

    public function isOpCacheAvailable(): bool
    {
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            return $status !== false && ($status['opcache_enabled'] ?? false);
        }
        
        if (function_exists('opcache_reset')) {
            return true;
        }
        
        if (ini_get('opcache.enable') === '1') {
            return true;
        }
        
        if ($this->isNginx() && stripos(PHP_SAPI, 'fpm') !== false) {
            ob_start();
            phpinfo(INFO_MODULES);
            $info = ob_get_clean();
            return stripos($info, 'opcache') !== false;
        }
        
        return false;
    }

    public function isJitAvailable(): bool
    {
        if (PHP_VERSION_ID < 80000) {
            return false;
        }
        
        if (!$this->isOpCacheAvailable()) {
            return false;
        }
        
        $bufferSize = ini_get('opcache.jit_buffer_size');
        if (empty($bufferSize) || $bufferSize === '0') {
            return false;
        }
        
        return true;
    }

    public function getServerType(): string
    {
        if ($this->isApache()) return 'apache';
        if ($this->isNginx()) return 'nginx';
        if ($this->isCgi()) return 'cgi';
        if ($this->isFpm()) return 'fpm';
        
        return 'unknown';
    }
    
    public function getSapiInfo(): array
    {
        return [
            'name' => PHP_SAPI,
            'version' => phpversion(),
            'is_cli' => $this->isCli(),
            'is_fpm' => $this->isFpm(),
            'is_cgi' => $this->isCgi(),
        ];
    }
}
