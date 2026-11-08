<?php if(!$preview): ?>
<div class="container stay-detail-extras">
    <?php
        $hostBadge = ($trustBadges ?? [])[(int)$listing['host_id']] ?? [];
        $hostReviewCount = (int)($hostBadge['review_count'] ?? 0);
        $hostJoined = !empty($listing['host_since']) ? strtotime((string)$listing['host_since']) : false;
    ?>
    <section class="stay-host" id="stay-host" aria-labelledby="stay-host-title">
        <h2 id="stay-host-title"><?= e(__('Gặp gỡ Host')) ?></h2>
        <div class="stay-host-layout">
        <div>
        <div class="stay-host-profile">
            <?php if(!empty($listing['host_avatar'])): ?><img src="<?= e(image_url($listing['host_avatar'])) ?>" alt="" loading="lazy" width="80" height="80"><?php else: ?><span class="stay-host-initial" aria-hidden="true"><?= e(mb_substr($listing['host_name'],0,1)) ?></span><?php endif; ?>
            <div><h3><?= e($listing['host_name']) ?></h3>
                <p><?= e(__('Host của {title}', ['title'=>$listing['title']])) ?></p>
                <?php $badge=$hostBadge; $badgeShowHost=true; $badgeExplain=false; require __DIR__.'/trust-badges.php'; ?>
            </div>
        </div>
        <?php if(!empty($listing['reviews'])): ?><a class="stay-host-review-link" href="#stay-reviews"><?= e(__('Xem đánh giá về chỗ ở này')) ?> <?= ui_icon('arrow') ?></a><?php endif; ?>
        </div>
        <dl class="stay-host-facts">
            <div><dt><?= ui_icon('calendar') ?> <?= e(__('Thành viên từ')) ?></dt><dd><?php if($hostJoined!==false): ?><time datetime="<?= e(date('Y-m-d',$hostJoined)) ?>"><?= e(date('m/Y',$hostJoined)) ?></time><?php else: ?><?= e(__('Chưa có thông tin')) ?><?php endif; ?></dd></div>
            <div><dt><?= ui_icon('star') ?> <?= e(__('Đánh giá về Host')) ?></dt><dd><?php if($hostReviewCount>0): ?><strong><?= e(display_decimal((float)$hostBadge['average_rating'],1)) ?><span> / 5</span></strong><span class="stay-host-review-count"><?= e(__('{count} đánh giá trên các chỗ ở của Host',['count'=>$hostReviewCount])) ?></span><?php else: ?><?= e(__('Chưa có đánh giá')) ?><?php endif; ?></dd></div>
            <div><dt><?= ui_icon('pin') ?> <?= e(__('Địa điểm chỗ ở')) ?></dt><dd><?= e($listing['city']) ?></dd></div>
            <div><dt><?= ui_icon('shield') ?> <?= e(__('Xác minh danh tính')) ?></dt><dd class="<?= !empty($hostBadge['is_verified']) ? 'stay-host-verified' : '' ?>"><?= e(!empty($hostBadge['is_verified']) ? __('Đã xác minh') : __('Chưa có xác minh danh tính')) ?></dd></div>
        </dl>
        </div>
    </section>
    <?php if(!empty($relatedListings)): ?>
    <section class="related-stays" data-stay-rail aria-labelledby="related-stays-title">
        <div class="stay-rail-heading"><h2 id="related-stays-title"><?= e(__('Chỗ ở tương tự')) ?></h2><div class="stay-rail-controls">
            <button class="stay-rail-button" type="button" data-rail-prev aria-label="<?= e(__('Chỗ ở trước')) ?>" aria-controls="related-stays-track" hidden><?= ui_icon('chevron') ?></button>
            <button class="stay-rail-button" type="button" data-rail-next aria-label="<?= e(__('Chỗ ở tiếp theo')) ?>" aria-controls="related-stays-track" hidden><?= ui_icon('chevron') ?></button>
        </div></div>
        <div class="stay-rail" id="related-stays-track" tabindex="0" aria-label="<?= e(__('Chỗ ở tương tự')) ?>">
            <?php $listings=$relatedListings; $cardFilters=$trip; $listingReturn=\App\Services\TripContext::path((int)$listing['id'],$trip); require __DIR__.'/listing-cards.php'; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php endif; ?>
