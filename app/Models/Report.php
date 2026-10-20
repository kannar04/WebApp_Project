<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection;

final class Report
{
    public function create(int $actorId,?int $listingId,?int $bookingId,string $category,string $description): void
    {
        ProcedureConnection::connection()->prepare('CALL sp_report_create(?,?,?,?,?)')->execute([$actorId,$listingId,$bookingId,$category,$description]);
    }
    public function forUser(int $actorId): array
    {
        $s=ProcedureConnection::connection()->prepare('CALL sp_reports_for_user(?)'); $s->execute([$actorId]); return $s->fetchAll();
    }
    public function forAdmin(int $actorId): array
    {
        $s=ProcedureConnection::connection()->prepare('CALL sp_admin_reports(?)'); $s->execute([$actorId]); return $s->fetchAll();
    }
    public function resolve(int $id,int $actorId,string $status,string $resolution): void
    {
        ProcedureConnection::connection()->prepare('CALL sp_admin_report_resolve(?,?,?,?)')->execute([$id,$actorId,$status,$resolution]);
    }
}
