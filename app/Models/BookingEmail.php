<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection;

final class BookingEmail
{
    private ProcedureConnection $db;
    public function __construct(){$this->db=ProcedureConnection::connection();}
    public function lock(bool $acquire): bool
    {
        $query=$this->db->prepare('CALL NMT_sp_booking_email_lock(?)');$query->execute([$acquire]);
        return (int)$query->fetchColumn()===1;
    }
    public function pending(int $limit): array
    {
        $query=$this->db->prepare('CALL DST_sp_booking_email_pending(?)');$query->execute([$limit]);return $query->fetchAll();
    }
    public function attempt(int $id): bool
    {
        $query=$this->db->prepare('CALL LTP_sp_booking_email_attempt(?)');$query->execute([$id]);return (int)$query->fetchColumn()===1;
    }
    public function finish(int $id,bool $sent,bool $terminal=false,string $reason='transport_failed'): void
    {
        $this->db->prepare('CALL NTK_sp_booking_email_finish(?,?,?,?)')->execute([$id,$sent,$terminal,$reason]);
    }
}
