<?php
$filterErrors=$filterErrors??[];
$filterInvalid=static fn(string $name): string => isset($filterErrors[$name])?' is-invalid':'';
$filterAria=static fn(string $name): string => isset($filterErrors[$name])?' aria-invalid="true" aria-describedby="filter-error-'.e($name).'"':'';
$resetFilters=array_intersect_key($filters,array_flip(['location','check_in','check_out','guests']));
$resetUrl=url('/'.($resetFilters?'?'.http_build_query($resetFilters):'').'#listing-results');
?>
<section class="hero">
    <div class="container">
        <form class="search-bar" data-search-form method="get" action="<?= e(url('/#listing-results')) ?>">
            <div class="search-field"><label for="location"><?= e(__('Địa điểm')) ?></label><input id="location" name="location" value="<?= e($filters['location']??'') ?>" placeholder="<?= e(__('Bạn muốn đi đâu?')) ?>" maxlength="254"></div>
            <div class="search-field"><label for="check_in"><?= e(__('Nhận phòng')) ?></label><input id="check_in" type="date" name="check_in" value="<?= e($filters['check_in']??'') ?>" min="<?= date('Y-m-d') ?>"></div>
            <div class="search-field"><label for="check_out"><?= e(__('Trả phòng')) ?></label><input id="check_out" type="date" name="check_out" value="<?= e($filters['check_out']??'') ?>" min="<?= date('Y-m-d',strtotime('+1 day')) ?>"></div>
            <div class="search-field"><label for="guests"><?= e(__('Số khách')) ?></label><input id="guests" type="number" min="1" name="guests" value="<?= e($filters['guests']??1) ?>"></div>
            <?php foreach(['type','min_price','max_price','bedrooms','sort'] as $name): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($filters[$name]??'') ?>"><?php endforeach; ?>
            <?php foreach($filters['amenities']??[] as $amenityId): ?><input type="hidden" name="amenities[]" value="<?= (int)$amenityId ?>"><?php endforeach; ?>
            <button class="btn btn-primary search-button" type="submit"><?= e(__('Tìm chỗ ở →')) ?></button>
        </form>
        <div class="hero-copy"><p class="eyebrow"><?= e(__('ĐẬU ĐỖ · THƯ GIÃN · KẾT NỐI')) ?></p><h1><?= e(__('Tìm nơi bạn muốn đến.')) ?></h1><p><?= e(__('Từ căn hộ tiện nghi đến ngôi nhà bình yên — luôn có một chỗ dành cho bạn.')) ?></p></div>
    </div>
