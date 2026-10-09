<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';

use App\Models\Listing;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Report;
use App\Models\Catalog;
use App\Models\Notification;
use App\Models\User;
use App\Models\AdminRepository;
use App\Models\HostRepository;
use App\Services\BookingService;
use Core\ProcedureConnection;

$config=require dirname(__DIR__).'/config/database.php';
if (PHP_SAPI!=='cli' || !preg_match('/^db_home2home_schema_test_[a-f0-9]{12}$/D',$config['database'])
    || !in_array($config['host'],['127.0.0.1','localhost','::1'],true)) {
    throw new RuntimeException('Quality mutation suite requires the owned isolated DB runner. Use fresh_setup_test.php --quality-regression.');
}
function qaExpect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function qaReject(callable $operation,string $message): void
{
    try { $operation(); } catch (DomainException $exception) { return; }
    throw new RuntimeException($message);
}
function qaCall(string $sql,array $parameters=[]): array
{
    $s=ProcedureConnection::connection()->prepare($sql); $s->execute($parameters); return $s->fetchAll();
}

$listings=new Listing(); $bookings=new Booking(); $service=new BookingService(); $db=ProcedureConnection::connection();
$first=(new DateTimeImmutable('today'))->modify('+50 days')->format('Y-m-d');
$last=(new DateTimeImmutable($first))->modify('+2 days')->format('Y-m-d');
$calendar=$listings->calendar(1,2,$first,$last);
qaExpect(count($calendar)===2 && $calendar[0]['day_status']==='available','Default-open dates missing.');
qaExpect($listings->setAvailability(1,2,$first,false,'Private maintenance'),'Block failed.');
qaExpect($listings->calendar(1,0,$first,$last)[0]['day_status']==='blocked','Public blocked state incorrect.');
qaExpect($listings->calendar(1,0,$first,$last)[0]['block_reason']===null,'Public calendar leaked private note.');
$listings->setAvailability(1,2,$first,true,null);
$bookingId=$service->create(1,3,$first,$last,2);
qaExpect($listings->calendar(1,0,$first,$last)[0]['day_status']==='pending','Pending calendar missing.');
$detail=$bookings->detail($bookingId,3);
qaExpect(count($detail['nights'])===2 && (float)$detail['total_amount']===2650000.0 && count($detail['events'])===1,'Frozen booking detail incorrect.');
qaExpect($detail['host_phone']===null && $detail['guest_phone']===null,'Pending contact leak.');
qaReject(fn()=>$bookings->detail($bookingId,999999),'Unknown actor read booking.');
qaReject(fn()=>$listings->softDelete(1,2),'Host deleted a listing with pending booking.');
$service->hostTransition($bookingId,2,'confirmed');
qaExpect($listings->calendar(1,0,$first,$last)[0]['day_status']==='confirmed','Confirmed calendar missing.');
qaExpect($bookings->detail($bookingId,3)['host_phone']!==null,'Confirmed Host contact unavailable.');
$preview=$bookings->detail($bookingId,3)['refund'];
$refund=$service->cancelByGuest($bookingId,3,'Quality fixture cancellation');
qaExpect((float)$preview['refund_amount']===$refund['refund_amount'],'Refund preview disagrees with cancellation.');
qaExpect((float)$bookings->detail($bookingId,2)['refund']['refund_amount']===$refund['refund_amount'],'Stored refund detail wrong.');
qaExpect($listings->calendar(1,0,$first,$last)[0]['day_status']==='available','Cancellation did not release calendar.');
qaExpect(count($bookings->adminSearch(1,(string)$bookingId,'cancelled'))===1,'Admin booking lookup failed.');
qaReject(fn()=>$bookings->adminSearch(3,'',''),'Guest queried admin booking search.');
$inbox=new Notification(); $guestNotifications=$inbox->forUser(3); $hostNotifications=$inbox->forUser(2);
qaExpect(count(array_filter($guestNotifications,fn($n)=>(int)$n['booking_id']===$bookingId))===2,'Guest event notifications missing.');
qaExpect(count(array_filter($hostNotifications,fn($n)=>(int)$n['booking_id']===$bookingId))===3,'Host event notifications missing.');
$notificationId=(int)$guestNotifications[0]['id'];
qaReject(fn()=>$inbox->markRead($notificationId,2),'Host marked Guest notification.');
$inbox->markRead($notificationId,3);
qaExpect($inbox->forUser(3)[0]['read_at']!==null,'Read state not persistent.');
echo "PASS QA calendar default/blocked/pending/confirmed/released; privacy; immutable detail/nights/events/refund; admin lookup; per-recipient inbox/read ownership\n";

