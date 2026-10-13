<?php
// Trust badges beside a person's name. Set before require: $badge (sp_trust_badges row or ['is_verified'=>bool]),
// optional $badgeShowHost (show the Trusted Host badge) and $badgeExplain (append the Trusted Host criteria).
$badgeVerified = !empty($badge['is_verified']);
$badgeTrusted = !empty($badgeShowHost) && !empty($badge['is_trusted_host']);
?>
<?php if($badgeVerified || $badgeTrusted): ?><span class="trust-badge-row"><?php if($badgeVerified): ?><span class="trust-badge trust-badge-verified"><span aria-hidden="true">✓</span> Đã xác minh</span><?php endif; ?><?php if($badgeTrusted): ?><span class="trust-badge trust-badge-host"><span aria-hidden="true">★</span> Host uy tín</span><?php if(!empty($badgeExplain)): ?><small class="text-muted">Từ <?= (int)$badge['min_reviews'] ?> đánh giá, trung bình ≥ <?= e(number_format((float)$badge['min_rating'],1,',','.')) ?>★, tỷ lệ Host từ chối/hủy ≤ <?= e(rtrim(rtrim(number_format((float)$badge['max_cancel_pct'],2,',','.'),'0'),',')) ?>% trong <?= (int)$badge['window_months'] ?> tháng.</small><?php endif; ?><?php endif; ?></span><?php endif; ?>
<?php $badge = null; $badgeShowHost = false; $badgeExplain = false; ?>
