<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection;
final class HostRequest
{
    public function status(int $user): ?array {
        try {
            $s=ProcedureConnection::connection()->prepare('CALL NMT_sp_host_request_status(?)'); $s->execute([$user]); return $s->fetch() ?: null;
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1305) { return null; }
            throw $e;
        }
    }
    public function submit(int $user, bool $debug): void {
        try {
            ProcedureConnection::connection()->prepare('CALL NMT_sp_host_request_submit(?,?)')->execute([$user,$debug]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1305) { throw new \DomainException('Host requests are not available until the database migration is installed.'); }
            throw $e;
        }
    }
    public function all(int $admin): array {
        $s=ProcedureConnection::connection()->prepare('CALL NMT_sp_admin_host_requests(?)'); $s->execute([$admin]); return $s->fetchAll();
    }
    public function decide(int $admin,int $user,string $decision,string $reason): void {
        if(mb_strlen($reason)>1000){throw new \DomainException('Reason must be at most 1000 characters.');}
        ProcedureConnection::connection()->prepare('CALL NMT_sp_admin_host_request_decide(?,?,?,?,?)')->execute([$admin,$user,$decision,$reason,\App\Services\DebugVerification::available()]);
    }
}
