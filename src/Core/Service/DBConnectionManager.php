<?php
namespace Core\Service;

use Bootstrap\Config\DotEnv;
use Infrastructure\DataBase\DBConnectorInterface;
use Infrastructure\DataBase\MySQLConnector;
use RuntimeException;

class DBConnectionManager implements CoreServiceInterface
{
    private $connections = [];
    private $defaultConnection = null;

    public function __construct()
    {
        $this->initDefaultConnection();
    }

    public function __destruct()
    {
        $this->closeAll();
    }

    public function initDefaultConnection(): DBConnectorInterface
    {
        if (isset($this->connections['default'])) {
            throw new RuntimeException("Connection with name 'default' already exists");
        }

        $env = DotEnv::getData();

        $connectorDefault = new MySQLConnector();
        $connectorDefault->init(
            $env['DB_HOST'],
            $env['DB_USERNAME'],
            $env['DB_PASSWORD'],
            $env['DB_DATABASE']
        );

        $this->connections['default'] = $connectorDefault;
        $this->defaultConnection = 'default';

        return $connectorDefault;
    }

    public function getConnection($name = null): DBConnectorInterface
    {
        $name = $name ?? $this->defaultConnection;

        if (!isset($this->connections[$name])) {
            throw new RuntimeException("Connection with name '{$name}' not found");
        }

        return $this->connections[$name];
    }

    public function setDefaultConnection($name): void
    {
        if (!isset($this->connections[$name])) {
            throw new RuntimeException("Connection with name '{$name}' not found");
        }

        $this->defaultConnection = $name;
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        $this->defaultConnection = null;
    }

    public function closeConnection($name): void
    {
        if (!isset($this->connections[$name])) {
            throw new RuntimeException("Connection with name '{$name}' not found");
        }

        $this->connections[$name]->close();
        unset($this->connections[$name]);

        if ($this->defaultConnection === $name) {
            $this->defaultConnection = !empty($this->connections) ? array_key_first($this->connections) : null;
        }
    }

    public function hasConnection($name): bool
    {
        return isset($this->connections[$name]);
    }

    public function listConnections(): array
    {
        return array_keys($this->connections);
    }
}