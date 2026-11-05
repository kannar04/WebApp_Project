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
/** One new browser session: optionally visits a page, opens a sign-in form with a Referer, submits it and returns the path it redirects to. */
function qaSignInRedirect(string $baseUrl,string $visit,string $form,string $referer,array $fields,string $action='/login'): string
{
    $curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]);
    if ($visit!=='') { curl_setopt($curl,CURLOPT_URL,$baseUrl.$visit); curl_exec($curl); }
    curl_setopt_array($curl,[CURLOPT_URL=>$baseUrl.$form,CURLOPT_REFERER=>$referer===''?'':$baseUrl.$referer]);
    qaExpect((bool)preg_match('/name="_token" value="([^"]+)"/',(string)curl_exec($curl),$match),'Sign-in form missing: '.$form);
    curl_setopt_array($curl,[CURLOPT_URL=>$baseUrl.$action,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields+['_token'=>$match[1]])]);
    curl_exec($curl); $location=(string)curl_getinfo($curl,CURLINFO_REDIRECT_URL); curl_close($curl);
    return (string)parse_url($location,PHP_URL_PATH);
}
function qaIssueRefused(\App\Models\PasswordReset $recovery,int $userId): bool
{
    try { $recovery->issue($userId,bin2hex(random_bytes(32))); } catch (DomainException $exception) { return true; }
    return false;
}
function qaRejectCall(callable $call,array $arguments,string $message): void
{
    try { $call(...$arguments); } catch (DomainException $exception) { return; }
    throw new RuntimeException($message);
}
function qaSetCatalogActive(Catalog $catalog,string $kind,int $id,bool $active): void
{
    foreach ($catalog->all(1,$kind) as $row) {
        if ((int)$row['id']===$id) { $catalog->save(1,$kind,$id,$row['name'],(string)$row['detail'],$active); return; }
    }
    throw new RuntimeException('Catalog fixture not found.');
}
function qaSortedAmenities(Listing $listings,int $listingId): array
{
    $ids=$listings->amenityIds($listingId); sort($ids); return $ids;
}
function qaRenderView(string $qaView,array $qaData): string
{
    extract($qaData,EXTR_SKIP); ob_start();
    require dirname(__DIR__).'/app/Views/'.$qaView.'.php';
    return (string)ob_get_clean();
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
$byNumber=$bookings->adminSearch(1,'#'.$bookingId,'');
qaExpect(count($byNumber)===1 && (int)$byNumber[0]['id']===$bookingId,'Admin booking search by #id is not exactly that booking.');
qaExpect($bookings->adminSearch(1,'#'.$bookingId,'pending')===[] && $bookings->adminSearch(1,'#999999999','')===[] && $bookings->adminSearch(1,'#abc','')===[],'Admin booking search by #id ignored the status, matched an unknown id or read #abc as a number.');
qaExpect(in_array($bookingId,array_map('intval',array_column($bookings->adminSearch(1,(string)$bookingId,''),'id')),true),'Admin booking search by a bare id lost the booking.');
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
$reviewPage=$reviews->adminPage(1,20,0);
qaExpect(count($reviewPage['reviews'])===1 && $reviewPage['total']===1,'Admin review listing failed.');
$reviewPage=$reviews->adminPage(1,1,1);
qaExpect($reviewPage['reviews']===[] && $reviewPage['total']===1,'Admin review paging lost the total.');
qaRejectCall([$reviews,'adminPage'],[3,20,0],'Guest listed Admin reviews.');
foreach ([[0,0],[101,0],[20,-1]] as [$badLimit,$badOffset]) { qaRejectCall([$reviews,'adminPage'],[1,$badLimit,$badOffset],'Invalid review paging accepted.'); }
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
$reportPage=$reports->adminPage(1,20,0);
qaExpect(count($reportPage['reports'])===1 && $reportPage['total']===1 && !$reports->forUser(2),'Report scope wrong.');
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
        // A refund exists only once money was collected.
        $policyPayments=new \App\Models\Payment(); $policyAttempt=$policyPayments->start($policyBooking,3,'vnpay');
        $policyPayments->vnpayResult($policyAttempt['reference'],(string)$policyAttempt['amount'],'00','00','QAPOLICY'.$policyId,'NCB');
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
$arrayLogin=$request('/login?next%5B%5D=1'); qaExpect($arrayLogin['status']===200 && str_contains($arrayLogin['body'],'name="email"'),'Array next broke the login page.');
$guestSignIn=['email'=>'guest@home2home.test','password'=>'Password123!'];
foreach ([['/bookings','/login','/listings/1','/bookings'],['/bookings','/login?next=/wishlist','/listings/1','/wishlist'],['','/login','/listings/1','/listings/1'],['','/login','','/'],['','/login','/register','/'],['','/login?next=/register','','/'],['','/login?next=/%5Cevil.example','','/'],['','/login?next=/%0d%0a','','/'],['','/login?next=/%0d%0aX','','/'],['','/login','/\evil.example','/'],['','/login?next=/bookings','','/bookings']] as [$visit,$form,$referer,$landing]) {
    qaExpect(qaSignInRedirect($baseUrl,$visit,$form,$referer,$guestSignIn)===$landing,'Sign-in return target wrong: '.$visit.' '.$form.' '.$referer);
}
$returnEmail='audit-'.bin2hex(random_bytes(6)).'@home2home.test';
qaExpect(qaSignInRedirect($baseUrl,'/bookings','/register','',['full_name'=>'Return audit','email'=>$returnEmail,'password'=>bin2hex(random_bytes(8))],'/register')==='/bookings','Register lost the protected page target.');
qaCall('CALL sp_test_cleanup_user(?)',[(int)(new User())->findByEmail($returnEmail)['id']]);
qaExpect(str_contains($request('/login?next=/bookings')['body'],'/register?next=%2Fbookings'),'Login page link to register lost next.');
qaExpect(qaSignInRedirect($baseUrl,'','/login','/\evil.example',['_token'=>'stale']+$guestSignIn)==='/','Stale form redirect followed an unsafe Referer.');
echo "PASS HTTP sign-in return target: protected page, explicit next, Referer, auth pages, register, unsafe targets\n";
$deletedUsers=new User(); $deletedEmail='audit-'.bin2hex(random_bytes(6)).'@home2home.test'; $deletedPassword=bin2hex(random_bytes(8));
$deletedId=$deletedUsers->create($deletedEmail,$deletedPassword,'Deleted audit',null); $deletedUsers->softDelete($deletedId);
$refused=$request('/register',['_token'=>$token($request('/register')),'full_name'=>'Second owner','email'=>$deletedEmail,'password'=>$deletedPassword]);
qaExpect(str_contains($refused['body'],'Email này thuộc về một tài khoản đã bị xóa'),'Register did not explain the deleted account.');
$refusedLogin=$request('/login',['_token'=>$token($request('/login')),'email'=>$deletedEmail,'password'=>$deletedPassword]);
qaExpect(str_contains($refusedLogin['body'],'name="password"'),'A deleted account signed in.');
$login('admin');
$deletedRow=$request('/admin?q='.urlencode($deletedEmail));
qaExpect(str_contains($deletedRow['body'],'value="restore"') && !str_contains($deletedRow['body'],'/admin/users/'.$deletedId.'/edit') && !str_contains($deletedRow['body'],'/admin/users/'.$deletedId.'/delete'),'Admin row of a deleted account must offer only restore.');
qaExpect($request('/admin/users/'.$deletedId.'/edit')['status']===404,'A deleted account could be edited.');
$request('/admin/users/'.$deletedId.'/status',['_token'=>$token($deletedRow),'status'=>'active']);
qaExpect($request('/admin/users/'.$deletedId.'/edit')['status']===404,'The unlock action restored a deleted account.');
$restoredPage=$request('/admin/users/'.$deletedId.'/status',['_token'=>$token($deletedRow),'status'=>'restore']);
qaExpect(str_contains($restoredPage['body'],'Đã khôi phục tài khoản.') && $request('/admin/users/'.$deletedId.'/edit')['status']===200,'Admin could not restore the account.');
$logout();
$restoredLogin=$request('/login',['_token'=>$token($request('/login')),'email'=>$deletedEmail,'password'=>$deletedPassword]);
qaExpect(!str_contains($restoredLogin['body'],'name="password"'),'The restored account could not sign in.');
$logout(); qaCall('CALL sp_test_cleanup_user(?)',[$deletedId]);
echo "PASS HTTP deleted account: register explains, no sign-in, Admin restore, sign-in again\n";
$login('guest');
foreach(['/listings/1?check_in%5B%5D=1','/articles?category%5B%5D=1'] as $path) { qaExpect($request($path)['status']===200,'Array parameter did not read as absent: '.$path); }
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
$page=$request('/reports?listing=1');
$again=$request('/reports',['_token'=>$token($page),'listing_id'=>1,'category'=>'other','description'=>'HTTP end-to-end quality report.']);
qaExpect($again['status']===200 && str_contains($again['body'],'Admin chưa xử lý xong') && count($reports->forUser(3))===2,'Double report saved a second open report or gave no friendly result.');
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
qaExpect(!str_contains($request('/admin?booking_q=%23'.$bookingId)['body'],'Không có booking khớp bộ lọc.') && str_contains($request('/admin?booking_q=%23999999999')['body'],'Không có booking khớp bộ lọc.'),'Admin booking search over HTTP does not read #id.');
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
// More reports than one page holds: every row is reachable, and the Admin stays on the page after acting on a report.
$reportTotal=$reports->adminPage(1,1,0)['total'];
for ($row=1; $row<=21; $row++) {
    $reports->create(3,2,null,'other','Quality paging fixture '.$row);
    $reports->resolve((int)$reports->forUser(3)[0]['id'],1,'resolved','Quality paging fixture closed');
}
$reportTotal+=21; $reportPages=(int)ceil($reportTotal/20); $seenReports=[];
for ($pageNumber=1; $pageNumber<=$reportPages; $pageNumber++) {
    $listed=$request('/admin/reports?page='.$pageNumber);
    qaExpect(str_contains($listed['body'],'Trang '.$pageNumber.'/'.$reportPages),'Report page summary wrong.');
    preg_match_all('/<h2 class="h5">#(\d+)/',$listed['body'],$matches);
    $seenReports=array_merge($seenReports,$matches[1]);
}
qaExpect(count($seenReports)===$reportTotal && count(array_unique($seenReports))===$reportTotal,'Report pages skipped or repeated rows.');
$listed=$request('/admin/reports');
qaExpect(str_contains($listed['body'],'/admin/reports?page=2') && str_contains($listed['body'],'pagination'),'Report list has no page links.');
qaExpect(str_contains($request('/admin/reports?page=9999')['body'],'Trang '.$reportPages.'/'.$reportPages),'Page past the end was not clamped.');
$listed=$request('/admin/reports?page=2');
qaExpect(preg_match('/\/admin\/reports\/(\d+)\/resolve/',$listed['body'],$matches)===1 && str_contains($listed['body'],'name="page" value="2"'),'Report form lost its page.');
$kept=$request('/admin/reports/'.$matches[1].'/resolve',['_token'=>$token($listed),'status'=>'resolved','resolution'=>'Quality paging fixture closed','page'=>'2']);
qaExpect(str_contains($kept['body'],'Trang 2/'.$reportPages),'Resolving a report lost the page.');
$auditCounts=qaCall('CALL sp_test_seed_counts()')[0];
qaExpect((int)$auditCounts['catalog_audits']>=8 && (int)$auditCounts['report_audits']>=3
    && (int)$auditCounts['review_audits']>=3 && (int)$auditCounts['admin_audits']>0,'Admin HTTP audit logs not persistent.');
$logout();
echo "PASS QA real HTTP Guest/Host/Admin details/calendar/preview/filters/inbox/reports/reply/moderation with database persistence and denial/404/422 paths\n";

// A retired catalog item stays on the listing that uses it: marked in the form, kept on save, refused for anything else.
qaSetCatalogActive($catalog,'type',2,false); qaSetCatalogActive($catalog,'amenity',6,false); qaSetCatalogActive($catalog,'amenity',5,false);
$login('host');
$page=$request('/host/listings/3/edit');
qaExpect(preg_match('/<option value="2" selected>[^<]*\(không còn cung cấp\)<\/option>/u',$page['body'])===1,'Edit form lost the current retired type.');
qaExpect(preg_match('/value="6" checked> [^<]*\(không còn cung cấp\)<\/label>/u',$page['body'])===1,'Edit form lost the current retired amenity.');
qaExpect(!str_contains($page['body'],'name="amenities[]" value="5"'),'Edit form offered a retired amenity the listing does not have.');
qaExpect(!str_contains($request('/host/listings/create')['body'],'không còn cung cấp'),'New listing form offered retired items.');
$retiredPost=$listings->findOwned(3,2); $retiredPost=array_intersect_key($retiredPost,array_flip(['cancellation_policy_id','title','address','city','description','nightly_price','cleaning_fee','max_guests','room_count','bedroom_count','bed_count','check_in_time','check_out_time']));
$retiredPost['check_in_time']=substr($retiredPost['check_in_time'],0,5); $retiredPost['check_out_time']=substr($retiredPost['check_out_time'],0,5);
$request('/host/listings/3',['_token'=>$token($page),'property_type_id'=>2,'amenities'=>[1,3,6]]+$retiredPost);
qaExpect((int)$listings->findOwned(3,2)['property_type_id']===2 && qaSortedAmenities($listings,3)===[1,3,6],'Saving the form changed the retired type or amenities.');
$request('/host/listings/3',['_token'=>$token($request('/host/listings/3/edit')),'property_type_id'=>2,'amenities'=>[1,5,6]]+$retiredPost);
qaExpect(qaSortedAmenities($listings,3)===[1,3,6],'Tampered form added another retired amenity.');
$listingsBefore=count($listings->hostListings(2));
$request('/host/listings',['_token'=>$token($request('/host/listings/create')),'property_type_id'=>2]+$retiredPost);
$request('/host/listings',['_token'=>$token($request('/host/listings/create')),'property_type_id'=>1,'amenities'=>[6]]+$retiredPost);
qaExpect(count($listings->hostListings(2))===$listingsBefore,'New listing accepted a retired type or amenity.');
qaSetCatalogActive($catalog,'type',2,true); qaSetCatalogActive($catalog,'amenity',6,true); qaSetCatalogActive($catalog,'amenity',5,true);
(new AdminRepository())->moderate(3,1,'approved','Quality retired catalog fixture');
$logout();
echo "PASS QA retired type/amenity marked on their own listing, kept on save, refused for other and new listings\n";

// Upcoming trips: nothing finished, cancelled or rejected, and soonest first whatever order they were booked in.
$tripStart=(new DateTimeImmutable('today'))->modify('+700 days');
$farTrip=$service->create(2,3,$tripStart->modify('+30 days')->format('Y-m-d'),$tripStart->modify('+32 days')->format('Y-m-d'),1);
$nearTrip=$service->create(2,3,$tripStart->format('Y-m-d'),$tripStart->modify('+2 days')->format('Y-m-d'),1);
$cancelledTrip=$service->create(2,3,$tripStart->modify('+10 days')->format('Y-m-d'),$tripStart->modify('+12 days')->format('Y-m-d'),1);
$service->cancelByGuest($cancelledTrip,3,'Quality upcoming trips fixture');
$finishedTrip=(int)qaCall('CALL sp_test_historical_booking(?,?)',[2,3])[0]['booking_id'];
qaCall('CALL sp_test_historical_confirmed(?)',[$finishedTrip]);
$upcoming=$bookings->upcomingForGuest(3);
$upcomingIds=array_map('intval',array_column($upcoming,'id'));
$checkIns=array_column($upcoming,'check_in'); $sortedCheckIns=$checkIns; sort($sortedCheckIns);
qaExpect($checkIns===$sortedCheckIns,'Upcoming trips are not soonest first.');
qaExpect(array_search($nearTrip,$upcomingIds,true)<array_search($farTrip,$upcomingIds,true),'Later booking of a nearer trip was not listed first.');
qaExpect(!in_array($cancelledTrip,$upcomingIds,true) && !in_array($finishedTrip,$upcomingIds,true),'Upcoming trips include a cancelled or finished stay.');
foreach ($upcoming as $trip) { qaExpect(in_array($trip['status'],['pending','confirmed'],true) && $trip['check_out']>=date('Y-m-d'),'Upcoming trips include a closed stay.'); }
$login('guest');
// Invalid UTF-8 in a review comment reads as an absent comment: refused, and never stored with '?'.
$request('/bookings/'.$finishedTrip.'/review',['_token'=>$token($request('/profile')),'rating'=>'5','comment'=>"Quality\xffreview"]);
qaExpect(!in_array('Quality?review',array_column($reviews->forHost(2),'comment'),true),'Review comment with invalid UTF-8 was stored with a question mark.');
$profile=$request('/profile')['body'];
qaExpect(preg_match_all('/<div class="trip-row">/',$profile)===min(2,count($upcoming)),'Profile does not show the soonest trips.');
qaExpect(str_contains($profile,display_date($upcoming[0]['check_in']).' → '.display_date($upcoming[0]['check_out'])),'Profile does not start with the soonest trip.');
$logout();
$tripGuest=(new User())->create('quality-trips-'.bin2hex(random_bytes(6)).'@home2home.test','QaTrips!'.bin2hex(random_bytes(10)),'Quality trips test',null);
qaExpect($bookings->upcomingForGuest($tripGuest)===[],'New guest has upcoming trips.');
foreach ([$farTrip,$nearTrip] as $tripId) { $service->cancelByGuest($tripId,3,'Quality upcoming trips cleanup'); }
echo "PASS QA upcoming trips exclude finished and cancelled stays and list the soonest first\n";

// A refused booking request keeps what the Guest typed, escaped, on that listing and for that one page load.
$login('guest');
$refused=$request('/listings/1/book',['_token'=>$token($request('/listings/1')),'check_in'=>'2031-04-10','check_out'=>'2031-04-12','guests'=>'2','promo_code'=>'"><b>NOPE']);
qaExpect((string)parse_url($refused['url'],PHP_URL_PATH)==='/listings/1' && str_contains($refused['body'],'alert-danger'),'Refused booking did not return to the listing with the error.');
foreach (['name="check_in" min="'.date('Y-m-d').'" value="2031-04-10"','value="2031-04-12"','name="guests" min="1" max="','value="2" required','value="&quot;&gt;&lt;b&gt;NOPE"'] as $kept) { qaExpect(str_contains($refused['body'],$kept),'Refused booking lost input: '.$kept); }
qaExpect(!str_contains($refused['body'],'"><b>NOPE'),'Refused promo code printed unescaped.');
qaExpect(!str_contains($request('/listings/1')['body'],'value="2031-04-10"'),'Old booking input survived the next page load.');
$request('/listings/1/book',['_token'=>$token($request('/listings/1')),'check_in'=>'"><script>','check_out'=>'2031-04-12','guests'=>'abc','promo_code'=>"\xFF"]);
$other=$request('/listings/2')['body'];
qaExpect(!str_contains($other,'value="2031-04-12"') && str_contains($other,'name="guests" min="1"'),'Booking input followed the Guest to another listing.');
$malformed=$request('/listings/1/book',['_token'=>$token($request('/listings/1')),'check_in'=>'"><script>','check_out'=>'2031-04-12','guests'=>'abc','promo_code'=>"\xFF"])['body'];
qaExpect(!str_contains($malformed,'"><script>') && str_contains($malformed,'value="2031-04-12"') && str_contains($malformed,'value="1" required'),'Malformed booking input was kept.');
$logout();
echo "PASS QA refused booking keeps dates, guests and promo code, escaped, once and on that listing only\n";

// Refused admin forms keep what was typed, never a password, and only for one page load.
$login('admin');
$kept=$request('/admin/users',['_token'=>$token($request('/admin')),'full_name'=>'Giu <b>ten','email'=>'guest@home2home.test','phone'=>'0912000111','password'=>'KhongLuu!12345','roles'=>['guest']]);
qaExpect(str_contains($kept['body'],'alert-danger'),'Taken email was not refused.');
foreach (['name="full_name" value="Giu &lt;b&gt;ten"','name="email" value="guest@home2home.test"','name="phone" value="0912000111"'] as $field) { qaExpect(str_contains($kept['body'],$field),'Admin create lost input: '.$field); }
qaExpect(!str_contains($kept['body'],'KhongLuu!12345') && !str_contains($kept['body'],'Giu <b>ten'),'Admin create printed the password or unescaped input.');
qaExpect(str_contains($request('/admin')['body'],'name="full_name" value=""'),'Admin create input survived the next page load.');
$invalid=$request('/admin/users',['_token'=>$token($request('/admin')),'full_name'=>'   ','email'=>'giu-lai@home2home.test','phone'=>'0912000222','password'=>'KhongLuu!12345','roles'=>['guest']])['body'];
qaExpect(str_contains($invalid,'name="email" value="giu-lai@home2home.test"') && str_contains($invalid,'name="phone" value="0912000222"') && !str_contains($invalid,'KhongLuu!12345'),'Invalid admin create lost input.');
$createdEmail='quality-kept-'.bin2hex(random_bytes(6)).'@home2home.test';
$created=$request('/admin/users',['_token'=>$token($request('/admin')),'full_name'=>'Quality kept','email'=>$createdEmail,'phone'=>'09'.random_int(10000000,99999999),'password'=>'KhongLuu!12345','roles'=>['guest']])['body'];
qaExpect(str_contains($created,'alert-success') && !str_contains($created,'name="email" value="'.$createdEmail.'"'),'Successful admin create kept the form input.');
$catalogPage=$request('/admin/catalog');
qaExpect((bool)preg_match('/id="catalog-name-type-([1-9]\d*)" name="name" value="([^"]*)"/',$catalogPage['body'],$existingType),'No catalog item to duplicate.');
$takenName=html_entity_decode($existingType[2],ENT_QUOTES);
$catalogKept=$request('/admin/catalog',['_token'=>$token($catalogPage),'kind'=>'type','id'=>'0','name'=>$takenName,'detail'=>'Mo ta <i>giu lai','active'=>'1'])['body'];
qaExpect(str_contains($catalogKept,'alert-danger') && str_contains($catalogKept,'id="catalog-name-type-0" name="name" value="'.$existingType[2].'"') && str_contains($catalogKept,'id="catalog-detail-type-0" name="detail" value="Mo ta &lt;i&gt;giu lai"'),'Refused new catalog item lost input.');
qaExpect(str_contains($catalogKept,'id="catalog-name-amenity-0" name="name" value=""') && str_contains($catalogKept,'id="catalog-detail-amenity-0" name="detail" value=""'),'Catalog input leaked into the other kind.');
qaExpect(str_contains($request('/admin/catalog')['body'],'id="catalog-name-type-0" name="name" value=""'),'Catalog input survived the next page load.');
$tooLong=$request('/admin/catalog',['_token'=>$token($catalogPage),'kind'=>'amenity','id'=>'0','name'=>str_repeat('x',81),'detail'=>'icon-giu','active'=>'1'])['body'];
qaExpect(str_contains($tooLong,'id="catalog-name-amenity-0" name="name" value="'.str_repeat('x',80).'"') && str_contains($tooLong,'id="catalog-detail-amenity-0" name="detail" value="icon-giu"'),'Invalid catalog item lost input.');
$logout();
$login('host');
$monthPage=$request('/host/listings/1/availability?month=2031-02');
qaExpect(str_contains($monthPage['body'],'name="month" value="2031-02"'),'Availability form does not carry the month on screen.');
$blocked=$request('/api/host/listings/1/availability',['_token'=>$token($monthPage),'date'=>'2031-02-10','reason'=>'Quality month','open'=>'0','month'=>'2031-02']);
qaExpect(str_contains($blocked['url'],'/host/listings/1/availability?month=2031-02') && str_contains($blocked['body'],'alert-success') && str_contains($blocked['body'],'data-date="2031-02-10" data-status="blocked"'),'Saved availability did not stay on the month.');
$refusedDay=$request('/api/host/listings/1/availability',['_token'=>$token($monthPage),'date'=>'2031-02-30','reason'=>'Ly do "><b>giu','open'=>'0','month'=>'2031-02']);
qaExpect(str_contains($refusedDay['url'],'?month=2031-02') && str_contains($refusedDay['body'],'alert-danger') && str_contains($refusedDay['body'],'value="2031-02-30"') && str_contains($refusedDay['body'],'value="Ly do &quot;&gt;&lt;b&gt;giu"'),'Refused availability lost the month or the input.');
qaExpect(!str_contains($request('/host/listings/1/availability?month=2031-02')['body'],'value="2031-02-30"'),'Availability input survived the next page load.');
$forgedDay=$request('/api/host/listings/1/availability',['_token'=>$token($monthPage),'date'=>'"><script>','reason'=>'','open'=>'0','month'=>'//evil.example/x']);
qaExpect((string)parse_url($forgedDay['url'],PHP_URL_HOST)===(string)parse_url($baseUrl,PHP_URL_HOST) && (string)parse_url($forgedDay['url'],PHP_URL_QUERY)==='' && !str_contains($forgedDay['body'],'"><script>'),'Forged month or date was kept.');
$request('/api/host/listings/1/availability',['_token'=>$token($monthPage),'date'=>'2031-02-10','reason'=>'','open'=>'1','month'=>'2031-02']);
$logout();
echo "PASS QA refused admin create, catalog and availability forms keep their input, availability stays on its month\n";

// Article actions on the admin list return to the same filtered page; a forged return path gives the plain list.
$login('admin');
$articleMark='qa-return-'.bin2hex(random_bytes(4));
foreach ([1,2] as $number) { $request('/admin/articles',['_token'=>$token($request('/admin/articles/create')),'title'=>'Bai '.$articleMark.' '.$number,'category'=>'guide','slug'=>$articleMark.'-'.$number,'excerpt'=>'Tom tat bai','body'=>'Noi dung du dai cho bai kiem thu tro ve danh sach.','intent'=>'draft']); }
$listPath='/admin/articles?q='.$articleMark.'&category=guide&status=draft&page=1';
$list=$request($listPath);
qaExpect(preg_match_all('#/admin/articles/(\d+)/status"#',$list['body'],$found)===2,'Article list fixtures were not created.');
qaExpect(preg_match_all('#/admin/articles/\d+/(?:status|delete)"><input type="hidden" name="_token" value="[^"]+"><input type="hidden" name="return_path" value="'.preg_quote(e($listPath),'#').'">#',$list['body'])===4,'Article list forms do not carry the current page and filters.');
$articleOne=(int)$found[1][0]; $articleTwo=(int)$found[1][1];
$articleToken=$token($list);
$toggled=$request('/admin/articles/'.$articleOne.'/status',['_token'=>$articleToken,'status'=>'published','return_path'=>$listPath]);
qaExpect($toggled['url']===$baseUrl.$listPath && str_contains($toggled['body'],'alert-success'),'Article status change left the filtered list.');
foreach (['//evil.example/admin/articles','https://evil.example/admin/articles','/admin/users','/admin/articles/../users','/admin/articles/'.$articleOne.'/edit','/\\evil.example/admin/articles',"/admin/articles?x=1\r\nX-Evil: 1",''] as $forged) {
    $sent=$request('/admin/articles/'.$articleOne.'/status',['_token'=>$articleToken,'status'=>'draft','return_path'=>$forged]);
    qaExpect($sent['url']===$baseUrl.'/admin/articles','Article status followed a forged return path: '.$forged);
}
$toEdit=$request('/admin/articles/'.$articleOne.'/status',['_token'=>$articleToken,'status'=>'draft','return'=>'edit','return_path'=>$listPath]);
qaExpect($toEdit['url']===$baseUrl.'/admin/articles/'.$articleOne.'/edit','Status change from the edit page no longer returns to it.');
$pastEnd='/admin/articles?q='.$articleMark.'&page=9';
$deleted=$request('/admin/articles/'.$articleTwo.'/delete',['_token'=>$articleToken,'return_path'=>$pastEnd]);
qaExpect($deleted['url']===$baseUrl.$pastEnd && $deleted['status']===200 && str_contains($deleted['body'],'alert-success') && substr_count($deleted['body'],'/articles/'.$articleMark.'-')===1,'Article delete left the filtered list.');
$deleted=$request('/admin/articles/'.$articleOne.'/delete',['_token'=>$articleToken,'return_path'=>'//evil.example/admin/articles']);
qaExpect($deleted['url']===$baseUrl.'/admin/articles','Article delete followed a forged return path.');
$logout();
echo "PASS QA admin article actions return to the same list page and filters, forged return paths give the plain list\n";

// The admin user search and booking filter each carry the other's current value, escaped.
$login('admin');
$bothFilters=$request('/admin?q=%22%3E%3Cb%3Eguest&booking_q=%22%3E%3Ci%3Eroom&booking_status=pending')['body'];
qaExpect(str_contains($bothFilters,'<form class="form-inline"><input type="hidden" name="booking_q" value="&quot;&gt;&lt;i&gt;room"><input type="hidden" name="booking_status" value="pending"><label'),'User search does not carry the booking filter.');
qaExpect(str_contains($bothFilters,'action="'.e(url('/admin')).'"><input type="hidden" name="q" value="&quot;&gt;&lt;b&gt;guest"><div class="form-group col-md-7">'),'Booking filter does not carry the user search.');
qaExpect(!str_contains($bothFilters,'"><b>guest') && !str_contains($bothFilters,'"><i>room'),'Carried admin filters printed unescaped.');
$noFilters=$request('/admin')['body'];
qaExpect(!str_contains($noFilters,'type="hidden" name="q"') && !str_contains($noFilters,'type="hidden" name="booking_q"') && !str_contains($noFilters,'type="hidden" name="booking_status"'),'Empty admin filters were carried.');
$badStatus=$request('/admin?booking_status=%22x')['body'];
qaExpect(!str_contains($badStatus,'type="hidden" name="booking_status"'),'Rejected booking status was carried.');
$logout();
echo "PASS QA admin user search and booking filter carry each other's value\n";

// The lists cut off by a fixed LIMIT 200 say so at the limit, not below it. Rendered from the real views: the rows would otherwise need 200 bookings in the shared test database.
$limitTime='2030-01-01 10:00:00.000000';
$limitBooking=['id'=>1,'title'=>'T','guest_name'=>'G','host_name'=>'H','check_in'=>'2030-01-01','check_out'=>'2030-01-02','status'=>'cancelled','total_amount'=>'100000.00'];
$limitNotification=['id'=>1,'title'=>'T','body'=>'B','event_type'=>'booking_status','booking_id'=>null,'read_at'=>$limitTime,'created_at'=>$limitTime];
$limitConversation=['booking_id'=>1,'title'=>'T','last_at'=>$limitTime,'my_role'=>'guest','other_name'=>'O','status'=>'pending','last_hidden'=>0,'last_mine'=>0,'last_snippet'=>'S','last_truncated'=>0,'unread_count'=>0];
$limitMessage=['id'=>1,'booking_id'=>1,'title'=>'T','booking_status'=>'pending','guest_name'=>'G','host_name'=>'H','sender_id'=>1,'sender_name'=>'S','sender_role'=>'guest','recipient_name'=>'R','body'=>'B','created_at'=>$limitTime,'read_at'=>null,'hidden_at'=>null,'hidden_reason'=>null,'hidden_by_name'=>null];
$limitStats=['users'=>0,'guests'=>0,'hosts'=>0,'listings'=>0,'bookings'=>0,'revenue'=>0];
foreach ([199=>false,200=>true] as $limitCount=>$limitShown) {
    $bookingsList=qaRenderView('admin/dashboard',['stats'=>$limitStats,'users'=>[],'listings'=>[],'bookings'=>array_fill(0,$limitCount,$limitBooking),'bookingQuery'=>'','bookingStatus'=>'','trustBadges'=>[],'old'=>[]]);
    qaExpect(str_contains($bookingsList,'Hiển thị 200 booking mới nhất (tối đa 200; hãy lọc để thu hẹp).')===$limitShown,'Admin bookings limit notice wrong at '.$limitCount);
    $messagesList=qaRenderView('admin/messages',['thread'=>null,'results'=>array_fill(0,$limitCount,$limitMessage),'filters'=>['q'=>'','booking'=>null,'user'=>'']]);
    qaExpect(str_contains($messagesList,'Hiển thị 200 tin nhắn mới nhất (tối đa 200; hãy lọc để thu hẹp).')===$limitShown,'Admin messages limit notice wrong at '.$limitCount);
    $notificationsList=qaRenderView('notifications/index',['notifications'=>array_fill(0,$limitCount,$limitNotification)]);
    qaExpect(str_contains($notificationsList,'Chỉ hiển thị 200 thông báo mới nhất.')===$limitShown,'Notifications limit notice wrong at '.$limitCount);
    $inboxList=qaRenderView('messages/index',['conversations'=>array_fill(0,$limitCount,$limitConversation)]);
    qaExpect(str_contains($inboxList,'Chỉ hiển thị 200 cuộc trò chuyện mới nhất.')===$limitShown,'Inbox limit notice wrong at '.$limitCount);
}
$threadList=qaRenderView('admin/messages',['thread'=>array_fill(0,200,$limitMessage),'results'=>[],'filters'=>['q'=>'','booking'=>1,'user'=>'']]);
qaExpect(!str_contains($threadList,'tin nhắn mới nhất'),'One booking thread is not a limited search.');
echo "PASS QA admin bookings, admin messages, notifications and inbox say so when the 200 row limit is reached\n";

// The Host sees a note and a manage link on their own listing, everyone else the booking form; the server still refuses the Host's booking.
$login('host');
$ownPage=$request('/listings/1');
qaExpect(!str_contains($ownPage['body'],'data-booking-form') && !str_contains($ownPage['body'],'/listings/1/book'),'Host is offered the booking form on their own listing.');
qaExpect(str_contains($ownPage['body'],'data-own-listing') && str_contains($ownPage['body'],'href="'.e(url('/host/listings/1/edit')).'"'),'Host has no manage link on their own listing.');
qaExpect(str_contains($ownPage['body'],'class="booking-price"') && str_contains($ownPage['body'],'id="availability-calendar"'),'Own listing lost its price or calendar.');
$ownBooking=$request('/listings/1/book',['_token'=>$token($ownPage),'check_in'=>'2031-06-10','check_out'=>'2031-06-12','guests'=>'1','promo_code'=>'']);
qaExpect(str_contains($ownBooking['body'],'alert-danger') && !str_contains($ownBooking['url'],'/bookings/'),'Server accepted a booking on the Host\'s own listing.');
$logout();
foreach (['guest','admin'] as $visitor) {
    $login($visitor);
    $visitorPage=$request('/listings/1')['body'];
    qaExpect(str_contains($visitorPage,'data-booking-form') && !str_contains($visitorPage,'data-own-listing'),'The booking form is gone for '.$visitor);
    $logout();
}
$anonPage=$request('/listings/1')['body'];
qaExpect(str_contains($anonPage,'data-booking-form') && !str_contains($anonPage,'data-own-listing'),'The booking form is gone for a visitor who is not logged in.');
echo "PASS QA Host gets a note and manage link instead of the booking form on their own listing, others keep the form\n";

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
// With mail on, unknown, throttled and deleted accounts all end silently in the issuing routine, and none of them reaches the mail step.
putenv('MAIL_ENABLED=true'); putenv('MAIL_FROM=no-reply@home2home.test'); putenv('PASSWORD_RESET_BASE_URL=http://127.0.0.1');
$recoveryService=new \App\Services\PasswordRecoveryService();
qaExpect($recoveryService->configured() && !preg_match('/\[.(status|deleted_at).\]/',(string)file_get_contents(dirname(__DIR__).'/app/Services/PasswordRecoveryService.php')),'Recovery must leave account state to the issuing routine.');
$recoveryFixtureEmail='quality-silent-'.bin2hex(random_bytes(6)).'@home2home.test';
$recoveryFixture=$passwordUser->create($recoveryFixtureEmail,$oldPassword,'Quality silent fixture',null);
$recovery->issue($recoveryFixture,bin2hex(random_bytes(32)));
$recoveryService->request('quality-nobody-'.bin2hex(random_bytes(6)).'@home2home.test');
$recoveryService->request($recoveryFixtureEmail);
$passwordUser->softDelete($recoveryFixture);
$recoveryService->request($recoveryFixtureEmail);
qaExpect(qaIssueRefused($recovery,0) && qaIssueRefused($recovery,$recoveryFixture),'Issuing routine accepted an unknown or deleted account.');
putenv('MAIL_ENABLED=false'); putenv('MAIL_FROM'); putenv('PASSWORD_RESET_BASE_URL');
echo "PASS QA recovery request takes the same silent path for unknown, throttled and deleted accounts\n";

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
