# VNPay integration review — 10 November 2026

Reference: [official VNPay payment specification](https://sandbox.vnpayment.vn/apis/docs/thanh-toan-pay/pay.html) and [2.1.0 signing changes](https://sandbox.vnpayment.vn/apis/docs/chuyen-doi-thuat-toan/changeTypeHash.html). The official page was downloaded directly for this review; the web reader timed out.

## Request format

The existing sandbox endpoint and API version are current in this specification:

```text
https://sandbox.vnpayment.vn/paymentv2/vpcpay.html
vnp_Version=2.1.0
vnp_Command=pay
```

The adapter keeps HMAC-SHA512 over sorted, PHP `urlencode`-encoded `vnp_*` fields. It does not send the obsolete `vnp_SecureHashType`. Vietnamese locale remains `vn`, currency is `VND`, timestamps use GMT+7, and expiry is 15 minutes. Omitting `vnp_BankCode` lets the guest choose a bank at VNPay.

Changes made:

- Validate an eight-character alphanumeric Terminal ID and a clean HTTPS gateway URL.
- Multiply a decimal amount by 100 using strings, without floating-point conversion or additional rounding. Reject nonpositive amounts and values outside the documented twelve-digit gateway amount limit. The existing database policy still rounds payable VND amounts to whole dong before creating a transaction.
- Validate the reference, client IP and return URL before producing the signed URL.
- Require a 128-character hexadecimal SHA512 signature when validating callbacks.
- Reject malformed or oversized callback amounts, transaction references, status codes, transaction numbers and bank codes. Oversized values are rejected instead of silently truncated.

## IPN and browser return

`/payments/vnpay/ipn` is the server-to-server notification endpoint. It verifies the signature and merchant code before checking order existence, amount and transaction state. The database routine retains its existing protection against duplicate results, duplicate payments and payments received after cancellation.

The endpoint replies with JSON and HTTP 200:

| RspCode | Meaning |
| --- | --- |
| `00` | Notification recorded |
| `01` | Order not found |
| `02` | Already processed |
| `04` | Amount mismatch |
| `97` | Invalid signature |
| `99` | Processing error / malformed required fields |

`/payments/vnpay/return` is now display-only. It verifies the signed browser result and redirects to booking details, whose normal access checks still apply. It does not record a payment. If the browser returns before IPN arrives, the guest sees a message explaining that server confirmation is pending.

**Local development needs a public HTTPS endpoint for IPN.** Register `https://YOUR-DEVELOPMENT-DOMAIN/payments/vnpay/ipn` with VNPay. A localhost or private VPN address is not reachable by VNPay's public notification server. Set `VNPAY_RETURN_URL` to the browser return address you want to use; when using a public development domain, this can be `https://YOUR-DEVELOPMENT-DOMAIN/payments/vnpay/return`. The public hostname must actually route to this application. No public exposure or tunnel was created by this patch.

## Merchant configuration error

The app's merchant settings were present and the configured endpoint was sandbox at the time of review. This does not prove VNPay has activated the terminal. A gateway page saying “Điểm thanh toán chưa được cấu hình” still needs the merchant's exact sandbox Terminal ID and VNPay-side terminal configuration checked. These code changes do not activate a terminal or replace its credentials.

## Validation

Run `php tests/vnpay_gateway_test.php` for field encoding, timestamp, exact amount, signature, tampering and return-handler regression checks. No real merchant secret or network payment is used by that test.

Also checked on the running local app: invalid-signature IPN returns `97`; signed unknown reference returns `01`; malformed amount returns `04`; missing required status returns `99`; a valid signed browser return redirects without recording payment. Callback checks use the configured secret in memory without printing it and only target the local server.

A transaction-scoped local database fixture verified amount mismatch rejection, successful recording and idempotent repeat processing. All fixture records were rolled back, and the existing booking, transaction, payment, payment-event and notification row counts were preserved. No external sandbox payment was completed during this review. No database migration is needed for these changes.
