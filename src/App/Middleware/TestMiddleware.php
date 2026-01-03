<?php
namespace App\Middleware;

use Core\Middleware\AppMiddlewareInterface;
use Core\MessageBus\MessageBusInterface;
use Dev\Dumper;

class TestMiddleware implements AppMiddlewareInterface
{
    public function process(MessageBusInterface $messageBus): MessageBusInterface
    {
        Dumper::dump('Hello World!');
        
        return $messageBus;
    }
}