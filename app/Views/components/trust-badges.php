<?php
// Trust badges beside a person's name. Set before require: $badge (sp_trust_badges row or ['is_verified'=>bool]),
// optional $badgeShowHost (show the Trusted Host badge) and $badgeExplain (append the Trusted Host criteria).
$badgeVerified = !empty($badge['is_verified']);
$badgeTrusted = !empty($badgeShowHost) && !empty($badge['is_trusted_host']);
?>
<?php if($badgeVerified || $badgeTrusted): ?><span class="trust-badge-row"><?php if($badgeVerified): ?><span class="trust-badge trust-badge-verified"><span aria-hidden="true">✓</span> <?= e(__('Đã xác minh')) ?></span><?php endif; ?><?php if($badgeTrusted): ?><span class="trust-badge trust-badge-host"><span aria-hidden="true">★</span> <?= e(__('Host uy tín')) ?></span><?php if(!empty($badgeExplain)): ?><small class="text-muted"><?= e(__('Từ {reviews} đánh giá, trung bình ≥ {rating}★, tỷ lệ Host từ chối/hủy ≤ {cancel}% trong {months} tháng.',['reviews'=>(int)$badge['min_reviews'],'rating'=>display_decimal((float)$badge['min_rating']),'cancel'=>display_decimal((float)$badge['max_cancel_pct'], 2, true),'months'=>(int)$badge['window_months']])) ?></small><?php endif; ?><?php endif; ?></span><?php endif; ?>
<?php $badge = null; $badgeShowHost = false; $badgeExplain = false; ?>
