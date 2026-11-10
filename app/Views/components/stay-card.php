<?php
/** Inputs: $stay, $staySaved, $cardTrip and $cardReturn. Contains no layout-specific columns. */
$stayUrl=url(\App\Services\TripContext::path((int)$stay['id'],$cardTrip));
$stayImage=image_url($stay['image_url']);
$stayLoginReturn=$cardReturn.(str_contains($cardReturn,'#')?'':'#home-'.$stay['id']);
?>
<article class="listing-card listing-card-glow stay-card">
    <div class="listing-image-glow" aria-hidden="true"><img src="<?= e($stayImage) ?>" alt="" loading="lazy" decoding="async" width="600" height="600"></div>
    <a href="<?= e($stayUrl) ?>" class="card-image-wrap" data-open-home><img class="card-image" src="<?= e($stayImage) ?>" alt="<?= e($stay['title']) ?>" loading="lazy" width="600" height="600"></a>
    <?php if(\Core\Auth::check()): ?>
    <button class="favorite-button <?= $staySaved?'is-saved':'' ?>" type="button" data-favorite="<?= (int)$stay['id'] ?>" aria-label="<?= e(__('Lưu {title}',['title'=>$stay['title']])) ?>" aria-pressed="<?= $staySaved?'true':'false' ?>"><?= ui_icon('heart') ?></button>
    <?php else: ?>
    <a class="favorite-button" href="<?= e(url('/login?'.http_build_query(['next'=>$stayLoginReturn]))) ?>" aria-label="<?= e(__('Đăng nhập để lưu {title}',['title'=>$stay['title']])) ?>"><?= ui_icon('heart') ?></a>
    <?php endif; ?>
    <div class="card-body">
        <h3><a href="<?= e($stayUrl) ?>" data-open-home><?= e($stay['title']) ?></a></h3>
        <p class="price" title="<?= e(__('Giá cơ bản, chưa bao gồm phụ phí')) ?>"><?= e(\App\Services\BookingService::money((float)$stay['nightly_price'])) ?> <span><?= e(__('/ đêm')) ?></span></p>
    </div>
</article>
