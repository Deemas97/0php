<?php
namespace Core\Pipeline;

use Core\Config\AppMiddlewareConfig;
use Core\Container\ReflectionContainerInterface;
use Core\MessageBus\MessageBusInterface;
use Core\Middleware\ActionsMiddleware;
use Core\Middleware\ClosureMiddleware;
use Core\Middleware\CompressionMiddleware;
use Core\Middleware\MiddlewareInterface;
use Core\Middleware\RequestMiddleware;
use Core\Middleware\ResponseMiddleware;
use Core\Middleware\SecurityMiddleware;
use Exception;

class Pipeline implements PipelineInterface
{
    private ReflectionContainerInterface $container;
    private CoreMiddlewarePipeline $corePipeline;
    private ?AppMiddlewarePipeline $appPipeline = null;
    private ?ClosureMiddleware $closureMiddleware = null;
    private array $defaultCoreMiddleware = [];
    private array $defaultCorePriorities = [];
    private ?array $closureConfig = null;
    private bool $closureConfigLoaded = false;
    private bool $useClosureMiddleware = true;

    public function init(ReflectionContainerInterface $container): void
    {
        $this->container = $container;
        $this->corePipeline = new CoreMiddlewarePipeline();

        $defaultMiddleware = [
            RequestMiddleware::class,
            SecurityMiddleware::class,
            ActionsMiddleware::class,
            CompressionMiddleware::class,
            ResponseMiddleware::class
        ];
        
        $defaultPriorities = [
            RequestMiddleware::class => 100,
            SecurityMiddleware::class => 90,
            ActionsMiddleware::class => 80,
            CompressionMiddleware::class => 70,
            ResponseMiddleware::class => 60
        ];

        $this->setDefaultConfiguration($defaultMiddleware, $defaultPriorities);
        
        $this->loadClosureConfig();
        $this->initClosureMiddleware();
    }

    private function setDefaultConfiguration(array $middlewareClasses, array $priorities = []): self
    {
        $this->defaultCoreMiddleware = $middlewareClasses;
        $this->defaultCorePriorities = $priorities;
        
        $this->createDefaultCorePipeline();
        
        return $this;
    }

    private function initClosureMiddleware(): void
    {
        $this->closureMiddleware = $this->createConfiguredClosureMiddleware();
    }

    private function loadClosureConfig(): void
    {
        try {
            $configPath = YADRO_PHP__ROOT_DIR . '/configs/middleware/closure.php';
            
            if (file_exists($configPath)) {
                $config = require $configPath;
                
                if (is_array($config)) {
                    $this->closureConfig = $config;
                    $this->closureConfigLoaded = true;
                } else {
                    error_log('Closure middleware config must return array, ' . gettype($config) . ' given');
                }
            }
        } catch (Exception $e) {
            error_log('Failed to load closure middleware config: ' . $e->getMessage());
        }
    }

    private function createConfiguredClosureMiddleware(): ClosureMiddleware
    {
        $middleware = new ClosureMiddleware();
        
        if ($this->closureConfig !== null) {
            if (isset($this->closureConfig['security'])) {
                $middleware->setSecurityConfig($this->closureConfig['security']);
            }
            
            if (isset($this->closureConfig['validation'])) {
                $middleware->setValidationRules($this->closureConfig['validation']);
            }
        }
        
        return $middleware;
    }

    private function getMiddlewareInstance(string $middlewareClass)
    {
        if ($middlewareClass === ClosureMiddleware::class) {
            return $this->createConfiguredClosureMiddleware();
        }
        
        return $this->container->get($middlewareClass);
    }

    private function createDefaultCorePipeline(): void
    {
        $this->corePipeline->clear();
        
        foreach ($this->defaultCoreMiddleware as $middlewareClass) {
            $middleware = $this->getMiddlewareInstance($middlewareClass);
            $this->corePipeline->pipe($middleware);
            
            if (isset($this->defaultCorePriorities[$middlewareClass])) {
                $this->corePipeline->setPriority($middlewareClass, $this->defaultCorePriorities[$middlewareClass]);
            }
        }
    }

