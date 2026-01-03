<?php
namespace Core\Router;

use Core\Container\RoutesContainer;
use Core\MessageBus\MessageBusInterface;

interface RouterInterface
{
    public function initMessageBus(): MessageBusInterface;
    public function setCurrentRoute(RouteInterface $route): void;
    public function getCurrentRoute(): RouteInterface;
    public function getRoutes(): RoutesContainer;
}