<?php

declare(strict_types=1);

namespace App\Models;

use Core\ProcedureConnection as Database;
use PDO;

final class Listing
{
    private \Core\ProcedureConnection $db;
    public function __construct() { $this->db = Database::connection(); }

    public function search(array $filters, int $limit = 12, int $offset = 0): array
    {
        $statement = $this->db->prepare('CALL sp_listing_search(?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([trim($filters['location'] ?? ''), (int) ($filters['guests'] ?? 0),
            (int) ($filters['type'] ?? 0), (float) ($filters['min_price'] ?? 0), (float) ($filters['max_price'] ?? 0),
            (int) ($filters['bedrooms'] ?? 0), ($filters['check_in'] ?? '') ?: null, ($filters['check_out'] ?? '') ?: null,
            $filters['sort'] ?? '', max(1, min(100, $limit)), max(0, $offset)]);
        return $statement->fetchAll();
    }

    public function findPublic(int $id): ?array
    {
        $statement = $this->db->prepare('CALL `sp_listing_get_public`(?)');
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
        $statement = $this->db->prepare('CALL `sp_listing_get_owned`(?,?)');
        $statement->execute([$id, $hostId]);
        return $statement->fetch() ?: null;
    }

    public function hostListings(int $hostId): array
    {
        $statement = $this->db->prepare('CALL `sp_listing_get_by_host`(?)');
        $statement->execute([$hostId]);
        return $statement->fetchAll();
    }

    public function create(int $hostId, array $data): int
    {
        $statement = $this->db->prepare('CALL `sp_listing_create`(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$hostId,$data['property_type_id'],$data['cancellation_policy_id'],$data['title'],$data['address'],$data['city'],$data['description'],$data['nightly_price'],$data['cleaning_fee'],$data['max_guests'],$data['room_count'],$data['bedroom_count'],$data['bed_count'],$data['check_in_time'],$data['check_out_time'],!empty($data['allows_smoking']),!empty($data['allows_pets']),!empty($data['allows_parties'])]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $hostId, array $data): void
    {
        $statement = $this->db->prepare('CALL `sp_listing_update`(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$data['property_type_id'],$data['cancellation_policy_id'],$data['title'],$data['address'],$data['city'],$data['description'],$data['nightly_price'],$data['cleaning_fee'],$data['max_guests'],$data['room_count'],$data['bedroom_count'],$data['bed_count'],$data['check_in_time'],$data['check_out_time'],!empty($data['allows_smoking']),!empty($data['allows_pets']),!empty($data['allows_parties']),$id,$hostId]);
    }

    public function addPhoto(int $listingId, string $url, string $alt, int $hostId): void
    {
        $statement = $this->db->prepare('CALL sp_listing_photo_add(?,?,?,?)');
        $statement->execute([$listingId, $url, $alt, $hostId]);
    }

    public function setAmenities(int $listingId, array $ids, int $hostId): void
    {
        $statement = $this->db->prepare('CALL sp_listing_amenities_replace(?,?,?)');
        $statement->execute([$listingId, $hostId, json_encode(array_values(array_unique(array_map('intval', $ids))), JSON_THROW_ON_ERROR)]);
    }

    public function setVisible(int $id, int $hostId, bool $visible): void
    {
        $this->db->prepare('CALL `sp_listing_set_visible`(?,?,?)')->execute([$visible,$id,$hostId]);
    }

    public function setAvailability(int $id, int $hostId, string $date, bool $open, ?string $reason): bool
    {
        $statement = $this->db->prepare('CALL sp_listing_availability_set(?,?,?,?,?)');
        $statement->execute([$id, $hostId, $date, $open, $reason]);
        return (bool) $statement->fetchColumn();
    }

    public function availability(int $id,int $hostId): array
    {
        if(!$this->findOwned($id,$hostId)){return [];}
        $statement=$this->db->prepare('CALL `sp_listing_availability_get`(?)');
        $statement->execute([$id]);return $statement->fetchAll();
    }

    public function types(): array { return $this->db->query('CALL `DST_sp_list_property_types`()')->fetchAll(); }
    public function policies(): array { return $this->db->query('CALL `LTP_sp_list_cancellation_policies`()')->fetchAll(); }
    public function allAmenities(): array { return $this->db->query('CALL `DST_ sp_list_amenities`()')->fetchAll(); }
    public function amenityIds(int $listingId): array
    {
        $statement = $this->db->prepare('CALL `sp_listing_amenity_ids`(?)');
        $statement->execute([$listingId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
    private function photos(int $id): array { $s=$this->db->prepare('CALL `LTP_sp_get_listing_photos`(?)'); $s->execute([$id]); return $s->fetchAll(); }
    private function amenities(int $id): array { $s=$this->db->prepare('CALL `LTP_sp_get_listing_amenities`(?)'); $s->execute([$id]); return $s->fetchAll(); }
    private function reviews(int $id): array { $s=$this->db->prepare('CALL `NTK_sp_get_listing_reviews`(?)'); $s->execute([$id]); return $s->fetchAll(); }
}

