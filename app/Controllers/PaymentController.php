<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Payment;
use App\Services\VietQr;
use App\Services\VnpayGateway;
use Core\Controller;
use Core\Csrf;
use Core\I18n;
use Core\Session;

/**
 * Online payment for a confirmed booking: VietQR bank transfer to the account in .env (Admin confirms receipt)
 * or VNPay (the signed gateway result marks the booking paid). Pay at check-in stays available.
 */
final class PaymentController extends Controller
{
    private const GENERIC_ERROR = 'Không thể hoàn tất thao tác. Vui lòng thử lại.';

    public function checkout(string $id): void
    {
        $user = $this->requireAuth();
        try { $checkout = (new Payment())->checkout((int) $id, (int) $user['id']); }
        catch (\DomainException $exception) { $checkout = null; }
        if ($checkout === null) {
            http_response_code(404); $this->render('errors/404', ['title'=>'Không tìm thấy booking']); return;
        }
        $config = self::config();
        $this->render('payments/checkout', ['title'=>'Thanh toán online', 'checkout'=>$checkout,
            'vietqrEnabled'=>VietQr::enabled($config), 'vnpayEnabled'=>VnpayGateway::enabled($config),
            'vnpaySandbox'=>str_contains($config['vnpay_pay_url'], 'sandbox.vnpayment.vn')]);
    }

    public function startTransfer(string $id): never
    {
        Csrf::ensure(); $user = $this->requireAuth();
        $attempt = [];
        try {
            if (!VietQr::enabled(self::config())) { throw new \DomainException('Chưa cấu hình tài khoản nhận chuyển khoản.'); }
            $attempt = (new Payment())->start((int) $id, (int) $user['id'], 'vietqr');
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
            $this->redirect('/bookings/'.(int) $id.'/pay');
        }
        $this->redirect('/payments/'.(int) $attempt['transaction_id']);
    }

    public function startVnpay(string $id): never
    {
        Csrf::ensure(); $user = $this->requireAuth();
        $config = self::config();
        $target = '';
        try {
            if (!VnpayGateway::enabled($config)) { throw new \DomainException('Chưa cấu hình VNPay.'); }
            $attempt = (new Payment())->start((int) $id, (int) $user['id'], 'vnpay');
            $target = VnpayGateway::paymentUrl($config, $attempt, self::returnUrl($config), self::clientIp(), I18n::locale(), new \DateTimeImmutable('now'));
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
            $this->redirect('/bookings/'.(int) $id.'/pay');
        }
        header('Location: '.$target);
        exit;
    }

    /** VNPay sends the Guest's browser back here. The signature is checked before anything is recorded. */
    public function vnpayReturn(): never
    {
        $config = self::config();
        if (!VnpayGateway::verify($config, $_GET)) {
            Session::flash('error', 'Kết quả thanh toán không hợp lệ (sai chữ ký VNPay).');
            $this->redirect('/bookings');
        }
        try { $result = $this->recordVnpay($_GET); }
        catch (\Throwable $exception) {
            Session::flash('error', self::GENERIC_ERROR);
            $this->redirect('/bookings');
        }
        $bookingId = (int) ($result['booking_id'] ?? 0);
        if ($result['result'] === 'not_found' || $bookingId === 0) {
            Session::flash('error', 'Không tìm thấy giao dịch thanh toán.');
            $this->redirect('/bookings');
        }
        if ($result['result'] === 'invalid_amount') {
            Session::flash('error', 'Số tiền VNPay trả về không khớp với giao dịch.');
        } elseif ($result['transaction_status'] === 'succeeded') {
            Session::flash('success', 'Thanh toán VNPay thành công cho booking #'.$bookingId.'.');
        } else {
            Session::flash('error', Payment::vnpayMessage((string) ($result['response_code'] ?? '')));
        }
        $this->redirect('/bookings/'.$bookingId);
    }

