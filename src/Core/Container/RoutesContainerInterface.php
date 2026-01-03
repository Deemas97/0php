<?php
namespace Core\Container;

use Core\Container\ContainerInterface;
use Core\Router\RouteInterface;

interface RoutesContainerInterface extends ContainerInterface
{
    public function set(string $id, RouteInterface $route): void;
}
