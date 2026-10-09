<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection as Database;
final class Review
{
    public function create(int $bookingId,int $guestId,int $rating,string $comment): bool { $db=Database::connection();$s=$db->prepare('CALL `sp_review_create`(?,?,?,?)');$s->execute([$rating,trim($comment),$bookingId,$guestId]);return $s->rowCount()===1; }
}