// Photo fixtures belong only to this isolated DB. No filesystem deletion involved.
$photos=$listings->photos(1,2); $ids=array_map('intval',array_column($photos,'id'));
$db->beginTransaction();
try {
    qaReject(fn()=>$listings->managePhotos(1,3,$ids,$ids),'Guest changed Host photos.');
    qaReject(fn()=>$listings->managePhotos(1,2,[$ids[0],$ids[0]],$ids),'Duplicate photo IDs accepted.');
    qaReject(fn()=>$listings->managePhotos(1,2,$ids,array_slice($ids,1)),'Stale photo snapshot accepted.');
    $listings->managePhotos(1,2,array_reverse($ids),$ids);
    qaExpect(array_map('intval',array_column($listings->photos(1,2),'id'))===array_reverse($ids),'Photo reorder failed.');
    qaExpect($db->inTransaction(),'Photo workflow committed caller transaction.');
} finally { $db->rollBack(); }
qaExpect(array_map('intval',array_column($listings->photos(1,2),'id'))===$ids,'Photo rollback lost data.');
$db->beginTransaction();
try {
    $listings->managePhotos(1,2,array_slice($ids,1),$ids);
    qaExpect(count($listings->photos(1,2))===count($ids)-1,'Photo reference removal failed.');
    qaExpect($listings->findPublic(1)===null,'Changed images bypassed moderation.');
    qaReject(fn()=>$listings->adminPreview(1,3),'Guest previewed unapproved listing.');
    $adminPreview=$listings->adminPreview(1,1);
    qaExpect(count($adminPreview['photos'])===count($ids)-1,'Admin preview missing private listing photos.');
    qaExpect(count($adminPreview['amenities'])===count($listings->amenityIds(1)),'Admin pending preview missing amenities.');
} finally { $db->rollBack(); }
echo "PASS QA photo reorder/removal/foreign actor/duplicate/stale snapshot/caller rollback/re-moderation and private Admin preview\n";

