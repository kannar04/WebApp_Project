<?php
$statusLabels=['pending'=>'Chờ duyệt','confirmed'=>'Đã xác nhận','rejected'=>'Bị từ chối','cancelled'=>'Đã hủy','completed'=>'Hoàn thành'];
$threadMode=$thread!==null;
$items=$threadMode?$thread:$results;
$hasFilters=$filters['q']!==''||$filters['booking']!==null||$filters['user']!=='';
?>
<section class="page-section"><div class="container">
<div class="section-title"><div><p class="eyebrow">QUẢN TRỊ</p><h1>Tin nhắn booking</h1><p>Tra cứu theo từ khóa, mã booking hoặc người dùng. Ẩn tin nhắn vi phạm kèm lý do; mọi thao tác được ghi nhật ký quản trị.</p></div><a class="btn btn-ghost" href="<?= e(url('/admin')) ?>">Về trang quản trị</a></div>
<form class="surface-card admin-message-search" method="get" action="<?= e(url('/admin/messages')) ?>" role="search" aria-label="Tìm tin nhắn booking">
    <div class="form-row">
        <div class="form-group col-md-4"><label for="admin-message-q">Từ khóa trong nội dung</label><input class="form-control" id="admin-message-q" name="q" maxlength="200" value="<?= e($filters['q']) ?>"></div>
        <div class="form-group col-md-3"><label for="admin-message-booking">Mã booking</label><input class="form-control" id="admin-message-booking" name="booking" type="number" min="1" inputmode="numeric" value="<?= $filters['booking']!==null?(int)$filters['booking']:'' ?>"></div>
        <div class="form-group col-md-3"><label for="admin-message-user">Người dùng (tên hoặc email)</label><input class="form-control" id="admin-message-user" name="user" maxlength="254" value="<?= e($filters['user']) ?>"></div>
        <div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-block" type="submit">Tìm</button></div>
    </div>
    <p class="small text-muted mb-0">Chỉ nhập mã booking để mở toàn bộ cuộc trò chuyện của booking đó.<?php if($hasFilters): ?> <a href="<?= e(url('/admin/messages')) ?>">Xóa bộ lọc</a><?php endif; ?></p>
</form>
<section class="mt-4" aria-labelledby="admin-messages-title">
    <h2 class="h4" id="admin-messages-title"><?= $threadMode?'Cuộc trò chuyện booking #'.(int)$filters['booking']:($hasFilters?'Kết quả tìm kiếm':'Tin nhắn mới nhất') ?> <small class="text-muted">(<?= count($items) ?>)</small></h2>
    <?php if($threadMode && $items): $first=$items[0]; ?><p class="text-muted"><?= e($first['title']) ?> · Guest: <?= e($first['guest_name']) ?> · Host: <?= e($first['host_name']) ?> · <span class="status status-<?= e($first['booking_status']) ?>"><?= e($statusLabels[$first['booking_status']]??$first['booking_status']) ?></span> · <a href="<?= e(url('/bookings/'.(int)$filters['booking'])) ?>">Chi tiết booking</a></p><?php endif; ?>
    <?php if(!$items): ?><div class="empty-state"><h3 class="h5">Không có tin nhắn phù hợp</h3><p><?= $threadMode?'Booking này chưa có tin nhắn hoặc không tồn tại.':'Thử từ khóa, mã booking hoặc người dùng khác.' ?></p></div><?php endif; ?>
    <?php foreach($items as $message): $isHidden=$message['hidden_at']!==null; ?>
    <article class="surface-card mb-3 admin-message<?= $isHidden?' is-hidden':'' ?>">
        <p class="message-meta"><strong><?= e($message['sender_name']) ?></strong><?php if($threadMode): ?> (<?= $message['sender_role']==='guest'?'Guest':'Host' ?>)<?php else: ?> → <?= e($message['recipient_name']) ?><?php endif; ?> · <time datetime="<?= e(\App\Services\ConversationService::isoTime($message['created_at'])) ?>"><?= e(\App\Services\ConversationService::displayTime($message['created_at'])) ?></time><?= $message['read_at']?' · Đã đọc':' · Chưa đọc' ?><?php if(!$threadMode): ?> · <a href="<?= e(url('/admin/messages?booking='.(int)$message['booking_id'])) ?>">Booking #<?= (int)$message['booking_id'] ?> · <?= e($message['title']) ?></a><?php endif; ?></p>
        <p class="message-body"><?= nl2br(e($message['body'])) ?></p>
        <?php if($isHidden): ?><p class="message-hidden-note"><span class="status status-cancelled">Đang ẩn</span> <?= e($message['hidden_by_name']) ?> · <?= e(\App\Services\ConversationService::displayTime($message['hidden_at'])) ?> · Lý do: <?= e($message['hidden_reason']) ?></p><?php endif; ?>
        <form class="message-moderation" method="post" action="<?= e(url('/admin/messages/'.(int)$message['id'].'/moderate')) ?>"><?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $isHidden?'unhide':'hide' ?>"><input type="hidden" name="booking" value="<?= (int)$message['booking_id'] ?>">
            <label for="moderation-reason-<?= (int)$message['id'] ?>"><?= $isHidden?'Lý do hiện lại (5–500 ký tự)':'Lý do ẩn (5–500 ký tự)' ?></label>
            <div class="message-moderation-row"><input class="form-control form-control-sm" id="moderation-reason-<?= (int)$message['id'] ?>" name="reason" minlength="5" maxlength="500" required><button class="btn btn-sm <?= $isHidden?'btn-outline-primary':'btn-outline-danger' ?>" type="submit" data-confirm="<?= $isHidden?'Hiện lại tin nhắn này cho Guest và Host?':'Ẩn tin nhắn này với Guest và Host?' ?>"><?= $isHidden?'Hiện lại':'Ẩn tin nhắn' ?></button></div>
        </form>
    </article>
    <?php endforeach; ?>
</section>
</div></section>
