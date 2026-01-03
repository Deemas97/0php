<?php
namespace Bootstrap;

require_once YADRO_PHP__ROOT_DIR . '/src/Bootstrap/AutoloaderPsr4.php';

use AutoloaderPsr4;

class Autoloader
{
    private static ?AutoloaderPsr4 $loader = null;

    public static function init(): AutoloaderPsr4
    {
        if (self::$loader !== null) {
            return self::$loader;
        }

        self::$loader = new AutoloaderPsr4();

        require YADRO_PHP__ROOT_DIR . '/src/Kernel.php';
        
        self::registerNamespaces();
        self::$loader->register();
        
        return self::$loader;
    }

    private static function registerNamespaces(): void
    {
        $baseDir = (YADRO_PHP__ROOT_DIR . '/src/');
        
        self::$loader->addNamespace('Bootstrap', $baseDir);
        self::$loader->addNamespace('Core', $baseDir);
        self::$loader->addNamespace('Infrastructure', $baseDir);
        self::$loader->addNamespace('App', $baseDir);
        self::$loader->addNamespace('Domain', $baseDir);

        self::$loader->addNamespace('Dev', $baseDir);
    }

    public static function getStats(): array
    {
        return self::init()->getStats();
    }

    public static function addNamespace(string $prefix, string $baseDir, bool $prepend = false): void
    {
        self::init()->addNamespace($prefix, $baseDir, $prepend);
    }
}

return Autoloader::init();