<?php
namespace Core\Router;

class Route implements RouteInterface
{
    private array $parameters = [];

    public function __construct(
        private readonly string $routeName,
        private readonly string $httpMethod,
        private readonly string $controllerName,
        private readonly string $methodName
    )
    {}

    public function getRouteName(): string
    {
        return $this->routeName;
    }

    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    public function getControllerName(): string
    {
        return $this->controllerName;
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

    public function setMethodName(string $methodName): void
    {
        $this->methodName = $methodName;
    }

    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }
    
    public function getParameters(): array
    {
        return $this->parameters;
    }
    
    public function getParameter(string $name, $default = null)
    {
        return ($this->parameters[$name] ?? $default);
    }
}
