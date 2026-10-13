<?php
$labels=['pending'=>__('Chờ duyệt'),'confirmed'=>__('Đã xác nhận'),'rejected'=>__('Bị từ chối'),'cancelled'=>__('Đã hủy'),'completed'=>__('Hoàn thành')];
$policy=$booking['policy'];
$extras=$extras??[];
?>
<section class="page-section"><div class="container narrow">
    <div class="section-title"><div><p class="eyebrow"><?= e(__('CHI TIẾT BOOKING #{id}', ['id'=>(int)$booking['id']])) ?></p><h1><?= e($booking['title']) ?></h1></div><a class="btn btn-ghost" href="<?= e(url($back)) ?>"><?= e(__('Quay lại danh sách')) ?></a></div>
    <article class="surface-card">
        <span class="status status-<?= e($booking['status']) ?>"><?= e($labels[$booking['status']]) ?></span><?php if(!empty($extras['is_instant_book'])): ?> <span class="instant-tag"><?= e(__('⚡ Đặt ngay')) ?></span><?php endif; ?>
        <p class="mt-3"><?= e($booking['address']) ?> · <?= e($booking['city']) ?></p>
        <p><?= e($booking['check_in']) ?> → <?= e($booking['check_out']) ?> · <?= e(__('{count} khách', ['count'=>(int)$booking['guest_count']])) ?></p>
        <p>Guest: <?= e($booking['guest_name']) ?><?php $badge=($trustBadges??[])[(int)$booking['guest_id']]??null; require dirname(__DIR__).'/components/trust-badges.php'; ?> · Host: <?= e($booking['host_name']) ?><?php $badge=($trustBadges??[])[(int)$booking['host_id']]??null; $badgeShowHost=true; require dirname(__DIR__).'/components/trust-badges.php'; ?></p>
        <?php if($actorId===(int)$booking['guest_id'] && $booking['host_phone']): ?><p><?= e(__('Liên hệ Host:')) ?> <a href="tel:<?= e($booking['host_phone']) ?>"><?= e($booking['host_phone']) ?></a><?php if($zaloUrl=\App\Services\ZaloLink::url($booking['host_phone'])): ?> <a class="btn btn-sm btn-soft zalo-link" href="<?= e($zaloUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e(__('Liên hệ qua Zalo')) ?><span class="sr-only"> <?= e(__('với Host (mở tab mới)')) ?></span></a><?php endif; ?></p>
        <?php elseif($actorId===(int)$booking['host_id'] && $booking['guest_phone']): ?><p><?= e(__('Liên hệ Guest:')) ?> <a href="tel:<?= e($booking['guest_phone']) ?>"><?= e($booking['guest_phone']) ?></a><?php if($zaloUrl=\App\Services\ZaloLink::url($booking['guest_phone'])): ?> <a class="btn btn-sm btn-soft zalo-link" href="<?= e($zaloUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e(__('Liên hệ qua Zalo')) ?><span class="sr-only"> <?= e(__('với Guest (mở tab mới)')) ?></span></a><?php endif; ?></p>
        <?php else: ?><p class="text-muted"><?= e(__('Thông tin liên hệ của hai bên chỉ mở sau khi booking được xác nhận.')) ?></p><?php endif; ?>
        <?php require __DIR__.'/checkin-guide.php'; ?>
        <h2 class="h4"><?= e(__('Giá đã lưu khi đặt')) ?></h2>
        <div class="table-responsive"><table class="table"><thead><tr><th><?= e(__('Đêm lưu trú')) ?></th><th><?= e(__('Giá / đêm ({currency})', ['currency'=>$booking['currency']])) ?></th></tr></thead><tbody>
        <?php foreach($booking['nights'] as $night): ?><tr><td><?= e($night['stay_date']) ?></td><td><?= number_format((float)$night['nightly_amount'],0,',','.') ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><?php if(!empty($extras['has_breakdown']) && ((float)$extras['length_discount_amount']>0 || (float)$extras['promotion_discount_amount']>0)): ?><tr><th><?= e(__('Tiền phòng')) ?></th><td><?= number_format((float)$extras['nights_subtotal'],0,',','.') ?></td></tr><?php if((float)$extras['length_discount_amount']>0): ?><tr class="discount-row"><th><?= e($extras['length_discount_type']==='monthly'?__('Giảm ở từ 28 đêm'):__('Giảm ở từ 7 đêm')) ?> (<?= e(\App\Services\BookingService::percent((float)$extras['length_discount_pct'])) ?>)</th><td>−<?= number_format((float)$extras['length_discount_amount'],0,',','.') ?></td></tr><?php endif; ?><?php if((float)$extras['promotion_discount_amount']>0): ?><tr class="discount-row"><th><?= e(__('Mã khuyến mãi')) ?> <?= e($extras['promotion_code']) ?></th><td>−<?= number_format((float)$extras['promotion_discount_amount'],0,',','.') ?></td></tr><?php endif; ?><?php endif; ?><tr><th><?= e(__('Phụ phí đã lưu')) ?></th><td><?= number_format((float)$booking['fee_amount'],0,',','.') ?></td></tr><tr><th><?= e(__('Tổng tiền')) ?></th><td><strong><?= number_format((float)$booking['total_amount'],0,',','.') ?></strong></td></tr></tfoot></table></div>
        <?php if($extras): $isListingHost=$actorId===(int)$booking['host_id']; ?>
        <section class="payment-panel" aria-labelledby="payment-heading"><h2 class="h4" id="payment-heading"><?= e(__('Thanh toán')) ?></h2>
        <p><span class="status status-<?= e($extras['payment_state']) ?>"><?= e(__(\App\Models\BookingExtras::paymentLabel($extras['payment_state']))) ?></span> · <?= e(__('Thanh toán khi nhận phòng')) ?> · <?= e(__('Số tiền:')) ?> <strong><?= number_format((float)$extras['amount_due'],0,',','.') ?> <?= e($booking['currency']) ?></strong></p>
        <?php if($extras['payment_state']==='unpaid' && in_array($booking['status'],['pending','confirmed'],true)): ?><p class="text-muted small"><?= e(__('Khách thanh toán trực tiếp cho Host khi nhận phòng; Host hoặc Admin ghi nhận “Đã thu tiền” từ ngày nhận phòng.')) ?></p><?php endif; ?>
        <?php if($extras['paid_at']): ?><p class="small mb-1"><?= e(__('Đã thu lúc {time}', ['time'=>$extras['paid_at']])) ?><?= $extras['paid_by_name']?' · '.e(__('bởi {name}', ['name'=>$extras['paid_by_name']])):'' ?></p><?php endif; ?>
        <?php if($extras['refunded_at']): ?><p class="small mb-1"><?= e(__('Đã hoàn tiền lúc {time}', ['time'=>$extras['refunded_at']])) ?><?= $extras['refunded_by_name']?' · '.e(__('bởi {name}', ['name'=>$extras['refunded_by_name']])):'' ?></p><?php endif; ?>
        <?php if($extras['payment_note']): ?><p class="small"><?= e(__('Ghi chú:')) ?> <?= e($extras['payment_note']) ?></p><?php endif; ?>
        <?php if($extras['can_mark_paid'] && ($isListingHost || \Core\Auth::hasRole('admin'))): ?>
        <form method="post" action="<?= e(url(($isListingHost?'/host/bookings/'.$booking['id'].'/payment':'/admin/payments/'.$booking['id'].'/paid'))) ?>"><?= csrf_field() ?><input type="hidden" name="return_to" value="detail"><label for="payment-note"><?= e(__('Ghi chú thu tiền (không bắt buộc)')) ?></label><input class="form-control mb-2" id="payment-note" name="note" maxlength="500" placeholder="<?= e(__('VD: Tiền mặt khi nhận phòng')) ?>"><button class="btn btn-primary" data-confirm="<?= e(__('Xác nhận đã thu {amount} {currency} cho booking này?', ['amount'=>number_format((float)$extras['amount_due'],0,',','.'), 'currency'=>$booking['currency']])) ?>"><?= e(__('Đã thu tiền')) ?></button></form>
        <?php endif; ?>
        <?php if($extras['payment_status']==='paid' && \Core\Auth::hasRole('admin')): ?>
        <form class="mt-3" method="post" action="<?= e(url('/admin/payments/'.$booking['id'].'/refund')) ?>"><?= csrf_field() ?><input type="hidden" name="return_to" value="detail"><label for="refund-note"><?= e(__('Lý do hoàn tiền (bắt buộc)')) ?></label><input class="form-control mb-2" id="refund-note" name="note" maxlength="500" required><button class="btn btn-outline-danger" data-confirm="<?= e(__('Ghi nhận đã hoàn tiền cho khách? Thao tác được lưu vào nhật ký quản trị.')) ?>"><?= e(__('Ghi nhận hoàn tiền')) ?></button></form>
        <?php endif; ?></section>
        <?php endif; ?>
        <h2 class="h4"><?= e(__('Chính sách hủy tại thời điểm đặt')) ?></h2>
        <p><?= e(__('{policy}: trước giờ nhận phòng ít nhất {hours} giờ hoàn {early}%; sau mốc đó hoàn {late}%.', ['policy'=>isset($policy['name'])?__((string)$policy['name']):__('Chính sách đã lưu'), 'hours'=>(int)($policy['cutoff_hours']??0), 'early'=>$policy['early_refund_pct']??0, 'late'=>$policy['late_refund_pct']??0])) ?></p>
        <p><?= e(!empty($policy['refund_fees'])?__('Phụ phí được tính trong cơ sở hoàn tiền.'):__('Phụ phí không nằm trong cơ sở hoàn tiền.')) ?> <?= e(__('Giờ nhận phòng {time} ({zone}).', ['time'=>$policy['check_in_time']??'', 'zone'=>$policy['time_zone']??''])) ?></p>
        <?php if($booking['refund'] && in_array($booking['status'],['pending','confirmed','cancelled'],true)): ?>
        <p data-refund-amount="<?= e($booking['refund']['refund_amount']) ?>"><strong><?= e($booking['status']==='cancelled'?__('Số tiền hoàn đã ghi nhận'):__('Số tiền hoàn nếu hủy lúc này')) ?>: <?= number_format((float)$booking['refund']['refund_amount'],0,',','.') ?> <?= e($booking['currency']) ?></strong></p>
        <p class="text-muted small"><?= e(__('Đây là mức hoàn theo chính sách; không phải xác nhận đã chuyển tiền. Khi hủy, máy chủ tính lại theo thời điểm thực hiện.')) ?></p>
        <?php endif; ?>
        <?php if($actorId===(int)$booking['guest_id'] && in_array($booking['status'],['pending','confirmed'],true)): ?>
        <form method="post" action="<?= e(url('/bookings/'.$booking['id'].'/cancel')) ?>"><?= csrf_field() ?><label for="cancel-reason"><?= e(__('Lý do hủy')) ?></label><input class="form-control mb-2" id="cancel-reason" name="reason" maxlength="2000"><button class="btn btn-outline-danger" data-confirm="<?= e(__('Hủy booking theo mức hoàn đang áp dụng?')) ?>"><?= e(__('Hủy booking')) ?></button></form>
        <?php endif; ?>
        <?php if($actorId===(int)$booking['host_id'] || \Core\Auth::hasRole('admin')): ?>
        <div class="action-row mt-3"><?php foreach($booking['status']==='pending'?['confirmed','rejected']:($booking['status']==='confirmed'&&$booking['check_out']<=date('Y-m-d')?['completed']:[]) as $target): ?>
        <form method="post" action="<?= e(url((\Core\Auth::hasRole('admin')?'/admin':'/host').'/bookings/'.$booking['id'].'/transition')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $target ?>"><button class="btn btn-outline-primary"><?= e($labels[$target]) ?></button></form>
        <?php endforeach; ?></div><?php endif; ?>
        <?php if($actorId===(int)$booking['guest_id']): ?><p class="mt-3"><a href="<?= e(url('/reports?booking='.$booking['id'])) ?>"><?= e(__('Gửi khiếu nại booking này')) ?></a></p><?php endif; ?><h2 class="h4 mt-4"><?= e(__('Lịch sử trạng thái')) ?></h2>
        <ol><?php foreach($booking['events'] as $event): ?><li><?= e($event['created_at']) ?> — <?= e($labels[$event['to_status']]??$event['to_status']) ?>: <?= e(__((string)$event['reason'])) ?></li><?php endforeach; ?></ol>
        <a class="btn btn-ghost" href="<?= e(url('/listings/'.$booking['listing_id'])) ?>"><?= e(__('Xem chỗ ở công khai')) ?></a>
    </article>
    <?php require __DIR__.'/../messages/thread.php'; ?>
</div></section>
