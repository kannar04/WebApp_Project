<?php
// Admin closes one duplicate VNPay payment once the money is back with the Guest. Expects $duplicate (transaction_id, amount, reference); $duplicateBookingId returns to the booking page.
$duplicateId = (int)$duplicate['transaction_id'];
$duplicateAmount = number_format((float)$duplicate['amount'],0,',','.');
$duplicateBookingId = $duplicateBookingId ?? null;
?>
<form class="duplicate-form mt-2" method="post" action="<?= e(url('/admin/payments/duplicates/'.$duplicateId)) ?>"><?= csrf_field() ?><?php if($duplicateBookingId): ?><input type="hidden" name="return_to" value="detail"><input type="hidden" name="booking_id" value="<?= (int)$duplicateBookingId ?>"><?php endif; ?>
<p class="small text-danger mb-1"><?= e(__('Thanh toán trùng {amount}đ (mã {reference}) cần hoàn cho khách.', ['amount'=>$duplicateAmount, 'reference'=>$duplicate['reference']])) ?></p>
<label class="sr-only" for="duplicate-note-<?= $duplicateId ?>"><?= e(__('Ghi chú hoàn khoản trùng {reference}', ['reference'=>$duplicate['reference']])) ?></label><textarea class="form-control form-control-sm mb-1" id="duplicate-note-<?= $duplicateId ?>" name="note" maxlength="500" rows="2" placeholder="<?= e(__('Ghi chú đã hoàn khoản trùng')) ?>" required></textarea>
<button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="<?= e(__('Ghi nhận đã hoàn khoản trùng {amount}đ? Trạng thái thanh toán của booking không đổi.', ['amount'=>$duplicateAmount])) ?>"><?= e(__('Đã hoàn khoản trùng')) ?></button></form>