$reviews=new Review();
$historical=qaCall('CALL sp_test_historical_booking(?,?)',[1,3]); $historicalId=(int)$historical[0]['booking_id'];
qaCall('CALL sp_test_historical_confirmed(?)',[$historicalId]);
qaReject(fn()=>$service->hostTransition($historicalId,3,'completed'),'Guest completed Host stay.');
$service->hostTransition($historicalId,2,'completed');
qaExpect($bookings->detail($historicalId,3)['status']==='completed','Host past-stay completion did not persist.');
$adminHistorical=qaCall('CALL sp_test_historical_booking(?,?)',[2,3]); $adminHistoricalId=(int)$adminHistorical[0]['booking_id'];
qaCall('CALL sp_test_historical_confirmed(?)',[$adminHistoricalId]);
$service->adminTransition($adminHistoricalId,1,'completed');
qaExpect($bookings->detail($adminHistoricalId,1)['status']==='completed','Admin past-stay completion failed.');
qaExpect($reviews->create($historicalId,3,5,'Quality verified historical stay'),'Eligible review failed.');
qaExpect(!$reviews->create($historicalId,3,5,'Second review'),'Duplicate review accepted.');
$hostReviews=$reviews->forHost(2); $reviewId=(int)$hostReviews[0]['id'];
qaReject(fn()=>$reviews->reply($reviewId,3,'Forged reply'),'Guest impersonated Host.');
$reviews->reply($reviewId,2,'Thank you for staying.');
qaExpect($listings->findPublic(1)['reviews'][0]['host_reply']==='Thank you for staying.','Reply not public.');
qaReject(fn()=>$reviews->moderate($reviewId,3,'hidden'),'Guest moderated review.');
$reviews->moderate($reviewId,1,'hidden');
qaExpect(!$listings->findPublic(1)['reviews'],'Hidden review still public.');
qaExpect(!$reviews->create($historicalId,3,5,'After removal'),'Soft removal allowed repeated review.');
$reviews->moderate($reviewId,1,'visible');
qaExpect(count($reviews->forAdmin(1))===1,'Admin review listing failed.');
$monthly=(new HostRepository())->monthlyRevenue(2);
qaExpect(count($monthly)===1 && (float)$monthly[0]['revenue']>0,'Monthly completed revenue missing.');
qaExpect((new AdminRepository())->stats()['guests']===3,'Guest role count wrong.');
echo "PASS QA completed review uniqueness; Host replies; Admin soft moderation/authorization; monthly completed revenue; Guest role stats\n";
qaExpect((int)(new HostRepository())->stats(2)['completed']===2,'Host completed-booking count wrong.');
$rejectedId=$service->create(1,3,$first,$last,2); $service->hostTransition($rejectedId,2,'rejected');
foreach ([2,3] as $recipient) {
    $messages=array_filter($inbox->forUser($recipient),fn($n)=>(int)$n['booking_id']===$rejectedId);
    qaExpect((bool)array_filter($messages,fn($n)=>str_contains($n['event_type'],'rejected')),'Recipient rejection notification missing.');
}
echo "PASS QA authorized Host/Admin historical completion, Host counts and rejection notification to both participants\n";

$reports=new Report();
qaReject(fn()=>$reports->create(3,1,null,'fraud','short'),'Short report accepted.');
$reports->create(3,1,null,'misleading','The listing details need verification.');
$report=$reports->forUser(3)[0]; $reportId=(int)$report['id'];
qaReject(fn()=>$reports->resolve($reportId,3,'resolved','No permission'),'Guest resolved report.');
qaReject(fn()=>$reports->resolve($reportId,1,'resolved',''),'Blank closure accepted.');
$reports->resolve($reportId,1,'investigating','Checking details');
$reports->resolve($reportId,1,'resolved','Evidence reviewed and information corrected.');
qaExpect($reports->forUser(3)[0]['status']==='resolved' && $reports->forUser(3)[0]['resolved_at']!==null,'Report resolution not visible to reporter.');
qaExpect(count($reports->forAdmin(1))===1 && !$reports->forUser(2),'Report scope wrong.');
$catalog=new Catalog();
qaReject(fn()=>$catalog->save(3,'type',0,'Forbidden','',true),'Guest changed catalog.');
$typeId=$catalog->save(1,'type',0,'Quality category','Created during test',true);
$catalog->save(1,'type',$typeId,'Quality category updated','Updated',false);
qaExpect(!in_array($typeId,array_map('intval',array_column($listings->types(),'id')),true),'Inactive category remains selectable.');
$amenityId=$catalog->save(1,'amenity',0,'Quality amenity','fixture',true);
$catalog->save(1,'amenity',$amenityId,'Quality amenity updated','fixture',false);
qaExpect(!in_array($amenityId,array_map('intval',array_column($listings->allAmenities(),'id')),true),'Inactive amenity remains selectable.');
echo "PASS QA report submission/resolution/closure checks/scoping; catalog create/update/deactivate and Admin-only writes\n";

