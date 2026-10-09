<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection;

final class Catalog
{
    public function all(int $actorId,string $kind): array
    {
        $s=ProcedureConnection::connection()->prepare('CALL sp_admin_catalog(?,?)'); $s->execute([$actorId,$kind]); return $s->fetchAll();
    }
    public function save(int $actorId,string $kind,int $id,string $name,string $detail,bool $active): int
    {
        $s=ProcedureConnection::connection()->prepare('CALL sp_admin_catalog_save(?,?,?,?,?,?)'); $s->execute([$actorId,$kind,$id,$name,$detail,$active]); return (int)$s->fetchColumn();
    }
}
