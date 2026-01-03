<?php
namespace Core\Router;

interface RouteInterface
{
    public function getRouteName(): string;
    public function getControllerName(): string;
    public function getMethodName(): string;
    public function setMethodName(string $methodName): void;
    public function getHttpMethod(): string;
    
    public function setParameters(array $parameters): void;
    public function getParameters(): array;
    public function getParameter(string $name, $default = null);
}
