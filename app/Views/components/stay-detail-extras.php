<?php if(!$preview): ?>
<div class="container stay-detail-extras">
    <section class="stay-host" aria-labelledby="stay-host-title">
        <h2 id="stay-host-title"><?= e(__('Gặp gỡ Host')) ?></h2>
        <div class="stay-host-profile">
            <?php if(!empty($listing['host_avatar'])): ?><img src="<?= e(image_url($listing['host_avatar'])) ?>" alt="" loading="lazy" width="80" height="80"><?php else: ?><span class="stay-host-initial" aria-hidden="true"><?= e(mb_substr($listing['host_name'],0,1)) ?></span><?php endif; ?>
            <div><h3><?= e($listing['host_name']) ?></h3>
                <?php if(!empty($listing['host_since'])): ?><p><?= e(__('Tham gia từ {year}',['year'=>date('Y',strtotime($listing['host_since']))])) ?></p><?php endif; ?>
                <?php $badge=($trustBadges??[])[(int)$listing['host_id']]??null; $badgeShowHost=true; $badgeExplain=false; require __DIR__.'/trust-badges.php'; ?>
            </div>
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
