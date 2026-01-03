<?php
namespace Core\MessageBus;

class Actions implements ActionsInterface
{
    private array $data = [];

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function getAll(): array
    {
        return $this->data;
    }

    public function set(string $id, $value): void
    {
        $this->data[$id] = $value;
    }

    public function reset():void
    {
        $this->data = [];
    }

    public function has(string $id): bool
    {
        return isset($data[$id]);
    }
}
