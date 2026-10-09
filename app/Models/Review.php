<?php
declare(strict_types=1);
namespace App\Models;
use Core\Database;
final class Review
{
    public function create(int $bookingId,int $guestId,int $rating,string $comment): bool { $db=Database::connection();$s=$db->prepare("INSERT INTO reviews(booking_id,rating,comment,moderation_status,created_at,updated_at) SELECT b.id,?,?,'visible',NOW(6),NOW(6) FROM bookings b WHERE b.id=? AND b.guest_id=? AND b.status='completed' AND NOT EXISTS(SELECT 1 FROM reviews WHERE booking_id=b.id)");$s->execute([$rating,trim($comment),$bookingId,$guestId]);return $s->rowCount()===1; }
}

