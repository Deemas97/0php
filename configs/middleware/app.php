<?php
return [
    // Базовый пример
    // 'App\Middleware\TestMiddleware' => [
    //     'enabled' => true,
    //     'priority' => 100,
    //     'config' => [
    //         'allowed_origins' => ['*'],
    //         'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    //     ],
    // ],
    
    // // Middleware с зависимостями
    // 'App\Middleware\AuthMiddleware' => [
    //     'enabled' => true,
    //     'priority' => 90,
    //     'config' => [
    //         'auth_driver' => 'jwt',
    //         'token_header' => 'Authorization',
    //     ],
    // ],
    
    // // Middleware для логирования
    // 'App\Middleware\LoggingMiddleware' => [
    //     //'enabled' => env('ENABLE_LOGGING', true),
    //     'priority' => 80,
    //     'config' => [
    //         'log_level' => 'info',
    //         'log_format' => 'json',
    //     ],
    // ],
    
    // // Middleware для кэширования
    // 'App\Middleware\CacheMiddleware' => [
    //     //'enabled' => env('ENABLE_CACHE', false),
    //     'priority' => 70,
    //     'config' => [
    //         'ttl' => 3600,
    //         'driver' => 'redis',
    //     ],
    // ],
    
    // // Отключенное middleware
    // 'App\Middleware\DebugMiddleware' => [
    //     //'enabled' => env('APP_DEBUG', false),
    //     'priority' => 10,
    // ],
];