<?php
declare(strict_types=1);
namespace App\Services;

use Core\ProcedureConnection;
use DateTimeImmutable;
use DomainException;

/** PHP validates request shape; routines enforce ownership and atomic business rules. */
final class BookingService
{
    private ProcedureConnection $db;
    public function __construct() { $this->db = ProcedureConnection::connection(); }

    public function quote(int $listingId, string $checkIn, string $checkOut, int $guests): array
    {
        $this->validateDates($checkIn, $checkOut, $guests);
        $statement = $this->db->prepare('CALL sp_booking_quote(?,?,?,?)');
        $statement->execute([$listingId, $checkIn, $checkOut, $guests]);
        $quote = $statement->fetch();
        return ['nights'=>(int) $quote['nights'], 'nightly_price'=>(float) $quote['nightly_price'],
            'subtotal'=>(float) $quote['subtotal'], 'fee'=>(float) $quote['fee'], 'total'=>(float) $quote['total']];
    }

    public function create(int $listingId, int $guestId, string $checkIn, string $checkOut, int $guests): int
    {
        $this->validateDates($checkIn, $checkOut, $guests);
        $statement = $this->db->prepare('CALL sp_booking_create(?,?,?,?,?)');
        $statement->execute([$listingId, $guestId, $checkIn, $checkOut, $guests]);
        return (int) $statement->fetchColumn();
    }

    public function hostTransition(int $bookingId, int $hostId, string $target): void
    {
        $this->transition($bookingId, $hostId, $target, false);
    }

    public function adminTransition(int $bookingId, int $adminId, string $target): void
    {
        $this->transition($bookingId, $adminId, $target, true);
    }

    private function transition(int $bookingId, int $actorId, string $target, bool $admin): void
    {
        if (!in_array($target, ['confirmed', 'rejected', 'completed'], true)) {
            throw new DomainException('Trạng thái không hợp lệ.');
        }
        $statement = $this->db->prepare('CALL sp_booking_transition(?,?,?,?)');
        $statement->execute([$bookingId, $actorId, $target, $admin]);
    }

    public function cancelByGuest(int $bookingId, int $guestId, string $reason=''): array
    {
        $statement = $this->db->prepare('CALL sp_booking_cancel(?,?,?)');
        $statement->execute([$bookingId, $guestId, trim($reason)]);
        $refund = $statement->fetch();
        return ['refund_percent'=>(float) $refund['refund_percent'], 'refund_amount'=>(float) $refund['refund_amount']];
    }

    private function validateDates(string $checkIn, string $checkOut, int $guests): void
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $checkIn);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $checkOut);
        if (!$start || $start->format('Y-m-d') !== $checkIn || !$end || $end->format('Y-m-d') !== $checkOut) {
            throw new DomainException('Ngày nhận hoặc trả phòng không hợp lệ.');
        }
        if ($start < new DateTimeImmutable('today')) { throw new DomainException('Không thể đặt ngày trong quá khứ.'); }
        if ($end <= $start) { throw new DomainException('Ngày trả phòng phải sau ngày nhận phòng.'); }
        if ($guests < 1) { throw new DomainException('Số khách không hợp lệ.'); }
    }
}

