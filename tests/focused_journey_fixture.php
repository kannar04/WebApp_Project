<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';
$config=require dirname(__DIR__).'/config/database.php';
if(PHP_SAPI!=='cli'||!preg_match('/^db_home2home_schema_test_[a-f0-9]{12}$/D',$config['database'])||!in_array($config['host'],['127.0.0.1','localhost','::1'],true)){
    throw new RuntimeException('Focused fixtures require the owned isolated database runner.');
}
$db=\Core\Database::connection();
if(($argv[1]??'')==='price-change'){
    $db->exec('UPDATE listings SET base_nightly_rate=base_nightly_rate+100000 WHERE id=1');
    echo "Changed isolated listing price\n"; exit;
}
if(($argv[1]??'')==='count-bookings'){
    echo $db->query('SELECT COUNT(*) FROM bookings WHERE listing_id=1')->fetchColumn();exit;
}
$source=$db->query('SELECT * FROM listings WHERE id=1')->fetch();
unset($source['id']);
$columns=array_keys($source);
$insert=$db->prepare('INSERT INTO listings (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
$photo=$db->query('SELECT image_url,alt_text FROM listing_photos WHERE listing_id=1 ORDER BY sort_order LIMIT 1')->fetch();
$insertPhoto=$db->prepare('INSERT INTO listing_photos(listing_id,image_url,alt_text,sort_order) VALUES(?,?,?,0)');
for($i=0;$i<26;$i++){
    $row=$source;$row['title']='Focus fixture home '.($i+1);$row['latitude']='11.940000';$row['longitude']='108.440000';
    $insert->execute(array_values($row));$id=(int)$db->lastInsertId();
    $insertPhoto->execute([$id,$photo['image_url'],$photo['alt_text']]);
}
echo "Prepared 26 isolated homes for real pagination\n";
