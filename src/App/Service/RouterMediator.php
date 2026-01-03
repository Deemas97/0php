<?php
namespace App\Service;

use Core\Container\RoutesContainer;
use Core\Service\ServiceInterface;
use Core\Router\Router;
use Core\Router\RouteInterface;

class RouterMediator implements ServiceInterface
{
    public function __construct(
        private Router $router
    )
    {}
    
    public function getCurrentRoute(): ?RouteInterface
    {
        return $this->router->getCurrentRoute();
    }

    public function getRoutes(): RoutesContainer
    {
        return $this->router->getRoutes();
    }
}