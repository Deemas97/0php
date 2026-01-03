<?php
namespace Core\Middleware;

interface CoreMiddlewareInterface extends MiddlewareInterface
{
    public const PRIORITY_HIGH = 100;
    public const PRIORITY_MEDIUM = 50;
    public const PRIORITY_LOW = 10;
    
    // public function getPriority(): int;
    // public function isEssential(): bool; // Можно ли отключить
}
