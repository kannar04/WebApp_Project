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

    /** The promo code is optional; an unusable code is reported in promo.message while the price stays valid. */
    public function quote(int $listingId, string $checkIn, string $checkOut, int $guests, string $promoCode=''): array
    {
        $this->validateDates($checkIn, $checkOut, $guests);
        $promoCode = $this->promoCode($promoCode);
        $statement = $this->db->prepare('CALL sp_booking_quote(?,?,?,?,?)');
        $statement->execute([$listingId, $checkIn, $checkOut, $guests, $promoCode]);
        $quote = $statement->fetch();
        return ['nights'=>(int) $quote['nights'], 'nightly_price'=>(float) $quote['nightly_price'],
            'subtotal'=>(float) $quote['subtotal'], 'fee'=>(float) $quote['fee'], 'total'=>(float) $quote['total'],
            'length_discount'=>['type'=>(string) $quote['length_discount_type'], 'percent'=>(float) $quote['length_discount_pct'],
                'amount'=>(float) $quote['length_discount_amount']],
            'promo'=>['requested'=>$promoCode, 'code'=>$quote['promo_code'] === null ? null : (string) $quote['promo_code'],
                'discount'=>(float) $quote['promo_discount'], 'message'=>$quote['promo_message'] === null ? null : (string) $quote['promo_message']],
            'instant_book'=>(bool) $quote['instant_book'], 'breakdown'=>$this->breakdown($quote)];
    }

    public function create(int $listingId, int $guestId, string $checkIn, string $checkOut, int $guests, string $promoCode=''): int
    {
        return $this->book($listingId, $guestId, $checkIn, $checkOut, $guests, $promoCode)['booking_id'];
    }

    /** Returns the new booking ID and its status: 'confirmed' for Instant Book listings, otherwise 'pending'. */
    public function book(int $listingId, int $guestId, string $checkIn, string $checkOut, int $guests, string $promoCode=''): array
    {
        $this->validateDates($checkIn, $checkOut, $guests);
        $statement = $this->db->prepare('CALL sp_booking_create(?,?,?,?,?,?)');
        $statement->execute([$listingId, $guestId, $checkIn, $checkOut, $guests, $this->promoCode($promoCode)]);
        $row = $statement->fetch();
        return ['booking_id'=>(int) $row['booking_id'], 'status'=>(string) $row['booking_status']];
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 0, ',', '.').'đ';
    }

    public static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',').'%';
    }

    /** Display rows for the price panel; amounts are signed (discounts negative) and add up to the total. */
    private function breakdown(array $quote): array
    {
        $rows = [];
        $base = (float) $quote['nightly_price'];
        $weekendNights = (int) $quote['weekend_nights'];
        $holidayNights = (int) $quote['holiday_nights'];
        $regularNights = (int) $quote['nights'] - $weekendNights - $holidayNights;
        if ($regularNights > 0) {
            $rows[] = ['kind'=>'night', 'label'=>$regularNights.' đêm thường × '.self::money($base), 'amount'=>$regularNights * $base];
        }
        if ($weekendNights > 0) {
            $rate = (float) $quote['weekend_rate'];
            $rows[] = ['kind'=>'night', 'label'=>$weekendNights.' đêm cuối tuần × '.self::money($rate), 'amount'=>$weekendNights * $rate];
        }
        if ($holidayNights > 0) {
            $rate = (float) $quote['holiday_rate'];
            $rows[] = ['kind'=>'night', 'label'=>$holidayNights.' đêm ngày lễ × '.self::money($rate), 'amount'=>$holidayNights * $rate];
        }
        if ((float) $quote['length_discount_amount'] > 0) {
            $label = $quote['length_discount_type'] === 'monthly' ? 'Giảm ở từ 28 đêm' : 'Giảm ở từ 7 đêm';
            $rows[] = ['kind'=>'discount', 'label'=>$label.' ('.self::percent((float) $quote['length_discount_pct']).')',
                'amount'=>-(float) $quote['length_discount_amount']];
        }
        if ($quote['promo_code'] !== null) {
            $rows[] = ['kind'=>'discount', 'label'=>'Mã '.$quote['promo_code'], 'amount'=>-(float) $quote['promo_discount']];
        }
        $rows[] = ['kind'=>'fee', 'label'=>'Phụ phí', 'amount'=>(float) $quote['fee']];
        $rows[] = ['kind'=>'total', 'label'=>'Tổng', 'amount'=>(float) $quote['total']];
        return $rows;
    }

    private function promoCode(string $promoCode): string
    {
        $promoCode = trim($promoCode);
        if (mb_strlen($promoCode) > 40) { throw new DomainException('Mã khuyến mãi không hợp lệ (4–20 chữ cái hoặc số).'); }
        return $promoCode;
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

