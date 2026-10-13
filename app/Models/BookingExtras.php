<?php
declare(strict_types=1);
namespace App\Models;

use Core\ProcedureConnection;

/** Booking extras: flexible pricing rules, holidays, promotions and pay-at-check-in payment status. Rules live in the routines. */
final class BookingExtras
{
    private ProcedureConnection $db;
    public function __construct() { $this->db = ProcedureConnection::connection(); }

    public function listingRules(int $listingId): ?array
    {
        $statement = $this->db->prepare('CALL sp_listing_booking_rules(?)');
        $statement->execute([$listingId]);
        return $statement->fetch() ?: null;
    }

    public function upcomingHolidays(int $limit): array
    {
        $statement = $this->db->prepare('CALL sp_public_holidays_upcoming(?)');
        $statement->execute([$limit]);
        return $statement->fetchAll();
    }

    public function hostSettings(int $listingId, int $actorId): array
    {
        $statement = $this->db->prepare('CALL sp_host_booking_settings_get(?,?)');
        $statement->execute([$listingId, $actorId]);
        $settings = $statement->fetch();
        if (!$settings) { throw new \DomainException('Không tìm thấy chỗ ở.'); }
        return $settings;
    }

    public function saveHostSettings(int $listingId, int $actorId, ?string $weekend, ?string $holiday, ?string $weekly, ?string $monthly, bool $instant): void
    {
        $statement = $this->db->prepare('CALL sp_host_booking_settings_save(?,?,?,?,?,?,?)');
        $statement->execute([$listingId, $actorId, $weekend, $holiday, $weekly, $monthly, $instant ? 1 : 0]);
    }

    public function holidays(int $actorId): array
    {
        $statement = $this->db->prepare('CALL sp_admin_holidays(?)');
        $statement->execute([$actorId]);
        return $statement->fetchAll();
    }

    public function saveHoliday(int $actorId, string $date, string $name): int
    {
        $statement = $this->db->prepare('CALL sp_admin_holiday_save(?,?,?)');
        $statement->execute([$actorId, $date, $name]);
        return (int) $statement->fetchColumn();
    }

    public function deleteHoliday(int $actorId, int $holidayId): void
    {
        $statement = $this->db->prepare('CALL sp_admin_holiday_delete(?,?)');
        $statement->execute([$actorId, $holidayId]);
    }

    public function promotions(int $actorId): array
    {
        $statement = $this->db->prepare('CALL sp_admin_promotions(?)');
        $statement->execute([$actorId]);
        return $statement->fetchAll();
    }

    /** $data keys: code, description, type, value, max, min_nights, min_subtotal, from, to, limit, active (optional values are null). */
    public function savePromotion(int $actorId, int $promotionId, array $data): int
    {
        $statement = $this->db->prepare('CALL sp_admin_promotion_save(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$actorId, $promotionId, $data['code'], $data['description'], $data['type'], $data['value'], $data['max'],
            $data['min_nights'], $data['min_subtotal'], $data['from'], $data['to'], $data['limit'], $data['active'] ? 1 : 0]);
        return (int) $statement->fetchColumn();
    }

    public function togglePromotion(int $actorId, int $promotionId, bool $active): void
    {
        $statement = $this->db->prepare('CALL sp_admin_promotion_toggle(?,?,?)');
        $statement->execute([$actorId, $promotionId, $active ? 1 : 0]);
    }

    public function deletePromotion(int $actorId, int $promotionId): void
    {
        $statement = $this->db->prepare('CALL sp_admin_promotion_delete(?,?)');
        $statement->execute([$actorId, $promotionId]);
    }

    public function bookingDetail(int $bookingId, int $actorId): array
    {
        $statement = $this->db->prepare('CALL sp_booking_extras_detail(?,?)');
        $statement->execute([$bookingId, $actorId]);
        return $statement->fetch() ?: [];
    }

    /** Payment state per booking ID for the Guest's trips ('guest') or the Host's listing bookings ('host'). */
    public function paymentMap(int $actorId, string $scope): array
    {
        $statement = $this->db->prepare('CALL sp_booking_payment_list(?,?)');
        $statement->execute([$actorId, $scope]);
        $map = [];
        foreach ($statement->fetchAll() as $row) { $map[(int) $row['booking_id']] = $row; }
        return $map;
    }

    public function markPaid(int $bookingId, int $actorId, bool $asAdmin, string $note): void
    {
        $statement = $this->db->prepare('CALL sp_booking_payment_mark_paid(?,?,?,?)');
        $statement->execute([$bookingId, $actorId, $asAdmin ? 1 : 0, $note]);
    }

    public function refund(int $bookingId, int $actorId, string $note): void
    {
        $statement = $this->db->prepare('CALL sp_booking_payment_refund(?,?,?)');
        $statement->execute([$bookingId, $actorId, $note]);
    }

    public function adminPayments(int $actorId, string $status, string $query): array
    {
        $statement = $this->db->prepare('CALL sp_admin_payments(?,?,?)');
        $statement->execute([$actorId, $status, $query]);
        return $statement->fetchAll();
    }

    public function adminPaymentTotals(int $actorId): array
    {
        $statement = $this->db->prepare('CALL sp_admin_payment_totals(?)');
        $statement->execute([$actorId]);
        return $statement->fetch() ?: [];
    }

    public static function paymentLabel(string $state): string
    {
        return ['unpaid'=>'Chưa thanh toán', 'paid'=>'Đã thanh toán', 'refunded'=>'Đã hoàn tiền', 'not_required'=>'Không cần thanh toán'][$state] ?? $state;
    }
}
