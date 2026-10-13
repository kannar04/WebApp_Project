<?php $otherLabels=['guest'=>'Host','host'=>'Guest']; $statusLabels=['pending'=>'Chờ duyệt','confirmed'=>'Đã xác nhận','rejected'=>'Bị từ chối','cancelled'=>'Đã hủy','completed'=>'Hoàn thành']; ?>
<section class="page-section"><div class="container narrow">
<div class="section-title"><div><p class="eyebrow">HỘP THƯ BOOKING</p><h1>Tin nhắn</h1><p>Mỗi booking có một cuộc trò chuyện riêng giữa Guest và Host.</p></div><a class="btn btn-ghost" href="<?= e(url('/notifications')) ?>">Xem thông báo</a></div>
<?php if(!$conversations): ?><div class="empty-state"><h2>Chưa có cuộc trò chuyện</h2><p>Mở chi tiết một booking để gửi tin nhắn đầu tiên cho Host hoặc Guest.</p><a class="btn btn-primary" href="<?= e(url('/bookings')) ?>">Xem chuyến đi</a></div><?php else: ?>
<ul class="conversation-list">
<?php foreach($conversations as $item): $unread=(int)$item['unread_count']; ?>
<li><a class="conversation-item<?= $unread?' is-unread':'' ?>" href="<?= e(url('/bookings/'.(int)$item['booking_id'].'#booking-messages')) ?>">
    <div class="conversation-head"><h2><?= e($item['title']) ?></h2><time datetime="<?= e(\App\Services\ConversationService::isoTime($item['last_at'])) ?>"><?= e(\App\Services\ConversationService::displayTime($item['last_at'])) ?></time></div>
    <div class="conversation-meta"><?= e($otherLabels[$item['my_role']]??'') ?>: <?= e($item['other_name']) ?> · Booking #<?= (int)$item['booking_id'] ?> · <span class="status status-<?= e($item['status']) ?>"><?= e($statusLabels[$item['status']]??$item['status']) ?></span></div>
    <div class="conversation-snippet"><?php if((int)$item['last_hidden']): ?><em>Tin nhắn đã bị quản trị viên ẩn.</em><?php else: ?><?= (int)$item['last_mine']?'Bạn: ':'' ?><?= e($item['last_snippet']) ?><?= (int)$item['last_truncated']?'…':'' ?><?php endif; ?></div>
    <?php if($unread): ?><span class="conversation-unread"><?= $unread ?> chưa đọc</span><?php endif; ?>
</a></li>
<?php endforeach; ?>
</ul><?php endif; ?>
</div></section>
