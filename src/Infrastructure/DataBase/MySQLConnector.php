<?php

namespace Infrastructure\DataBase;

use Infrastructure\DataBase\DBConnectorInterface;
use mysqli;
use RuntimeException;

class MySQLConnector implements DBConnectorInterface
{
    private ?mysqli $connection = null;
    private $lastError = null;

    public function init(
        string $host,
        string $username,
        string $password,
        string $database,
        int $port = 3306,
        string $charset = 'utf8mb4'
    ): void
    {
        $this->connection = new mysqli($host, $username, $password, $database, $port);

        if ($this->connection->connect_error) {
            $this->lastError = $this->connection->connect_error;
            throw new RuntimeException("Connection failed: " . $this->lastError);
        }

        $this->connection->set_charset($charset);
    }

    public function query(string $sql): array|bool
    {
        $this->lastError = null;
        $result = $this->connection->query($sql);

        if ($result === false) {
            $this->lastError = $this->connection->error;
            return false;
        }

        if ($result === true) {
            return true;
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    public function escape(string $value): string
    {
        return $this->connection->real_escape_string($value);
    }

    public function getLastInsertId(): int
    {
        return $this->connection->insert_id;
    }

    public function getAffectedRows(): int
    {
        if (!$this->connection) {
            throw new RuntimeException("Database connection is not established");
        }

        $affectedRows = $this->connection->affected_rows;
        
        if ($affectedRows < 0) {
            $this->lastError = $this->connection->error;
            throw new RuntimeException("Error getting affected rows: " . $this->lastError);
        }

        return $affectedRows;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function beginTransaction(): bool
    {
        return $this->connection->begin_transaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollback(): bool
    {
        return $this->connection->rollback();
    }

    public function close(): void
    {
        if ($this->connection) {
            $this->connection->close();
            unset($this->connection);
        }
    }
}