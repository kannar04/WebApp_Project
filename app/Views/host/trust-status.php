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
    $trustMinRating = number_format((float)$trustMetrics['min_rating'], 1, ',', '.');
    $trustMetLabel = __('Đạt');
    $trustUnmetLabel = __('Chưa đạt');
?>
<section class="surface-card mt-4" id="trusted-host" aria-labelledby="trusted-host-title">
<div class="section-title compact"><div><h2 id="trusted-host-title"><?= e(__('Huy hiệu Host uy tín')) ?></h2><p><?= e(__('Tính trực tiếp từ đánh giá đang hiển thị và lịch sử xử lý booking của bạn.')) ?></p></div><?php if((int)$trustMetrics['is_trusted_host'] === 1): ?><span class="trust-badge trust-badge-host"><span aria-hidden="true">★</span> <?= e(__('Host uy tín')) ?></span><?php else: ?><span class="status status-pending"><?= e($trustUnmetLabel) ?></span><?php endif; ?></div>
<ul class="trust-progress">
<li class="<?= $trustReviewsMet ? 'is-met' : 'is-unmet' ?>"><span><span aria-hidden="true"><?= $trustReviewsMet ? '✓' : '✗' ?></span> <?= e(__('Đánh giá đang hiển thị')) ?></span><strong><?= e(__('{count}/{min} đánh giá', ['count' => $trustReviewCount, 'min' => (int)$trustMetrics['min_reviews']])) ?> · <?= e($trustReviewsMet ? $trustMetLabel : $trustUnmetLabel) ?></strong></li>
<li class="<?= $trustRatingMet ? 'is-met' : 'is-unmet' ?>"><span><span aria-hidden="true"><?= $trustRatingMet ? '✓' : '✗' ?></span> <?= e(__('Điểm trung bình')) ?></span><strong><?= $trustReviewCount > 0 ? e(number_format((float)$trustMetrics['average_rating'], 1, ',', '.')).'★' : e(__('Chưa có')) ?> / <?= e(__('cần ≥ {min}★', ['min' => $trustMinRating])) ?> · <?= e($trustRatingMet ? $trustMetLabel : $trustUnmetLabel) ?></strong></li>
<li class="<?= $trustCancelMet ? 'is-met' : 'is-unmet' ?>"><span><span aria-hidden="true"><?= $trustCancelMet ? '✓' : '✗' ?></span> <?= e(__('Tỷ lệ Host từ chối/hủy ({months} tháng)', ['months' => (int)$trustMetrics['window_months']])) ?></span><strong><?php if($trustCancelApplies): ?><?= e(__('{percent} % ({cancelled}/{decided} yêu cầu)', ['percent' => number_format((float)$trustMetrics['cancel_pct'], 1, ',', '.'), 'cancelled' => (int)$trustMetrics['host_side_cancellations'], 'decided' => $trustDecided])) ?> / <?= e(__('cần ≤ {max} %', ['max' => $trustPercent])) ?> · <?= e($trustCancelMet ? $trustMetLabel : $trustUnmetLabel) ?><?php else: ?><?= e(__('Chưa áp dụng: {decided}/{min} yêu cầu đã xử lý', ['decided' => $trustDecided, 'min' => (int)$trustMetrics['min_decided_requests']])) ?><?php endif; ?></strong></li>
</ul>
<p class="text-muted small mt-3 mb-0"><?= e(__('Điều kiện: ít nhất {reviews} đánh giá đang hiển thị trên các chỗ ở của bạn, điểm trung bình từ {rating}★ và tỷ lệ yêu cầu bị Host từ chối hoặc Host hủy không quá {percent}% trên các yêu cầu đã có kết quả trong {months} tháng gần nhất.', ['reviews' => (int)$trustMetrics['min_reviews'], 'rating' => $trustMinRating, 'percent' => $trustPercent, 'months' => (int)$trustMetrics['window_months']])) ?> <?= e(__('Tiêu chí hủy chỉ áp dụng khi có từ {min} yêu cầu đã xử lý.', ['min' => (int)$trustMetrics['min_decided_requests']])) ?></p>
</section>
<?php endif; ?>
