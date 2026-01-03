<?php
namespace Infrastructure\Http;

use Bootstrap\Config\DotEnv;

class Protocol
{
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && 
            strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && 
            strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        
        if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            return true;
        }
        
        return false;
    }
    
    public static function isHttpsForced(): bool
    {
        return (self::isHttps() || (DotEnv::getDataItem('HTTPS_FORCED') === '1'));
    }
    
    public static function getCurrentProtocol(): string
    {
        return self::isHttps() ? 'https://' : 'http://';
    }
}