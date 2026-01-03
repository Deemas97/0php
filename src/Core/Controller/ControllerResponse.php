<?php
namespace Core\Controller;

class ControllerResponse implements ControllerResponseInterface
{
    protected array $data = [];
    protected int $statusCode = 200;

    public function get(string $id)
    {
        return $this->data[$id] ?? null;
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

    public function setStatusCode(int $code): void
    {
        $this->statusCode = $code;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}