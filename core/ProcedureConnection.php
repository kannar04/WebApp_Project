<?php
declare(strict_types=1);
namespace Core;

use PDO;
use InvalidArgumentException;

/** CALL-only adapter. Transactions use the same PDO connection as controllers. */
final class ProcedureConnection
{
    private PDO $pdo;
    private string $insertId = '0';
    private function __construct() { $this->pdo = Database::connection(); }
    public static function connection(): self { return new self(); }
    public function prepare(string $call): ProcedureStatement
    {
        // No identifiers or SQL fragments supplied by a request are accepted.
        if (!preg_match('/^CALL (?:`[A-Za-z_][A-Za-z_0-9 ]*`|[A-Za-z_][A-Za-z_0-9]*)\((?:\?(?:,\?)*)?\)$/D', $call)) {
            throw new InvalidArgumentException('Database access requires a literal procedure CALL.');
        }
        return new ProcedureStatement($this->pdo->prepare($call), $this);
    }
    public function query(string $call): ProcedureStatement
    {
        $statement = $this->prepare($call);
        $statement->execute();
        return $statement;
    }
    public function rememberInsertId(string $id): void { $this->insertId = $id; }
    public function lastInsertId(): string { return $this->insertId; }
    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollBack(): bool { return $this->pdo->rollBack(); }
    public function inTransaction(): bool { return $this->pdo->inTransaction(); }
}
