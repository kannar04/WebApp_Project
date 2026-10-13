<?php
// Admin-only Trusted Host state for a Host row that has not earned the badge (metrics come from sp_trust_badges).
$hostTrust = ($trustBadges ?? [])[(int)$user['id']] ?? null;
?>
<?php if($hostTrust && (int)$hostTrust['is_host'] === 1 && (int)$hostTrust['is_trusted_host'] !== 1): ?><small class="d-block text-muted"><?= e(__('Host chưa đạt uy tín: {reviews}/{min} đánh giá · {rating} · từ chối/hủy {cancel}', [
    'reviews' => (int)$hostTrust['review_count'],
    'min' => (int)$hostTrust['min_reviews'],
    'rating' => (int)$hostTrust['review_count'] > 0 ? number_format((float)$hostTrust['average_rating'], 1, ',', '.').'★' : __('chưa có điểm'),
    'cancel' => $hostTrust['cancel_pct'] !== null ? number_format((float)$hostTrust['cancel_pct'], 1, ',', '.').'%' : __('chưa áp dụng'),
])) ?></small><?php endif; ?>