</section>
<?php require __DIR__.'/components/discovery-home.php'; ?>
<section class="listing-section" id="listing-results" aria-labelledby="listing-results-title">
    <div class="container">
        <div class="section-title"><div><h2 id="listing-results-title"><?= e(__('Chỗ ở nổi bật')) ?></h2><p class="text-muted"><?= e(__('Những chỗ ở được duyệt và đang sẵn sàng đón bạn.')) ?></p></div></div>
        <form class="filter-panel surface-card mb-4" data-filter-form method="get" action="<?= e(url('/#listing-results')) ?>" aria-labelledby="filter-title">
            <h3 id="filter-title" class="h6"><?= e(__('Lọc chỗ ở theo nhu cầu')) ?></h3>
            <p class="text-muted small" id="filter-help"><?= e(__('Giá tính bằng VND cho một đêm, chưa bao gồm phụ phí. Bỏ trống trường không cần lọc.')) ?></p>
            <?php foreach(['location','check_in','check_out','guests'] as $name): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($filters[$name]??'') ?>"><?php endforeach; ?>
            <?php if($searchError!==null): ?><div class="alert alert-danger" role="alert" data-filter-error><strong><?= e(__('Chưa áp dụng bộ lọc.')) ?></strong> <?= e($filterErrors?implode(' ',array_map('__',array_unique(array_values($filterErrors)))):__($searchError)) ?> <?= e(__('Điều kiện đã nhập được giữ lại để bạn sửa.')) ?></div><?php endif; ?>
            <div class="form-row filter-fields">
                <div class="form-group col-12 col-md-6 col-lg">
                    <label for="type-filter"><?= e(__('Loại chỗ ở')) ?></label>
                    <select id="type-filter" name="type" class="form-control<?= $filterInvalid('type') ?>"<?= $filterAria('type') ?>><option value=""><?= e(__('Tất cả loại')) ?></option>
                        <?php foreach($types as $type): ?><option value="<?= (int)$type['id'] ?>" <?= (string)($filters['type']??'')===(string)$type['id']?'selected':'' ?>><?= e(__($type['name'])) ?></option><?php endforeach; ?>
                    </select>
                    <?php if(isset($filterErrors['type'])): ?><div class="invalid-feedback" id="filter-error-type"><?= e(__($filterErrors['type'])) ?></div><?php endif; ?>
                </div>
                <div class="form-group col-12 col-md-6 col-lg">
                    <label for="sort-filter"><?= e(__('Sắp xếp')) ?></label>
                    <select id="sort-filter" name="sort" class="form-control<?= $filterInvalid('sort') ?>"<?= $filterAria('sort') ?>><option value="" <?= in_array($filters['sort']??'',['','newest'],true)?'selected':'' ?>><?= e(__('Mới nhất')) ?></option>
                        <?php foreach(['price_asc'=>__('Giá tăng dần'),'price_desc'=>__('Giá giảm dần'),'rating'=>__('Đánh giá cao')] as $value=>$label): ?><option value="<?= $value ?>" <?= ($filters['sort']??'')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                    <?php if(isset($filterErrors['sort'])): ?><div class="invalid-feedback" id="filter-error-sort"><?= e(__($filterErrors['sort'])) ?></div><?php endif; ?>
                </div>
                <div class="form-group col-12 col-md-6 col-lg">
                    <label for="min-price"><?= e(__('Giá từ / đêm')) ?></label>
                    <input id="min-price" class="form-control<?= $filterInvalid('min_price') ?>"<?= isset($filterErrors['min_price'])?' aria-invalid="true"':'' ?> name="min_price" type="number" min="0" max="9999999999.99" step="any" inputmode="decimal" value="<?= e($filters['min_price']??'') ?>" aria-describedby="filter-help<?= isset($filterErrors['min_price'])?' filter-error-min_price':'' ?>">
                    <?php if(isset($filterErrors['min_price'])): ?><div class="invalid-feedback" id="filter-error-min_price"><?= e(__($filterErrors['min_price'])) ?></div><?php endif; ?>
                </div>
                <div class="form-group col-12 col-md-6 col-lg">
                    <label for="max-price"><?= e(__('Giá đến / đêm')) ?></label>
                    <input id="max-price" class="form-control<?= $filterInvalid('max_price') ?>"<?= isset($filterErrors['max_price'])?' aria-invalid="true"':'' ?> name="max_price" type="number" min="0" max="9999999999.99" step="any" inputmode="decimal" value="<?= e($filters['max_price']??'') ?>" aria-describedby="filter-help<?= isset($filterErrors['max_price'])?' filter-error-max_price':'' ?>">
                    <div class="invalid-feedback" id="filter-error-max_price" data-price-error><?= e(isset($filterErrors['max_price'])?__($filterErrors['max_price']):__('Giá đến phải lớn hơn hoặc bằng giá từ.')) ?></div>
                </div>
                <div class="form-group col-12 col-md-6 col-lg">
                    <label for="bedrooms-filter"><?= e(__('Phòng ngủ tối thiểu')) ?></label>
                    <input id="bedrooms-filter" class="form-control<?= $filterInvalid('bedrooms') ?>"<?= $filterAria('bedrooms') ?> name="bedrooms" type="number" min="1" max="65535" step="1" value="<?= e($filters['bedrooms']??'') ?>">
                    <?php if(isset($filterErrors['bedrooms'])): ?><div class="invalid-feedback" id="filter-error-bedrooms"><?= e(__($filterErrors['bedrooms'])) ?></div><?php endif; ?>
                </div>
            </div>
            <fieldset class="filter-amenities">
                <legend class="h6"><?= e(__('Tiện nghi')) ?></legend>
                <p class="text-muted small" id="amenities-help"><?= e(__('Chỗ ở phải có tất cả tiện nghi bạn chọn.')) ?></p>
                <div class="row">
                    <?php foreach($amenities as $amenity): ?><div class="col-12 col-sm-6 col-lg-4 mb-2"><div class="custom-control custom-checkbox">
                        <input class="custom-control-input" id="filter-amenity-<?= (int)$amenity['id'] ?>" type="checkbox" name="amenities[]" value="<?= (int)$amenity['id'] ?>" aria-describedby="amenities-help" <?= in_array((int)$amenity['id'],$filters['amenities']??[],true)?'checked':'' ?>>
                        <label class="custom-control-label" for="filter-amenity-<?= (int)$amenity['id'] ?>"><?= e(__($amenity['name'])) ?></label>
                    </div></div><?php endforeach; ?>
                </div>
            </fieldset>
            <div class="filter-actions">
                <button class="btn btn-primary" type="submit" data-filter-submit><?= e(__('Lọc')) ?></button>
                <a class="btn btn-outline-primary" href="<?= e($resetUrl) ?>" data-filter-reset><?= e(__('Đặt lại')) ?></a>
            </div>
            <p class="text-muted small mb-0 mt-2"><?= e(__('Đặt lại loại, cách sắp xếp, giá, phòng ngủ và tiện nghi; giữ địa điểm, ngày và số khách.')) ?></p>
        </form>
        <?php if($searchError!==null): ?>
            <div class="empty-state" data-search-invalid><h3><?= e(__('Hãy sửa điều kiện tìm kiếm')) ?></h3><p><?= e(__('Chưa hiển thị kết quả vì bộ lọc không hợp lệ.')) ?></p><a class="btn btn-outline-primary" href="<?= e(url('/#listing-results')) ?>"><?= e(__('Xóa tất cả điều kiện')) ?></a></div>
        <?php else: ?>
            <p class="results-summary" role="status" data-results-summary><strong><?= e(__('{count} kết quả',['count'=>(int)$total])) ?></strong> · <?= e(__('Trang {page}/{pages}',['page'=>(int)$page,'pages'=>(int)$pages])) ?> · <?= e($hasFilters?__('Đã áp dụng điều kiện tìm kiếm.'):__('Đang hiển thị tất cả chỗ ở.')) ?></p>
            <?php require __DIR__.'/components/discovery-map-link.php'; ?>
            <?php if(!$listings): ?>
                <div class="empty-state"><h3><?= e(__('Chưa tìm thấy chỗ ở phù hợp')) ?></h3><p><?= e(__('Hãy thử bỏ bớt tiện nghi hoặc đổi địa điểm, ngày và số khách.')) ?></p><a class="btn btn-primary" href="<?= e(url('/#listing-results')) ?>"><?= e(__('Xóa tất cả điều kiện')) ?></a></div>
            <?php else: ?>
                <div class="row">
                    <?php foreach($listings as $listing): ?><div class="col-md-6 col-lg-4 mb-4"><article class="listing-card">
                        <a href="<?= e(url('/listings/'.$listing['id'])) ?>" class="card-image-wrap"><img class="card-image" src="<?= e(image_url($listing['image_url'])) ?>" alt="<?= e($listing['title']) ?>" loading="lazy"></a>
                        <?php if(\Core\Auth::check()): ?><button class="favorite-button <?= in_array((int)$listing['id'],$favorites,true)?'is-saved':'' ?>" data-favorite="<?= (int)$listing['id'] ?>" aria-label="<?= e(__('Lưu {title}',['title'=>$listing['title']])) ?>" aria-pressed="<?= in_array((int)$listing['id'],$favorites,true)?'true':'false' ?>">♡</button><?php endif; ?>
                        <div class="card-body"><div class="d-flex justify-content-between"><h3><a href="<?= e(url('/listings/'.$listing['id'])) ?>"><?= e($listing['title']) ?></a></h3><?php if((int)($listing['review_count']??0)>0): ?><span class="rating">★ <?= number_format((float)$listing['rating'],1) ?></span><?php else: ?><span class="rating rating-new"><?= e(__('Mới')) ?></span><?php endif; ?></div>
                            <p class="card-meta"><?= e($listing['city']) ?> · <?= e(__($listing['property_type'])) ?> · <?= e(__('{count} khách',['count'=>(int)$listing['max_guests']])) ?></p><?php if(!empty(($trustBadges??[])[(int)$listing['host_id']]['is_trusted_host'])): ?><p class="card-meta"><span class="trust-badge trust-badge-host"><span aria-hidden="true">★</span> <?= e(__('Host uy tín')) ?></span></p><?php endif; ?><p class="price"><?= e(\App\Services\BookingService::money((float)$listing['nightly_price'])) ?> <span><?= e(__('/ đêm')) ?></span></p>
                        </div>
                    </article></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if($pages>1): ?><nav aria-label="<?= e(__('Phân trang kết quả')) ?>" class="mt-3"><ul class="pagination flex-wrap"><?php foreach(array_unique(array_filter([1,$page-1,$page,$page+1,$pages],fn($p)=>$p>=1&&$p<=$pages)) as $p): ?><li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" <?= $p===$page?'aria-current="page"':'' ?> href="<?= e(url('/?'.http_build_query(array_replace($filters,['page'=>$p])).'#listing-results')) ?>"><?= (int)$p ?></a></li><?php endforeach; ?></ul></nav><?php endif; ?>
        <?php endif; ?>
    </div>
</section>

