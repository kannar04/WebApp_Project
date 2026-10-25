<?php
// Admin decision on one VietQR transfer. Expects $transfer (transaction_id, amount, reference); $decideBookingId returns to the booking page.
$transferId = (int)$transfer['transaction_id'];
$decideBookingId = $decideBookingId ?? null;
?>
<form class="transfer-decide" method="post" action="<?= e(url('/admin/payments/transfers/'.$transferId)) ?>"><?= csrf_field() ?><?php if($decideBookingId): ?><input type="hidden" name="return_to" value="detail"><input type="hidden" name="booking_id" value="<?= (int)$decideBookingId ?>"><?php endif; ?>
<label class="sr-only" for="transfer-note-<?= $transferId ?>"><?= e(__('Ghi chú giao dịch {reference}', ['reference'=>$transfer['reference']])) ?></label><input class="form-control form-control-sm mb-1" id="transfer-note-<?= $transferId ?>" name="note" maxlength="500" placeholder="<?= e(__('Ghi chú (bắt buộc khi chưa nhận tiền)')) ?>">
<div class="action-row"><button class="btn btn-sm btn-primary" type="submit" name="decision" value="confirm" data-confirm="<?= e(__('Tài khoản đã nhận {amount}đ với nội dung {reference}?', ['amount'=>number_format((float)$transfer['amount'],0,',','.'), 'reference'=>$transfer['reference']])) ?>"><?= e(__('Đã nhận tiền')) ?></button><button class="btn btn-sm btn-outline-danger" type="submit" name="decision" value="reject" data-confirm="<?= e(__('Báo khách là chưa nhận được khoản chuyển này?')) ?>"><?= e(__('Chưa nhận được')) ?></button></div></form>
