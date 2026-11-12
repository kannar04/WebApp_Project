<?php
declare(strict_types=1);

namespace App\Services;

/**
 * VNPay payment gateway, API version 2.1.0 (https://sandbox.vnpayment.vn/apis/docs/thanh-toan-pay/pay.html).
 * Requests and results are signed with HMAC-SHA512 over the vnp_* fields sorted by name and URL-encoded.
 */
final class VnpayGateway
{
    public static function enabled(array $config): bool
    {
        return preg_match('/^[A-Za-z0-9]{8}$/D', $config['vnpay_tmn_code']) === 1 && $config['vnpay_hash_secret'] !== ''
            && filter_var($config['vnpay_pay_url'], FILTER_VALIDATE_URL) !== false
            && parse_url($config['vnpay_pay_url'], PHP_URL_SCHEME) === 'https'
            && parse_url($config['vnpay_pay_url'], PHP_URL_QUERY) === null
            && parse_url($config['vnpay_pay_url'], PHP_URL_FRAGMENT) === null;
    }

    /** Address the Guest is sent to for one payment attempt; it expires after 15 minutes. */
    public static function paymentUrl(array $config, array $attempt, string $returnUrl, string $clientIp, string $locale, \DateTimeImmutable $now): string
    {
        if (!self::enabled($config)) { throw new \DomainException('Cấu hình VNPay không hợp lệ: kiểm tra mã Terminal ID 8 ký tự, khóa bí mật và URL HTTPS.'); }
        if (filter_var($returnUrl,FILTER_VALIDATE_URL)===false || !in_array(parse_url($returnUrl,PHP_URL_SCHEME),['http','https'],true) || strlen($returnUrl)>255) {
            throw new \DomainException('URL trả kết quả VNPay phải là địa chỉ HTTP/HTTPS hợp lệ, tối đa 255 ký tự.');
        }
        if (!is_string($attempt['reference']??null) || !preg_match('/^[A-Za-z0-9]{1,100}$/D',$attempt['reference']) || filter_var($clientIp,FILTER_VALIDATE_IP)===false) {
            throw new \DomainException('Mã giao dịch hoặc địa chỉ IP thanh toán không hợp lệ.');
        }
        $now = $now->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $fields = ['vnp_Version' => '2.1.0', 'vnp_Command' => 'pay', 'vnp_TmnCode' => $config['vnpay_tmn_code'],
            'vnp_Amount' => self::gatewayAmount($attempt['amount']), 'vnp_CurrCode' => 'VND',
            'vnp_TxnRef' => (string) $attempt['reference'], 'vnp_OrderInfo' => 'Thanh toan booking '.(int) $attempt['booking_id'],
            'vnp_OrderType' => 'other', 'vnp_Locale' => $locale === 'en' ? 'en' : 'vn', 'vnp_ReturnUrl' => $returnUrl,
            'vnp_IpAddr' => $clientIp, 'vnp_CreateDate' => $now->format('YmdHis'), 'vnp_ExpireDate' => $now->modify('+15 minutes')->format('YmdHis')];
        $query = self::signedData($fields);
        return $config['vnpay_pay_url'].'?'.$query.'&vnp_SecureHash='.hash_hmac('sha512', $query, $config['vnpay_hash_secret']);
    }

    /** True only when the vnp_* fields (return URL or IPN query) carry VNPay's signature for this merchant. */
    public static function verify(array $config, array $input): bool
    {
        $received = $input['vnp_SecureHash'] ?? '';
        if (!is_string($received) || !preg_match('/^[a-fA-F0-9]{128}$/D',$received) || $config['vnpay_hash_secret'] === '') { return false; }
        $fields = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'vnp_') || $key === 'vnp_SecureHash' || $key === 'vnp_SecureHashType') { continue; }
            if (!is_string($value)) { return false; }
            $fields[$key] = $value;
        }
        if (($fields['vnp_TmnCode'] ?? '') !== $config['vnpay_tmn_code']) { return false; }
        return hash_equals(hash_hmac('sha512', self::signedData($fields), $config['vnpay_hash_secret']), strtolower($received));
    }

    /** Exact decimal ×100. The database owns whole-VND rounding; the adapter never rounds again. */
    public static function gatewayAmount(mixed $amount): string
    {
        if ((!is_string($amount) && !is_int($amount)) || !preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D',(string)$amount,$match)) {
            throw new \DomainException('Số tiền VNPay không hợp lệ.');
        }
        $value=ltrim($match[1].str_pad($match[2]??'',2,'0'),'0');
        if ($value==='' || strlen($value)>12) { throw new \DomainException('Số tiền VNPay phải lớn hơn 0 và nằm trong giới hạn 12 chữ số sau khi nhân 100.'); }
        return $value;
    }

    public static function returnedAmount(mixed $amount): ?string
    {
        if (!is_string($amount) || !preg_match('/^[0-9]{1,12}$/D',$amount) || (int)$amount<=0) { return null; }
        return intdiv((int)$amount,100).'.'.str_pad((string)((int)$amount%100),2,'0',STR_PAD_LEFT);
    }

    /** The exact string VNPay signs: key=value pairs sorted by key, both URL-encoded, joined with '&'. */
    public static function signedData(array $fields): string
    {
        ksort($fields);
        $pairs = [];
        foreach ($fields as $key => $value) { $pairs[] = urlencode((string) $key).'='.urlencode((string) $value); }
        return implode('&', $pairs);
    }
}
