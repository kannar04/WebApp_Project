<?php

declare(strict_types=1);

namespace App\Models;

use Core\ProcedureConnection as Database;

final class Booking
{
    private \Core\ProcedureConnection $db;
    public function __construct() { $this->db = Database::connection(); }

    public function forGuest(int $guestId): array
    {
        $s=$this->db->prepare('CALL `NTK_sp_get_guest_bookings`(?)');
        $s->execute([$guestId]); return $s->fetchAll();
    }

    public function forHost(int $hostId): array
    {
        $s=$this->db->prepare('CALL `NTK_sp_get_host_bookings`(?)');
        $s->execute([$hostId]); return $s->fetchAll();
    }

    public function findForUser(int $id, int $userId, bool $isAdmin=false): ?array
    {
        $statement = $this->db->prepare('CALL sp_booking_find_for_user(?,?,?)');
        $statement->execute([$id, $userId, $isAdmin]);
        return $statement->fetch() ?: null;
    }

    public function all(): array
    {
        return $this->db->query('CALL `sp_booking_get_all`()')->fetchAll();
    }
}

