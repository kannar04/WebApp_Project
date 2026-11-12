<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
putenv('APP_URL=http://127.0.0.1:8091');
require dirname(__DIR__).'/core/bootstrap.php';
Core\Session::start();
$output=sys_get_temp_dir().'/h2h-qc-ui';
if(!is_dir($output)){mkdir($output,0700,true);}
$controller=new Core\Controller();
$booking=['id'=>42,'title'=>'Chỗ ở thử nghiệm'];
$conversation=['state'=>['other_name'=>'Chủ nhà thử nghiệm','my_role'=>'guest','can_send'=>1,'status'=>'pending'],
    'messages'=>[['is_mine'=>0,'sender_name'=>'Chủ nhà','created_at'=>'2026-11-09 10:00:00','is_hidden'=>0,'body'=>'Xin chào! Bạn cần hỗ trợ gì về chỗ ở?','read_at'=>null]],'draft'=>''];
ob_start();$controller->render('messages/show',compact('booking','conversation')+['title'=>'QC tin nhắn','booking'=>['id'=>42,'title'=>'Chỗ ở thử nghiệm']]);$html=ob_get_clean();
file_put_contents($output.'/messages.html',$html);
$listing=['id'=>42,'title'=>'Chỗ ở thử nghiệm','moderation_status'=>'pending','address'=>'12 Đường Nguyễn Văn Cừ','city'=>'Đà Lạt'];
$photos=[['id'=>1,'image_url'=>'/assets/images/demo/1502672260266-1c1ef2d93688.jpg','alt_text'=>'Phòng khách'],['id'=>2,'image_url'=>'/assets/images/demo/1560448204-e02f11c3d0e2.jpg','alt_text'=>'Phòng ngủ']];
ob_start();$controller->render('host/listing-form',compact('listing','photos')+['title'=>'QC đăng chỗ ở','types'=>[['id'=>1,'name'=>'Căn hộ']],'policies'=>[],'amenities'=>[],'selectedAmenities'=>[],'old'=>[]]);$html=ob_get_clean();
file_put_contents($output.'/host.html',$html);
echo $output.PHP_EOL;
