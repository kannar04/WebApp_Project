<?php
// Trusted Host progress; every threshold comes from sp_trust_badges so the help text cannot drift from the rule.
if (!empty($trustStatus)):
    $trustMetrics = $trustStatus;
    $trustReviewCount = (int)$trustMetrics['review_count'];
    $trustDecided = (int)$trustMetrics['decided_requests'];
    $trustReviewsMet = $trustReviewCount >= (int)$trustMetrics['min_reviews'];
    $trustRatingMet = $trustReviewCount > 0 && (float)$trustMetrics['average_rating'] >= (float)$trustMetrics['min_rating'];
    $trustCancelApplies = $trustDecided >= (int)$trustMetrics['min_decided_requests'];
    $trustCancelMet = !$trustCancelApplies || (float)$trustMetrics['cancel_pct'] <= (float)$trustMetrics['max_cancel_pct'];
    $trustPercent = rtrim(rtrim(number_format((float)$trustMetrics['max_cancel_pct'], 2, ',', '.'), '0'), ',');
?>
<section class="surface-card mt-4" id="trusted-host" aria-labelledby="trusted-host-title">
<div class="section-title compact"><div><h2 id="trusted-host-title">Huy hiệu Host uy tín</h2><p>Tính trực tiếp từ đánh giá đang hiển thị và lịch sử xử lý booking của bạn.</p></div><?php if((int)$trustMetrics['is_trusted_host'] === 1): ?><span class="trust-badge trust-badge-host"><span aria-hidden="true">★</span> Host uy tín</span><?php else: ?><span class="status status-pending">Chưa đạt</span><?php endif; ?></div>
<ul class="trust-progress">
<li class="<?= $trustReviewsMet ? 'is-met' : 'is-unmet' ?>"><span><span aria-hidden="true"><?= $trustReviewsMet ? '✓' : '✗' ?></span> Đánh giá đang hiển thị</span><strong><?= $trustReviewCount ?>/<?= (int)$trustMetrics['min_reviews'] ?> đánh giá · <?= $trustReviewsMet ? 'Đạt' : 'Chưa đạt' ?></strong></li>
<li class="<?= $trustRatingMet ? 'is-met' : 'is-unmet' ?>"><span><span aria-hidden="true"><?= $trustRatingMet ? '✓' : '✗' ?></span> Điểm trung bình</span><strong><?= $trustReviewCount > 0 ? e(number_format((float)$trustMetrics['average_rating'], 1, ',', '.')).'★' : 'Chưa có' ?> / cần ≥ <?= e(number_format((float)$trustMetrics['min_rating'], 1, ',', '.')) ?>★ · <?= $trustRatingMet ? 'Đạt' : 'Chưa đạt' ?></strong></li>
<li class="<?= $trustCancelMet ? 'is-met' : 'is-unmet' ?>"><span><span aria-hidden="true"><?= $trustCancelMet ? '✓' : '✗' ?></span> Tỷ lệ Host từ chối/hủy (<?= (int)$trustMetrics['window_months'] ?> tháng)</span><strong><?php if($trustCancelApplies): ?><?= e(number_format((float)$trustMetrics['cancel_pct'], 1, ',', '.')) ?> % (<?= (int)$trustMetrics['host_side_cancellations'] ?>/<?= $trustDecided ?> yêu cầu) / cần ≤ <?= e($trustPercent) ?> % · <?= $trustCancelMet ? 'Đạt' : 'Chưa đạt' ?><?php else: ?>Chưa áp dụng: <?= $trustDecided ?>/<?= (int)$trustMetrics['min_decided_requests'] ?> yêu cầu đã xử lý<?php endif; ?></strong></li>
</ul>
<p class="text-muted small mt-3 mb-0">Điều kiện: ít nhất <?= (int)$trustMetrics['min_reviews'] ?> đánh giá đang hiển thị trên các chỗ ở của bạn, điểm trung bình từ <?= e(number_format((float)$trustMetrics['min_rating'], 1, ',', '.')) ?>★ và tỷ lệ yêu cầu bị Host từ chối hoặc Host hủy không quá <?= e($trustPercent) ?>% trên các yêu cầu đã có kết quả trong <?= (int)$trustMetrics['window_months'] ?> tháng gần nhất. Tiêu chí hủy chỉ áp dụng khi có từ <?= (int)$trustMetrics['min_decided_requests'] ?> yêu cầu đã xử lý.</p>
</section>
<?php endif; ?>
