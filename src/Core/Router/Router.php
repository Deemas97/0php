<?php
namespace Core\Router;

use Core\Config\RoutesConfig;
use Core\Router\Route;
use Core\Router\RouteInterface;
use Core\Container\RoutesContainer;
use Core\Service\ServiceInterface;
use Core\Service\ServiceProviderInterface;

use App\Service\RouterMediator;
use Core\MessageBus\MessageBusInterface;
use Core\MessageBus\Request;
use Infrastructure\Http\ServerData;

class Router implements RouterInterface, ServiceProviderInterface
{
    private const MAX_EXECUTION_TIME = 0.1;
    private const MAX_URI_LENGTH = 2048;
    private const MAX_PATH_SEGMENTS = 32;
    private const MAX_PATTERN_LENGTH = 512;
    private const PATTERN_COMPLEXITY_LIMIT = 10;

    private ?RouteInterface $currentRoute = null;
    private array $staticRoutes = [];
    private array $dynamicRoutes = [];

    public function __construct(
        private readonly ServerData      $serverData,
        private readonly RoutesConfig    $config,
        private readonly RoutesContainer $routes
    )
    {
        $this->initRoutes();
        $this->categorizeRoutes();
    }

    public function initMessageBus(): MessageBusInterface
    {
        $messageBus = new Request();

        $messageBus->set('request_time', microtime(true));
        $messageBus->set('request_id',   uniqid('req_', true));

        $route = $this->resolve(
            explode('?', $this->serverData->getUri())[0],
            ($this->serverData->getMethod() ?? 'GET'),
            $this->serverData->isAjax()
        );

        $messageBus->set('route_controller_name',  $route->getControllerName());
        $messageBus->set('route_method_name',      $route->getMethodName());
        $messageBus->set('route_http_method',      $route->getHttpMethod());
        $messageBus->set('route_parameters',       $route->getParameters());
        $messageBus->set('route_query_string',     $this->serverData->getQueryString());
        
        return $messageBus;
    }

    private function resolve(
        string $requestUri,
        string $httpMethod,
        bool   $isXhr
    ): ?RouteInterface
    {
        if (strlen($requestUri) > self::MAX_URI_LENGTH) {
            return $this->getErrorRoute('414');
        }

        $uri = trim(strtok($requestUri, '?'), '/');
        
        if (substr_count($uri, '/') > self::MAX_PATH_SEGMENTS) {
            return $this->getErrorRoute('414');
        }

        $normalizedUri = '/' . $uri;

        if (isset($this->staticRoutes[$normalizedUri])) {
            $this->setCurrentRoute($this->staticRoutes[$normalizedUri]);
            return $this->checkHttpMethod($httpMethod, $this->staticRoutes[$normalizedUri], $isXhr);
        }

        foreach ($this->dynamicRoutes as $pattern => $route) {
            $normalizedPattern = trim($pattern, '/');
            
            if (!$this->isPatternSafe($normalizedPattern)) {
                continue;
            }
            
            if ($this->matchDynamicRoute($normalizedPattern, $uri, $matches)) {
                $filteredMatches = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $route->setParameters($filteredMatches);
                $this->setCurrentRoute($route);

                return $this->checkHttpMethod($httpMethod, $route, $isXhr);
            }
        }

        return $this->getErrorRoute('404');
    }

    public function setCurrentRoute(RouteInterface $route): void
    {
        $this->currentRoute = $route;
    }
    
    public function getCurrentRoute(): RouteInterface
    {
        return $this->currentRoute;
    }

    public function getRoutes(): RoutesContainer
    {
        return $this->routes;
    }

    public function getMediatorServiceName(): string
    {
        return RouterMediator::class;
    }

    public function getMediatorService(): ServiceInterface
    {
        return new RouterMediator($this);
    }

    public function getHealthStatus(): array
    {
        return [
            'static_routes_count' => count($this->staticRoutes),
            'dynamic_routes_count' => count($this->dynamicRoutes),
            'memory_usage' => memory_get_usage(true),
            'last_cleanup' => date('Y-m-d H:i:s')
        ];
    }

    private function initRoutes(): void
    {
        while (($routeData = $this->config->extractRouteData()) !== null) {
            if (strpos($routeData['path'], '{') !== false && !$this->isPatternSafe($routeData['path'])) {
                error_log("Unsafe route pattern detected: " . $routeData['path']);
                continue;
            }

            $route = new Route(
                $routeData['path'],
                $routeData['http_method'],
                $routeData['controller'],
                $routeData['controller_method']
            );

            if (isset($routeData['parameters'])) {
                $route->setParameters($routeData['parameters']);
            }
            
            $this->routes->set($routeData['path'], $route);

            if (strpos($routeData['path'], '{') !== false) {
                $this->dynamicRoutes[$routeData['path']] = $route;
            } else {
                $this->staticRoutes[$routeData['path']] = $route;
            }
        }
    }

    private function categorizeRoutes(): void
    {
        foreach ($this->routes->getAll() as $pattern => $route) {
            if (strpos($pattern, '{') !== false) {
                $this->dynamicRoutes[$pattern] = $route;
            } else {
                $this->staticRoutes[$pattern] = $route;
            }
        }
    }

    private function matchDynamicRoute(string $pattern, string $uri, &$matches): bool
    {
        $startTime = microtime(true);
        
        $regex = $this->convertPatternToRegex($pattern);
        
        $result = (preg_match($regex, $uri, $matches) === 1);
        
        $executionTime = microtime(true) - $startTime;
        if ($executionTime > self::MAX_EXECUTION_TIME) {
            error_log("Potential ReDoS attack detected for pattern: $pattern, URI: $uri, time: " . $executionTime);
            return false;
        }
        
        return $result;
    }

    private function convertPatternToRegex(string $pattern): string
    {
        $pattern = preg_quote($pattern, '@');
        $pattern = str_replace(['\{', '\}'], ['{', '}'], $pattern);
        
        $regex = preg_replace('/\{([a-z]+)\}/', '(?P<$1>[^\/]{1,255})', $pattern);
        
        return ('@^' . $regex . '$@D');
    }

    private function checkHttpMethod(
        string $httpMethod,
        RouteInterface $route,
        bool $isXhr
    ): RouteInterface
    {
        if ($httpMethod === $route->getHttpMethod()) {
            return $route;
        } else {
            if ($isXhr === true) {
                $route = $this->routes->get('/error406');
            } else {
                $route = $this->routes->get('/error405');
            }

            return $route;
        }
    }

    private function isPatternSafe(string $pattern): bool
    {
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return false;
        }

        $parameterCount = substr_count($pattern, '{');
        if ($parameterCount > self::PATTERN_COMPLEXITY_LIMIT) {
            return false;
        }

        if (preg_match('/\{[^{}]*\{/', $pattern)) {
            return false;
        }

        $segmentCount = substr_count($pattern, '/');
        if ($segmentCount > self::MAX_PATH_SEGMENTS) {
            return false;
        }

        return true;
    }

    private function getErrorRoute(string $errorCode): RouteInterface
    {
        $routeMap = [
            '400' => '/error_400',
            '404' => '/error_404',
            '405' => '/error_405',
            '406' => '/error_406',
            '414' => '/error_414'
        ];

        $routePath = $routeMap[$errorCode] ?? '/error_400';
        $route = $this->routes->get($routePath);
        $this->setCurrentRoute($route);
        
        return $route;
    }
}