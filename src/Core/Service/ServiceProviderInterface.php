<?php
namespace Core\Service;

use Core\Service\ServiceInterface;

interface ServiceProviderInterface
{
    public function getMediatorServiceName(): string;
    public function getMediatorService(): ServiceInterface;
}