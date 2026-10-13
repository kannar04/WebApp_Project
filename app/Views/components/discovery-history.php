<?php
/** Profile: what browsing history is kept for recommendations, with a one-click clear for the owner. */
$discoveryHistory=$discoveryHistory??null;
?>
<?php if($discoveryHistory!==null): ?>
<section class="page-section pt-0 discovery-history-section" id="discovery-history" aria-labelledby="discovery-history-title"><div class="container narrow"><div class="surface-card">
<h2 id="discovery-history-title">Lịch sử xem &amp; tìm kiếm</h2>
<p>Home2Home dùng chỗ ở bạn đã xem và các lần tìm kiếm gần đây (tối đa 20) để gợi ý chỗ ở phù hợp. Chỉ bạn thấy lịch sử này; chỗ ở của chính bạn không được ghi lại.</p>
<?php if((int)$discoveryHistory['viewed_listings']>0 || (int)$discoveryHistory['searches']>0): ?>
<div class="discovery-history-actions"><p class="mb-0" data-history-summary>Đang lưu <strong><?= (int)$discoveryHistory['viewed_listings'] ?></strong> chỗ ở đã xem và <strong><?= (int)$discoveryHistory['searches'] ?></strong> lần tìm kiếm.</p>
<form method="post" action="<?= e(url('/profile/history/clear')) ?>"><?= csrf_field() ?><button class="btn btn-outline-danger" type="submit" data-confirm="Xóa toàn bộ lịch sử xem và tìm kiếm? Gợi ý cá nhân sẽ bắt đầu lại từ đầu.">Xóa lịch sử xem &amp; tìm kiếm</button></form></div>
<?php else: ?>
<p class="text-muted mb-0" data-history-summary>Chưa có lịch sử xem hoặc tìm kiếm nào được lưu.</p>
<?php endif; ?>
</div></div></section>
<?php endif; ?>
