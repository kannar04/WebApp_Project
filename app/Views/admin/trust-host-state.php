<?php
// Admin-only Trusted Host state for a Host row that has not earned the badge (metrics come from sp_trust_badges).
$hostTrust = ($trustBadges ?? [])[(int)$user['id']] ?? null;
?>
<?php if($hostTrust && (int)$hostTrust['is_host'] === 1 && (int)$hostTrust['is_trusted_host'] !== 1): ?><small class="d-block text-muted">Host chưa đạt uy tín: <?= (int)$hostTrust['review_count'] ?>/<?= (int)$hostTrust['min_reviews'] ?> đánh giá · <?= (int)$hostTrust['review_count'] > 0 ? e(number_format((float)$hostTrust['average_rating'], 1, ',', '.')).'★' : 'chưa có điểm' ?> · từ chối/hủy <?= $hostTrust['cancel_pct'] !== null ? e(number_format((float)$hostTrust['cancel_pct'], 1, ',', '.')).'%' : 'chưa áp dụng' ?></small><?php endif; ?>
