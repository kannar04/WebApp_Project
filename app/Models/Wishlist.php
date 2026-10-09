<?php
declare(strict_types=1);
namespace App\Models;
use Core\Database;
final class Wishlist
{
    public function toggle(int $userId,int $listingId): bool { $db=Database::connection();$s=$db->prepare('SELECT 1 FROM wishlist_items WHERE user_id=? AND listing_id=?');$s->execute([$userId,$listingId]);if($s->fetchColumn()){$db->prepare('DELETE FROM wishlist_items WHERE user_id=? AND listing_id=?')->execute([$userId,$listingId]);return false;}$db->prepare('INSERT INTO wishlist_items(user_id,listing_id,created_at) VALUES(?,?,NOW(6))')->execute([$userId,$listingId]);return true; }
    public function ids(int $userId): array { $s=Database::connection()->prepare('SELECT listing_id FROM wishlist_items WHERE user_id=?');$s->execute([$userId]);return array_map('intval',$s->fetchAll(\PDO::FETCH_COLUMN)); }
    public function all(int $userId): array { $s=Database::connection()->prepare("SELECT l.*,l.base_nightly_rate nightly_price,l.fee_amount cleaning_fee,pt.name property_type,(SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url FROM wishlist_items w JOIN listings l ON l.id=w.listing_id JOIN property_types pt ON pt.id=l.property_type_id WHERE w.user_id=? AND l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL ORDER BY w.created_at DESC");$s->execute([$userId]);return $s->fetchAll(); }
}

