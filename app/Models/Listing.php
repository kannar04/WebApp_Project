<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use PDO;

final class Listing
{
    private PDO $db;
    public function __construct() { $this->db = Database::connection(); }

    public function search(array $filters, int $limit = 12, int $offset = 0): array
    {
        $conditions = ["l.moderation_status = 'approved'", 'l.is_visible = 1', 'l.deleted_at IS NULL'];
        $params = [];
        if (!empty($filters['location'])) { $conditions[] = '(l.city LIKE ? OR l.address LIKE ? OR l.title LIKE ?)'; $needle = '%' . trim($filters['location']) . '%'; array_push($params, $needle, $needle, $needle); }
        if (!empty($filters['guests'])) { $conditions[] = 'l.max_guests >= ?'; $params[] = (int) $filters['guests']; }
        if (!empty($filters['type'])) { $conditions[] = 'l.property_type_id = ?'; $params[] = (int) $filters['type']; }
        if (!empty($filters['min_price'])) { $conditions[] = 'l.base_nightly_rate >= ?'; $params[] = (float) $filters['min_price']; }
        if (!empty($filters['max_price'])) { $conditions[] = 'l.base_nightly_rate <= ?'; $params[] = (float) $filters['max_price']; }
        if (!empty($filters['bedrooms'])) { $conditions[] = 'l.bedroom_count >= ?'; $params[] = (int) $filters['bedrooms']; }
        if (!empty($filters['check_in']) && !empty($filters['check_out'])) {
            $conditions[] = "NOT EXISTS (SELECT 1 FROM listing_availability la WHERE la.listing_id=l.id AND la.is_open=0 AND la.stay_date >= ? AND la.stay_date < ?)";
            array_push($params, $filters['check_in'], $filters['check_out']);
            $conditions[] = "NOT EXISTS (SELECT 1 FROM bookings b WHERE b.listing_id=l.id AND b.status IN ('pending','confirmed') AND b.check_in < ? AND b.check_out > ?)";
            array_push($params, $filters['check_out'], $filters['check_in']);
        }
        $order = match ($filters['sort'] ?? '') {
            'price_asc' => 'l.base_nightly_rate ASC',
            'price_desc' => 'l.base_nightly_rate DESC',
            'rating' => 'rating DESC, l.created_at DESC',
            default => 'l.created_at DESC',
        };
        $sql = "SELECT l.*, l.base_nightly_rate nightly_price, l.fee_amount cleaning_fee, pt.name property_type, u.full_name host_name,
                (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
                COALESCE(AVG(r.rating),0) rating, COUNT(DISTINCT r.id) review_count
                FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
                LEFT JOIN bookings rb ON rb.listing_id=l.id LEFT JOIN reviews r ON r.booking_id=rb.id
                WHERE " . implode(' AND ', $conditions) . " GROUP BY l.id ORDER BY {$order} LIMIT ? OFFSET ?";
        $statement = $this->db->prepare($sql);
        foreach ($params as $index => $value) { $statement->bindValue($index + 1, $value); }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function findPublic(int $id): ?array
    {
        $statement = $this->db->prepare("SELECT l.*, l.base_nightly_rate nightly_price, l.fee_amount cleaning_fee, l.smoking_allowed allows_smoking, l.pets_allowed allows_pets, l.parties_allowed allows_parties, pt.name property_type, cp.name cancellation_name, cp.description cancellation_description, cp.cutoff_hours, cp.early_refund_pct refund_percent, u.full_name host_name, u.avatar_url host_avatar, u.created_at host_since FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN cancellation_policies cp ON cp.id=l.cancellation_policy_id JOIN users u ON u.id=l.host_id WHERE l.id=? AND l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL");
        $statement->execute([$id]);
        $listing = $statement->fetch();
        if (!$listing) { return null; }
        $listing['photos'] = $this->photos($id);
        $listing['amenities'] = $this->amenities($id);
        $listing['reviews'] = $this->reviews($id);
        return $listing;
    }

    public function findOwned(int $id, int $hostId): ?array
    {
        $statement = $this->db->prepare('SELECT l.*, l.base_nightly_rate nightly_price, l.fee_amount cleaning_fee, l.smoking_allowed allows_smoking, l.pets_allowed allows_pets, l.parties_allowed allows_parties FROM listings l WHERE id=? AND host_id=? AND deleted_at IS NULL');
        $statement->execute([$id, $hostId]);
        return $statement->fetch() ?: null;
    }

    public function hostListings(int $hostId): array
    {
        $statement = $this->db->prepare("SELECT l.*, l.base_nightly_rate nightly_price, l.fee_amount cleaning_fee, pt.name property_type, (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url FROM listings l JOIN property_types pt ON pt.id=l.property_type_id WHERE l.host_id=? AND l.deleted_at IS NULL ORDER BY l.created_at DESC");
        $statement->execute([$hostId]);
        return $statement->fetchAll();
    }

    public function create(int $hostId, array $data): int
    {
        $statement = $this->db->prepare('INSERT INTO listings (host_id, property_type_id, cancellation_policy_id, title, address, city, country_code, time_zone, description, base_nightly_rate, fee_amount, currency, max_guests, room_count, bedroom_count, bed_count, check_in_time, check_out_time, smoking_allowed, pets_allowed, parties_allowed, moderation_status, is_visible, created_at, updated_at) VALUES (?,?,?,?,?,?,\'VN\',\'Asia/Ho_Chi_Minh\',?,?,?,\'VND\',?,?,?,?,?,?,?,?,?,\'pending\',1,NOW(6),NOW(6))');
        $statement->execute([$hostId,$data['property_type_id'],$data['cancellation_policy_id'],$data['title'],$data['address'],$data['city'],$data['description'],$data['nightly_price'],$data['cleaning_fee'],$data['max_guests'],$data['room_count'],$data['bedroom_count'],$data['bed_count'],$data['check_in_time'],$data['check_out_time'],!empty($data['allows_smoking']),!empty($data['allows_pets']),!empty($data['allows_parties'])]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $hostId, array $data): void
    {
        $statement = $this->db->prepare("UPDATE listings SET property_type_id=?, cancellation_policy_id=?, title=?, address=?, city=?, description=?, base_nightly_rate=?, fee_amount=?, max_guests=?, room_count=?, bedroom_count=?, bed_count=?, check_in_time=?, check_out_time=?, smoking_allowed=?, pets_allowed=?, parties_allowed=?, moderation_status='pending', updated_at=NOW(6) WHERE id=? AND host_id=? AND deleted_at IS NULL");
        $statement->execute([$data['property_type_id'],$data['cancellation_policy_id'],$data['title'],$data['address'],$data['city'],$data['description'],$data['nightly_price'],$data['cleaning_fee'],$data['max_guests'],$data['room_count'],$data['bedroom_count'],$data['bed_count'],$data['check_in_time'],$data['check_out_time'],!empty($data['allows_smoking']),!empty($data['allows_pets']),!empty($data['allows_parties']),$id,$hostId]);
    }

    public function addPhoto(int $listingId, string $url, string $alt): void
    {
        $statement = $this->db->prepare('INSERT INTO listing_photos (listing_id,image_url,alt_text,sort_order,created_at) VALUES (?,?,?,(SELECT next_order FROM (SELECT COALESCE(MAX(sort_order),0)+1 next_order FROM listing_photos WHERE listing_id=?) x),NOW(6))');
        $statement->execute([$listingId,$url,$alt,$listingId]);
    }

    public function setAmenities(int $listingId, array $ids): void
    {
        $this->db->prepare('DELETE FROM listing_amenities WHERE listing_id=?')->execute([$listingId]);
        $statement = $this->db->prepare('INSERT IGNORE INTO listing_amenities (listing_id,amenity_id) VALUES (?,?)');
        foreach (array_unique(array_map('intval', $ids)) as $id) { if ($id > 0) { $statement->execute([$listingId,$id]); } }
    }

    public function setVisible(int $id, int $hostId, bool $visible): void
    {
        $this->db->prepare('UPDATE listings SET is_visible=? WHERE id=? AND host_id=?')->execute([$visible,$id,$hostId]);
    }

    public function setAvailability(int $id, int $hostId, string $date, bool $open, ?string $reason): bool
    {
        if (!$this->findOwned($id,$hostId)) { return false; }
        $conflict = $this->db->prepare("SELECT COUNT(*) FROM bookings WHERE listing_id=? AND status='confirmed' AND check_in <= ? AND check_out > ?");
        $conflict->execute([$id,$date,$date]);
        if (!$open && (int)$conflict->fetchColumn() > 0) { return false; }
        $statement = $this->db->prepare('INSERT INTO listing_availability (listing_id,stay_date,is_open,block_reason,updated_at) VALUES (?,?,?,?,NOW(6)) ON DUPLICATE KEY UPDATE is_open=VALUES(is_open), block_reason=VALUES(block_reason),updated_at=NOW(6)');
        $statement->execute([$id,$date,$open,$open ? null : $reason]);
        return true;
    }

    public function availability(int $id,int $hostId): array
    {
        if(!$this->findOwned($id,$hostId)){return [];}
        $statement=$this->db->prepare("SELECT la.*,CASE WHEN EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=la.listing_id AND b.status='confirmed' AND b.check_in<=la.stay_date AND b.check_out>la.stay_date) THEN 1 ELSE 0 END booked FROM listing_availability la WHERE la.listing_id=? AND la.stay_date>=CURDATE() ORDER BY la.stay_date LIMIT 120");
        $statement->execute([$id]);return $statement->fetchAll();
    }

    public function types(): array { return $this->db->query('SELECT * FROM property_types WHERE is_active=1 ORDER BY name')->fetchAll(); }
    public function policies(): array { return $this->db->query('SELECT * FROM cancellation_policies ORDER BY id')->fetchAll(); }
    public function allAmenities(): array { return $this->db->query('SELECT * FROM amenities WHERE is_active=1 ORDER BY name')->fetchAll(); }
    public function amenityIds(int $listingId): array
    {
        $statement = $this->db->prepare('SELECT amenity_id FROM listing_amenities WHERE listing_id=?');
        $statement->execute([$listingId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
    private function photos(int $id): array { $s=$this->db->prepare('SELECT * FROM listing_photos WHERE listing_id=? ORDER BY sort_order,id'); $s->execute([$id]); return $s->fetchAll(); }
    private function amenities(int $id): array { $s=$this->db->prepare('SELECT a.* FROM amenities a JOIN listing_amenities la ON la.amenity_id=a.id WHERE la.listing_id=? ORDER BY a.name'); $s->execute([$id]); return $s->fetchAll(); }
    private function reviews(int $id): array { $s=$this->db->prepare("SELECT r.*,r.host_reply host_response,u.full_name guest_name FROM reviews r JOIN bookings b ON b.id=r.booking_id JOIN users u ON u.id=b.guest_id WHERE b.listing_id=? AND r.moderation_status='visible' ORDER BY r.created_at DESC"); $s->execute([$id]); return $s->fetchAll(); }
}

