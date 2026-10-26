<?php
/** Listing detail: small OpenStreetMap view when the Host saved coordinates (no drag/scroll capture on the page). */
if (($listing['latitude'] ?? null) !== null && ($listing['longitude'] ?? null) !== null):
    $pageScripts[]='js/map.js';
?>
<div class="discovery-map discovery-mini-map" data-discovery-map="static" data-lat="<?= e($listing['latitude']) ?>" data-lng="<?= e($listing['longitude']) ?>" data-label="<?= e(__('Bản đồ vị trí của {title}',['title'=>$listing['title']])) ?>"></div>
<?php endif; ?>
