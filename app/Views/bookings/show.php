<?php
$labels=['pending'=>'Chờ duyệt','confirmed'=>'Đã xác nhận','rejected'=>'Bị từ chối','cancelled'=>'Đã hủy','completed'=>'Hoàn thành'];
$policy=$booking['policy'];
$extras=$extras??[];
?>
<section class="page-section"><div class="container narrow">
    <div class="section-title"><div><p class="eyebrow">CHI TIẾT BOOKING #<?= (int)$booking['id'] ?></p><h1><?= e($booking['title']) ?></h1></div><a class="btn btn-ghost" href="<?= e(url($back)) ?>">Quay lại danh sách</a></div>
    <article class="surface-card">
        <span class="status status-<?= e($booking['status']) ?>"><?= e($labels[$booking['status']]) ?></span><?php if(!empty($extras['is_instant_book'])): ?> <span class="instant-tag">⚡ Đặt ngay</span><?php endif; ?>
        <p class="mt-3"><?= e($booking['address']) ?> · <?= e($booking['city']) ?></p>
        <p><?= e($booking['check_in']) ?> → <?= e($booking['check_out']) ?> · <?= (int)$booking['guest_count'] ?> khách</p>
        <p>Guest: <?= e($booking['guest_name']) ?> · Host: <?= e($booking['host_name']) ?></p>
        <?php if($actorId===(int)$booking['guest_id'] && $booking['host_phone']): ?><p>Liên hệ Host: <a href="tel:<?= e($booking['host_phone']) ?>"><?= e($booking['host_phone']) ?></a></p>
        <?php elseif($actorId===(int)$booking['host_id'] && $booking['guest_phone']): ?><p>Liên hệ Guest: <a href="tel:<?= e($booking['guest_phone']) ?>"><?= e($booking['guest_phone']) ?></a></p>
        <?php else: ?><p class="text-muted">Thông tin liên hệ của hai bên chỉ mở sau khi booking được xác nhận.</p><?php endif; ?>
        <h2 class="h4">Giá đã lưu khi đặt</h2>
        <div class="table-responsive"><table class="table"><thead><tr><th>Đêm lưu trú</th><th>Giá / đêm (<?= e($booking['currency']) ?>)</th></tr></thead><tbody>
        <?php foreach($booking['nights'] as $night): ?><tr><td><?= e($night['stay_date']) ?></td><td><?= number_format((float)$night['nightly_amount'],0,',','.') ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><?php if(!empty($extras['has_breakdown']) && ((float)$extras['length_discount_amount']>0 || (float)$extras['promotion_discount_amount']>0)): ?><tr><th>Tiền phòng</th><td><?= number_format((float)$extras['nights_subtotal'],0,',','.') ?></td></tr><?php if((float)$extras['length_discount_amount']>0): ?><tr class="discount-row"><th><?= $extras['length_discount_type']==='monthly'?'Giảm ở từ 28 đêm':'Giảm ở từ 7 đêm' ?> (<?= e(\App\Services\BookingService::percent((float)$extras['length_discount_pct'])) ?>)</th><td>−<?= number_format((float)$extras['length_discount_amount'],0,',','.') ?></td></tr><?php endif; ?><?php if((float)$extras['promotion_discount_amount']>0): ?><tr class="discount-row"><th>Mã khuyến mãi <?= e($extras['promotion_code']) ?></th><td>−<?= number_format((float)$extras['promotion_discount_amount'],0,',','.') ?></td></tr><?php endif; ?><?php endif; ?><tr><th>Phụ phí đã lưu</th><td><?= number_format((float)$booking['fee_amount'],0,',','.') ?></td></tr><tr><th>Tổng tiền</th><td><strong><?= number_format((float)$booking['total_amount'],0,',','.') ?></strong></td></tr></tfoot></table></div>
        <?php if($extras): $isListingHost=$actorId===(int)$booking['host_id']; ?>
        <section class="payment-panel" aria-labelledby="payment-heading"><h2 class="h4" id="payment-heading">Thanh toán</h2>
        <p><span class="status status-<?= e($extras['payment_state']) ?>"><?= e(\App\Models\BookingExtras::paymentLabel($extras['payment_state'])) ?></span> · Thanh toán khi nhận phòng · Số tiền: <strong><?= number_format((float)$extras['amount_due'],0,',','.') ?> <?= e($booking['currency']) ?></strong></p>
        <?php if($extras['payment_state']==='unpaid' && in_array($booking['status'],['pending','confirmed'],true)): ?><p class="text-muted small">Khách thanh toán trực tiếp cho Host khi nhận phòng; Host hoặc Admin ghi nhận “Đã thu tiền” từ ngày nhận phòng.</p><?php endif; ?>
        <?php if($extras['paid_at']): ?><p class="small mb-1">Đã thu lúc <?= e($extras['paid_at']) ?><?= $extras['paid_by_name']?' · bởi '.e($extras['paid_by_name']):'' ?></p><?php endif; ?>
        <?php if($extras['refunded_at']): ?><p class="small mb-1">Đã hoàn tiền lúc <?= e($extras['refunded_at']) ?><?= $extras['refunded_by_name']?' · bởi '.e($extras['refunded_by_name']):'' ?></p><?php endif; ?>
        <?php if($extras['payment_note']): ?><p class="small">Ghi chú: <?= e($extras['payment_note']) ?></p><?php endif; ?>
        <?php if($extras['can_mark_paid'] && ($isListingHost || \Core\Auth::hasRole('admin'))): ?>
        <form method="post" action="<?= e(url(($isListingHost?'/host/bookings/'.$booking['id'].'/payment':'/admin/payments/'.$booking['id'].'/paid'))) ?>"><?= csrf_field() ?><input type="hidden" name="return_to" value="detail"><label for="payment-note">Ghi chú thu tiền (không bắt buộc)</label><input class="form-control mb-2" id="payment-note" name="note" maxlength="500" placeholder="VD: Tiền mặt khi nhận phòng"><button class="btn btn-primary" data-confirm="Xác nhận đã thu <?= e(number_format((float)$extras['amount_due'],0,',','.')) ?> <?= e($booking['currency']) ?> cho booking này?">Đã thu tiền</button></form>
        <?php endif; ?>
        <?php if($extras['payment_status']==='paid' && \Core\Auth::hasRole('admin')): ?>
        <form class="mt-3" method="post" action="<?= e(url('/admin/payments/'.$booking['id'].'/refund')) ?>"><?= csrf_field() ?><input type="hidden" name="return_to" value="detail"><label for="refund-note">Lý do hoàn tiền (bắt buộc)</label><input class="form-control mb-2" id="refund-note" name="note" maxlength="500" required><button class="btn btn-outline-danger" data-confirm="Ghi nhận đã hoàn tiền cho khách? Thao tác được lưu vào nhật ký quản trị.">Ghi nhận hoàn tiền</button></form>
        <?php endif; ?></section>
        <?php endif; ?>
        <h2 class="h4">Chính sách hủy tại thời điểm đặt</h2>
        <p><?= e($policy['name']??'Chính sách đã lưu') ?>: trước giờ nhận phòng ít nhất <?= (int)($policy['cutoff_hours']??0) ?> giờ hoàn <?= e($policy['early_refund_pct']??0) ?>%; sau mốc đó hoàn <?= e($policy['late_refund_pct']??0) ?>%.</p>
        <p><?= !empty($policy['refund_fees'])?'Phụ phí được tính trong cơ sở hoàn tiền.':'Phụ phí không nằm trong cơ sở hoàn tiền.' ?> Giờ nhận phòng <?= e($policy['check_in_time']??'') ?> (<?= e($policy['time_zone']??'') ?>).</p>
        <?php if($booking['refund'] && in_array($booking['status'],['pending','confirmed','cancelled'],true)): ?>
        <p data-refund-amount="<?= e($booking['refund']['refund_amount']) ?>"><strong><?= $booking['status']==='cancelled'?'Số tiền hoàn đã ghi nhận':'Số tiền hoàn nếu hủy lúc này' ?>: <?= number_format((float)$booking['refund']['refund_amount'],0,',','.') ?> <?= e($booking['currency']) ?></strong></p>
        <p class="text-muted small">Đây là mức hoàn theo chính sách; không phải xác nhận đã chuyển tiền. Khi hủy, máy chủ tính lại theo thời điểm thực hiện.</p>
        <?php endif; ?>
        <?php if($actorId===(int)$booking['guest_id'] && in_array($booking['status'],['pending','confirmed'],true)): ?>
        <form method="post" action="<?= e(url('/bookings/'.$booking['id'].'/cancel')) ?>"><?= csrf_field() ?><label for="cancel-reason">Lý do hủy</label><input class="form-control mb-2" id="cancel-reason" name="reason" maxlength="2000"><button class="btn btn-outline-danger" data-confirm="Hủy booking theo mức hoàn đang áp dụng?">Hủy booking</button></form>
        <?php endif; ?>
        <?php if($actorId===(int)$booking['host_id'] || \Core\Auth::hasRole('admin')): ?>
        <div class="action-row mt-3"><?php foreach($booking['status']==='pending'?['confirmed','rejected']:($booking['status']==='confirmed'&&$booking['check_out']<=date('Y-m-d')?['completed']:[]) as $target): ?>
        <form method="post" action="<?= e(url((\Core\Auth::hasRole('admin')?'/admin':'/host').'/bookings/'.$booking['id'].'/transition')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $target ?>"><button class="btn btn-outline-primary"><?= e($labels[$target]) ?></button></form>
        <?php endforeach; ?></div><?php endif; ?>
        <?php if($actorId===(int)$booking['guest_id']): ?><p class="mt-3"><a href="<?= e(url('/reports?booking='.$booking['id'])) ?>">Gửi khiếu nại booking này</a></p><?php endif; ?><h2 class="h4 mt-4">Lịch sử trạng thái</h2>
        <ol><?php foreach($booking['events'] as $event): ?><li><?= e($event['created_at']) ?> — <?= e($labels[$event['to_status']]??$event['to_status']) ?>: <?= e($event['reason']) ?></li><?php endforeach; ?></ol>
        <a class="btn btn-ghost" href="<?= e(url('/listings/'.$booking['listing_id'])) ?>">Xem chỗ ở công khai</a>
    </article>
</div></section>
