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
    public const PHONE_RULE = 'Số điện thoại di động Việt Nam không hợp lệ (10 chữ số, bắt đầu bằng 03, 05, 07, 08 hoặc 09).';

    /** Identity verification's phone rule, for the forms where a phone is optional: blank passes. Spaces and +84 are accepted, as there. */
    public static function phoneAllowed(string $raw): bool
    {
        return trim($raw) === '' || preg_match('/^0[35789][0-9]{8}$/D', self::normalizePhone($raw)) === 1;
    }

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

    public function submitVerification(int $actorId, string $fullName, string $phone, string $idNumber, array $documents): int
    {
        $statement = $this->db->prepare('CALL DST_sp_identity_verification_submit(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$actorId, trim($fullName), self::normalizePhone($phone), self::normalizeIdNumber($idNumber),
            $documents['front']['storage_name'],$documents['front']['mime_type'],$documents['front']['byte_size'],
            $documents['back']['storage_name'],$documents['back']['mime_type'],$documents['back']['byte_size'],
            $documents['selfie']['storage_name'],$documents['selfie']['mime_type'],$documents['selfie']['byte_size']]);
        return (int) $this->db->lastInsertId();
    }

    public function documentForActor(int $verificationId,string $kind,int $actorId,bool $admin): ?array
    {
        $procedure=$admin?'DST_sp_identity_document_for_admin':'NMT_sp_identity_document_for_user';
        $statement=$this->db->prepare('CALL '.$procedure.'(?,?,?)');$statement->execute([$actorId,$verificationId,$kind]);return $statement->fetch()?:null;
    }

    public function documentsForAdmin(int $verificationId,int $adminId): array
    {
        $statement=$this->db->prepare('CALL LTP_sp_identity_documents_for_admin(?,?)');$statement->execute([$adminId,$verificationId]);return $statement->fetchAll();
    }

    public function verificationStatus(int $actorId): ?array
    {
        $statement = $this->db->prepare('CALL NTK_sp_identity_verification_status(?)');
        $statement->execute([$actorId]);
        $status=$statement->fetch() ?: null;
        if (\App\Services\DebugVerification::verified('identity',$actorId)) {
            $user=\Core\Auth::user();
            if ($user && (int)$user['id']===$actorId && self::normalizePhone((string)$user['phone'])===($_SESSION['_debug_verification']['identity']??null)) {
                return ['id'=>0,'status'=>'approved','is_current'=>1,'is_debug'=>true,'full_name_on_card'=>$user['full_name'],
                    'phone'=>self::normalizePhone((string)$user['phone']),'id_number_masked'=>'DEBUG (không có CCCD thật)',
                    'decision_reason'=>'Local debug simulation','created_at'=>date('Y-m-d H:i:s'),'reviewed_at'=>date('Y-m-d H:i:s')];
            }
        }
        return $status;
    }

    public function adminVerifications(int $adminId, string $status, string $query): array
    {
        $statement = $this->db->prepare('CALL DST_sp_admin_identity_verifications(?,?,?)');
        $statement->execute([$adminId, $status, $query]);
        return $statement->fetchAll();
    }

    public function decideVerification(int $id, int $adminId, string $decision, string $reason): void
    {
        $statement = $this->db->prepare('CALL LTP_sp_admin_identity_verification_decide(?,?,?,?)');
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
        $statement = $this->db->prepare('CALL NTK_sp_trust_badges(?,?)');
        foreach (array_chunk(array_values($ids), 200) as $chunk) {
            $statement->execute([implode(',', $chunk), $actorId]);
            foreach ($statement->fetchAll() as $row) { $badges[(int) $row['user_id']] = $row; }
        }
        if ($actorId!==null && isset($badges[$actorId]) && \App\Services\DebugVerification::verified('identity',$actorId)) {
            $user=\Core\Auth::user();
            if ($user && (int)$user['id']===$actorId && self::normalizePhone((string)$user['phone'])===($_SESSION['_debug_verification']['identity']??null)) {
                $badges[$actorId]['is_verified']=1;
            }
        }
        return $badges;
    }

    /** @return array<int,bool> visible review id => reviewer is verified */
    public function reviewBadges(int $listingId): array
    {
        $statement = $this->db->prepare('CALL DST_sp_trust_review_badges(?)');
        $statement->execute([$listingId]);
        $badges = [];
        foreach ($statement->fetchAll() as $row) { $badges[(int) $row['review_id']] = (int) $row['reviewer_verified'] === 1; }
        return $badges;
    }

    public function guideForHost(int $listingId, int $hostId): ?array
    {
        $statement = $this->db->prepare('CALL LTP_sp_checkin_guide_for_host(?,?)');
        $statement->execute([$listingId, $hostId]);
        return $statement->fetch() ?: null;
    }

    public function saveGuide(int $listingId, int $hostId, array $data): void
    {
        $statement = $this->db->prepare('CALL NMT_sp_checkin_guide_save(?,?,?,?,?,?,?)');
        $statement->execute([$listingId, $hostId, (string) ($data['arrival_instructions'] ?? ''), (string) ($data['door_code'] ?? ''),
            (string) ($data['wifi_name'] ?? ''), (string) ($data['wifi_password'] ?? ''), (string) ($data['note'] ?? '')]);
    }

    /** Null unless the actor is entitled: the booking's Guest, the listing Host or an Admin, and the booking is confirmed/completed. Door code and Wi-Fi are null outside their window; codes_state and codes_from say why. */
    public function guideForBooking(int $bookingId, int $actorId): ?array
    {
        $statement = $this->db->prepare('CALL DST_sp_checkin_guide_for_booking(?,?)');
        $statement->execute([$bookingId, $actorId]);
        return $statement->fetch() ?: null;
    }
}
