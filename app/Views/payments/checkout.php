<?php
$amount = number_format((float)$checkout['amount_due'],0,',','.');
$bookingPath = '/bookings/'.(int)$checkout['booking_id'];
?>
<section class="page-section"><div class="container narrow">
<div class="section-title"><div><p class="eyebrow"><?= e(__('THANH TOÁN BOOKING #{id}', ['id'=>(int)$checkout['booking_id']])) ?></p><h1><?= e(__('Thanh toán online')) ?></h1><p><?= e($checkout['title']) ?> · <?= e(display_date($checkout['check_in'])) ?> → <?= e(display_date($checkout['check_out'])) ?></p></div><a class="btn btn-ghost" href="<?= e(url($bookingPath)) ?>"><?= e(__('Quay lại booking')) ?></a></div>
<article class="surface-card">
<p class="mb-3"><?= e(__('Số tiền cần thanh toán:')) ?> <strong><?= e(__('{amount}đ', ['amount'=>$amount])) ?></strong></p>
<?php if($checkout['payment_status']!=='unpaid'): ?>
<div class="alert alert-success" role="status"><?= e(__('Booking này đã được ghi nhận thanh toán.')) ?></div>
<?php elseif(!$checkout['can_pay_online']): ?>
<div class="alert alert-info" role="status"><?= e($checkout['booking_status']==='pending'?__('Bạn có thể thanh toán online sau khi Host xác nhận booking.'):__('Booking này không thể thanh toán online.')) ?></div>
<?php elseif($checkout['transfer_status']==='submitted'): ?>
<div class="alert alert-info" role="status"><?= e(__('Bạn đã báo chuyển khoản với nội dung {reference}. Admin đang kiểm tra tài khoản và sẽ xác nhận thanh toán.', ['reference'=>$checkout['transfer_reference']])) ?></div>
<a class="btn btn-outline-primary" href="<?= e(url('/payments/'.(int)$checkout['transfer_id'])) ?>"><?= e(__('Xem giao dịch chuyển khoản')) ?></a>
<?php elseif(!$vietqrEnabled && !$vnpayEnabled): ?>
<div class="alert alert-warning" role="status"><?= e(__('Website chưa cấu hình thanh toán online. Bạn vẫn có thể thanh toán cho Host khi nhận phòng.')) ?></div>
<?php else: ?>
<div class="pay-options">
<?php if($vietqrEnabled): ?><section class="pay-option" aria-labelledby="pay-vietqr-title"><h2 class="h5" id="pay-vietqr-title"><?= e(__('Chuyển khoản VietQR')) ?></h2><p><?= e(__('Quét mã QR bằng ứng dụng ngân hàng, MoMo hoặc ZaloPay; số tiền và nội dung được điền sẵn. Admin xác nhận sau khi tiền vào tài khoản.')) ?></p>
<form method="post" action="<?= e(url($bookingPath.'/pay/vietqr')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-block" type="submit"><?= e($checkout['transfer_status']==='pending'?__('Xem lại mã QR'):__('Lấy mã QR chuyển khoản')) ?></button></form></section><?php endif; ?>
<?php if($vnpayEnabled): ?><section class="pay-option" aria-labelledby="pay-vnpay-title"><h2 class="h5" id="pay-vnpay-title">VNPay</h2><p><?= e(__('Thanh toán bằng thẻ ATM, thẻ quốc tế hoặc mã QR qua cổng VNPay. Booking được ghi nhận đã thanh toán ngay khi VNPay báo thành công.')) ?></p>
<?php if($vnpaySandbox): ?><p class="small text-muted"><?= e(__('Cổng VNPay thử nghiệm (sandbox): dùng thẻ test của VNPay, không trừ tiền thật.')) ?></p><?php endif; ?>
<form method="post" action="<?= e(url($bookingPath.'/pay/vnpay')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary btn-block" type="submit"><?= e(__('Thanh toán qua VNPay')) ?></button></form></section><?php endif; ?>
</div>
<p class="text-muted small mt-3 mb-0"><?= e(__('Không muốn trả trước? Bạn vẫn có thể thanh toán cho Host khi nhận phòng.')) ?></p>
<?php endif; ?>
</article>
</div></section>
