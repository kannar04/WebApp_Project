<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection as Database;
final class AdminRepository
{
    public function stats(): array {$db=Database::connection();return ['guests'=>(int)$db->query('CALL sp_admin_count_guests()')->fetchColumn(),'users'=>(int)$db->query('CALL `sp_admin_count_users`()')->fetchColumn(),'hosts'=>(int)$db->query('CALL `sp_admin_count_hosts`()')->fetchColumn(),'listings'=>(int)$db->query('CALL `sp_admin_count_listings`()')->fetchColumn(),'bookings'=>(int)$db->query('CALL `sp_admin_count_bookings`()')->fetchColumn(),'revenue'=>(float)$db->query('CALL `sp_admin_total_revenue`()')->fetchColumn()];}
    public function listings(): array {return Database::connection()->query('CALL `sp_admin_listings`()')->fetchAll();}
    public function moderate(int $listingId, int $adminId, string $action, string $note): void
    {
        $statement = Database::connection()->prepare('CALL sp_admin_listing_moderate(?,?,?,?)');
        $statement->execute([$listingId, $adminId, $action, trim($note)]);
    }

    public function audit(int $adminId,string $action,string $type,?int $id,string $details=''): void {$summary=json_encode(['details'=>$details],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);Database::connection()->prepare('CALL `sp_admin_audit_add`(?,?,?,?,?)')->execute([$adminId,$action,$type,$id??0,$summary]);}
    public function findListing(int $id): ?array {$s=Database::connection()->prepare('CALL `sp_admin_listing_find`(?)');$s->execute([$id]);return $s->fetch()?:null;}
    public function updateListing(int $id,int $adminId,array $data): void {$db=Database::connection();$db->prepare('CALL sp_admin_listing_update(?,?,?,?,?,?,?,?,?)')->execute([$data['title'],$data['address'],$data['city'],$data['description'],$data['nightly_price'],$data['cleaning_fee'],$data['max_guests'],$id,$adminId]);}
    public function setListingVisibility(int $id,int $adminId,bool $visible): void {Database::connection()->prepare('CALL sp_admin_listing_visibility(?,?,?)')->execute([$visible,$id,$adminId]);}
    public function softDeleteListing(int $id,int $adminId): void {Database::connection()->prepare('CALL sp_admin_listing_soft_delete(?,?)')->execute([$id,$adminId]);}
}

