<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection as Database;
final class HostRepository
{
    public function stats(int $hostId): array {$db=Database::connection();$s=$db->prepare('CALL `sp_host_stats`(?)');$s->execute([$hostId]);return $s->fetch();}
    public function monthlyRevenue(int $hostId): array
    {
        $s=Database::connection()->prepare('CALL sp_host_monthly_revenue(?)'); $s->execute([$hostId]); return $s->fetchAll();
    }
}

