<?php
$pageScripts[]='js/map.js';
$filterErrors=$filterErrors??[];
$mapFilters=array_intersect_key($filters,array_flip(['location','check_in','check_out','guests','type','min_price','max_price','bedrooms','amenities','sort','city','north','south','east','west']));
$countLabel=$total>count($listings)
    ? __('Hiển thị {shown}/{total} chỗ ở trong khu vực này — phóng to để xem thêm',['shown'=>count($listings),'total'=>(int)$total])
    : __('{total} chỗ ở trong khu vực này',['total'=>(int)$total]);
// map.js renders API results client-side; it receives the property type names in the interface language from here.
$mapTypeLabels=[];
foreach($types as $type){ $mapTypeLabels[(string)$type['name']]=__((string)$type['name']); }
?>
<section class="page-section discovery-map-page" aria-labelledby="map-title">
    <div class="container">
        <div class="section-title"><div><h1 id="map-title"><?= e(__('Tìm chỗ ở trên bản đồ')) ?></h1><p><?= e(__('Di chuyển bản đồ, rồi chọn Tìm trong khu vực này để cập nhật kết quả.')) ?></p><a href="#tourism-services"><?= e(__('Khám phá địa điểm ăn uống và giải trí')) ?></a></div></div>
        <form class="surface-card discovery-map-filters mb-4" method="get" action="<?= e(url('/map')) ?>" data-map-filter-form aria-labelledby="map-filter-title">
            <h2 class="h6" id="map-filter-title"><?= e(__('Điều kiện tìm kiếm')) ?></h2>
            <?php if($searchError!==null): ?><div class="alert alert-danger" role="alert"><strong><?= e(__('Chưa áp dụng được điều kiện.')) ?></strong> <?= e($filterErrors?implode(' ',array_map('__',array_unique(array_values($filterErrors)))):__($searchError)) ?></div><?php endif; ?>
            <?php foreach(['bedrooms','sort'] as $extra): ?><input type="hidden" name="<?= e($extra) ?>" value="<?= e($filters[$extra]??'') ?>"><?php endforeach; ?>
            <?php foreach($filters['amenities']??[] as $amenity): ?><input type="hidden" name="amenities[]" value="<?= (int)$amenity ?>"><?php endforeach; ?>
            <div class="form-row">
                <div class="form-group col-12 col-md-4 col-xl-3"><label for="map-location"><?= e(__('Địa điểm')) ?></label><input class="form-control" id="map-location" name="location" value="<?= e($filters['location']??'') ?>" maxlength="254" placeholder="<?= e(__('Ví dụ: Hội An')) ?>"></div>
                <div class="form-group col-6 col-md-4 col-xl-2"><label for="map-check-in"><?= e(__('Nhận phòng')) ?></label><input class="form-control<?= isset($filterErrors['dates'])?' is-invalid':'' ?>" id="map-check-in" type="date" name="check_in" value="<?= e($filters['check_in']??'') ?>" min="<?= date('Y-m-d') ?>"></div>
                <div class="form-group col-6 col-md-4 col-xl-2"><label for="map-check-out"><?= e(__('Trả phòng')) ?></label><input class="form-control<?= isset($filterErrors['dates'])?' is-invalid':'' ?>" id="map-check-out" type="date" name="check_out" value="<?= e($filters['check_out']??'') ?>" min="<?= date('Y-m-d',strtotime('+1 day')) ?>"></div>
                <div class="form-group col-6 col-md-3 col-xl-1"><label for="map-guests"><?= e(__('Số khách')) ?></label><input class="form-control<?= isset($filterErrors['guests'])?' is-invalid':'' ?>" id="map-guests" type="number" min="1" max="65535" name="guests" value="<?= e($filters['guests']??'') ?>"></div>
                <div class="form-group col-6 col-md-3 col-xl-2"><label for="map-type"><?= e(__('Loại chỗ ở')) ?></label><select class="form-control<?= isset($filterErrors['type'])?' is-invalid':'' ?>" id="map-type" name="type"><option value=""><?= e(__('Tất cả loại')) ?></option><?php foreach($types as $type): ?><option value="<?= (int)$type['id'] ?>" <?= (string)($filters['type']??'')===(string)$type['id']?'selected':'' ?>><?= e(__($type['name'])) ?></option><?php endforeach; ?></select></div>
                <div class="form-group col-6 col-md-3 col-xl-1"><label for="map-min-price"><?= e(__('Giá từ')) ?></label><input class="form-control<?= isset($filterErrors['min_price'])?' is-invalid':'' ?>" id="map-min-price" type="number" min="0" step="any" inputmode="decimal" name="min_price" value="<?= e($filters['min_price']??'') ?>"></div>
                <div class="form-group col-6 col-md-3 col-xl-1"><label for="map-max-price"><?= e(__('Giá đến')) ?></label><input class="form-control<?= isset($filterErrors['max_price'])?' is-invalid':'' ?>" id="map-max-price" type="number" min="0" step="any" inputmode="decimal" name="max_price" value="<?= e($filters['max_price']??'') ?>"></div>
            </div>
            <div class="filter-actions"><button class="btn btn-primary" type="submit"><?= e(__('Tìm trên bản đồ')) ?></button><a class="btn btn-outline-primary" href="<?= e(url('/map')) ?>"><?= e(__('Đặt lại')) ?></a><a class="btn btn-ghost" data-map-list-link href="<?= e(url('/'.($mapFilters?'?'.http_build_query($mapFilters):'').'#listing-results')) ?>"><?= e(__('Xem dạng danh sách')) ?></a></div>
            <p class="text-muted small mb-0 mt-2"><?= e(__('Giá tính bằng VND cho một đêm. Chỉ chỗ ở đã có vị trí trên bản đồ mới được hiển thị.')) ?></p>
        </form>
        <div class="discovery-map-layout">
            <div class="discovery-map-panel"><button class="btn btn-primary mb-3" type="button" data-search-area hidden><?= e(__('Tìm trong khu vực này')) ?></button>
                <div class="discovery-map discovery-map-search" data-discovery-map="search" data-label="<?= e(__('Bản đồ chỗ ở')) ?>"
                    data-api="<?= e(url('/api/listings/map')) ?>" data-location="<?= e($filters['location']??'') ?>"
                    data-filters="<?= e(json_encode($searchError===null?array_diff_key($mapFilters,['location'=>true]):new stdClass(),JSON_UNESCAPED_UNICODE)) ?>"
                    data-initial="<?= e(json_encode($listings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>"
                    data-type-labels="<?= e(json_encode($mapTypeLabels,JSON_UNESCAPED_UNICODE|JSON_FORCE_OBJECT)) ?>"></div>
            </div>
            <section class="discovery-map-results" aria-labelledby="map-results-title" tabindex="0">
                <h2 class="h6 sr-only" id="map-results-title"><?= e(__('Chỗ ở trong khu vực bản đồ')) ?></h2>
                <p class="discovery-map-count" role="status" aria-live="polite" data-map-count><strong><?= e($countLabel) ?></strong></p>
                <p class="text-danger small" role="alert" data-map-error hidden></p>
                <ol class="discovery-map-list" data-map-list>
                    <?php foreach($listings as $item): ?><li class="discovery-map-item" data-listing-id="<?= (int)$item['id'] ?>"><a class="discovery-map-item-link" href="<?= e($item['url']) ?>"><img src="<?= e($item['photo']) ?>" alt="" loading="lazy" width="96" height="72"><span class="discovery-map-item-body"><strong><?= e($item['title']) ?></strong><span class="discovery-map-item-meta"><?= e($item['city']) ?> · <?= e(__($item['property_type'])) ?> · <?= e(__('{count} khách',['count'=>(int)$item['max_guests']])) ?></span><span class="discovery-map-item-price"><?= e($item['price_label']) ?> <span><?= e(__('/ đêm')) ?></span><?php if($item['review_count']>0): ?> · ★ <?= e(number_format($item['rating'],1)) ?><?php endif; ?></span></span></a></li><?php endforeach; ?>
                </ol>
                <div class="empty-state discovery-map-empty" data-map-empty<?= $listings?' hidden':'' ?>><h3 class="h5"><?= e(__('Không có chỗ ở trong khu vực này')) ?></h3><p><?= e(__('Hãy kéo bản đồ sang vùng khác, thu nhỏ để xem rộng hơn hoặc bỏ bớt điều kiện.')) ?></p><a class="btn btn-outline-primary" href="<?= e(url('/map')) ?>"><?= e(__('Xem tất cả chỗ ở')) ?></a></div>
            </section>
        </div>
        <section id="tourism-services" class="mt-5" aria-labelledby="services-title" data-tourism-services>
            <h2 id="services-title"><?= e(__('Bản đồ dịch vụ du lịch')) ?></h2>
            <p><?= e(__('Khám phá quán ăn, bar, địa điểm giải trí và quán nước. Chọn thành phố hoặc di chuyển bản đồ, rồi tìm trong khu vực bạn muốn.')) ?></p>
            <form class="surface-card mb-4" data-services-form>
                <div class="form-row">
                    <div class="form-group col-12 col-md-6"><label for="services-city"><?= e(__('Thành phố')) ?></label><select id="services-city" class="form-control" data-services-city><option value=""><?= e(__('Khu vực trên bản đồ')) ?></option></select></div>
                    <div class="form-group col-12 col-md-6"><label for="services-category"><?= e(__('Loại dịch vụ')) ?></label><select id="services-category" class="form-control" data-services-category><option value="all"><?= e(__('Tất cả dịch vụ')) ?></option><option value="food"><?= e(__('Quán ăn')) ?></option><option value="bar">Bar</option><option value="entertainment"><?= e(__('Giải trí')) ?></option><option value="cafe"><?= e(__('Quán nước')) ?></option></select></div>
                </div>
                <button type="submit" class="btn btn-primary" data-services-search><?= e(__('Tìm dịch vụ trong khu vực này')) ?></button>
                <p class="small text-muted mt-2 mb-0"><?= e(__('Dữ liệu cộng đồng OpenStreetMap có thể chưa đầy đủ. Phóng to bản đồ để tìm trong khu vực nhỏ hơn.')) ?></p>
            </form>
            <div class="discovery-map-layout">
                <div class="discovery-map-panel"><div class="discovery-map discovery-map-search" data-services-map data-label="<?= e(__('Bản đồ dịch vụ du lịch')) ?>"></div></div>
                <section class="discovery-map-results" aria-label="<?= e(__('Địa điểm dịch vụ du lịch')) ?>" tabindex="0">
                    <p role="status" aria-live="polite" data-services-status><?= e(__('Chọn Tìm dịch vụ trong khu vực này để tải địa điểm.')) ?></p>
                    <p role="alert" class="text-danger" data-services-error hidden></p>
                    <ol class="discovery-map-list" data-services-list></ol>
                </section>
            </div>
            <noscript><p><?= e(__('Bật JavaScript để sử dụng bản đồ dịch vụ du lịch.')) ?></p></noscript>
        </section>
    </div>
</section>
