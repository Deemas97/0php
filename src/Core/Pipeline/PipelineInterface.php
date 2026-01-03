<?php
namespace Core\Pipeline;

use Core\MessageBus\MessageBusInterface;
use Core\Middleware\MiddlewareInterface;

interface PipelineInterface
{
    public function process(MessageBusInterface $messageBus): MessageBusInterface;
    public function pipe(MiddlewareInterface $middleware): self;
    public function prepend(MiddlewareInterface $middleware): self;
    public function clear(): self;
    public function has(string $middlewareClass): bool;
    public function remove(string $middlewareClass): self;
    public function count(): int;
    public function getMiddleware(): array;
}