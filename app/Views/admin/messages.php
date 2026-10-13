<?php
$statusLabels=['pending'=>__('Chờ duyệt'),'confirmed'=>__('Đã xác nhận'),'rejected'=>__('Bị từ chối'),'cancelled'=>__('Đã hủy'),'completed'=>__('Hoàn thành')];
$threadMode=$thread!==null;
$items=$threadMode?$thread:$results;
$hasFilters=$filters['q']!==''||$filters['booking']!==null||$filters['user']!=='';
?>
<section class="page-section"><div class="container">
<div class="section-title"><div><p class="eyebrow"><?= e(__('QUẢN TRỊ')) ?></p><h1><?= e(__('Tin nhắn booking')) ?></h1><p><?= e(__('Tra cứu theo từ khóa, mã booking hoặc người dùng. Ẩn tin nhắn vi phạm kèm lý do; mọi thao tác được ghi nhật ký quản trị.')) ?></p></div><a class="btn btn-ghost" href="<?= e(url('/admin')) ?>"><?= e(__('Về trang quản trị')) ?></a></div>
<form class="surface-card admin-message-search" method="get" action="<?= e(url('/admin/messages')) ?>" role="search" aria-label="<?= e(__('Tìm tin nhắn booking')) ?>">
    <div class="form-row">
        <div class="form-group col-md-4"><label for="admin-message-q"><?= e(__('Từ khóa trong nội dung')) ?></label><input class="form-control" id="admin-message-q" name="q" maxlength="200" value="<?= e($filters['q']) ?>"></div>
        <div class="form-group col-md-3"><label for="admin-message-booking"><?= e(__('Mã booking')) ?></label><input class="form-control" id="admin-message-booking" name="booking" type="number" min="1" inputmode="numeric" value="<?= $filters['booking']!==null?(int)$filters['booking']:'' ?>"></div>
        <div class="form-group col-md-3"><label for="admin-message-user"><?= e(__('Người dùng (tên hoặc email)')) ?></label><input class="form-control" id="admin-message-user" name="user" maxlength="254" value="<?= e($filters['user']) ?>"></div>
        <div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-block" type="submit"><?= e(__('Tìm')) ?></button></div>
    </div>
    <p class="small text-muted mb-0"><?= e(__('Chỉ nhập mã booking để mở toàn bộ cuộc trò chuyện của booking đó.')) ?><?php if($hasFilters): ?> <a href="<?= e(url('/admin/messages')) ?>"><?= e(__('Xóa bộ lọc')) ?></a><?php endif; ?></p>
</form>
<section class="mt-4" aria-labelledby="admin-messages-title">
    <h2 class="h4" id="admin-messages-title"><?= e($threadMode?__('Cuộc trò chuyện booking #{id}', ['id'=>(int)$filters['booking']]):($hasFilters?__('Kết quả tìm kiếm'):__('Tin nhắn mới nhất'))) ?> <small class="text-muted">(<?= count($items) ?>)</small></h2>
    <?php if($threadMode && $items): $first=$items[0]; ?><p class="text-muted"><?= e($first['title']) ?> · Guest: <?= e($first['guest_name']) ?> · Host: <?= e($first['host_name']) ?> · <span class="status status-<?= e($first['booking_status']) ?>"><?= e($statusLabels[$first['booking_status']]??$first['booking_status']) ?></span> · <a href="<?= e(url('/bookings/'.(int)$filters['booking'])) ?>"><?= e(__('Chi tiết booking')) ?></a></p><?php endif; ?>
    <?php if(!$items): ?><div class="empty-state"><h3 class="h5"><?= e(__('Không có tin nhắn phù hợp')) ?></h3><p><?= e($threadMode?__('Booking này chưa có tin nhắn hoặc không tồn tại.'):__('Thử từ khóa, mã booking hoặc người dùng khác.')) ?></p></div><?php endif; ?>
    <?php foreach($items as $message): $isHidden=$message['hidden_at']!==null; ?>
    <article class="surface-card mb-3 admin-message<?= $isHidden?' is-hidden':'' ?>">
        <p class="message-meta"><strong><?= e($message['sender_name']) ?></strong><?php if($threadMode): ?> (<?= $message['sender_role']==='guest'?'Guest':'Host' ?>)<?php else: ?> → <?= e($message['recipient_name']) ?><?php endif; ?> · <time datetime="<?= e(\App\Services\ConversationService::isoTime($message['created_at'])) ?>"><?= e(\App\Services\ConversationService::displayTime($message['created_at'])) ?></time><?= ' · '.e($message['read_at']?__('Đã đọc'):__('Chưa đọc')) ?><?php if(!$threadMode): ?> · <a href="<?= e(url('/admin/messages?booking='.(int)$message['booking_id'])) ?>">Booking #<?= (int)$message['booking_id'] ?> · <?= e($message['title']) ?></a><?php endif; ?></p>
        <p class="message-body"><?= nl2br(e($message['body'])) ?></p>
        <?php if($isHidden): ?><p class="message-hidden-note"><span class="status status-cancelled"><?= e(__('Đang ẩn')) ?></span> <?= e($message['hidden_by_name']) ?> · <?= e(\App\Services\ConversationService::displayTime($message['hidden_at'])) ?> · <?= e(__('Lý do: {reason}', ['reason'=>$message['hidden_reason']])) ?></p><?php endif; ?>
        <form class="message-moderation" method="post" action="<?= e(url('/admin/messages/'.(int)$message['id'].'/moderate')) ?>"><?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $isHidden?'unhide':'hide' ?>"><input type="hidden" name="booking" value="<?= (int)$message['booking_id'] ?>">
            <label for="moderation-reason-<?= (int)$message['id'] ?>"><?= e($isHidden?__('Lý do hiện lại (5–500 ký tự)'):__('Lý do ẩn (5–500 ký tự)')) ?></label>
            <div class="message-moderation-row"><input class="form-control form-control-sm" id="moderation-reason-<?= (int)$message['id'] ?>" name="reason" minlength="5" maxlength="500" required><button class="btn btn-sm <?= $isHidden?'btn-outline-primary':'btn-outline-danger' ?>" type="submit" data-confirm="<?= e($isHidden?__('Hiện lại tin nhắn này cho Guest và Host?'):__('Ẩn tin nhắn này với Guest và Host?')) ?>"><?= e($isHidden?__('Hiện lại'):__('Ẩn tin nhắn')) ?></button></div>
        </form>
    </article>
    <?php endforeach; ?>
</section>
</div></section>
