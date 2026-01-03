<?php
namespace Core\MessageBus;

interface MessageBusInterface
{
    public function get(string $id);
    public function getAll();
    public function set(string $id, $value): void;
    public function has(string $id): bool;
}