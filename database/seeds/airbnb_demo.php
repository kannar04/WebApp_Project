<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__, 2) . '/core/bootstrap.php';

$directory = dirname(__DIR__, 2) . '/demo_stays/airbnb_vietnam';
$raw = json_decode(file_get_contents($directory . '/raw.json'), true, 512, JSON_THROW_ON_ERROR);
$cities = ['Hanoi'=>'Hà Nội','Ho Chi Minh City'=>'Hồ Chí Minh','Da Nang'=>'Đà Nẵng','Hoi An'=>'Hội An','Hue'=>'Huế','Da Lat'=>'Đà Lạt','Nha Trang'=>'Nha Trang','Vung Tau'=>'Vũng Tàu','Ha Long'=>'Hạ Long','Phu Quoc'=>'Phú Quốc'];
$prices = [];
foreach ($raw as $record) {
    $city = $record['discovery_input']['location'] ?? '';
    if (($record['price'] ?? 0) > 0 && ($record['currency'] ?? '') === 'VND') { $prices[$city][] = (float)$record['price']; }
}
function median(array $values): int {
    sort($values, SORT_NUMERIC);
    $count = count($values);
    if (!$count) { throw new RuntimeException('No source prices for demo fallback.'); }
    return (int)round(($values[(int)floor(($count-1)/2)] + $values[(int)floor($count/2)]) / 2);
}
function detailCount(array $details, string $kind): int {
    foreach ($details as $detail) {
        if (preg_match('/^(\d+)\s+' . $kind . 's?\b/i', $detail, $match)) { return max(1, (int)$match[1]); }
    }
    return 1;
}
$listings = [];
$seen = [];
$counts = [];
foreach ($raw as $record) {
    $id = (string)($record['property_id'] ?? '');
    $sourceCity = $record['discovery_input']['location'] ?? '';
    $city = $cities[str_replace(', Vietnam', '', $sourceCity)] ?? null;
    if (!$id || !$city || isset($seen[$id])) { throw new RuntimeException('Invalid city or duplicate source property.'); }
    $seen[$id] = true;
    if (($record['currency'] ?? '') !== 'VND') { throw new RuntimeException('Expected VND prices.'); }
    $counts[$city] = ($counts[$city] ?? 0) + 1;
    if (count($counts)>10 || $counts[$city]>20) { throw new RuntimeException('City/listing cap exceeded.'); }
    $amenities = [];
    foreach ($record['amenities'] ?? [] as $group) {
        if (strcasecmp($group['group_name'] ?? '', 'Not included') === 0) { continue; }
        foreach ($group['items'] ?? [] as $item) { $amenities[] = $item['name']; }
    }
    $has = static function(string $pattern) use ($amenities): bool {
        foreach ($amenities as $amenity) { if (preg_match($pattern, $amenity)) { return true; } }
        return false;
    };
    $icons = [];
    foreach (['wifi'=>'/\bwifi\b/i','kitchen'=>'/^kitchen$/i','car'=>'/parking/i','pool'=>'/\bpool\b/i','snowflake'=>'/air conditioning/i','paw'=>'/^pets allowed$/i'] as $icon=>$pattern) {
        if ($has($pattern)) { $icons[] = $icon; }
    }
    $estimated = !is_numeric($record['price'] ?? null) || $record['price'] <= 0;
    $description = trim(html_entity_decode(strip_tags($record['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // Some scraper strings contain a literal escaped apostrophe.
    $description = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', static fn($m)=>json_decode('"'.$m[0].'"'), $description);
    $description .= "\n\nDữ liệu demo Home2Home. Nguồn: https://www.airbnb.com/rooms/$id\n[Airbnb demo: $id]";
    $description .= "\nChính sách demo: Linh hoạt; nhận phòng 14:00, trả phòng 11:00. Lịch trống và giá chỉ phục vụ thử nghiệm.";
    if ($estimated) { $description .= "\nGiá demo ước tính theo trung vị giá đã thu thập trong cùng thành phố; nguồn không cung cấp giá."; }
    $details = $record['details'] ?? [];
    $space = strtolower(implode(' ', $record['description_items'] ?? []));
    $type = str_contains($space, 'shared room') ? 'Phòng chung' : ((str_contains($space, 'private room') || str_starts_with($space, 'room in ')) ? 'Phòng riêng' : 'Nguyên căn');
    $photos = array_values(array_unique(array_filter($record['images'] ?? [], static fn($url)=>is_string($url) && str_starts_with($url, 'https://a0.muscache.com/'))));
    if (!$photos) { throw new RuntimeException("Missing photos: $id"); }
    $listings[] = [
        'source_id'=>$id,'source_url'=>"https://www.airbnb.com/rooms/$id",'title'=>mb_substr($record['listing_title'] ?: $record['name'],0,200),
        'property_type'=>$type,'address'=>$record['location'] ?: $city,'city'=>$city,'country_code'=>'VN',
        'latitude'=>$record['lat'],'longitude'=>$record['long'],'description'=>$description,
        'nightly_price'=>$estimated ? median($prices[$sourceCity] ?? []) : (int)round($record['price']),
        'price_estimated'=>$estimated,'cleaning_fee'=>max(0,(int)round($record['pricing_details']['cleaning_fee'] ?? 0)),
        'currency'=>'VND','max_guests'=>max(1,(int)($record['guests'] ?? 1)),
        'room_count'=>detailCount($details,'bedroom'),'bedroom_count'=>detailCount($details,'bedroom'),'bed_count'=>detailCount($details,'bed'),
        'check_in_time'=>'14:00:00','check_out_time'=>'11:00:00','allows_smoking'=>$has('/^smoking allowed$/i'),
        'allows_pets'=>$has('/^pets allowed$/i'),'allows_parties'=>false,'amenity_icons'=>$icons,'amenities'=>$amenities,
        'photos'=>array_slice($photos,0,3),
    ];
}
foreach($listings as &$listing){
    foreach($listing['photos'] as $index=>&$photo){
        $local='/uploads/airbnb-'.$listing['source_id'].'-'.($index+1).'.jpg';
        if(is_file(dirname(__DIR__,2).'/public'.$local)){ $photo=$local; }
    } unset($photo);
} unset($listing);
file_put_contents($directory.'/listings.json',json_encode($listings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$csv=fopen($directory.'/stays.csv','w');
$existing=fopen(dirname($directory).'/stays.csv','r');
fputcsv($csv,fgetcsv($existing,0,',','"','')); fclose($existing);
foreach($listings as $l) {
    fputcsv($csv,['AIRBNB-'.$l['source_id'],'airbnb-demo@example.test',$l['title'],$l['property_type'],$l['description'],$l['address'],$l['city'],'VN',$l['latitude'],$l['longitude'],$l['nightly_price'],$l['cleaning_fee'],'VND',$l['max_guests'],$l['room_count'],$l['bedroom_count'],$l['bed_count'],$l['check_in_time'],$l['check_out_time'],(int)$l['allows_smoking'],(int)$l['allows_pets'],0,'Chỉ dùng làm dữ liệu demo','Linh hoạt',implode('; ',$l['amenities']),implode('; ',$l['photos'])]);
} fclose($csv);
echo json_encode(['converted'=>count($listings),'estimated_prices'=>count(array_filter($listings,fn($l)=>$l['price_estimated'])),'cities'=>$counts],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
if (!in_array('--import',$argv,true)) { echo "Preview generated. Use --import to seed the configured database.\n"; exit; }
foreach($listings as $listing){foreach($listing['photos'] as $photo){if(!str_starts_with($photo,'/uploads/')){throw new RuntimeException('Download local photos with airbnb_photos.php before importing.');}}}

$users = new App\Models\User();
$model = new App\Models\Listing();
$db = Core\ProcedureConnection::connection();
$admins = [];
foreach ($users->all() as $user) {
    $full = $users->findWithRoles((int)$user['id']);
    if ($full && in_array('admin',$full['roles'],true)) { $admins[]=$full['id']; }
}
if (!$admins) { throw new RuntimeException('An active demo administrator is required.'); }
$typeIds = array_column($model->types(),'id','name');
$policyIds = array_column($model->policies(),'id','name');
$amenityIds = array_column($model->allAmenities(),'id','icon_key');
if (!isset($policyIds['Linh hoạt'])) { throw new RuntimeException('Missing flexible demo cancellation policy.'); }
$db->beginTransaction();
try {
    $host = $users->findByEmail('airbnb-demo@example.test');
    if (!$host) {
        $hostId = $users->create('airbnb-demo@example.test',bin2hex(random_bytes(32)),'Home2Home Demo',null);
        $users->grantHost($hostId);
    } else {
        $hostId = (int)$host['id'];
        $full = $users->findWithRoles($hostId);
        if (!$full || !in_array('host',$full['roles'],true)) { throw new RuntimeException('Demo host is inactive or missing its host role.'); }
    }
    $existing = [];
    foreach ($model->hostListings($hostId) as $listing) {
        $owned=$model->findOwned((int)$listing['id'],$hostId);
        if (preg_match('/\[Airbnb demo: (\d+)\]/',$owned['description'] ?? '',$match)) { $existing[$match[1]]=(int)$listing['id']; }
    }
    $manifest=[]; $created=0;
    foreach ($listings as $listing) {
        $sourceId=$listing['source_id'];
        if (isset($existing[$sourceId])) { $manifest[$sourceId]=$existing[$sourceId]; continue; }
        $id=$model->create($hostId,$listing+['property_type_id'=>$typeIds[$listing['property_type']],'cancellation_policy_id'=>$policyIds['Linh hoạt']]);
        foreach ($listing['photos'] as $photo) { $model->addPhoto($id,$photo,$listing['title'],$hostId); }
        $ids=[];
        foreach($listing['amenity_icons'] as $icon) { if(isset($amenityIds[$icon])) { $ids[]=$amenityIds[$icon]; } }
        $model->setAmenities($id,$ids,$hostId);
        (new App\Models\AdminRepository())->moderate($id,(int)$admins[0],'approved','Imported Airbnb demo fixture; not a live offer.');
        $manifest[$sourceId]=$id; $created++;
    }
    $db->commit();
} catch (Throwable $exception) {
    if($db->inTransaction()) { $db->rollBack(); }
    throw $exception;
}
file_put_contents($directory.'/import-manifest.json',json_encode(['host_id'=>$hostId,'listings'=>$manifest],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "Imported $created new demo listings; ".count($manifest)." source mappings saved.\n";
