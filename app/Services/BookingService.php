<?php
declare(strict_types=1);
namespace App\Services;

use Core\ProcedureConnection;
use DateTimeImmutable;
use DomainException;

/** PHP validates request shape; routines enforce ownership and atomic business rules. */
final class BookingService
{
    /** Same limit as sp_booking_validate. */
    public const MAX_NIGHTS = 365;
    private ProcedureConnection $db;
    public function __construct() { $this->db = ProcedureConnection::connection(); }

    /** The promo code is optional; an unusable code is reported in promo.message while the price stays valid. */
    public function quote(int $listingId, string $checkIn, string $checkOut, int $guests, string $promoCode=''): array
    {
        $this->validateDates($checkIn, $checkOut, $guests);
        $promoCode = $this->promoCode($promoCode);
        $statement = $this->db->prepare('CALL DST_sp_booking_quote(?,?,?,?,?)');
        $statement->execute([$listingId, $checkIn, $checkOut, $guests, $promoCode]);
        $quote = $statement->fetch();
        return ['nights'=>(int) $quote['nights'], 'nightly_price'=>(float) $quote['nightly_price'],
            'subtotal'=>(float) $quote['subtotal'], 'fee'=>(float) $quote['fee'], 'total'=>(float) $quote['total'],
            'length_discount'=>['type'=>(string) $quote['length_discount_type'], 'percent'=>(float) $quote['length_discount_pct'],
                'amount'=>(float) $quote['length_discount_amount']],
            // promo.message and the breakdown labels are shown as-is by the booking form, so they arrive in the interface language.
            'promo'=>['requested'=>$promoCode, 'code'=>$quote['promo_code'] === null ? null : (string) $quote['promo_code'],
                'discount'=>(float) $quote['promo_discount'], 'message'=>$quote['promo_message'] === null ? null : __((string) $quote['promo_message'])],
            'instant_book'=>(bool) $quote['instant_book'], 'breakdown'=>$this->breakdown($quote)];
    }

    public function create(int $listingId, int $guestId, string $checkIn, string $checkOut, int $guests, string $promoCode=''): int
    {
        return $this->book($listingId, $guestId, $checkIn, $checkOut, $guests, $promoCode)['booking_id'];
    }

    /**
     * Returns the new booking ID and its status: 'confirmed' for Instant Book listings, otherwise 'pending'.
     * $shownTotal is the total the Guest saw, as a decimal string: if it no longer matches, nothing is booked. Null means no total was shown.
     */
    public function book(int $listingId, int $guestId, string $checkIn, string $checkOut, int $guests, string $promoCode='', ?string $shownTotal=null): array
    {
        $this->validateDates($checkIn, $checkOut, $guests);
        $statement = $this->db->prepare('CALL DST_sp_booking_create(?,?,?,?,?,?,?)');
        $statement->execute([$listingId, $guestId, $checkIn, $checkOut, $guests, $this->promoCode($promoCode), $shownTotal]);
        $row = $statement->fetch();
        return ['booking_id'=>(int) $row['booking_id'], 'status'=>(string) $row['booking_status']];
    }

