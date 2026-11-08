<?php
/** Opens the current home search on the map page (map supports place, dates, guests, type and price). */
$discoveryMapQuery=[];
foreach (array_intersect_key($filters,array_flip(['location','check_in','check_out','guests','type','min_price','max_price','bedrooms','amenities','sort','city','north','south','east','west'])) as $discoveryMapKey=>$discoveryMapValue) {
    if (is_array($discoveryMapValue)?count($discoveryMapValue)>0:strlen((string)$discoveryMapValue)>0) { $discoveryMapQuery[$discoveryMapKey]=$discoveryMapValue; }
}
?>
<p class="discovery-map-link"><a class="btn btn-soft btn-sm" href="<?= e(url('/map'.($discoveryMapQuery?'?'.http_build_query($discoveryMapQuery):''))) ?>"><?= e(__('Xem trên bản đồ')) ?></a></p>
