<?php
declare(strict_types=1);
namespace App\Models;
use Core\ProcedureConnection as Database;
use DomainException;
final class Wishlist
{
    public function toggle(int $userId, int $listingId): bool
    {
        $database = Database::connection();
        $statement = $database->prepare('CALL `sp_wishlist_contains`(?,?)');
        $statement->execute([$userId, $listingId]);
        if ($statement->fetchColumn()) {
            $database->prepare('CALL `NMT_sp_remove_wishlist_item`(?,?)')->execute([$userId, $listingId]);
            return false;
        }
        if (!(new Listing())->findPublic($listingId, false)) {
            throw new DomainException('Chỗ ở không tồn tại hoặc không còn hiển thị.');
        }
        $statement = $database->prepare('CALL `NMT_sp_add_wishlist_item`(?,?)');
        $statement->execute([$userId, $listingId]);
        if (!(bool) $statement->fetchColumn()) { throw new DomainException('Chỗ ở không còn khả dụng.'); }
        return true;
    }
    public function ids(int $userId): array { $s=Database::connection()->prepare('CALL `sp_wishlist_ids`(?)');$s->execute([$userId]);return array_map('intval',$s->fetchAll(\PDO::FETCH_COLUMN)); }
    public function all(int $userId): array { $s=Database::connection()->prepare('CALL `NTK_sp_get_wishlist`(?)');$s->execute([$userId]);return $s->fetchAll(); }
}