    /** Keep review acceptance and create in one caller-owned transaction. */
    public function bookReviewed(int $listingId,int $guestId,array $trip,string $fingerprint): array
    {
        $this->db->beginTransaction();
        try {
            $lock=$this->db->prepare('CALL NTK_sp_focused_review_lock(?)'); $lock->execute([$listingId]); $lock->fetchAll();
            $listing=(new \App\Models\Listing())->findPublic($listingId);
            if (!$listing) { throw new DomainException('Chỗ ở không khả dụng.'); }
            $quote=$this->quote($listingId,$trip['check_in'],$trip['check_out'],(int)$trip['guests'],$trip['promo_code']??'');
            if (!hash_equals($fingerprint,TripContext::fingerprint($quote,$listing))) { throw new DomainException('Giá hoặc chính sách đã thay đổi. Vui lòng xem lại trước khi xác nhận.'); }
            $booking=$this->book($listingId,$guestId,$trip['check_in'],$trip['check_out'],(int)$trip['guests'],$trip['promo_code']??'',number_format((float)$quote['total'],2,'.',''));
            $actual=(new \App\Models\Booking())->detail((int)$booking['booking_id'],$guestId);
            if (abs((float)$actual['total_amount']-(float)$quote['total'])>.005 || ($booking['status']==='confirmed')!==$quote['instant_book']) {
                throw new DomainException('Giá hoặc chính sách đã thay đổi. Vui lòng xem lại trước khi xác nhận.');
            }
            $this->db->commit(); return $booking;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $exception;
        }
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 0, ',', '.').'đ';
    }

    public static function percent(float $value): string
    {
        return display_decimal($value, 2, true).'%';
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
            $rows[] = ['kind'=>'night', 'label'=>__('{count} đêm thường × {price}', ['count'=>$regularNights, 'price'=>self::money($base)]),
                'amount'=>$regularNights * $base];
        }
        if ($weekendNights > 0) {
            $rate = (float) $quote['weekend_rate'];
            $rows[] = ['kind'=>'night', 'label'=>__('{count} đêm cuối tuần × {price}', ['count'=>$weekendNights, 'price'=>self::money($rate)]),
                'amount'=>$weekendNights * $rate];
        }
        if ($holidayNights > 0) {
            $rate = (float) $quote['holiday_rate'];
            $rows[] = ['kind'=>'night', 'label'=>__('{count} đêm ngày lễ × {price}', ['count'=>$holidayNights, 'price'=>self::money($rate)]),
                'amount'=>$holidayNights * $rate];
        }
        if ((float) $quote['length_discount_amount'] > 0) {
            $percent = ['percent'=>self::percent((float) $quote['length_discount_pct'])];
            $label = $quote['length_discount_type'] === 'monthly' ? __('Giảm ở từ 28 đêm ({percent})', $percent) : __('Giảm ở từ 7 đêm ({percent})', $percent);
            $rows[] = ['kind'=>'discount', 'label'=>$label, 'amount'=>-(float) $quote['length_discount_amount']];
        }
        if ($quote['promo_code'] !== null) {
            $rows[] = ['kind'=>'discount', 'label'=>__('Mã {code}', ['code'=>$quote['promo_code']]), 'amount'=>-(float) $quote['promo_discount']];
        }
        $rows[] = ['kind'=>'fee', 'label'=>__('Phụ phí'), 'amount'=>(float) $quote['fee']];
        $rows[] = ['kind'=>'total', 'label'=>__('Tổng'), 'amount'=>(float) $quote['total']];
        return $rows;
    }

    /**
     * A code as the box means it: JS .trim() strips Unicode whitespace from a pasted code (U+00A0, U+3000, U+FEFF and the like), so the server strips the same set,
     * and the NUL PHP's trim() took. Both ends only: an inner space stays invalid. Far over any code, or bad UTF-8, is left for the length and format checks to refuse.
     */
    public static function cleanPromoCode(string $code): string
    {
        if (strlen($code) > 1000) { return $code; }
        $space = '[\x00\x09-\x0D\x20\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+';
        return preg_replace('/^'.$space.'|'.$space.'$/uD', '', $code) ?? trim($code);
    }

    private function promoCode(string $promoCode): string
    {
        $promoCode = self::cleanPromoCode($promoCode);
        if (!mb_check_encoding($promoCode, 'UTF-8') || mb_strlen($promoCode) > 40) { throw new DomainException('Mã khuyến mãi không hợp lệ (4–20 chữ cái hoặc số).'); }
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
        $statement = $this->db->prepare('CALL NMT_sp_booking_transition(?,?,?,?)');
        $statement->execute([$bookingId, $actorId, $target, $admin]);
    }

    public function cancelByGuest(int $bookingId, int $guestId, string $reason=''): array
    {
        $statement = $this->db->prepare('CALL NTK_sp_booking_cancel(?,?,?)');
        $statement->execute([$bookingId, $guestId, trim($reason)]);
        $refund = $statement->fetch();
        return ['refund_percent'=>(float) $refund['refund_percent'], 'refund_amount'=>(float) $refund['refund_amount'], 'was_paid'=>(bool) $refund['was_paid']];
    }

    private function validateDates(string $checkIn, string $checkOut, int $guests): void
    {
        $start = \Core\Calendar::date($checkIn);
        $end = \Core\Calendar::date($checkOut);
        if (!$start || !$end) {
            throw new DomainException('Ngày nhận hoặc trả phòng không hợp lệ.');
        }
        if ($start < new DateTimeImmutable('today')) { throw new DomainException('Không thể đặt ngày trong quá khứ.'); }
        if ($end <= $start) { throw new DomainException('Ngày trả phòng phải sau ngày nhận phòng.'); }
        if ($start->diff($end)->days > self::MAX_NIGHTS) { throw new DomainException('Kỳ ở tối đa 365 đêm. Vui lòng chọn khoảng ngày ngắn hơn.'); }
        if ($guests < 1 || $guests > 65535) { throw new DomainException('Số khách không hợp lệ.'); }
    }
}

