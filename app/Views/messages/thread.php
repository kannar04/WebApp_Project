<?php /* Booking conversation panel; included by bookings/show.php with $booking and $conversation in scope. */ ?>
<?php if($conversation!==null): $conversationState=$conversation['state']; $pageScripts[]='js/messages.js'; ?>
<section class="surface-card mt-4 booking-thread" id="booking-messages" aria-labelledby="booking-messages-title">
    <div class="section-title compact"><div><p class="eyebrow">TIN NHẮN BOOKING</p><h2 class="h4" id="booking-messages-title">Trao đổi với <?= e($conversationState['other_name']) ?></h2><p><?= $conversationState['my_role']==='guest'?'Host của chỗ ở':'Guest của booking' ?> · chỉ hai bên và quản trị viên xem được.</p></div><a class="btn btn-ghost" href="<?= e(url('/messages')) ?>">Tất cả tin nhắn</a></div>
    <?php if(!$conversation['messages']): ?><p class="thread-empty">Chưa có tin nhắn. Bạn có thể hỏi về giờ nhận phòng, đường đi hoặc yêu cầu đặc biệt.</p><?php else: ?>
    <ol class="message-list" data-message-list tabindex="0" aria-label="Lịch sử tin nhắn">
        <?php foreach($conversation['messages'] as $message): ?><li class="message-item <?= (int)$message['is_mine']?'is-mine':'is-theirs' ?>"><div class="message-bubble">
            <p class="message-meta"><strong><?= (int)$message['is_mine']?'Bạn':e($message['sender_name']) ?></strong> · <time datetime="<?= e(\App\Services\ConversationService::isoTime($message['created_at'])) ?>"><?= e(\App\Services\ConversationService::displayTime($message['created_at'])) ?></time></p>
            <?php if((int)$message['is_hidden']): ?><p class="message-hidden">Tin nhắn đã bị quản trị viên ẩn.</p><?php else: ?><p class="message-body"><?= nl2br(e($message['body'])) ?></p><?php endif; ?>
            <?php if((int)$message['is_mine'] && $message['read_at']): ?><p class="message-receipt">Đã xem</p><?php endif; ?>
        </div></li><?php endforeach; ?>
    </ol><?php endif; ?>
    <?php if((int)$conversationState['can_send']): ?>
    <form class="message-form" method="post" action="<?= e(url('/bookings/'.(int)$booking['id'].'/messages')) ?>" data-message-form><?= csrf_field() ?>
        <label for="message-body">Tin nhắn mới</label>
        <textarea class="form-control" id="message-body" name="body" rows="3" maxlength="2000" required aria-describedby="message-help"><?= e($conversation['draft']) ?></textarea>
        <div class="message-form-footer"><small class="text-muted" id="message-help">Văn bản thuần, tối đa 2000 ký tự. Nhấn Ctrl + Enter để gửi nhanh.</small><button class="btn btn-primary" type="submit">Gửi tin nhắn</button></div>
    </form>
    <?php if($conversationState['status']==='completed'): ?><p class="text-muted small mt-2 mb-0">Bạn có thể nhắn tin đến hết ngày <?= e(\App\Services\ConversationService::displayDate($conversationState['closes_on'])) ?> (14 ngày sau trả phòng).</p><?php endif; ?>
    <?php else: ?><p class="thread-closed" role="note"><?= in_array($conversationState['status'],['rejected','cancelled'],true)?'Booking đã bị từ chối hoặc hủy nên cuộc trò chuyện chỉ còn để xem.':'Cuộc trò chuyện đã đóng sau 14 ngày kể từ ngày trả phòng; bạn vẫn có thể xem lại.' ?></p><?php endif; ?>
</section>
<?php elseif(\Core\Auth::hasRole('admin')): ?>
<section class="surface-card mt-4" id="booking-messages" aria-labelledby="booking-messages-title"><h2 class="h4" id="booking-messages-title">Tin nhắn booking</h2><p class="text-muted">Quản trị viên xem cuộc trò chuyện giữa Guest và Host ở trang quản trị, không làm thay đổi trạng thái đã đọc.</p><a class="btn btn-outline-primary" href="<?= e(url('/admin/messages?booking='.(int)$booking['id'])) ?>">Mở cuộc trò chuyện (quản trị)</a></section>
<?php endif; ?>