    /** Server-to-server notification (needs a public URL); answers in the JSON format VNPay expects. */
    public function vnpayIpn(): never
    {
        $config = self::config();
        $answer = ['RspCode'=>'99', 'Message'=>'Unknow error'];
        if (!VnpayGateway::verify($config, $_GET)) {
            $answer = ['RspCode'=>'97', 'Message'=>'Invalid signature'];
        } else {
            try {
                $result = $this->recordVnpay($_GET);
                $answer = ['ok'=>['RspCode'=>'00', 'Message'=>'Confirm Success'], 'already'=>['RspCode'=>'02', 'Message'=>'Order already confirmed'],
                    'not_found'=>['RspCode'=>'01', 'Message'=>'Order not found'], 'invalid_amount'=>['RspCode'=>'04', 'Message'=>'invalid amount']][$result['result']] ?? $answer;
            } catch (\Throwable $exception) {
                $answer = ['RspCode'=>'99', 'Message'=>'Unknow error'];
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($answer);
        exit;
    }

    public function transfer(string $id): void
    {
        $user = $this->requireAuth();
        try { $attempt = (new Payment())->attempt((int) $id, (int) $user['id']); }
        catch (\DomainException $exception) { $attempt = null; }
        if ($attempt === null || $attempt['provider'] !== 'vietqr') {
            http_response_code(404); $this->render('errors/404', ['title'=>'Không tìm thấy giao dịch']); return;
        }
        $config = self::config();
        $this->render('payments/transfer', ['title'=>'Chuyển khoản VietQR', 'attempt'=>$attempt, 'bank'=>$config,
            'qrUrl'=>VietQr::enabled($config) ? VietQr::imageUrl($config, (float) $attempt['amount'], $attempt['reference']) : '',
            'isGuest'=>(int) $attempt['guest_id'] === (int) $user['id']]);
    }

    public function submitTransfer(string $id): never
    {
        Csrf::ensure(); $user = $this->requireAuth();
        try {
            (new Payment())->submitTransfer((int) $id, (int) $user['id']);
            Session::flash('success', 'Đã báo chuyển khoản. Admin sẽ kiểm tra tài khoản và xác nhận thanh toán.');
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
        $this->redirect('/payments/'.(int) $id);
    }

    public function adminDecideTransfer(string $id): never
    {
        Csrf::ensure(); $admin = $this->requireRole('admin');
        $decision = ($_POST['decision'] ?? '') === 'reject' ? 'reject' : 'confirm';
        $note = is_string($_POST['note'] ?? null) ? mb_substr(trim($_POST['note']), 0, 501) : '';
        $returnTo = ($_POST['return_to'] ?? '') === 'detail' && ctype_digit((string) ($_POST['booking_id'] ?? '')) ? '/bookings/'.(int) $_POST['booking_id'] : '/admin/payments';
        try {
            if (mb_strlen($note) > 500) { throw new \DomainException('Ghi chú tối đa 500 ký tự.'); }
            (new Payment())->decideTransfer((int) $id, (int) $admin['id'], $decision, $note);
            Session::flash('success', $decision === 'confirm' ? 'Đã xác nhận nhận được tiền chuyển khoản.' : 'Đã báo khách chưa nhận được tiền chuyển khoản.');
        } catch (\Throwable $exception) {
            Session::flash('error', $exception instanceof \DomainException ? $exception->getMessage() : self::GENERIC_ERROR);
        }
        $this->redirect($returnTo);
    }

    /** Shared by the return URL and the IPN: plain strings only, amount converted back from VNPay's ×100. */
    private function recordVnpay(array $input): array
    {
        $rawAmount = self::field($input, 'vnp_Amount', 15);
        $amount = ctype_digit($rawAmount) ? intdiv((int) $rawAmount, 100).'.'.str_pad((string) ((int) $rawAmount % 100), 2, '0', STR_PAD_LEFT) : '-1';
        return (new Payment())->vnpayResult(self::field($input, 'vnp_TxnRef', 32), $amount, self::field($input, 'vnp_ResponseCode', 4),
            self::field($input, 'vnp_TransactionStatus', 4), self::field($input, 'vnp_TransactionNo', 40), self::field($input, 'vnp_BankCode', 20));
    }

    private static function field(array $input, string $key, int $limit): string
    {
        return is_string($input[$key] ?? null) ? mb_substr($input[$key], 0, $limit) : '';
    }

    private static function config(): array
    {
        return require dirname(__DIR__, 2).'/config/payment.php';
    }

    /** Absolute return address: VNPAY_RETURN_URL, else APP_URL, else the host the Guest is browsing. */
    private static function returnUrl(array $config): string
    {
        if ($config['vnpay_return_url'] !== '') { return $config['vnpay_return_url']; }
        $path = url('/payments/vnpay/return');
        if (preg_match('#^https?://#i', $path)) { return $path; }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/D', $host)) { throw new \DomainException('Không xác định được địa chỉ trang web để VNPay trả kết quả.'); }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https://' : 'http://').$host.$path;
    }

    private static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '127.0.0.1';
    }
}