    public function enableClosureMiddleware(bool $enabled = true): self
    {
        $this->useClosureMiddleware = $enabled;
        return $this;
    }

    public function updateClosureConfig(?array $config): self
    {
        $this->closureConfig = $config;
        $this->closureConfigLoaded = $config !== null;
        
        if (!empty($this->defaultCoreMiddleware)) {
            $this->createDefaultCorePipeline();
        }
        
        return $this;
    }

    public function getClosureConfig(): ?array
    {
        return $this->closureConfig;
    }

    public function hasClosureConfig(): bool
    {
        return $this->closureConfigLoaded && $this->closureConfig !== null;
    }

    public function isClosureMiddlewareEnabled(): bool
    {
        return $this->useClosureMiddleware && $this->closureMiddleware !== null;
    }

    public function getClosureStats(): array
    {
        $stats = [
            'has_config' => $this->hasClosureConfig(),
            'config_loaded' => $this->closureConfigLoaded,
            'config_keys' => [],
        ];
        
        if ($this->closureConfig !== null) {
            $stats['config_keys'] = array_keys($this->closureConfig);
            
            if (isset($this->closureConfig['security'])) {
                $stats['security_enabled'] = !empty($this->closureConfig['security']);
            }
            
            if (isset($this->closureConfig['validation'])) {
                $stats['validation_enabled'] = !empty($this->closureConfig['validation']);
            }
        }
        
        return $stats;
    }

    public function createCorePipelineWithConfig(array $middlewareClasses = [], ?array $closureConfig = null): CoreMiddlewarePipeline
    {
        $pipeline = new CoreMiddlewarePipeline();
        
        $classes = empty($middlewareClasses) ? $this->defaultCoreMiddleware : $middlewareClasses;
        $config = $closureConfig ?? $this->closureConfig;
        
        foreach ($classes as $middlewareClass) {
            if ($middlewareClass === ClosureMiddleware::class && $config !== null) {
                $middleware = $this->createCustomClosureMiddleware($config);
            } else {
                $middleware = $this->container->get($middlewareClass);
            }
            
            $pipeline->pipe($middleware);
            
            if (isset($this->defaultCorePriorities[$middlewareClass])) {
                $pipeline->setPriority($middlewareClass, $this->defaultCorePriorities[$middlewareClass]);
            }
        }
        
        return $pipeline;
    }

    private function createCustomClosureMiddleware(array $config): ClosureMiddleware
    {
        $middleware = new ClosureMiddleware();
        
        if (isset($config['security'])) {
            $middleware->setSecurityConfig($config['security']);
        }
        
        if (isset($config['validation'])) {
            $middleware->setValidationRules($config['validation']);
        }
        
        return $middleware;
    }

    public function initAppPipeline(): void
    {
        if ($this->appPipeline !== null) {
            return;
        }

        $configPath = YADRO_PHP__ROOT_DIR . '/configs/middleware/app.php';
        
        try {
            $configurer = new AppMiddlewareConfig($configPath);
            $this->appPipeline = new AppMiddlewarePipeline($this->container, $configurer);
        } catch (Exception $e) {
            error_log('App middleware config not found, using empty pipeline');
            $this->appPipeline = new AppMiddlewarePipeline(
                $this->container, 
                $this->createEmptyConfigurer($configPath)
            );
        }
    }

    private function createEmptyConfigurer(string $path): AppMiddlewareConfig
    {
        return new AppMiddlewareConfig($path);
    }

    public function process(MessageBusInterface $messageBus): MessageBusInterface
    {
        $messageBus = $this->corePipeline->process($messageBus);
        
        if ($this->isPipelineStopped($messageBus)) {
            return $messageBus;
        }
        
        $this->initAppPipeline();
        $messageBus = $this->appPipeline->process($messageBus);
        
        if ($this->isPipelineStopped($messageBus)) {
            return $messageBus;
        }
        
        if ($this->useClosureMiddleware && $this->closureMiddleware !== null) {
            $messageBus = $this->closureMiddleware->process($messageBus);
        }
        
        return $messageBus;
    }

