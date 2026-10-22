<?php
declare(strict_types=1);

namespace App\Services;

/** VietQR (NAPAS 247) bank-transfer codes for the receiving account set in config/payment.php. */
final class VietQr
{
    public static function enabled(array $config): bool
    {
        return preg_match('/^[A-Za-z0-9]{2,20}$/D', $config['bank_id']) === 1 && preg_match('/^[A-Za-z0-9]{4,19}$/D', $config['account_no']) === 1;
    }

    /** QR image from the free VietQR quick link; any banking app, MoMo or ZaloPay fills in account, amount and content. */
    public static function imageUrl(array $config, float $amount, string $reference): string
    {
        $query = http_build_query(['amount' => (string) (int) round($amount), 'addInfo' => $reference, 'accountName' => $config['account_name']],
            '', '&', PHP_QUERY_RFC3986);
        return 'https://img.vietqr.io/image/'.rawurlencode($config['bank_id']).'-'.rawurlencode($config['account_no']).'-compact2.png?'.$query;
    }
}
