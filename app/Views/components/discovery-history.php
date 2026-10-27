<?php
/** Profile: what browsing history is kept for recommendations, with a one-click clear for the owner. */
$discoveryHistory=$discoveryHistory??null;
?>
<?php if($discoveryHistory!==null): ?>
<section class="page-section pt-0 discovery-history-section" id="discovery-history" aria-labelledby="discovery-history-title"><div class="container narrow"><div class="surface-card">
<h2 id="discovery-history-title"><?= e(__('Lịch sử xem & tìm kiếm')) ?></h2>
<p><?= e(__('Home2Home dùng chỗ ở bạn đã xem và các lần tìm kiếm gần đây (tối đa 20) để gợi ý chỗ ở phù hợp. Chỉ bạn thấy lịch sử này; chỗ ở của chính bạn không được ghi lại.')) ?></p>
<?php if((int)$discoveryHistory['viewed_listings']>0 || (int)$discoveryHistory['searches']>0): ?>
<?php /* The slots carry <strong> markup around integers, so this one template is echoed without e(). */ ?>
<div class="discovery-history-actions"><p class="mb-0" data-history-summary><?= __('Đang lưu {viewed} chỗ ở đã xem và {searches} lần tìm kiếm.',['viewed'=>'<strong>'.(int)$discoveryHistory['viewed_listings'].'</strong>','searches'=>'<strong>'.(int)$discoveryHistory['searches'].'</strong>']) ?></p>
<form method="post" action="<?= e(url('/profile/history/clear')) ?>"><?= csrf_field() ?><button class="btn btn-outline-danger" type="submit" data-confirm="<?= e(__('Xóa toàn bộ lịch sử xem và tìm kiếm? Gợi ý cá nhân sẽ bắt đầu lại từ đầu.')) ?>"><?= e(__('Xóa lịch sử xem & tìm kiếm')) ?></button></form></div>
<?php else: ?>
<p class="text-muted mb-0" data-history-summary><?= e(__('Chưa có lịch sử xem hoặc tìm kiếm nào được lưu.')) ?></p>
<?php endif; ?>
</div></div></section>
<?php endif; ?>
