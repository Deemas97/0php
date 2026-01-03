<?php
namespace Infrastructure\DataBase;

interface DBConnectorInterface
{
    public function init(
        string $host,
        string $username,
        string $password,
        string $database,
        int $port = 3306,
        string $charset = 'utf8mb4'
    ): void;

    public function query(string $query);

    public function escape(string $value);

    public function getLastInsertId(): int;

    public function getLastError(): ?string;
}