$filtered=$listings->searchPage(['amenities'=>[5],'min_price'=>2000000],1,0);
qaExpect($filtered['total']===1 && (int)$filtered['listings'][0]['id']===2,'Amenity/price filter wrong.');
$firstPage=$listings->searchPage(['sort'=>'price_asc'],1,0); $secondPage=$listings->searchPage(['sort'=>'price_asc'],1,1);
qaExpect($firstPage['total']===3 && $secondPage['total']===3 && (int)$firstPage['listings'][0]['id']===3 && (int)$secondPage['listings'][0]['id']===1,'Pagination/sort/count wrong.');
qaReject(fn()=>$listings->searchPage(['amenities'=>[999999]]),'Unknown amenity filter accepted.');
foreach (['price_asc'=>[3,1,2],'price_desc'=>[2,1,3],'rating'=>[1,3,2],'newest'=>[3,2,1]] as $sort=>$expectedIds) {
    qaExpect(array_map('intval',array_column($listings->searchPage(['sort'=>$sort])['listings'],'id'))===$expectedIds,'Search order wrong: '.$sort);
}
qaExpect((int)$listings->searchPage(['amenities'=>[6],'type'=>2,'bedrooms'=>1])['listings'][0]['id']===3,'Pets/type/bedroom filter wrong.');
echo "PASS QA advanced amenity/price filtering, stable sort, SQL pagination and total count\n";

$db->beginTransaction();
try {
    $configured=$listings->findOwned(1,2); $configured['check_in_time']='16:30'; $configured['check_out_time']='10:30';
    $configured['allows_smoking']=true; $configured['allows_pets']=true; $configured['allows_parties']=true;
    $listings->update(1,2,$configured); $saved=$listings->findOwned(1,2);
    qaExpect(substr($saved['check_in_time'],0,5)==='16:30' && substr($saved['check_out_time'],0,5)==='10:30'
        && $saved['allows_smoking'] && $saved['allows_pets'] && $saved['allows_parties'],'Host times/rules not persisted.');
    foreach ([1=>2650000.0,2=>1250000.0,3=>0.0] as $policyId=>$expectedRefund) {
        $configured['cancellation_policy_id']=$policyId; $listings->update(1,2,$configured);
        (new AdminRepository())->moderate(1,1,'approved','Owned policy fixture');
        $policyBooking=$service->create(1,3,$first,$last,2); $service->hostTransition($policyBooking,2,'confirmed');
        qaExpect((float)$bookings->detail($policyBooking,3)['refund']['refund_amount']===$expectedRefund,'Policy refund preview wrong: '.$policyId);
        qaExpect($service->cancelByGuest($policyBooking,3,'Owned policy fixture')['refund_amount']===$expectedRefund,'Policy refund record wrong: '.$policyId);
    }
} finally { $db->rollBack(); }
echo "PASS QA Host check-in/out and all rule flags; flexible/intermediate/strict snapshot refund preview equals recorded refund; caller rollback\n";

// Real HTTP journeys against the same owned server, not mocked responses.
$baseUrl=(require dirname(__DIR__).'/config/app.php')['url'];
$curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]);
$request=static function(string $path,?array $data=null) use($curl,$baseUrl): array {
    curl_setopt($curl,CURLOPT_URL,$baseUrl.$path); curl_setopt($curl,CURLOPT_POST,$data!==null);
    if ($data!==null) { curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($data)); } else { curl_setopt($curl,CURLOPT_HTTPGET,true); }
    $body=curl_exec($curl);
    return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'url'=>curl_getinfo($curl,CURLINFO_EFFECTIVE_URL)];
};
$token=static function(array $page): string { qaExpect((bool)preg_match('/name="_token" value="([^"]+)"/',$page['body'],$match),'Missing HTTP CSRF token.'); return $match[1]; };
$login=static function(string $role) use($request,$token): void {
    $page=$request('/login'); $logged=$request('/login',['_token'=>$token($page),'email'=>$role.'@home2home.test','password'=>'Password123!']);
    qaExpect($logged['status']===200,'HTTP login failed: '.$role);
};
$logout=static function() use($request,$token): void { $request('/logout',['_token'=>$token($request('/profile'))]); };
$login('guest');
foreach(['/bookings/'.$bookingId,'/notifications','/reports','/reports?listing=1','/reports?booking='.$bookingId,'/listings/1?month='.substr($first,0,7)] as $path) {
    $page=$request($path); qaExpect($page['status']===200 && !preg_match('/Fatal error:|Warning:/',$page['body']),'Guest HTTP page failed: '.$path);
}
qaExpect($request('/bookings/999999')['status']===404,'Unknown booking not safe 404.');
qaExpect($request('/admin/listings/1/preview')['status']===403 && $request('/admin/catalog')['status']===403,'Guest accessed private Admin flow.');
qaExpect($request('/api/listings?amenities%5B%5D=999999')['status']===422,'Bad filter did not return 422.');
$api=json_decode($request('/api/listings?amenities%5B%5D=5')['body'],true);
qaExpect($api['success'] && $api['data']['total']===1 && $api['data']['page']===1,'HTTP search metadata incorrect.');
  $page=$request('/reports?listing=1');
  $invalidReport=$request('/reports',['_token'=>$token($page),'listing_id'=>1,'category'=>'fraud','description'=>'short']);
  qaExpect(str_contains($invalidReport['body'],'>short</textarea>') && preg_match('/value="fraud"\s+selected/',$invalidReport['body'])===1,'Invalid report erased description/category.');
  qaExpect(count($reports->forUser(3))===1,'Invalid report changed saved data.');
  $page=$request('/reports?listing=1');
