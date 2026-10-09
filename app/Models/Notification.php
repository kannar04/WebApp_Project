<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection;

final class Notification
{
    public function forUser(int $actorId): array
    {
        $s=ProcedureConnection::connection()->prepare('CALL sp_notifications_list(?)'); $s->execute([$actorId]); return $s->fetchAll();
    }
    public function markRead(int $id,int $actorId): void
    {
        ProcedureConnection::connection()->prepare('CALL sp_notification_read(?,?)')->execute([$id,$actorId]);
    }
}
