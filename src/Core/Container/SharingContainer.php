<?php
namespace Core\Container;

class SharingContainer implements SharingContainerInterface
{
    protected array $components = [];

    public function get(string $id)
    {
        return (isset($this->components[$id]) ? $this->components[$id] : null);
    }

    public function has(string $id): bool
    {
        return isset($this->components[$id]);
    }

    public function set(string $id, $item): void
    {
        $this->components[$id] = $item;
    }
}