$sent=$request('/reports',['_token'=>$token($page),'listing_id'=>1,'category'=>'other','description'=>'HTTP end-to-end quality report.']);
qaExpect($sent['status']===200 && count($reports->forUser(3))===2,'HTTP report persistence failed.');
$logout(); $login('host');
foreach(['/host','/host/reviews','/host/listings/1/edit','/host/listings/1/availability','/bookings/'.$bookingId] as $path) { qaExpect($request($path)['status']===200,'Host HTTP page failed.'); }
$page=$request('/host/reviews'); $request('/host/reviews/'.$reviewId.'/reply',['_token'=>$token($page),'reply'=>'HTTP Host reply.']);
qaExpect($listings->findPublic(1)['reviews'][0]['host_reply']==='HTTP Host reply.','HTTP reply not persistent.');
$ownedPhotos=$listings->photos(1,2); $positions=[];
foreach ($ownedPhotos as $index=>$photo) { $positions[(int)$photo['id']]=count($ownedPhotos)-$index; }
$lastPhoto=(int)$ownedPhotos[count($ownedPhotos)-1]['id'];
$page=$request('/host/listings/1/edit');
$request('/host/listings/1/photos',['_token'=>$token($page),'positions'=>$positions,'remove'=>[$lastPhoto]]);
qaExpect(count($listings->photos(1,2))===count($ownedPhotos)-1 && $listings->findPublic(1)===null,'HTTP photo removal/moderation failed.');
qaExpect($request('/host/listings/1/edit')['status']===200,'Pending photo management no longer accessible.');
$data=$listings->findOwned(1,2); $data['title']='Quality isolated deletable listing';
$deletable=$listings->create(2,$data);
$page=$request('/host'); $request('/host/listings/'.$deletable.'/delete',['_token'=>$token($page)]);
qaExpect($listings->findOwned($deletable,2)===null,'HTTP Host soft-delete failed.');
qaExpect($bookings->detail($bookingId,2)['status']==='cancelled','Host deletion damaged booking history.');
$logout(); $login('admin');
foreach(['/admin','/admin/reviews','/admin/reports','/admin/catalog','/admin/listings/1/preview','/bookings/'.$bookingId,'/admin?booking_q='.$bookingId] as $path) { qaExpect($request($path)['status']===200,'Admin HTTP page failed: '.$path); }
foreach (['type','amenity'] as $kind) {
    $page=$request('/admin/catalog'); $name='HTTP quality '.$kind;
    $request('/admin/catalog',['_token'=>$token($page),'kind'=>$kind,'id'=>0,'name'=>$name,'detail'=>'HTTP fixture','active'=>'1']);
    $saved=array_values(array_filter($catalog->all(1,$kind),fn($row)=>$row['name']===$name));
    qaExpect(count($saved)===1,'HTTP catalog creation failed.');
    $request('/admin/catalog',['_token'=>$token($request('/admin/catalog')),'kind'=>$kind,'id'=>$saved[0]['id'],'name'=>$name.' edited','detail'=>'HTTP fixture','active'=>'0']);
    $saved=array_values(array_filter($catalog->all(1,$kind),fn($row)=>(int)$row['id']===(int)$saved[0]['id']));
    qaExpect($saved[0]['name']===$name.' edited' && (int)$saved[0]['is_active']===0,'HTTP catalog edit/deactivate not persistent.');
}
$page=$request('/admin/reviews'); $request('/admin/reviews/'.$reviewId.'/moderate',['_token'=>$token($page),'status'=>'hidden']);
// Reapprove the photo edit before asserting public review moderation.
(new AdminRepository())->moderate(1,1,'approved','Quality image edit reapproved');
qaExpect(!$listings->findPublic(1)['reviews'],'HTTP moderation not persistent.');
$reviews->moderate($reviewId,1,'visible');
$page=$request('/admin/reports'); $latest=(int)$reports->forUser(3)[0]['id'];
$request('/admin/reports/'.$latest.'/resolve',['_token'=>$token($page),'status'=>'resolved','resolution'=>'HTTP verified resolution.']);
qaExpect($reports->forUser(3)[0]['status']==='resolved','HTTP report closure failed.');
$auditCounts=qaCall('CALL sp_test_seed_counts()')[0];
qaExpect((int)$auditCounts['catalog_audits']>=8 && (int)$auditCounts['report_audits']>=3
    && (int)$auditCounts['review_audits']>=3 && (int)$auditCounts['admin_audits']>0,'Admin HTTP audit logs not persistent.');
