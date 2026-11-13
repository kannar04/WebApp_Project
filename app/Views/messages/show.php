<section class="page-section"><div class="container narrow"><div class="row"><div class="col-12">
<div class="section-title"><div><h1><?= e(__('Tin nhắn')) ?></h1><p><?= e($booking['title']) ?> · #<?= (int)$booking['id'] ?></p></div><a class="btn btn-ghost" href="<?= e(url('/bookings/'.(int)$booking['id'])) ?>"><?= e(__('Chi tiết đặt phòng')) ?></a></div>
<?php require __DIR__.'/thread.php'; ?>
</div></div></div></section>
