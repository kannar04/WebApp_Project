<?php if (!$preview): ?>
<hr>
<section id="stay-nearby" aria-labelledby="stay-nearby-title">
    <div class="nearby-heading"><h2 id="stay-nearby-title"><?= e(__('Dịch vụ gần đây')) ?></h2><span class="nearby-radius"><?= ui_icon('pin') ?> 1 km</span></div>
    <p class="nearby-intro"><?= e(__('Ăn uống, vui chơi và những tiện ích cần thiết quanh chỗ ở.')) ?></p>
    <?php if (($listing['latitude'] ?? null) !== null && ($listing['longitude'] ?? null) !== null): ?>
    <?php if (!in_array('js/map.js', $pageScripts ?? [], true)) { $pageScripts[] = 'js/map.js'; } ?>
    <div data-nearby-services data-api="<?= e(url('/api/listings/'.(int)$listing['id'].'/nearby-services')) ?>" data-lat="<?= e($listing['latitude']) ?>" data-lng="<?= e($listing['longitude']) ?>">
        <div class="nearby-filters" role="group" aria-label="<?= e(__('Loại dịch vụ')) ?>">
            <?php foreach (['all'=>['grid','Tất cả dịch vụ'], 'cafe'=>['coffee','Quán cà phê'], 'food'=>['restaurant','Quán ăn'], 'bar'=>['glass','Bar & pub'], 'entertainment'=>['ticket','Giải trí'], 'shop'=>['shop','Cửa hàng'], 'supermarket'=>['basket','Siêu thị'], 'sights'=>['camera','Tham quan'], 'park'=>['tree','Công viên'], 'pharmacy'=>['medical','Nhà thuốc']] as $value => [$icon, $label]): ?>
            <button type="button" class="nearby-filter" data-nearby-category value="<?= e($value) ?>" aria-pressed="<?= $value === 'all' ? 'true' : 'false' ?>"><?= ui_icon($icon) ?><span><?= e(__($label)) ?></span></button>
            <?php endforeach; ?>
        </div>
        <div class="discovery-map-layout nearby-services-layout">
            <div class="discovery-map-panel"><div class="discovery-map discovery-map-search" data-nearby-map data-label="<?= e(__('Bản đồ dịch vụ gần chỗ ở')) ?>"></div></div>
            <section class="discovery-map-results" tabindex="0" aria-label="<?= e(__('Danh sách dịch vụ gần chỗ ở')) ?>" data-nearby-results>
                <div class="nearby-results-heading"><span class="nearby-results-title"><?= e(__('Quanh chỗ ở')) ?></span><p role="status" aria-live="polite" data-nearby-status><?= e(__('Đang tải dịch vụ gần đây…')) ?></p></div>
                <div role="alert" data-nearby-error hidden><p class="text-danger" data-nearby-message></p><button type="button" class="btn btn-outline-primary btn-sm" data-nearby-retry><?= e(__('Thử lại')) ?></button></div>
                <ol class="discovery-map-list" data-nearby-list></ol>
                <button type="button" class="btn btn-outline-primary nearby-view-all" data-nearby-open data-label="<?= e(__('Xem tất cả {count} địa điểm')) ?>" aria-haspopup="dialog" aria-controls="nearby-sheet" hidden></button>
            </section>
        </div>
        <dialog id="nearby-sheet" class="nearby-sheet" aria-labelledby="nearby-sheet-title" data-nearby-sheet>
            <header class="nearby-sheet-header">
                <h2 id="nearby-sheet-title"><?= e(__('Quanh chỗ ở')) ?></h2>
                <form method="dialog"><button type="submit" class="btn btn-ghost" autofocus><?= e(__('Đóng')) ?></button></form>
            </header>
            <div class="nearby-sheet-content" data-nearby-sheet-content></div>
        </dialog>
        <div class="nearby-footer"><p class="nearby-legend"><span aria-hidden="true"></span><?= e(__('Ghim màu đỏ đánh dấu chỗ ở.')) ?></p><p><?= e(__('Khoảng cách theo đường thẳng.')) ?></p><p><a href="https://www.geoapify.com/" target="_blank" rel="noopener noreferrer">Geoapify</a> · <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">© OpenStreetMap</a></p></div>
        <noscript><p><?= e(__('Bật JavaScript để xem dịch vụ gần chỗ ở.')) ?></p></noscript>
    </div>
    <?php else: ?>
    <p class="text-muted"><?= e(__('Chỗ ở chưa có tọa độ để tìm dịch vụ gần đây.')) ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>
