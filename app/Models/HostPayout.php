<?php
declare(strict_types=1);
namespace App\Models;

use Core\Database;
use PDO;

final class HostPayout
{
    private PDO $db;
    public function __construct(){ $this->db=Database::connection(); }

    public static function feePercent(): string
    {
        $config=require dirname(__DIR__,2).'/config/payment.php';
        $value=(string)($config['host_payout_fee_percent']??'');
        if(!preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/D',$value)){throw new \RuntimeException('HOST_PAYOUT_FEE_PERCENT must be between 0 and 100 with at most two decimals.');}
        return $value;
    }

    public function account(int $hostId): ?array
    {
        $statement=$this->db->prepare('CALL DST_sp_host_payout_account_get(?)');$statement->execute([$hostId]);
        $row=$statement->fetch()?:null;$statement->closeCursor();return $row;
    }

    public function saveAccount(int $hostId,array $data): void
    {
        $statement=$this->db->prepare('CALL LTP_sp_host_payout_account_save(?,?,?,?,?)');
        $statement->execute([$hostId,$data['bank_id'],$data['bank_name'],$data['account_no'],$data['account_name']]);$statement->closeCursor();
    }

    public function hostSummary(int $hostId): array
    {
        $statement=$this->db->prepare('CALL NMT_sp_host_payout_summary(?,?)');$statement->execute([$hostId,self::feePercent()]);
        $row=$statement->fetch()?:['paid_total'=>0,'pending_total'=>0,'eligible_total'=>0];$statement->closeCursor();return $row;
    }

    public function hostPayouts(int $hostId): array
    {
        $statement=$this->db->prepare('CALL NTK_sp_host_payouts(?)');$statement->execute([$hostId]);$rows=$statement->fetchAll();$statement->closeCursor();return $rows;
    }

    public function adminAccounts(int $adminId,string $status): array
    {
        $statement=$this->db->prepare('CALL NMT_sp_admin_payout_accounts(?,?)');$statement->execute([$adminId,$status]);$rows=$statement->fetchAll();$statement->closeCursor();return $rows;
    }

    public function decideAccount(int $accountId,int $adminId,string $decision,string $reason): void
    {
        $statement=$this->db->prepare('CALL NTK_sp_admin_payout_account_decide(?,?,?,?)');$statement->execute([$accountId,$adminId,$decision,$reason]);$statement->closeCursor();
    }

    public function adminPayouts(int $adminId): array
    {
        $statement=$this->db->prepare('CALL DST_sp_admin_host_payouts(?,?)');$statement->execute([$adminId,self::feePercent()]);$rows=$statement->fetchAll();$statement->closeCursor();return $rows;
    }

    public function createPayout(int $bookingId,int $adminId): array
    {
        $statement=$this->db->prepare('CALL LTP_sp_admin_host_payout_create(?,?,?)');$statement->execute([$bookingId,$adminId,self::feePercent()]);
        $row=$statement->fetch()?:[];$statement->closeCursor();return $row;
    }

    public function markPaid(int $payoutId,int $adminId,string $transactionNo,string $note): void
    {
        $statement=$this->db->prepare('CALL NMT_sp_admin_host_payout_mark_paid(?,?,?,?)');$statement->execute([$payoutId,$adminId,$transactionNo,$note]);$statement->closeCursor();
    }

    public function adminTotals(int $adminId): array
    {
        $statement=$this->db->prepare('CALL NTK_sp_admin_host_payout_totals(?)');$statement->execute([$adminId]);
        $row=$statement->fetch()?:['pending_count'=>0,'pending_amount'=>0,'paid_count'=>0,'paid_amount'=>0];$statement->closeCursor();return $row;
    }
}
