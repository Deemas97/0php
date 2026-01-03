<?php
namespace Infrastructure\Config;

class AppConfig
{
    private static ?string $rootDir = null;
    private static ?array $env = null;
    private static ?string $environment = null;
    
    public static function initialize(string $entryPoint, array $env): void
    {
        self::$rootDir = $entryPoint;
        
        self::$env = $env;
        self::$environment = self::$env['APP_ENV'] ?? 'production';
    }
    
    public static function getRootDir(): string
    {
        return self::$rootDir;
    }
    
    public static function getEnvironment(): string
    {
        return self::$environment;
    }
    
    public static function isProduction(): bool
    {
        return self::$environment === 'production';
    }
    
    public static function isDevelopment(): bool
    {
        return self::$environment === 'dev';
    }
    
    public static function get(string $key, $default = null)
    {
        return self::$env[$key] ?? $default;
    }
}