<?php
$pageScripts[]='js/map.js';
$filterErrors=$filterErrors??[];
$mapFilters=array_intersect_key($filters,array_flip(['location','check_in','check_out','guests','type','min_price','max_price']));
$countLabel=$total>count($listings)
    ? 'Hiển thị '.count($listings).'/'.(int)$total.' chỗ ở trong khu vực này — phóng to để xem thêm'
    : (int)$total.' chỗ ở trong khu vực này';
?>
<section class="page-section discovery-map-page" aria-labelledby="map-title">
    <div class="container">
        <div class="section-title"><div><p class="eyebrow">BẢN ĐỒ</p><h1 id="map-title">Tìm chỗ ở trên bản đồ</h1><p>Kéo hoặc phóng to bản đồ để xem chỗ ở trong khu vực; danh sách tự cập nhật theo vùng đang xem.</p></div></div>
        <form class="surface-card discovery-map-filters mb-4" method="get" action="<?= e(url('/map')) ?>" data-map-filter-form aria-labelledby="map-filter-title">
            <h2 class="h6" id="map-filter-title">Điều kiện tìm kiếm</h2>
            <?php if($searchError!==null): ?><div class="alert alert-danger" role="alert"><strong>Chưa áp dụng được điều kiện.</strong> <?= e($searchError) ?></div><?php endif; ?>
            <div class="form-row">
                <div class="form-group col-12 col-md-4 col-xl-3"><label for="map-location">Địa điểm</label><input class="form-control" id="map-location" name="location" value="<?= e($filters['location']??'') ?>" maxlength="254" placeholder="Ví dụ: Hội An"></div>
                <div class="form-group col-6 col-md-4 col-xl-2"><label for="map-check-in">Nhận phòng</label><input class="form-control<?= isset($filterErrors['dates'])?' is-invalid':'' ?>" id="map-check-in" type="date" name="check_in" value="<?= e($filters['check_in']??'') ?>" min="<?= date('Y-m-d') ?>"></div>
                <div class="form-group col-6 col-md-4 col-xl-2"><label for="map-check-out">Trả phòng</label><input class="form-control<?= isset($filterErrors['dates'])?' is-invalid':'' ?>" id="map-check-out" type="date" name="check_out" value="<?= e($filters['check_out']??'') ?>" min="<?= date('Y-m-d',strtotime('+1 day')) ?>"></div>
                <div class="form-group col-6 col-md-3 col-xl-1"><label for="map-guests">Số khách</label><input class="form-control<?= isset($filterErrors['guests'])?' is-invalid':'' ?>" id="map-guests" type="number" min="1" max="65535" name="guests" value="<?= e($filters['guests']??'') ?>"></div>
                <div class="form-group col-6 col-md-3 col-xl-2"><label for="map-type">Loại chỗ ở</label><select class="form-control<?= isset($filterErrors['type'])?' is-invalid':'' ?>" id="map-type" name="type"><option value="">Tất cả loại</option><?php foreach($types as $type): ?><option value="<?= (int)$type['id'] ?>" <?= (string)($filters['type']??'')===(string)$type['id']?'selected':'' ?>><?= e($type['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group col-6 col-md-3 col-xl-1"><label for="map-min-price">Giá từ</label><input class="form-control<?= isset($filterErrors['min_price'])?' is-invalid':'' ?>" id="map-min-price" type="number" min="0" step="any" inputmode="decimal" name="min_price" value="<?= e($filters['min_price']??'') ?>"></div>
                <div class="form-group col-6 col-md-3 col-xl-1"><label for="map-max-price">Giá đến</label><input class="form-control<?= isset($filterErrors['max_price'])?' is-invalid':'' ?>" id="map-max-price" type="number" min="0" step="any" inputmode="decimal" name="max_price" value="<?= e($filters['max_price']??'') ?>"></div>
            </div>
            <div class="filter-actions"><button class="btn btn-primary" type="submit">Tìm trên bản đồ</button><a class="btn btn-outline-primary" href="<?= e(url('/map')) ?>">Đặt lại</a><a class="btn btn-ghost" href="<?= e(url('/'.($mapFilters?'?'.http_build_query($mapFilters):'').'#listing-results')) ?>">Xem dạng danh sách</a></div>
            <p class="text-muted small mb-0 mt-2">Giá tính bằng VND cho một đêm. Chỉ chỗ ở đã có vị trí trên bản đồ mới được hiển thị.</p>
        </form>
        <div class="discovery-map-layout">
            <div class="discovery-map-panel">
                <div class="discovery-map discovery-map-search" data-discovery-map="search" data-label="Bản đồ chỗ ở"
                    data-api="<?= e(url('/api/listings/map')) ?>" data-location="<?= e($filters['location']??'') ?>"
                    data-filters="<?= e(json_encode($searchError===null?array_diff_key($mapFilters,['location'=>true]):new stdClass(),JSON_UNESCAPED_UNICODE)) ?>"
                    data-initial="<?= e(json_encode($listings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>"></div>
            </div>
            <section class="discovery-map-results" aria-labelledby="map-results-title">
                <h2 class="h6 sr-only" id="map-results-title">Chỗ ở trong khu vực bản đồ</h2>
                <p class="discovery-map-count" role="status" aria-live="polite" data-map-count><strong><?= e($countLabel) ?></strong></p>
                <p class="text-danger small" role="alert" data-map-error hidden></p>
                <ol class="discovery-map-list" data-map-list>
                    <?php foreach($listings as $item): ?><li class="discovery-map-item" data-listing-id="<?= (int)$item['id'] ?>"><a class="discovery-map-item-link" href="<?= e($item['url']) ?>"><img src="<?= e($item['photo']) ?>" alt="" loading="lazy" width="96" height="72"><span class="discovery-map-item-body"><strong><?= e($item['title']) ?></strong><span class="discovery-map-item-meta"><?= e($item['city']) ?> · <?= e($item['property_type']) ?> · <?= (int)$item['max_guests'] ?> khách</span><span class="discovery-map-item-price"><?= e($item['price_label']) ?> <span>/ đêm</span><?php if($item['review_count']>0): ?> · ★ <?= e(number_format($item['rating'],1)) ?><?php endif; ?></span></span></a></li><?php endforeach; ?>
                </ol>
                <div class="empty-state discovery-map-empty" data-map-empty<?= $listings?' hidden':'' ?>><h3 class="h5">Không có chỗ ở trong khu vực này</h3><p>Hãy kéo bản đồ sang vùng khác, thu nhỏ để xem rộng hơn hoặc bỏ bớt điều kiện.</p><a class="btn btn-outline-primary" href="<?= e(url('/map')) ?>">Xem tất cả chỗ ở</a></div>
            </section>
        </div>
    </div>
</section>
