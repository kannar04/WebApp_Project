<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection as Database;
final class Review
{
    public function forHost(int $actorId): array
    {
        $s=\Core\ProcedureConnection::connection()->prepare('CALL sp_host_reviews(?)'); $s->execute([$actorId]); return $s->fetchAll();
    }
    public function reply(int $id,int $actorId,string $reply): void
    {
        \Core\ProcedureConnection::connection()->prepare('CALL sp_host_review_reply(?,?,?)')->execute([$id,$actorId,$reply]);
    }
    public function forAdmin(int $actorId): array
    {
        $s=\Core\ProcedureConnection::connection()->prepare('CALL sp_admin_reviews(?)'); $s->execute([$actorId]); return $s->fetchAll();
    }
    public function moderate(int $id,int $actorId,string $status): void
    {
        \Core\ProcedureConnection::connection()->prepare('CALL sp_admin_review_moderate(?,?,?)')->execute([$id,$actorId,$status]);
    }
    public function create(int $bookingId,int $guestId,int $rating,string $comment): bool { $db=Database::connection();$s=$db->prepare('CALL `sp_review_create`(?,?,?,?)');$s->execute([$rating,trim($comment),$bookingId,$guestId]);return $s->rowCount()===1; }
}

