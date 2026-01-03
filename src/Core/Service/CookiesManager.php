<?php
namespace Core\Service;

use Bootstrap\Config\DotEnv;

class CookiesManager implements CoreServiceInterface
{
    private bool $isHttps;
    private string $defaultDomain;
    private string $sameSite;

    public function __construct()
    {
        $this->isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                        || ($_SERVER['SERVER_PORT'] == 443);
        $this->defaultDomain = $this->resolveDefaultDomain();
        $this->sameSite = $this->isHttps ? 'Strict' : 'Lax';
    }

    /**
     * Получает значение куки
     */
    public function get(string $name): ?string
    {
        return $_COOKIE[$name] ?? null;
    }

    /**
     * Устанавливает куку с настройками
     */
    public function set(
        string $name,
        string $value,
        array $options = []
    ): void {
        $params = $this->prepareParams($options);
        setcookie($name, $value, $params);
    }

    /**
     * Удаляет куку
     */
    public function remove(string $name): void
    {
        $params = session_get_cookie_params();
        setcookie(
            $name, 
            '', 
            time() - 42000,
            $params["path"], 
            $params["domain"],
            $params["secure"], 
            $params["httponly"]
        );
    }

    /**
     * Проверяет наличие куки
     */
    public function has(string $name): bool
    {
        return isset($_COOKIE[$name]);
    }

    /**
     * Устанавливает сессионную куку (истекает при закрытии браузера)
     */
    public function setSessionCookie(
        string $name,
        string $value,
        array $options = []
    ): void {
        $options['expires'] = 0;
        $this->set($name, $value, $options);
    }

    private function prepareParams(array $options): array
    {
        return [
            'expires' => $options['expires'] ?? time() + 3600,
            'path' => $options['path'] ?? '/',
            'domain' => $options['domain'] ?? $this->defaultDomain,
            'secure' => $options['secure'] ?? $this->isHttps,
            'httponly' => $options['httponly'] ?? true,
            'samesite' => $options['samesite'] ?? $this->sameSite
        ];
    }

    private function resolveDefaultDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? DotEnv::getDataItem('HOST__SELF__DOMAIN') ?? 'localhost';
        
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        return $host;
    }
}