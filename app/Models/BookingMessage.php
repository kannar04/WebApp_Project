<?php
declare(strict_types=1);
namespace App\Models;

use Core\ProcedureConnection;

/** Booking conversation between the Guest and the listing Host; routines enforce participation and status rules. */
final class BookingMessage
{
    public const MAX_LENGTH = 2000;
    /** What draws nothing: control and format characters (zero-width ones included), separators, variation selectors and the blank letters. */
    private const INVISIBLE = '/[\p{Cc}\p{Cf}\p{Z}\x{034F}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}\x{2800}\x{3164}\x{FFA0}\x{115F}\x{1160}]/u';
    private ProcedureConnection $db;

    public function __construct() { $this->db = ProcedureConnection::connection(); }

    /** Shape check only: plain UTF-8 text, unified line breaks, no control characters but newline and tab, 1-2000 characters after trimming, something visible. sp_booking_message_send applies the same rule. */
    public static function normalize(mixed $raw): ?string
    {
        if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) { return null; }
        $body = trim((string) preg_replace('/(?![\n\t])\p{Cc}/u', '', str_replace(["\r\n", "\r"], "\n", $raw)));
        if (preg_replace(self::INVISIBLE, '', $body) === '') { return null; }
        return mb_strlen($body) <= self::MAX_LENGTH ? $body : null;
    }

    public function send(int $bookingId, int $actorId, string $body): int
    {
        $statement = $this->db->prepare('CALL sp_booking_message_send(?,?,?)');
        $statement->execute([$bookingId, $actorId, $body]);
        return (int) $statement->fetchColumn();
    }

    public function state(int $bookingId, int $actorId): array
    {
        $statement = $this->db->prepare('CALL sp_booking_message_state(?,?)');
        $statement->execute([$bookingId, $actorId]);
        $state = $statement->fetch();
        if (!$state) { throw new \DomainException('Booking không tồn tại.'); }
        return $state;
    }

    /** Returns the thread and marks the other participant's messages as read. */
    public function thread(int $bookingId, int $actorId): array
    {
        $statement = $this->db->prepare('CALL sp_booking_messages_thread(?,?)');
        $statement->execute([$bookingId, $actorId]);
        return $statement->fetchAll();
    }

    public function inbox(int $actorId): array
    {
        $statement = $this->db->prepare('CALL sp_message_inbox(?)');
        $statement->execute([$actorId]);
        return $statement->fetchAll();
    }

    public function unreadCount(int $actorId): int
    {
        $statement = $this->db->prepare('CALL sp_message_unread_count(?)');
        $statement->execute([$actorId]);
        return (int) $statement->fetchColumn();
    }

    public function adminSearch(int $actorId, string $keyword, ?int $bookingId, string $user): array
    {
        $statement = $this->db->prepare('CALL sp_admin_messages_search(?,?,?,?)');
        $statement->execute([$actorId, $keyword, $bookingId, $user]);
        return $statement->fetchAll();
    }

    public function adminThread(int $actorId, int $bookingId): array
    {
        $statement = $this->db->prepare('CALL sp_admin_message_thread(?,?)');
        $statement->execute([$actorId, $bookingId]);
        return $statement->fetchAll();
    }

    /** Hides or restores one message with an audited reason; returns the booking ID of the message. */
    public function moderate(int $messageId, int $actorId, string $action, string $reason): int
    {
        $statement = $this->db->prepare('CALL sp_admin_message_moderate(?,?,?,?)');
        $statement->execute([$messageId, $actorId, $action, $reason]);
        return (int) $statement->fetchColumn();
    }
}
