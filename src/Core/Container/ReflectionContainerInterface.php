<?php
namespace Core\Container;

interface ReflectionContainerInterface extends ContainerInterface
{
    public function set(string $id, $item): void;
}