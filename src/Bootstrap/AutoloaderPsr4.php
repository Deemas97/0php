<?php
class AutoloaderPsr4
{
    private array $prefixes = [];
    private array $classMap = [];

    public function register(): void
    {
        spl_autoload_register([$this, 'loadClass'], true, true);
    }

    public function addNamespace(string $prefix, string $baseDir, bool $prepend = false): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        
        if (!isset($this->prefixes[$prefix])) {
            $this->prefixes[$prefix] = [];
        }
        
        if ($prepend) {
            array_unshift($this->prefixes[$prefix], $baseDir);
        } else {
            $this->prefixes[$prefix][] = $baseDir;
        }
    }

    public function loadClass(string $class): ?string
    {
        if (isset($this->classMap[$class])) {
            require $this->classMap[$class];
            return $this->classMap[$class];
        }
        
        $file = $this->findFile($class);
        
        if ($file !== null && file_exists($file)) {
            $this->classMap[$class] = $file;
            require $file;
            return $file;
        }
        
        return null;
    }

    private function findFile(string $class): ?string
    {
        $prefix = $class;
        while (false !== $pos = strrpos($prefix, '\\')) {
            $prefix = substr($class, 0, $pos + 1);
            
            if ($file = $this->loadMappedFile($prefix, $class)) {
                return $file;
            }
            
            $prefix = rtrim($prefix, '\\');
        }
        
        return null;
    }

    private function loadMappedFile(string $prefix, string $relativeClass): ?string
    {
        if (!isset($this->prefixes[$prefix])) {
            return null;
        }
        
        foreach ($this->prefixes[$prefix] as $baseDir) {
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
            
            if (file_exists($file)) {
                return $file;
            }
        }
        
        return null;
    }

    public function getStats(): array
    {
        return [
            'registered_namespaces' => count($this->prefixes)
        ];
    }
}