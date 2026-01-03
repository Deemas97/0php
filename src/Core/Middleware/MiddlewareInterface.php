<?php
namespace Core\Middleware;

use Core\MessageBus\MessageBusInterface;

interface MiddlewareInterface
{
    public function process(MessageBusInterface $messageBus): MessageBusInterface;
}