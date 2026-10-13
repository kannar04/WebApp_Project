<?php
declare(strict_types=1);

namespace App\Models;

use Core\ProcedureConnection as Database;

/** Identity verification, trust badges and check-in guides. Every authorization rule is enforced by the routines. */
final class Trust
{
    private \Core\ProcedureConnection $db;

    public function __construct() { $this->db = Database::connection(); }

    /** Keeps digits only and rewrites +84/84 to the domestic 0 prefix; the routine validates the result. */
    public static function normalizePhone(string $raw): string
    {
        $phone = preg_replace('/[\s.\-()]+/u', '', trim($raw)) ?? '';
        if (str_starts_with($phone, '+84')) { $phone = '0'.substr($phone, 3); }
        elseif (str_starts_with($phone, '84') && strlen($phone) === 11) { $phone = '0'.substr($phone, 2); }
        return $phone;
    }

    public static function normalizeIdNumber(string $raw): string
    {
        return preg_replace('/[\s.\-]+/u', '', trim($raw)) ?? '';
    }

    public function submitVerification(int $actorId, string $fullName, string $phone, string $idNumber): int
    {
        $statement = $this->db->prepare('CALL sp_identity_verification_submit(?,?,?,?)');
        $statement->execute([$actorId, trim($fullName), self::normalizePhone($phone), self::normalizeIdNumber($idNumber)]);
        return (int) $this->db->lastInsertId();
    }

    public function verificationStatus(int $actorId): ?array
    {
        $statement = $this->db->prepare('CALL sp_identity_verification_status(?)');
        $statement->execute([$actorId]);
        return $statement->fetch() ?: null;
    }

    public function adminVerifications(int $adminId, string $status, string $query): array
    {
        $statement = $this->db->prepare('CALL sp_admin_identity_verifications(?,?,?)');
        $statement->execute([$adminId, $status, $query]);
        return $statement->fetchAll();
    }

    public function decideVerification(int $id, int $adminId, string $decision, string $reason): void
    {
        $statement = $this->db->prepare('CALL sp_admin_identity_verification_decide(?,?,?,?)');
        $statement->execute([$id, $adminId, $decision, $reason]);
    }

    /**
     * Badge rows keyed by user id. Cancellation metrics are only filled for the actor themself or an Admin.
     * @param array<int|string> $userIds
     */
    public function badges(array $userIds, ?int $actorId): array
    {
        $ids = [];
        foreach ($userIds as $userId) {
            $id = (int) $userId;
            if ($id > 0) { $ids[$id] = $id; }
        }
        $badges = [];
        $statement = $this->db->prepare('CALL sp_trust_badges(?,?)');
        foreach (array_chunk(array_values($ids), 200) as $chunk) {
            $statement->execute([implode(',', $chunk), $actorId]);
            foreach ($statement->fetchAll() as $row) { $badges[(int) $row['user_id']] = $row; }
        }
        return $badges;
    }

    /** @return array<int,bool> visible review id => reviewer is verified */
    public function reviewBadges(int $listingId): array
    {
        $statement = $this->db->prepare('CALL sp_trust_review_badges(?)');
        $statement->execute([$listingId]);
        $badges = [];
        foreach ($statement->fetchAll() as $row) { $badges[(int) $row['review_id']] = (int) $row['reviewer_verified'] === 1; }
        return $badges;
    }

    public function guideForHost(int $listingId, int $hostId): ?array
    {
        $statement = $this->db->prepare('CALL sp_checkin_guide_for_host(?,?)');
        $statement->execute([$listingId, $hostId]);
        return $statement->fetch() ?: null;
    }

    public function saveGuide(int $listingId, int $hostId, array $data): void
    {
        $statement = $this->db->prepare('CALL sp_checkin_guide_save(?,?,?,?,?,?,?)');
        $statement->execute([$listingId, $hostId, (string) ($data['arrival_instructions'] ?? ''), (string) ($data['door_code'] ?? ''),
            (string) ($data['wifi_name'] ?? ''), (string) ($data['wifi_password'] ?? ''), (string) ($data['note'] ?? '')]);
    }

    /** Null unless the actor is entitled: the booking's Guest, the listing Host or an Admin, and the booking is confirmed/completed. */
    public function guideForBooking(int $bookingId, int $actorId): ?array
    {
        $statement = $this->db->prepare('CALL sp_checkin_guide_for_booking(?,?)');
        $statement->execute([$bookingId, $actorId]);
        return $statement->fetch() ?: null;
    }
}
