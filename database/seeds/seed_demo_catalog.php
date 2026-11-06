<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/core/bootstrap.php';
$app=require dirname(__DIR__,2).'/config/app.php';
$config=require dirname(__DIR__,2).'/config/database.php';
if ($app['env']!=='local' || !in_array($config['host'],['127.0.0.1','localhost','::1'],true) || $config['database']!=='db_home2home') {
    fwrite(STDERR,"Demo catalog is restricted to local db_home2home.\n"); exit(1);
}
$apply=in_array('--apply',$argv,true);
$catalog=require __DIR__.'/demo_catalog.php';
$db=\Core\Database::connection();
$locked=false;
try {
    $locked=(int)$db->query("SELECT GET_LOCK('home2home_demo_catalog_v1',10)")->fetchColumn()===1;
    if (!$locked) { throw new RuntimeException('Another catalog seed is running.'); }
    $host=$db->query("SELECT id FROM users WHERE email='host@home2home.test' AND status='active' AND deleted_at IS NULL AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=users.id AND role='host')")->fetchColumn();
    if (!$host) { throw new RuntimeException('The local demo host is missing or inactive.'); }
    foreach ([['property_types',[1,2,3]],['cancellation_policies',[1,2]],['amenities',[1,2,3,4,6]]] as [$table,$ids]) {
        $available=$db->query('SELECT id FROM '.$table.' WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($ids,array_map('intval',$available))) { throw new RuntimeException('Required demo catalog values are missing.'); }
    }
    foreach ($catalog as $room) {
        $photo=dirname(__DIR__,2).'/public/assets/images/demo/'.$room[7].'.jpg';
        if (!is_file($photo) || (new finfo(FILEINFO_MIME_TYPE))->file($photo)!=='image/jpeg' || getimagesize($photo)===false) {
            throw new RuntimeException('Missing or invalid catalog image: '.$room[7]);
        }
    }
    $existing=$db->query('SELECT * FROM listings ORDER BY id')->fetchAll();
    $existingIds=array_map('intval',array_column($existing,'id'));
    $bookingCount=(int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
    $find=$db->prepare('SELECT id FROM listings WHERE host_id=? AND (moderation_reason=? OR (title=? AND city=?)) LIMIT 1');
    $columns=['host_id','property_type_id','cancellation_policy_id','title','address','city','country_code','latitude','longitude','time_zone','description','base_nightly_rate','fee_amount','currency','max_guests','room_count','bedroom_count','bed_count','check_in_time','check_out_time','smoking_allowed','pets_allowed','parties_allowed','house_rules','moderation_status','moderation_reason','is_visible'];
    $insert=$db->prepare('INSERT INTO listings (`'.implode('`,`',$columns).'`,created_at,updated_at) VALUES ('.implode(',',array_fill(0,count($columns),'?')).',NOW(6),NOW(6))');
    $photoInsert=$db->prepare('INSERT INTO listing_photos(listing_id,image_url,alt_text,sort_order,created_at) VALUES(?,?,?,0,NOW(6))');
    $amenityInsert=$db->prepare('INSERT INTO listing_amenities(listing_id,amenity_id) VALUES(?,?)');
    $settingsInsert=$db->prepare('INSERT INTO listing_booking_settings(listing_id,weekend_nightly_rate,holiday_nightly_rate,weekly_discount_pct,monthly_discount_pct,instant_book,created_at,updated_at) VALUES(?,?,NULL,7,12,?,NOW(6),NOW(6))');
    $cities=['Đà Lạt'=>[11.9404,108.4583,'Phường 8'],'Hội An'=>[15.8801,108.3380,'Cẩm Châu'],'Đà Nẵng'=>[16.0544,108.2022,'Sơn Trà'],'Vũng Tàu'=>[10.3459,107.0843,'Phường Thắng Tam']];
    $inserted=0;$skipped=0;
    if ($apply) { $db->beginTransaction(); }
    foreach ($catalog as $index=>[$key,$title,$city,$type,$price,$guests,$bedrooms,$photoId]) {
        $marker='Home2Home demo catalog v1: '.$key;
        $find->execute([(int)$host,$marker,$title,$city]);
        if ($find->fetchColumn()) { $skipped++; continue; }
        if (!$apply) { $inserted++; continue; }
        [$lat,$lng,$area]=$cities[$city];
        $lat+=($index%5-2)*.002;$lng+=($index%4-1)*.002;
        $description='Chỗ ở minh họa để trải nghiệm Home2Home, không phải cơ sở lưu trú đã xác minh. '.($type===1?'Không gian riêng dành cho gia đình và nhóm bạn, có khu sinh hoạt chung và bếp.':($type===2?'Phòng ngủ riêng cho chuyến nghỉ ngắn ngày; một số khu vực sinh hoạt được dùng chung.':'Một giường trong phòng chung, phù hợp khách đi một mình và muốn giao lưu.')).' Địa chỉ và tọa độ chỉ dùng cho dữ liệu demo.';
        $values=[(int)$host,$type,$index%2+1,$title,$area.', '.$city.' (địa chỉ demo)',$city,'VN',number_format($lat,6,'.',''),number_format($lng,6,'.',''),'Asia/Ho_Chi_Minh',$description,$price,$type===3?0:100000,'VND',$guests,$bedrooms+($type===1?2:0),$bedrooms,max($bedrooms,(int)ceil($guests/2)),'14:00:00','11:00:00',0,$index%4===0?1:0,0,'Giữ yên tĩnh sau 22:00. Dữ liệu minh họa cho môi trường phát triển.','approved',$marker,1];
        $insert->execute($values);$id=(int)$db->lastInsertId();
        $photoInsert->execute([$id,'/assets/images/demo/'.$photoId.'.jpg','Ảnh minh họa chỗ ở demo: '.$title]);
        foreach (array_merge([1,3],$type===1?[2,4]:[], $index%4===0?[6]:[]) as $amenity) { $amenityInsert->execute([$id,$amenity]); }
        $settingsInsert->execute([$id,(int)round($price*1.15),$index%3===0?1:0]);
        $inserted++;
    }
    if ($apply) {
        $after=$existingIds?$db->query('SELECT * FROM listings WHERE id IN ('.implode(',',$existingIds).') ORDER BY id')->fetchAll():[];
        if ($after!==$existing || (int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()!==$bookingCount) { throw new RuntimeException('Existing records changed unexpectedly.'); }
        $db->commit();
    }
    echo ($apply?'APPLIED':'DRY RUN').": {$inserted} demo rooms ".($apply?'added':'ready').", {$skipped} existing rooms skipped. Existing listings and bookings preserved.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR,'Catalog seed stopped: '.$error->getMessage()."\n");exit(1);
} finally {
    if ($locked) { $db->query("SELECT RELEASE_LOCK('home2home_demo_catalog_v1')"); }
}
