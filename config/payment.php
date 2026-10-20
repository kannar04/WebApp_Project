<?php
declare(strict_types=1);

// Online payment settings come only from the environment (.env), never from the repository.
// VietQR is shown when the receiving bank account is set; VNPay when the sandbox/merchant keys are set.
return [
    'bank_id' => trim((string) (getenv('PAYMENT_BANK_ID') ?: '')),
    'bank_name' => trim((string) (getenv('PAYMENT_BANK_NAME') ?: '')),
    'account_no' => trim((string) (getenv('PAYMENT_ACCOUNT_NO') ?: '')),
    'account_name' => trim((string) (getenv('PAYMENT_ACCOUNT_NAME') ?: '')),
    'vnpay_tmn_code' => trim((string) (getenv('VNPAY_TMN_CODE') ?: '')),
    'vnpay_hash_secret' => trim((string) (getenv('VNPAY_HASH_SECRET') ?: '')),
    'vnpay_pay_url' => trim((string) (getenv('VNPAY_PAY_URL') ?: 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html')),
    // Empty: built from the address the Guest is using, so the return works on localhost and over Radmin VPN alike.
    'vnpay_return_url' => trim((string) (getenv('VNPAY_RETURN_URL') ?: '')),
];
