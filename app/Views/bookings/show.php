<?php
$labels=['pending'=>'Chờ duyệt','confirmed'=>'Đã xác nhận','rejected'=>'Bị từ chối','cancelled'=>'Đã hủy','completed'=>'Hoàn thành'];
$policy=$booking['policy'];
?>
<section class="page-section"><div class="container narrow">
    <div class="section-title"><div><p class="eyebrow">CHI TIẾT BOOKING #<?= (int)$booking['id'] ?></p><h1><?= e($booking['title']) ?></h1></div><a class="btn btn-ghost" href="<?= e(url($back)) ?>">Quay lại danh sách</a></div>
    <article class="surface-card">
        <span class="status status-<?= e($booking['status']) ?>"><?= e($labels[$booking['status']]) ?></span>
        <p class="mt-3"><?= e($booking['address']) ?> · <?= e($booking['city']) ?></p>
        <p><?= e($booking['check_in']) ?> → <?= e($booking['check_out']) ?> · <?= (int)$booking['guest_count'] ?> khách</p>
        <p>Guest: <?= e($booking['guest_name']) ?> · Host: <?= e($booking['host_name']) ?></p>
        <?php if($actorId===(int)$booking['guest_id'] && $booking['host_phone']): ?><p>Liên hệ Host: <a href="tel:<?= e($booking['host_phone']) ?>"><?= e($booking['host_phone']) ?></a></p>
        <?php elseif($actorId===(int)$booking['host_id'] && $booking['guest_phone']): ?><p>Liên hệ Guest: <a href="tel:<?= e($booking['guest_phone']) ?>"><?= e($booking['guest_phone']) ?></a></p>
        <?php else: ?><p class="text-muted">Thông tin liên hệ của hai bên chỉ mở sau khi booking được xác nhận.</p><?php endif; ?>
        <h2 class="h4">Giá đã lưu khi đặt</h2>
        <div class="table-responsive"><table class="table"><thead><tr><th>Đêm lưu trú</th><th>Giá / đêm (<?= e($booking['currency']) ?>)</th></tr></thead><tbody>
        <?php foreach($booking['nights'] as $night): ?><tr><td><?= e($night['stay_date']) ?></td><td><?= number_format((float)$night['nightly_amount'],0,',','.') ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr><th>Phụ phí đã lưu</th><td><?= number_format((float)$booking['fee_amount'],0,',','.') ?></td></tr><tr><th>Tổng tiền</th><td><strong><?= number_format((float)$booking['total_amount'],0,',','.') ?></strong></td></tr></tfoot></table></div>
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
