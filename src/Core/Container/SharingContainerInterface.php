<?php
namespace Core\Container;

interface SharingContainerInterface extends ContainerInterface
{
    public function set(string $id, $item): void;
}