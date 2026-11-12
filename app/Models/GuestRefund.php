<?php
declare(strict_types=1);
namespace App\Models;

use Core\Database;
use PDO;

final class GuestRefund
{
    private PDO $db;
    public function __construct(){ $this->db=Database::connection(); }
    public function account(int $guestId): ?array{$s=$this->db->prepare('CALL DST_sp_guest_refund_account_get(?)');$s->execute([$guestId]);$row=$s->fetch()?:null;$s->closeCursor();return $row;}
    public function saveAccount(int $guestId,array $data): void{$s=$this->db->prepare('CALL LTP_sp_guest_refund_account_save(?,?,?,?,?)');$s->execute([$guestId,$data['bank_id'],$data['bank_name'],$data['account_no'],$data['account_name']]);$s->closeCursor();}
    public function guestRefunds(int $guestId): array{$s=$this->db->prepare('CALL NTK_sp_guest_refunds(?)');$s->execute([$guestId]);$rows=$s->fetchAll();$s->closeCursor();return $rows;}
    public function adminAccounts(int $adminId,string $status): array{$s=$this->db->prepare('CALL NMT_sp_admin_refund_accounts(?,?)');$s->execute([$adminId,$status]);$rows=$s->fetchAll();$s->closeCursor();return $rows;}
    public function decideAccount(int $id,int $adminId,string $decision,string $reason): void{$s=$this->db->prepare('CALL NTK_sp_admin_refund_account_decide(?,?,?,?)');$s->execute([$id,$adminId,$decision,$reason]);$s->closeCursor();}
    public function adminRefunds(int $adminId): array{$s=$this->db->prepare('CALL DST_sp_admin_guest_refunds(?)');$s->execute([$adminId]);$rows=$s->fetchAll();$s->closeCursor();return $rows;}
    public function create(int $bookingId,int $adminId,string $reason,?string $shownAmount): array{$s=$this->db->prepare('CALL LTP_sp_admin_guest_refund_create(?,?,?,?)');$s->execute([$bookingId,$adminId,$reason,$shownAmount]);$row=$s->fetch()?:[];$s->closeCursor();return $row;}
    public function markPaid(int $id,int $adminId,string $transactionNo,string $note): void{$s=$this->db->prepare('CALL NMT_sp_admin_guest_refund_mark_paid(?,?,?,?)');$s->execute([$id,$adminId,$transactionNo,$note]);$s->closeCursor();}
}
