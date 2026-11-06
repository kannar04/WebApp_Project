<?php
/** Shared collection adapter for discovery, API batches and related stays. */
require_once __DIR__.'/ui-icons.php';
$cardTrip=\App\Services\TripContext::inputs($cardFilters??$filters??[]);
$cardReturn=$listingReturn??('/?'.http_build_query($filters??[]));
foreach($listings as $stay):
    $staySaved=in_array((int)$stay['id'],$favorites??[],true);
?>
<div class="stay-card-item" data-listing-id="<?= (int)$stay['id'] ?>" id="home-<?= (int)$stay['id'] ?>">
    <?php require __DIR__.'/stay-card.php'; ?>
</div>
<?php endforeach; ?>
