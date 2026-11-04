<?php
require_once __DIR__.'/ui-icons.php';
$trip=\App\Services\TripContext::inputs($filters??[]);
$searchReturn='/?'.http_build_query($filters??[]);
?>
<?php foreach($listings as $listing):
    $detailUrl=url(\App\Services\TripContext::path((int)$listing['id'],$trip));
    $saved=in_array((int)$listing['id'],$favorites??[],true);
?>
<div class="col-md-6 col-lg-4 mb-4" data-listing-id="<?= (int)$listing['id'] ?>" id="home-<?= (int)$listing['id'] ?>">
    <article class="listing-card listing-card-glow">
        <div class="listing-image-glow" aria-hidden="true"><img src="<?= e(image_url($listing['image_url'])) ?>" alt="" loading="lazy" decoding="async" width="840" height="610"></div>
        <a href="<?= e($detailUrl) ?>" class="card-image-wrap" data-open-home><img class="card-image" src="<?= e(image_url($listing['image_url'])) ?>" alt="<?= e($listing['title']) ?>" loading="lazy" width="840" height="610"></a>
        <?php if(\Core\Auth::check()): ?>
        <button class="favorite-button <?= $saved?'is-saved':'' ?>" data-favorite="<?= (int)$listing['id'] ?>" aria-label="<?= e(__('Lưu {title}',['title'=>$listing['title']])) ?>" aria-pressed="<?= $saved?'true':'false' ?>"><?= ui_icon('heart') ?></button>
        <?php else: ?>
        <a class="favorite-button" href="<?= e(url('/login?'.http_build_query(['next'=>$searchReturn.'#home-'.$listing['id']]))) ?>" aria-label="<?= e(__('Đăng nhập để lưu {title}',['title'=>$listing['title']])) ?>"><?= ui_icon('heart') ?></a>
        <?php endif; ?>
        <div class="card-body">
            <p class="listing-location"><?= e(__($listing['property_type'])) ?> · <?= e($listing['city']) ?></p>
            <h3><a href="<?= e($detailUrl) ?>" data-open-home><?= e($listing['title']) ?></a></h3>
            <p class="card-meta"><?= e(__('{count} khách',['count'=>(int)$listing['max_guests']])) ?> · <?= e(__('{count} phòng ngủ',['count'=>(int)$listing['bedroom_count']])) ?>
            <?php if((int)($listing['review_count']??0)>0): ?> · <?= ui_icon('star') ?> <?= number_format((float)$listing['rating'],1) ?> (<?= (int)$listing['review_count'] ?>)<?php else: ?> · <?= e(__('Mới')) ?><?php endif; ?></p>
            <?php if(!empty(($trustBadges??[])[(int)$listing['host_id']]['is_trusted_host'])): ?><p class="card-meta"><?= e(__('Host uy tín')) ?></p><?php endif; ?>
            <p class="price"><?= e(\App\Services\BookingService::money((float)$listing['nightly_price'])) ?> <span><?= e(__('giá cơ bản / đêm')) ?></span></p>
        </div>
    </article>
</div>
<?php endforeach; ?>
