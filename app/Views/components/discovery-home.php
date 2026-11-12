<?php
/** Signed-in home shelf: personal recommendations and recently viewed listings (nothing for anonymous visitors). */
$discoveryRecommendations=$discovery['recommendations']??[];
$discoveryRecent=$discovery['recent']??[];
?>
<?php if($discoveryRecommendations || $discoveryRecent): ?>
<section class="discovery-shelf" aria-label="<?= e(__('Gợi ý dựa trên lịch sử của bạn')) ?>" data-discovery-shelf>
    <div class="container">
        <?php if($discoveryRecommendations): ?>
        <div class="section-title"><div><h2 id="discovery-recommendations-title"><?= e(__('Gợi ý cho bạn')) ?></h2><p><?= e(__('Dựa trên chỗ ở bạn đã xem và tìm kiếm gần đây.')) ?> <a href="<?= e(url('/profile#discovery-history')) ?>"><?= e(__('Quản lý lịch sử')) ?></a></p></div></div>
        <div class="row" data-recommendations>
            <?php foreach($discoveryRecommendations as $item): ?><div class="col-sm-6 col-lg-3 mb-4"><article class="discovery-card">
                <a class="discovery-card-image" href="<?= e(url('/listings/'.(int)$item['id'])) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e(image_url($item['image_url'])) ?>" alt="" loading="lazy"></a>
                <p class="discovery-reason"><?= e(__($item['reason'])) ?></p>
                <h3><a href="<?= e(url('/listings/'.(int)$item['id'])) ?>"><?= e($item['title']) ?></a></h3>
                <p class="card-meta"><?= e($item['city']) ?> · <?= e(__($item['property_type'])) ?> · <?= e(__('{count} khách',['count'=>(int)$item['max_guests']])) ?></p>
                <p class="price"><?= e(\App\Services\BookingService::money((float)$item['nightly_price'])) ?> <span><?= e(__('/ đêm')) ?><?php if((int)$item['review_count']>0): ?> · ★ <?= number_format((float)$item['rating'],1) ?><?php endif; ?></span></p>
            </article></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if($discoveryRecent): ?>
        <h2 class="h5" id="discovery-recent-title"><?= e(__('Đã xem gần đây')) ?></h2>
        <ul class="discovery-recent" aria-labelledby="discovery-recent-title" data-recent-views>
            <?php foreach($discoveryRecent as $item): ?><li><a href="<?= e(url('/listings/'.(int)$item['id'])) ?>"><img src="<?= e(image_url($item['image_url'])) ?>" alt="" loading="lazy"><span class="discovery-recent-body"><strong><?= e($item['title']) ?></strong><small><?= e($item['city']) ?> · <?= e(\App\Services\BookingService::money((float)$item['nightly_price'])) ?> <?= e(__('/ đêm')) ?></small></span></a></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
