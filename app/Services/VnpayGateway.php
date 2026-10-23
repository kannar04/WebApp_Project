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
        return $config['vnpay_tmn_code'] !== '' && $config['vnpay_hash_secret'] !== ''
            && filter_var($config['vnpay_pay_url'], FILTER_VALIDATE_URL) !== false;
    }

    /** Address the Guest is sent to for one payment attempt; it expires after 15 minutes. */
    public static function paymentUrl(array $config, array $attempt, string $returnUrl, string $clientIp, string $locale, \DateTimeImmutable $now): string
    {
        $now = $now->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $fields = ['vnp_Version' => '2.1.0', 'vnp_Command' => 'pay', 'vnp_TmnCode' => $config['vnpay_tmn_code'],
            'vnp_Amount' => (string) ((int) round((float) $attempt['amount']) * 100), 'vnp_CurrCode' => 'VND',
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
        if (!is_string($received) || $received === '' || $config['vnpay_hash_secret'] === '') { return false; }
        $fields = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'vnp_') || $key === 'vnp_SecureHash' || $key === 'vnp_SecureHashType') { continue; }
            if (!is_string($value)) { return false; }
            $fields[$key] = $value;
        }
        if (($fields['vnp_TmnCode'] ?? '') !== $config['vnpay_tmn_code']) { return false; }
        return hash_equals(hash_hmac('sha512', self::signedData($fields), $config['vnpay_hash_secret']), strtolower($received));
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
