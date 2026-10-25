<?php $otherLabels=['guest'=>'Host','host'=>'Guest']; $statusLabels=['pending'=>__('Chờ duyệt'),'confirmed'=>__('Đã xác nhận'),'rejected'=>__('Bị từ chối'),'cancelled'=>__('Đã hủy'),'completed'=>__('Hoàn thành')]; ?>
<section class="page-section"><div class="container narrow">
<div class="section-title"><div><p class="eyebrow"><?= e(__('HỘP THƯ BOOKING')) ?></p><h1><?= e(__('Tin nhắn')) ?></h1><p><?= e(__('Mỗi booking có một cuộc trò chuyện riêng giữa Guest và Host.')) ?></p></div><a class="btn btn-ghost" href="<?= e(url('/notifications')) ?>"><?= e(__('Xem thông báo')) ?></a></div>
<?php if(!$conversations): ?><div class="empty-state"><h2><?= e(__('Chưa có cuộc trò chuyện')) ?></h2><p><?= e(__('Mở chi tiết một booking để gửi tin nhắn đầu tiên cho Host hoặc Guest.')) ?></p><a class="btn btn-primary" href="<?= e(url('/bookings')) ?>"><?= e(__('Xem chuyến đi')) ?></a></div><?php else: ?>
<ul class="conversation-list">
<?php foreach($conversations as $item): $unread=(int)$item['unread_count']; ?>
<li><a class="conversation-item<?= $unread?' is-unread':'' ?>" href="<?= e(url('/bookings/'.(int)$item['booking_id'].'#booking-messages')) ?>">
    <div class="conversation-head"><h2><?= e($item['title']) ?></h2><time datetime="<?= e(\App\Services\ConversationService::isoTime($item['last_at'])) ?>"><?= e(\App\Services\ConversationService::displayTime($item['last_at'])) ?></time></div>
    <div class="conversation-meta"><?= e($otherLabels[$item['my_role']]??'') ?>: <?= e($item['other_name']) ?> · Booking #<?= (int)$item['booking_id'] ?> · <span class="status status-<?= e($item['status']) ?>"><?= e($statusLabels[$item['status']]??$item['status']) ?></span></div>
    <div class="conversation-snippet"><?php if((int)$item['last_hidden']): ?><em><?= e(__('Tin nhắn đã bị quản trị viên ẩn.')) ?></em><?php else: ?><?= (int)$item['last_mine']?e(__('Bạn:')).' ':'' ?><?= e($item['last_snippet']) ?><?= (int)$item['last_truncated']?'…':'' ?><?php endif; ?></div>
    <?php if($unread): ?><span class="conversation-unread"><?= e(__('{count} chưa đọc', ['count'=>$unread])) ?></span><?php endif; ?>
</a></li>
<?php endforeach; ?>
</ul><?php endif; ?>
</div></section>