<?php
$amount = number_format((float)$attempt['amount'],0,',','.');
$bookingPath = '/bookings/'.(int)$attempt['booking_id'];
$payable = $attempt['status']==='pending' && $attempt['payment_status']==='unpaid' && $attempt['booking_status']==='confirmed';
$isAdmin = \Core\Auth::hasRole('admin');
?>
<section class="page-section"><div class="container narrow">
<div class="section-title"><div><p class="eyebrow"><?= e(__('CHUYỂN KHOẢN · BOOKING #{id}', ['id'=>(int)$attempt['booking_id']])) ?></p><h1><?= e(__('Chuyển khoản VietQR')) ?></h1><p><?= e($attempt['title']) ?> · <?= e(display_date($attempt['check_in'])) ?> → <?= e(display_date($attempt['check_out'])) ?></p></div><a class="btn btn-ghost" href="<?= e(url($bookingPath)) ?>"><?= e(__('Quay lại booking')) ?></a></div>
<article class="surface-card">
<p><span class="status status-<?= e($attempt['status']) ?>"><?= e(__(\App\Models\Payment::attemptLabel($attempt['status']))) ?></span></p>
<?php if($attempt['status']==='submitted'): ?>
<div class="alert alert-info" role="status"><?= e($isGuest?__('Bạn đã báo chuyển khoản lúc {time}. Admin đang kiểm tra tài khoản; bạn sẽ nhận thông báo khi có kết quả.', ['time'=>display_date($attempt['submitted_at'],true)]):__('Khách báo đã chuyển khoản lúc {time}.', ['time'=>display_date($attempt['submitted_at'],true)])) ?></div>
<?php elseif($attempt['status']==='succeeded'): ?>
<div class="alert alert-success" role="status"><?= e(__('Đã xác nhận nhận tiền lúc {time}.', ['time'=>display_date($attempt['decided_at'],true)])) ?></div>
<?php elseif($attempt['status']==='failed'): ?>
<div class="alert alert-warning" role="status"><?= e(__('Admin chưa xác nhận được khoản chuyển này.')) ?><?php if($attempt['note']): ?> <?= e(__('Lý do:')) ?> <?= e($attempt['note']) ?><?php endif; ?></div>
<?php elseif($attempt['status']==='cancelled'): ?>
<div class="alert alert-secondary" role="status"><?= e(__('Giao dịch này đã đóng.')) ?><?php if($attempt['note']): ?> <?= e(__((string)$attempt['note'])) ?><?php endif; ?></div>
<?php elseif(!$payable): ?>
<div class="alert alert-secondary" role="status"><?= e(__('Booking này không còn chờ thanh toán.')) ?></div>
<?php endif; ?>
<div class="payment-qr">
<?php if($payable && $qrUrl!==''): ?><img src="<?= e($qrUrl) ?>" width="300" height="370" alt="<?= e(__('Mã VietQR chuyển {amount}đ, nội dung {reference}', ['amount'=>$amount, 'reference'=>$attempt['reference']])) ?>" referrerpolicy="no-referrer"><?php endif; ?>
<div><dl class="trust-facts">
<?php if($payable && $qrUrl!==''): ?><dt><?= e(__('Ngân hàng')) ?></dt><dd><?= e($bank['bank_name']!==''?$bank['bank_name']:$bank['bank_id']) ?></dd><dt><?= e(__('Số tài khoản')) ?></dt><dd><?= e($bank['account_no']) ?></dd><?php if($bank['account_name']!==''): ?><dt><?= e(__('Chủ tài khoản')) ?></dt><dd><?= e($bank['account_name']) ?></dd><?php endif; ?><?php endif; ?>
<dt><?= e(__('Số tiền')) ?></dt><dd><strong><?= e(__('{amount}đ', ['amount'=>$amount])) ?></strong></dd>
<dt><?= e(__('Nội dung chuyển khoản')) ?></dt><dd><strong class="promo-code"><?= e($attempt['reference']) ?></strong></dd>
<dt><?= e(__('Tạo lúc')) ?></dt><dd><?= e(display_date($attempt['created_at'],true)) ?></dd>
</dl>
<?php if($payable && $qrUrl!==''): ?><p class="small text-muted mt-3"><?= e(__('Giữ nguyên số tiền và nội dung chuyển khoản để Admin đối chiếu; mã QR đã điền sẵn cả hai.')) ?></p>
<?php if($isGuest): ?><form method="post" action="<?= e(url('/payments/'.(int)$attempt['transaction_id'].'/transferred')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit" data-confirm="<?= e(__('Bạn đã chuyển {amount}đ với nội dung {reference}?', ['amount'=>$amount, 'reference'=>$attempt['reference']])) ?>"><?= e(__('Tôi đã chuyển khoản')) ?></button></form><?php endif; ?>
<?php elseif($payable): ?><p class="small text-muted mt-3"><?= e(__('Website chưa cấu hình tài khoản nhận chuyển khoản.')) ?></p><?php endif; ?>
<?php if($isGuest && $attempt['status']==='failed' && $attempt['payment_status']==='unpaid' && $attempt['booking_status']==='confirmed'): ?><a class="btn btn-outline-primary mt-2" href="<?= e(url($bookingPath.'/pay')) ?>"><?= e(__('Thanh toán lại')) ?></a><?php endif; ?>
<?php if($isAdmin && in_array($attempt['status'],['pending','submitted'],true)): ?><div class="mt-3"><?php $transfer=$attempt; $decideBookingId=null; require __DIR__.'/decide-form.php'; ?></div><?php endif; ?>
</div></div>
</article>
</div></section>
