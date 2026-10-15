<?php
declare(strict_types=1);

namespace App\Models;

use Core\ProcedureConnection as Database;

/** Online payment attempts: VietQR bank transfers and VNPay. Every authorization rule is enforced by the routines. */
final class Payment
{
    private \Core\ProcedureConnection $db;

    public function __construct() { $this->db = Database::connection(); }

    public function checkout(int $bookingId, int $guestId): ?array
    {
        $statement = $this->db->prepare('CALL sp_payment_checkout(?,?)');
        $statement->execute([$bookingId, $guestId]);
        return $statement->fetch() ?: null;
    }

    /** $provider: 'vietqr' | 'vnpay'. Returns the attempt (an unchanged VietQR attempt is reused). */
    public function start(int $bookingId, int $guestId, string $provider): array
    {
        $statement = $this->db->prepare('CALL sp_payment_start(?,?,?)');
        $statement->execute([$bookingId, $guestId, $provider]);
        return $statement->fetch() ?: [];
    }

    public function attempt(int $transactionId, int $actorId): ?array
    {
        $statement = $this->db->prepare('CALL sp_payment_transaction_get(?,?)');
        $statement->execute([$transactionId, $actorId]);
        return $statement->fetch() ?: null;
    }

    public function submitTransfer(int $transactionId, int $guestId): void
    {
        $statement = $this->db->prepare('CALL sp_payment_transfer_submit(?,?)');
        $statement->execute([$transactionId, $guestId]);
    }

    /** $decision: 'confirm' | 'reject' (reason required). */
    public function decideTransfer(int $transactionId, int $adminId, string $decision, string $note): void
    {
        $statement = $this->db->prepare('CALL sp_admin_payment_transfer_decide(?,?,?,?)');
        $statement->execute([$transactionId, $adminId, $decision, $note]);
    }

    /** Records a VNPay result whose signature was already verified; returns result/booking_id/transaction_status. */
    public function vnpayResult(string $reference, string $amount, string $responseCode, string $transactionStatus, string $transactionNo, string $bankCode): array
    {
        $statement = $this->db->prepare('CALL sp_payment_vnpay_result(?,?,?,?,?,?)');
        $statement->execute([$reference, $amount, $responseCode, $transactionStatus, $transactionNo, $bankCode]);
        return $statement->fetch() ?: [];
    }

    public function bookingAttempts(int $bookingId, int $actorId): array
    {
        $statement = $this->db->prepare('CALL sp_payment_booking_transactions(?,?)');
        $statement->execute([$bookingId, $actorId]);
        return $statement->fetchAll();
    }

    public function adminTransfers(int $adminId): array
    {
        $statement = $this->db->prepare('CALL sp_admin_payment_transfers(?)');
        $statement->execute([$adminId]);
        return $statement->fetchAll();
    }

    public static function methodLabel(string $method): string
    {
        return ['pay_at_checkin'=>'Thanh toán khi nhận phòng', 'vietqr'=>'Chuyển khoản VietQR', 'vnpay'=>'VNPay'][$method] ?? $method;
    }

    public static function attemptLabel(string $status): string
    {
        return ['pending'=>'Chưa hoàn tất', 'submitted'=>'Chờ Admin xác nhận', 'succeeded'=>'Thành công', 'failed'=>'Không thành công',
            'cancelled'=>'Đã hủy'][$status] ?? $status;
    }

    /** Guest-facing meaning of a VNPay vnp_ResponseCode (the common ones; anything else is a generic failure). */
    public static function vnpayMessage(string $code): string
    {
        return ['24'=>'Bạn đã hủy giao dịch VNPay.', '11'=>'Đã hết thời gian chờ thanh toán VNPay.', '51'=>'Tài khoản không đủ số dư.',
            '13'=>'Sai mã OTP.', '12'=>'Thẻ/tài khoản đang bị khóa.', '09'=>'Thẻ/tài khoản chưa đăng ký Internet Banking.'][$code]
            ?? 'Thanh toán VNPay không thành công.';
    }
}