$logout();
echo "PASS QA real HTTP Guest/Host/Admin details/calendar/preview/filters/inbox/reports/reply/moderation with database persistence and denial/404/422 paths\n";

$passwordUser=new User(); $email='quality-password-'.bin2hex(random_bytes(6)).'@home2home.test';
$oldPassword='QaOld!'.bin2hex(random_bytes(10)); $newPassword='QaNew!'.bin2hex(random_bytes(10));
$passwordId=$passwordUser->create($email,$oldPassword,'Quality password test',null);
qaReject(fn()=>$passwordUser->changePassword($passwordId,$email,'wrong-current',$newPassword),'Wrong current password accepted.');
qaReject(fn()=>$passwordUser->changePassword($passwordId,$email,$oldPassword,'tiny'),'Short new password accepted.');
$page=$request('/login'); $request('/login',['_token'=>$token($page),'email'=>$email,'password'=>$oldPassword]);
qaExpect($request('/profile')['status']===200,'Password fixture login failed.');
$beforeInvalid=$passwordUser->findWithRoles($passwordId);
$page=$request('/profile');
$invalidProfile=$request('/profile',['_token'=>$token($page),'full_name'=>str_repeat('Q',151),'phone'=>'0900000098']);
qaExpect(str_contains($invalidProfile['body'],'0900000098') && str_contains($invalidProfile['body'],str_repeat('Q',151)),'Invalid profile erased editable input.');
qaExpect($passwordUser->findWithRoles($passwordId)['full_name']===$beforeInvalid['full_name'],'Invalid profile changed saved data.');
// Actual multipart avatar upload for an owned fixture user; never overwrite a team image.
$avatarPath=null;
try {
    $page=$request('/profile');
    curl_setopt_array($curl,[CURLOPT_URL=>$baseUrl.'/profile',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>[
        '_token'=>$token($page),'full_name'=>'Quality avatar profile','phone'=>'0900000099',
        'avatar'=>new CURLFile(dirname(__DIR__).'/public/assets/images/demo/1600585154340-be6161a56a0c.jpg','image/jpeg','avatar.jpg')]]);
    $uploaded=curl_exec($curl);
    $profile=$passwordUser->findWithRoles($passwordId); $avatarPath=$profile['avatar_url'];
    qaExpect(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===200 && $profile['full_name']==='Quality avatar profile'
        && $profile['phone']==='0900000099' && preg_match('~^/uploads/avatar-'.$passwordId.'-[a-f0-9]{24}\.jpg$~D',(string)$avatarPath),'HTTP multipart avatar/name/phone persistence failed.');
    qaExpect($request($avatarPath)['status']===200 && str_contains($uploaded,$avatarPath),'Saved avatar not accessible/rendered.');
    $page=$request('/profile'); $request('/profile',['_token'=>$token($page),'full_name'=>'Quality avatar retained','phone'=>'0900000099']);
    qaExpect($passwordUser->findWithRoles($passwordId)['avatar_url']===$avatarPath,'Profile without upload lost avatar.');
} finally {
    if (is_string($avatarPath) && preg_match('~^/uploads/avatar-'.$passwordId.'-[a-f0-9]{24}\.jpg$~D',$avatarPath)) {
        $uploadRoot=realpath(dirname(__DIR__).'/public/uploads'); $target=realpath(dirname(__DIR__).'/public'.$avatarPath);
        if ($target!==false && $uploadRoot!==false && is_file($target)
            && str_starts_with(strtolower(str_replace('\\','/',$target)),strtolower(str_replace('\\','/',$uploadRoot)).'/')) {
            qaExpect(unlink($target),'Could not remove the exact owned fixture avatar.');
        }
    }
}
echo "PASS QA real HTTP multipart avatar/name/phone, rendered image and preserve-on-no-upload; exact fixture file cleaned\n";
$stale=curl_init(); curl_setopt_array($stale,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]);
$staleRequest=static function(string $path,?array $data=null) use($stale,$baseUrl): array {
    curl_setopt($stale,CURLOPT_URL,$baseUrl.$path); curl_setopt($stale,CURLOPT_POST,$data!==null);
    if ($data!==null) { curl_setopt($stale,CURLOPT_POSTFIELDS,http_build_query($data)); } else { curl_setopt($stale,CURLOPT_HTTPGET,true); }
    $body=curl_exec($stale); return ['status'=>curl_getinfo($stale,CURLINFO_RESPONSE_CODE),'body'=>$body,'url'=>curl_getinfo($stale,CURLINFO_EFFECTIVE_URL)];
};
$page=$staleRequest('/login'); $staleRequest('/login',['_token'=>$token($page),'email'=>$email,'password'=>$oldPassword]);
qaExpect(str_contains($staleRequest('/profile')['url'],'/profile'),'Second fixture session unavailable.');
$page=$request('/profile');
$changed=$request('/profile/password',['_token'=>$token($page),'current_password'=>$oldPassword,'new_password'=>$newPassword,'password_confirmation'=>$newPassword]);
qaExpect(str_contains($changed['url'],'/login') && password_verify($newPassword,$passwordUser->findByEmail($email)['password_hash']),'HTTP password update/hash/logout failed.');
qaExpect(str_contains($changed['body'],'alert-success') && str_contains($changed['body'],'Đã đổi mật khẩu'),'Password-change confirmation invisible on login page.');
qaExpect(str_contains($staleRequest('/profile')['url'],'/login'),'Old second session survived password change.');
$page=$request('/login'); $invalid=$request('/login',['_token'=>$token($page),'email'=>$email,'password'=>$oldPassword]);
qaExpect(str_contains($invalid['url'],'/login'),'Old password still accepted.');
$page=$request('/login'); $valid=$request('/login',['_token'=>$token($page),'email'=>$email,'password'=>$newPassword]);
qaExpect(!str_contains($valid['url'],'/login'),'New password rejected.');
$logout(); $passwordUser->softDelete($passwordId); curl_close($stale);
echo "PASS QA real HTTP password change/current-password validation/hash/old-password rejection and invalidation of second session\n";
$recovery=new \App\Models\PasswordReset();
$recoveryId=$passwordUser->create('quality-recovery-'.bin2hex(random_bytes(6)).'@home2home.test',$oldPassword,'Quality recovery fixture',null);
$rawToken=bin2hex(random_bytes(32)); $recovery->issue($recoveryId,$rawToken);
qaReject(fn()=>$recovery->issue($recoveryId,bin2hex(random_bytes(32))),'Recovery issuance rate limit failed.');
qaReject(fn()=>$recovery->consume(bin2hex(random_bytes(32)),$newPassword),'Unknown recovery token accepted.');
qaExpect($request('/reset-password?token=invalid')['status']===422,'Malformed reset link did not return 422.');
$resetPage=$request('/reset-password?token='.$rawToken);
qaExpect($resetPage['status']===200,'Valid shaped reset form unavailable.');
$resetResult=$request('/reset-password',['_token'=>$token($resetPage),'token'=>$rawToken,'password'=>$newPassword,'password_confirmation'=>$newPassword]);
qaExpect(str_contains($resetResult['url'],'/login'),'Real HTTP token consumption failed.');
qaExpect(str_contains($resetResult['body'],'alert-success') && str_contains($resetResult['body'],'Đã đặt mật khẩu mới'),'Reset confirmation invisible on login page.');
qaReject(fn()=>$recovery->consume($rawToken,$newPassword),'Used reset token accepted twice.');
$expiredId=$passwordUser->create('quality-expiry-'.bin2hex(random_bytes(6)).'@home2home.test',$oldPassword,'Quality expiry fixture',null);
$expiredToken=bin2hex(random_bytes(32)); $recovery->issue($expiredId,$expiredToken);
qaCall('CALL sp_test_expire_reset_token(?)',[hash('sha256',$expiredToken)]);
qaReject(fn()=>$recovery->consume($expiredToken,$newPassword),'Expired reset token accepted.');
$unconfigured=$request('/forgot-password');
qaExpect($unconfigured['status']===200 && str_contains($unconfigured['body'],'disabled') && !str_contains($unconfigured['body'],$rawToken),'Unconfigured mail flow exposed a token or falsely enabled delivery.');
echo "PASS QA recovery hashed token/one-use/expiry/issuance throttle/HTTP consume; mail delivery explicitly BLOCKED, not simulated\n";

