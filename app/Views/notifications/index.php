<section class="page-section"><div class="container narrow"><div class="section-title"><div><p class="eyebrow">CẬP NHẬT CỦA BẠN</p><h1>Thông báo</h1></div><a class="btn btn-ghost" href="<?= e(url('/bookings')) ?>">Xem chuyến đi</a></div>
<?php if(!$notifications): ?><div class="empty-state"><h2>Chưa có thông báo</h2><p>Cập nhật booking sẽ xuất hiện ở đây.</p></div><?php endif; ?>
<?php foreach($notifications as $notification): ?><article class="surface-card mb-3"><h2 class="h5"><?= e($notification['title']) ?></h2><p><?= e($notification['body']) ?></p><small><?= e($notification['created_at']) ?> · <?= $notification['read_at']?'Đã đọc':'Chưa đọc' ?></small><div class="action-row mt-2">
<?php if($notification['booking_id']): ?><a class="btn btn-outline-primary" href="<?= e(url('/bookings/'.$notification['booking_id'])) ?>">Xem booking</a><?php endif; ?>
<?php if(!$notification['read_at']): ?><form method="post" action="<?= e(url('/notifications/'.$notification['id'].'/read')) ?>"><?= csrf_field() ?><button class="btn btn-ghost">Đánh dấu đã đọc</button></form><?php endif; ?></div></article><?php endforeach; ?>
</div></section>
