<?php
namespace YadroPHP;

use Bootstrap\Config\DotEnv;
use Bootstrap\Config\ProjectMode;
use Core\Container\GlobalContainer;
use Core\Container\ReflectionContainerInterface;
use Core\Pipeline\Pipeline;
use Core\Pipeline\PipelineInterface;
use Core\Router\Router;
use Core\Router\RouterInterface;
use Core\Service\Configurer;
use Exception;
use RuntimeException;

class Kernel
{
    private bool $isBooted = false;

    protected ReflectionContainerInterface $container;
    protected RouterInterface              $router;
    protected PipelineInterface            $pipeline;

    public function __construct()
    {
        try {
            $this->init();
        } catch (RuntimeException $error) {
            throw new RuntimeException('[Ошибка инициализации приложения]: ' . $error->getMessage());
        }
    }

    final public function handle(): array
    {
        try {
            $messageBus = $this->router->initMessageBus();
            $messageBus = $this->pipeline->process($messageBus);
            return $messageBus->getAll();
        } catch (Exception $e) {
            $this->handleException($e);
            return [];
        }
    }

    protected function handleException(Exception $error): void
    {
        if (php_sapi_name() === 'cli') {
            echo "[Error]: " . $error->getMessage() . PHP_EOL;
        } else {
            error_log($error->getMessage());
        }
    }

    private function init(): void
    {
        if ($this->isBooted === true) {
            throw new RuntimeException('Экземпляр ядра уже создан');
        }

        $this->boot();
        $this->initCore();

        $this->isBooted = true;
    }

    private function boot(): void
    {
        DotEnv::init(YADRO_PHP__ROOT_DIR);
        ProjectMode::init();
        $this->initContainer();
    }

    protected function initContainer(): void
    {
        $this->container = new GlobalContainer();
    }

    private function initCore(): void
    {
        $this->initInfrastructure();
        $this->initRouter();
        $this->initPipeline();
    }

    protected function initInfrastructure(): void
    {
        $this->container->get(Configurer::class)->init();
    }

    protected function initRouter(): void
    {
        $this->router = $this->container->get(Router::class);
    }

    protected function initPipeline(): void
    {
        $pipeline = $this->container->get(Pipeline::class);
        $pipeline->init($this->container);
        $this->pipeline = $pipeline;
    }
}
