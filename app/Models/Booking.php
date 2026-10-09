<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use PDO;

final class Booking
{
    private PDO $db;
    public function __construct() { $this->db = Database::connection(); }

    public function forGuest(int $guestId): array
    {
        $s=$this->db->prepare("SELECT b.*,l.title,l.city,(SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.guest_id=? ORDER BY b.created_at DESC");
        $s->execute([$guestId]); return $s->fetchAll();
    }

    public function forHost(int $hostId): array
    {
        $s=$this->db->prepare("SELECT b.*,l.title,u.full_name guest_name,u.phone guest_phone FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id WHERE l.host_id=? ORDER BY b.created_at DESC");
        $s->execute([$hostId]); return $s->fetchAll();
    }

    public function findForUser(int $id, int $userId, bool $isAdmin=false): ?array
    {
        $sql="SELECT b.*,l.title,l.address,l.city,l.host_id,u.full_name guest_name,h.full_name host_name FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id WHERE b.id=?";
        $params=[$id];
        if(!$isAdmin){$sql.=' AND (b.guest_id=? OR l.host_id=?)'; array_push($params,$userId,$userId);}
        $s=$this->db->prepare($sql);$s->execute($params);return $s->fetch()?:null;
    }

    public function all(): array
    {
        return $this->db->query("SELECT b.*,l.title,u.full_name guest_name,h.full_name host_name FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id ORDER BY b.created_at DESC")->fetchAll();
    }
}

