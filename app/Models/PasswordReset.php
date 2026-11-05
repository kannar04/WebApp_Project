<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection;

final class PasswordReset
{
    public function issue(int $userId,string $rawToken): void
    {
        ProcedureConnection::connection()->prepare('CALL sp_password_reset_issue(?,?)')->execute([$userId,hash('sha256',$rawToken)]);
    }
    public function consume(string $rawToken,string $password): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$rawToken)) { throw new \DomainException('Liên kết không hợp lệ, đã hết hạn hoặc đã sử dụng.'); }
        if (!\Core\Validator::passwordAllowed($password)) { throw new \DomainException(\Core\Validator::PASSWORD_RULE); }
        ProcedureConnection::connection()->prepare('CALL sp_password_reset_consume(?,?)')->execute([hash('sha256',$rawToken),password_hash($password,PASSWORD_DEFAULT)]);
    }
}