    public function createCombinedPipeline(array $coreMiddleware = [], array $appMiddleware = []): CombinedPipeline
    {
        $combined = new CombinedPipeline();
        
        $corePipeline = $this->createCorePipeline($coreMiddleware);
        $combined->addPipeline($corePipeline);
        
        $appPipeline = $this->createAppPipeline($appMiddleware);
        $combined->addPipeline($appPipeline);
        
        return $combined;
    }

    public function createCorePipeline(array $middlewareClasses = []): CoreMiddlewarePipeline
    {
        $pipeline = new CoreMiddlewarePipeline();
        
        $classes = empty($middlewareClasses) ? $this->defaultCoreMiddleware : $middlewareClasses;
        
        foreach ($classes as $middlewareClass) {
            $middleware = $this->getMiddlewareInstance($middlewareClass);
            $pipeline->pipe($middleware);
            
            if (isset($this->defaultCorePriorities[$middlewareClass])) {
                $pipeline->setPriority($middlewareClass, $this->defaultCorePriorities[$middlewareClass]);
            }
        }
        
        return $pipeline;
    }

    public function createAppPipeline(array $middlewareClasses = []): AppMiddlewarePipeline
    {
        $this->initAppPipeline();
        
        if (empty($middlewareClasses)) {
            return $this->appPipeline;
        }
        
        $configurer = $this->createCustomConfigurer($middlewareClasses);
        return new AppMiddlewarePipeline($this->container, $configurer);
    }

    private function createCustomConfigurer(array $middlewareClasses): AppMiddlewareConfig
    {
        $config = [];
        foreach ($middlewareClasses as $index => $middlewareClass) {
            $config[$middlewareClass] = [
                'enabled' => true,
                'priority' => 100 - $index,
            ];
        }
        
        return new AppMiddlewareConfig(YADRO_PHP__ROOT_DIR . '/configs/middleware/app.php');
    }

    private function isPipelineStopped(MessageBusInterface $messageBus): bool
    {
        return $messageBus->has('_pipeline_stopped') && 
               $messageBus->get('_pipeline_stopped') === true;
    }

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->corePipeline->pipe($middleware);
        return $this;
    }

    public function prepend(MiddlewareInterface $middleware): self
    {
        $this->corePipeline->prepend($middleware);
        return $this;
    }

    public function setPriority(string $middlewareClass, int $priority): self
    {
        $this->corePipeline->setPriority($middlewareClass, $priority);
        return $this;
    }

    public function clear(): self
    {
        $this->corePipeline->clear();
        if ($this->appPipeline) {
            $this->appPipeline->clear();
        }
        return $this;
    }

    public function has(string $middlewareClass): bool
    {
        return $this->corePipeline->has($middlewareClass) || 
               ($this->appPipeline && $this->appPipeline->has($middlewareClass));
    }

    public function remove(string $middlewareClass): self
    {
        $this->corePipeline->remove($middlewareClass);
        if ($this->appPipeline) {
            $this->appPipeline->remove($middlewareClass);
        }
        return $this;
    }

    public function count(): int
    {
        $count = $this->corePipeline->count();
        if ($this->appPipeline) {
            $count += $this->appPipeline->count();
        }
        return $count;
    }

    public function getMiddleware(): array
    {
        $middleware = $this->corePipeline->getMiddleware();
        if ($this->appPipeline) {
            $middleware = array_merge($middleware, $this->appPipeline->getMiddleware());
        }
        return $middleware;
    }

    public function getStatistics(): array
    {
        $this->initAppPipeline();
        
        return [
            'core_middleware' => [
                'count' => $this->corePipeline->count(),
                'list' => array_map('get_class', $this->corePipeline->getMiddleware()),
            ],
            'app_middleware' => [
                'count' => $this->appPipeline->count(),
                'list' => array_map('get_class', $this->appPipeline->getMiddleware()),
            ],
            'closure_middleware' => $this->getClosureStats(),
            'total_middleware' => $this->count(),
        ];
    }
}