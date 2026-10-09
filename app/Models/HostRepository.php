<?php
declare(strict_types=1);
namespace App\Models;
use Core\Database;
final class HostRepository
{
    public function stats(int $hostId): array {$db=Database::connection();$s=$db->prepare("SELECT COUNT(DISTINCT l.id) listings,COUNT(DISTINCT b.id) bookings,SUM(b.status='completed') completed,COALESCE(SUM(CASE WHEN b.status='completed' THEN b.total_amount ELSE 0 END),0) revenue FROM listings l LEFT JOIN bookings b ON b.listing_id=l.id WHERE l.host_id=? AND l.deleted_at IS NULL");$s->execute([$hostId]);return $s->fetch();}
}

