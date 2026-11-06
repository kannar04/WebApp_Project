<?php
require_once __DIR__.'/components/ui-icons.php';
$pageScripts[]='js/focused-discovery.js';
$filterErrors=$filterErrors??[];
$filterInvalid=static fn(string $name): string => isset($filterErrors[$name])?' is-invalid':'';
$filterAria=static fn(string $name): string => isset($filterErrors[$name])?' aria-invalid="true" aria-describedby="filter-error-'.e($name).'"':'';
$resetFilters=array_intersect_key($filters,array_flip(['location','check_in','check_out','guests','city','north','south','east','west']));
$resetUrl=url('/'.($resetFilters?'?'.http_build_query($resetFilters):'').'#listing-results');
?>
<div class="home-page focused-home">
<section class="container" aria-labelledby="home-title">
    <div class="discovery-intro">
        <h1 id="home-title"><?= e(__('Bạn muốn ở đâu trong chuyến đi tới?')) ?></h1>
    </div>
    <div class="home-search" id="stay-search">
        <form class="search-bar" data-search-form method="get" action="<?= e(url('/#listing-results')) ?>">
            <div class="search-field destination-field"><label class="sr-only" for="location"><?= e(__('Địa điểm')) ?></label><input id="location" name="location" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="destination-options" value="<?= e($filters['location']??'') ?>" placeholder="<?= e(__('Bạn muốn đi đâu?')) ?>" maxlength="254"><input type="hidden" name="city" id="destination-city" value="<?= e($filters['city']??'') ?>"><div id="destination-options" class="destination-options" role="listbox" aria-label="<?= e(__('Điểm đến')) ?>" hidden></div><script type="application/json" id="destination-data"><?= json_encode($destinations,JSON_HEX_TAG|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?></script></div>
            <?php foreach(['check_in'=>'Nhận phòng','check_out'=>'Trả phòng'] as $dateName=>$dateLabel): ?>
            <div class="search-field search-date" data-search-date>
                <label class="sr-only" for="<?= $dateName ?>"><?= e(__($dateLabel.' (tùy chọn)')) ?></label>
                <span class="search-date-caption" aria-hidden="true"><?= ui_icon($dateName==='check_in'?'calendar-in':'calendar-out') ?><span data-date-caption data-placeholder="<?= e(__($dateLabel)) ?>"><?= e(__($dateLabel)) ?></span></span>
                <input id="<?= $dateName ?>" type="date" name="<?= $dateName ?>" value="<?= e($filters[$dateName]??'') ?>" min="<?= date('Y-m-d',$dateName==='check_in'?time():strtotime('+1 day')) ?>" title="<?= e(__($dateLabel.' (tùy chọn)')) ?>">
            </div>
            <?php endforeach; ?>
            <div class="search-field search-guests"><label class="sr-only" for="guests"><?= e(__('Số khách')) ?></label><?= ui_icon('user') ?><input id="guests" type="number" min="1" name="guests" value="<?= e($filters['guests']??1) ?>" title="<?= e(__('Số khách')) ?>"></div>
            <?php foreach(['type','min_price','max_price','bedrooms','sort'] as $name): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($filters[$name]??'') ?>"><?php endforeach; ?>
            <?php foreach($filters['amenities']??[] as $amenityId): ?><input type="hidden" name="amenities[]" value="<?= (int)$amenityId ?>"><?php endforeach; ?>
            <button class="btn btn-primary search-button" data-search-icon type="submit" aria-label="<?= e(__('Tìm chỗ ở')) ?>" title="<?= e(__('Tìm chỗ ở')) ?>"><svg class="search-progress-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle class="search-progress-ring" cx="10.5" cy="10.5" r="6.5" pathLength="100"/><path class="search-progress-handle" d="m16 16 4.5 4.5"/></svg></button>
        </form>
        <div class="destination-links"><a href="<?= e(url('/')) ?>" <?= !$hasFilters?'aria-current="page"':'' ?>><?= e(__('Khám phá tất cả')) ?></a><?php foreach(array_slice($destinations,0,5) as $destination): $destinationQuery=array_replace($resetFilters,['location'=>$destination['city'],'city'=>$destination['city']]); foreach(['north','south','east','west'] as $bound){unset($destinationQuery[$bound]);} ?><a href="<?= e(url('/?'.http_build_query($destinationQuery).'#listing-results')) ?>" <?= ($filters['city']??'')===$destination['city']?'aria-current="page"':'' ?>><?= e($destination['city']) ?></a><?php endforeach; ?></div>

    </div>
</section>
<?php if(!empty($upcoming) && !$hasFilters): ?><div class="container"><a class="upcoming-trip" href="<?= e(url('/bookings/'.$upcoming['id'])) ?>"><?= ui_icon('calendar') ?><span><strong><?= e(__('Chuyến đi của bạn')) ?> · <?= e($upcoming['title']) ?></strong><small><?= e($upcoming['check_in']) ?> — <?= e($upcoming['check_out']) ?> · <?= e(status_label($upcoming['status'])) ?></small></span><?= ui_icon('arrow') ?></a></div><?php endif; ?>
<section class="listing-section" id="listing-results" aria-labelledby="listing-results-title">
    <div class="container">
        <div class="discovery-results-heading"><h2 id="listing-results-title" class="<?= $hasFilters?'':'sr-only' ?>"><?= e(!empty($filters['north'])?__('Chỗ ở trong khu vực đã chọn'):(!empty($filters['location'])?__('Chỗ ở tại {place}',['place'=>$filters['location']]):__('Khám phá chỗ ở khắp Việt Nam'))) ?></h2><?php require __DIR__.'/components/discovery-map-link.php'; ?></div>
        <div class="stay-type-links" aria-label="<?= e(__('Loại chỗ ở')) ?>">
            <?php foreach(array_merge([['id'=>'','name'=>'Tất cả loại']],$types) as $type): $typeQuery=array_replace($filters,['type'=>$type['id'],'page'=>1]); ?>
            <a href="<?= e(url('/?'.http_build_query($typeQuery).'#listing-results')) ?>" <?= (string)($filters['type']??'')===(string)$type['id']?'aria-current="page"':'' ?>><?= e(__($type['name'])) ?></a><?php endforeach; ?>
        </div>
        <details class="home-filter" <?= $searchError!==null?'open':'' ?>>
            <summary><?= ui_icon('sliders') ?> <?= e(__('Bộ lọc tìm kiếm')) ?></summary>
        <form class="filter-panel surface-card mb-4" data-filter-form method="get" action="<?= e(url('/#listing-results')) ?>" aria-labelledby="filter-title">
            <h3 id="filter-title" class="h6"><?= e(__('Lọc chỗ ở theo nhu cầu')) ?></h3>
            <p class="text-muted small" id="filter-help"><?= e(__('Giá tính bằng VND cho một đêm, chưa bao gồm phụ phí. Bỏ trống trường không cần lọc.')) ?></p>
            <?php foreach(['location','check_in','check_out','guests','city','north','south','east','west'] as $name): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($filters[$name]??'') ?>"><?php endforeach; ?>
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
        </details>
        <?php if($hasFilters && $searchError===null): ?><div class="applied-filters" aria-label="<?= e(__('Điều kiện đã chọn')) ?>">
            <?php foreach(['location'=>'Địa điểm','check_in'=>'Nhận phòng','check_out'=>'Trả phòng','guests'=>'Số khách','min_price'=>'Giá từ','max_price'=>'Giá đến','bedrooms'=>'Phòng ngủ tối thiểu'] as $field=>$label): if(empty($filters[$field]))continue; $without=$filters; unset($without[$field],$without['page']); if($field==='location'){unset($without['city']);} if(in_array($field,['check_in','check_out'],true)){unset($without['check_in'],$without['check_out']);} ?>
            <a href="<?= e(url('/?'.http_build_query($without).'#listing-results')) ?>" aria-label="<?= e(__('Bỏ điều kiện {name}',['name'=>__($label)])) ?>"><?= e(__($label)) ?>: <?= e($filters[$field]) ?><span aria-hidden="true">×</span></a><?php endforeach; ?>
            <a href="<?= e(url('/#listing-results')) ?>"><?= e(__('Xóa tất cả điều kiện')) ?></a>
        </div><?php endif; ?>
        <?php if($searchError!==null): ?>
            <div class="empty-state" data-search-invalid><h3><?= e(__('Hãy sửa điều kiện tìm kiếm')) ?></h3><p><?= e(__('Chưa hiển thị kết quả vì bộ lọc không hợp lệ.')) ?></p><a class="btn btn-outline-primary" href="<?= e(url('/#listing-results')) ?>"><?= e(__('Xóa tất cả điều kiện')) ?></a></div>
        <?php else: ?>
            <p class="results-summary sr-only" role="status" data-results-summary><?= e(__('{count} kết quả',['count'=>(int)$total])) ?></p>
            <?php if(!$listings): ?>
                <div class="empty-state"><h3><?= e(__('Chưa tìm thấy chỗ ở phù hợp')) ?></h3><p><?= e(__('Hãy thử bỏ bớt tiện nghi hoặc đổi địa điểm, ngày và số khách.')) ?></p><a class="btn btn-primary" href="<?= e(url('/#listing-results')) ?>"><?= e(__('Xóa tất cả điều kiện')) ?></a></div>
            <?php else: ?>
                <div class="stay-grid" data-home-feed data-page="<?= (int)$page ?>" data-pages="<?= (int)$pages ?>" data-endpoint="<?= e(url('/api/listings')) ?>" data-user="<?= (int)(\Core\Auth::id()??0) ?>"><?php require __DIR__.'/components/listing-cards.php'; ?></div>
                <div class="feed-loading" data-feed-sentinel><p role="status" aria-live="polite" data-feed-status></p><button class="btn btn-outline-primary" data-feed-more type="button" hidden><?= e(__('Xem thêm chỗ ở')) ?></button></div>
            <?php endif; ?>
            <?php if($pages>1): ?><nav data-feed-pagination aria-label="<?= e(__('Phân trang kết quả')) ?>" class="mt-3"><ul class="pagination flex-wrap"><?php foreach(array_unique(array_filter([1,$page-1,$page,$page+1,$pages],fn($p)=>$p>=1&&$p<=$pages)) as $p): ?><li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" <?= $p===$page?'aria-current="page"':'' ?> href="<?= e(url('/?'.http_build_query(array_replace($filters,['page'=>$p])).'#listing-results')) ?>"><?= (int)$p ?></a></li><?php endforeach; ?></ul></nav><?php endif; ?>
        <?php endif; ?>
    </div>
</section>

</div>
