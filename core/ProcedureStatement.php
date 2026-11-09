<?php
declare(strict_types=1);
namespace Core;

use PDO;
use PDOStatement;
use PDOException;
use DomainException;

/** Materializes a CALL result and drains every rowset before another CALL. */
final class ProcedureStatement
{
    private array $rows = [];
    private int $position = 0;
    private int $affected = 0;
    public function __construct(private PDOStatement $statement, private ProcedureConnection $connection) {}
    public function bindValue(int|string $parameter, mixed $value, ?int $type = null): bool
    {
        $type ??= $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : (is_bool($value) ? PDO::PARAM_BOOL : PDO::PARAM_STR));
        return $this->statement->bindValue($parameter, $value, $type);
    }
    public function execute(?array $parameters = null): bool
    {
        $this->rows = []; $this->position = 0; $this->affected = 0;
        if ($parameters !== null) {
            foreach (array_values($parameters) as $index => $value) { $this->bindValue($index + 1, $value); }
        }
        try {
            $this->statement->execute();
            do {
                if ($this->statement->columnCount() === 0) { continue; }
                $rows = $this->statement->fetchAll(PDO::FETCH_ASSOC);
                if (isset($rows[0]['__affected'])) {
                    $this->affected = (int) $rows[0]['__affected'];
                    $this->connection->rememberInsertId((string) $rows[0]['__insert_id']);
                } else { array_push($this->rows, ...$rows); }
            } while ($this->statement->nextRowset());
            return true;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '45000') { throw new DomainException($exception->errorInfo[2] ?? 'Thao tác không hợp lệ.', 0, $exception); }
            throw $exception;
        } finally { $this->statement->closeCursor(); }
    }
    public function fetch(): array|false { return $this->rows[$this->position++] ?? false; }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : (array_values($row)[$column] ?? false);
    }
    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array
    {
        $rows = array_slice($this->rows, $this->position); $this->position = count($this->rows);
        return $mode === PDO::FETCH_COLUMN ? array_map(static fn(array $row) => array_values($row)[0], $rows) : $rows;
    }
    public function rowCount(): int { return $this->affected; }
    public function closeCursor(): bool { $this->rows = []; $this->position = 0; return true; }
}