// More than one UI page, with real approval and filter-preserving navigation.
$paginationFixtures=[]; $paginationData=$listings->findOwned(1,2); $paginationData['city']='QualityPagination';
try {
    for ($i=0;$i<13;$i++) {
        $paginationData['title']='Quality pagination '.str_pad((string)$i,2,'0',STR_PAD_LEFT);
        $id=$listings->create(2,$paginationData); $paginationFixtures[]=$id;
        $listings->setAmenities($id,[1,2],2); (new AdminRepository())->moderate($id,1,'approved','Owned pagination fixture');
    }
    $filter=['location'=>'QualityPagination','sort'=>'price_asc','amenities'=>[1,2],'min_price'=>1000000];
    $pageOne=$request('/?'.http_build_query($filter)); $pageTwo=$request('/?'.http_build_query($filter+['page'=>2]));
    qaExpect($pageOne['status']===200 && substr_count($pageOne['body'],'class="listing-card"')===12,'First HTTP page did not contain 12 results.');
    qaExpect($pageTwo['status']===200 && substr_count($pageTwo['body'],'class="listing-card"')===1 && str_contains($pageTwo['body'],'Trang 2/2'),'Second HTTP page/count wrong.');
    qaExpect((bool)preg_match('~class="page-link"[^>]*href="([^"]*page=2(?:#listing-results)?)"~',$pageOne['body'],$link),'Page-two navigation missing.');
    parse_str((string)parse_url(html_entity_decode($link[1],ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$navigationFilters);
    qaExpect($navigationFilters['location']===$filter['location'] && $navigationFilters['sort']==='price_asc'
        && $navigationFilters['amenities']===['1','2'] && $navigationFilters['min_price']==='1000000','Pagination lost selected filters.');
} finally { foreach ($paginationFixtures as $id) { $listings->softDelete($id,2); } }
echo "PASS QA 13 approved fixtures: 12+1 HTTP results, page totals and navigation preserves location/sort/price/all amenities\n";
curl_close($curl);
