<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';

use App\Models\Listing;
use App\Models\User;
use App\Models\Booking;
use App\Models\Wishlist;
use App\Models\AdminRepository;
use App\Models\Catalog;
use App\Models\Report;
use App\Models\Notification;
use App\Models\Discovery;
use App\Services\BookingService;
use Core\ProcedureConnection;

function callRows(string $call, array $parameters=[]): array
{
    $statement=ProcedureConnection::connection()->prepare($call);
    $statement->execute($parameters);
    return $statement->fetchAll();
}
function ensure(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function emailIsTaken(User $users,string $email): bool
{
    try { $users->create($email,bin2hex(random_bytes(16)),'Audit duplicate',null); } catch (PDOException $exception) { return $exception->getCode()==='23000'; }
    return false;
}
function restoreRefused(User $users,int $id): bool
{
    try { $users->setStatus($id,'restore'); } catch (DomainException $exception) { return true; }
    return false;
}
function domainFailure(callable $operation): void
{
    try { $operation(); } catch (DomainException $exception) { return; }
    throw new RuntimeException('A routine accepted an invalid business operation.');
}
function refused(callable $call, array $arguments): void
{
    try { $call(...$arguments); } catch (DomainException $exception) { return; }
    throw new RuntimeException('A routine accepted an invalid business operation.');
}
function refusedSaying(callable $call, array $arguments, string $needle): void
{
    try { $call(...$arguments); } catch (DomainException $exception) {
        ensure(str_contains($exception->getMessage(),$needle),'Refused with the wrong message: '.$exception->getMessage());
        return;
    }
    throw new RuntimeException('A routine accepted: '.$needle);
}
function bookingState(int $id): array
{
    return callRows('CALL sp_test_booking_inspect(?)',[$id])[0];
}
function noticesFor(int $userId, ?int $bookingId, string $eventType): array
{
    $found=[];
    foreach ((new Notification())->forUser($userId) as $notice) {
        if ($notice['event_type']===$eventType && ($bookingId===null ? $notice['booking_id']===null : (int)$notice['booking_id']===$bookingId)) { $found[]=$notice; }
    }
    return $found;
}
function noticeLinks(int $userId, int $bookingId): array
{
    $links=[];
    foreach ((new Notification())->forUser($userId) as $notice) {
        if ((int)$notice['booking_id']===$bookingId) { $links[]=(int)$notice['can_open_booking']; }
    }
    return $links;
}
function hostListingRow(int $hostId, int $listingId): array
{
    foreach ((new Listing())->hostListings($hostId) as $row) {
        if ((int)$row['id']===$listingId) { return $row; }
    }
    throw new RuntimeException('The Host lost a listing from the dashboard.');
}
function listingNoticeCount(int $hostId, int $listingId, string $eventType): int
{
    $title=(string)hostListingRow($hostId,$listingId)['title']; $count=0;
    foreach (noticesFor($hostId,null,$eventType) as $notice) {
        if (str_contains((string)$notice['body'],$title)) { $count++; }
    }
    return $count;
}

$app=require dirname(__DIR__).'/config/app.php';
$config=require dirname(__DIR__).'/config/database.php';
ensure(PHP_SAPI==='cli' && $app['env']==='local' && in_array($config['host'],['localhost','127.0.0.1','::1'],true), 'Local test only.');

if (($argv[1]??'')==='--worker') {
    // Distinct processes, distinct PDO sessions, deliberately stale read snapshots.
    $db=ProcedureConnection::connection(); $db->beginTransaction();
    callRows('CALL sp_user_get_by_email(?)',['guest@home2home.test']);
    echo "READY\n"; fflush(STDOUT); fgets(STDIN);
    try {
        $id=(new BookingService())->create((int)$argv[2],(int)$argv[3],$argv[4],$argv[5],1);
        $db->commit(); echo json_encode(['created'=>$id])."\n";
    } catch (DomainException $exception) {
        if ($db->inTransaction()) { $db->rollBack(); }
        echo json_encode(['blocked'=>true])."\n";
    }
    exit;
}

$users=new User(); $host=$users->findByEmail('host@home2home.test'); $guest=$users->findByEmail('guest@home2home.test'); $admin=$users->findByEmail('admin@home2home.test');
ensure($host && $guest && $admin,'Local seed accounts required.');
$model=new Listing(); $listingId=null; $fixtureUser=null; $bookingIds=[]; $processes=[];
$db=ProcedureConnection::connection();
try {
    $data=['property_type_id'=>(int)$model->types()[0]['id'],'cancellation_policy_id'=>(int)$model->policies()[0]['id'],
        'title'=>'Audit fixture '.bin2hex(random_bytes(8)),'address'=>'Test only','city'=>'Audit','description'=>'Procedure test fixture',
        'nightly_price'=>100000,'cleaning_fee'=>20000,'max_guests'=>2,'room_count'=>1,'bedroom_count'=>1,'bed_count'=>1,'check_in_time'=>'16:00','check_out_time'=>'10:00'];
    $listingId=$model->create((int)$host['id'],$data);
    callRows('CALL sp_test_listing_approve(?)',[$listingId]);
    $amenity=(int)$model->allAmenities()[0]['id'];
    $model->setAmenities($listingId,[$amenity],(int)$host['id']);
    ensure((new Wishlist())->toggle((int)$guest['id'],$listingId), 'Could not save a public listing.');
    ensure(!(new Wishlist())->toggle((int)$guest['id'],$listingId), 'Could not remove a saved listing.');
    echo "PASS reused wishlist routines save and remove\n";
    domainFailure(fn()=>callRows('CALL sp_listing_amenities_replace(?,?,?)',[$listingId,(int)$host['id'],'['.$amenity.',999999999]']));
    ensure($model->amenityIds($listingId)===[$amenity],'Failed routine did not roll back amenities.');
    domainFailure(fn()=>callRows('CALL sp_listing_amenities_replace(?,?,?)',[$listingId,(int)$guest['id'],'[]']));
    domainFailure(fn()=>callRows('CALL sp_user_roles_replace(?,?,?)',[(int)$guest['id'],'["superadmin"]',null]));
    ensure($users->findWithRoles((int)$guest['id'])['roles']===['guest'],'Invalid role changed the demo user.');
    echo "PASS routine authorization, JSON validation, error cursor draining and rollback\n";

    // Retiring a catalog item must not change a listing that uses it; everything below rolls back with the outer transaction.
    $db->beginTransaction();
    try {
        $catalog=new Catalog(); $tag=bin2hex(random_bytes(6)); $hostId=(int)$host['id'];
        $usedType=$catalog->save((int)$admin['id'],'type',0,'Audit used '.$tag,'',true);
        $otherType=$catalog->save((int)$admin['id'],'type',0,'Audit other '.$tag,'',false);
        $usedAmenity=$catalog->save((int)$admin['id'],'amenity',0,'Audit used '.$tag,'',true);
        $otherAmenity=$catalog->save((int)$admin['id'],'amenity',0,'Audit other '.$tag,'',false);
        $retiredData=['property_type_id'=>$usedType]+$data;
        $model->update($listingId,$hostId,$retiredData);
        $model->setAmenities($listingId,[$amenity,$usedAmenity],$hostId);
        $catalog->save((int)$admin['id'],'type',$usedType,'Audit used '.$tag,'',false);
        $catalog->save((int)$admin['id'],'amenity',$usedAmenity,'Audit used '.$tag,'',false);

        $typeFlags=array_column($model->catalogChoices('type',$listingId,$hostId),'is_active','id');
        ensure(isset($typeFlags[$usedType]) && (int)$typeFlags[$usedType]===0 && !isset($typeFlags[$otherType]) && (int)$typeFlags[(int)$data['property_type_id']]===1,'Edit form types wrong.');
        $amenityFlags=array_column($model->catalogChoices('amenity',$listingId,$hostId),'is_active','id');
        ensure(isset($amenityFlags[$usedAmenity]) && (int)$amenityFlags[$usedAmenity]===0 && !isset($amenityFlags[$otherAmenity]) && (int)$amenityFlags[$amenity]===1,'Edit form amenities wrong.');
        foreach ([[0,(int)$host['id'],'New listing'],[$listingId,(int)$guest['id'],'Another account']] as [$listing,$actor,$who]) {
            ensure(!isset(array_column($model->catalogChoices('type',$listing,$actor),'name','id')[$usedType]),$who.' was offered a retired type.');
            ensure(!isset(array_column($model->catalogChoices('amenity',$listing,$actor),'name','id')[$usedAmenity]),$who.' was offered a retired amenity.');
        }
        refused([$model,'catalogChoices'],['bogus',$listingId,$hostId]);

        $model->update($listingId,$hostId,$retiredData);
        $model->setAmenities($listingId,[$amenity,$usedAmenity],$hostId);
        $kept=$model->amenityIds($listingId); sort($kept); $wanted=[$amenity,$usedAmenity]; sort($wanted);
        ensure((int)$model->findOwned($listingId,$hostId)['property_type_id']===$usedType && $kept===$wanted,'Saving changed the retired type or amenities.');
        refused([$model,'update'],[$listingId,$hostId,['property_type_id'=>$otherType]+$data]);
        refused([$model,'setAmenities'],[$listingId,[$amenity,$otherAmenity],$hostId]);
        refused([$model,'create'],[$hostId,$retiredData]);
        ensure((int)$model->findOwned($listingId,$hostId)['property_type_id']===$usedType && count($model->amenityIds($listingId))===2,'Rejected retired item changed the listing.');

        $model->setAmenities($listingId,[$amenity],$hostId);
        refused([$model,'setAmenities'],[$listingId,[$amenity,$usedAmenity],$hostId]);
        $model->update($listingId,$hostId,$data);
        refused([$model,'update'],[$listingId,$hostId,$retiredData]);
        ensure($model->amenityIds($listingId)===[$amenity] && (int)$model->findOwned($listingId,$hostId)['property_type_id']===(int)$data['property_type_id'],'Dropped retired item did not stay dropped.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS retired catalog items stay on their listing, are marked, and cannot be newly selected\n";

    // A repeated report adds nothing while the first is open or under investigation on the same target; everything rolls back with the outer transaction.
    $db->beginTransaction();
    try {
        $reports=new Report(); $repeatReporter=(int)$guest['id']; $body='Procedure duplicate report check';
        (new AdminRepository())->moderate($listingId,(int)$admin['id'],'approved','Procedure report fixture');
        $before=count($reports->forUser($repeatReporter));
        ensure($reports->create($repeatReporter,$listingId,null,'other',$body)===true,'First report was not saved.');
        ensure($reports->create($repeatReporter,$listingId,null,'fraud',$body.' again')===false,'Second open report on the same target was saved.');
        ensure(count($reports->forUser($repeatReporter))===$before+1,'Duplicate report changed the list.');
        ensure($reports->create((int)$host['id'],$listingId,null,'other',$body)===true,'Another reporter was blocked by someone else\'s report.');
        refused([$reports,'create'],[$repeatReporter,$listingId,null,'other','short']);
        $open=$reports->forUser($repeatReporter)[0];
        ensure((int)$open['listing_id']===$listingId && $open['status']==='open' && $open['category']==='other','Duplicate report overwrote the first one.');
        $reports->resolve((int)$open['id'],(int)$admin['id'],'investigating','Checking');
        ensure($reports->create($repeatReporter,$listingId,null,'other',$body)===false,'A second report was saved while the Admin is still investigating the first.');
        ensure(count($reports->forUser($repeatReporter))===$before+1,'A report under investigation did not count as the open one.');
        $reports->resolve((int)$reports->forUser($repeatReporter)[0]['id'],(int)$admin['id'],'resolved','Closed');
        ensure($reports->create($repeatReporter,$listingId,null,'other',$body)===true,'A closed report blocked a new one.');
        // The Admin's list knows a listing was taken down, so the card offers no preview link that would answer 404.
        $newest=$reports->adminPage((int)$admin['id'],1,0)['reports'][0];
        ensure((int)$newest['listing_id']===$listingId && $newest['listing_deleted_at']===null,'The Admin report list lost the listing.');
        (new AdminRepository())->softDeleteListing($listingId,(int)$admin['id']);
        ensure($reports->adminPage((int)$admin['id'],1,0)['reports'][0]['listing_deleted_at']!==null,'The Admin report list does not know the listing was taken down.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS repeated report on an open or investigated target saves nothing; other reporters and closed reports are unaffected\n";

    // Admin report paging; the fixtures roll back with the outer transaction.
    $db->beginTransaction();
    try {
        $reports=new Report(); $adminId=(int)$admin['id']; $before=$reports->adminPage($adminId,1,0)['total'];
        (new AdminRepository())->moderate($listingId,$adminId,'approved','Procedure report fixture');
        $created=[];
        for ($row=1; $row<=25; $row++) {
            $reports->create((int)$host['id'],$listingId,null,'other','Procedure paging fixture '.$row);
            $created[]=(int)$reports->forUser((int)$host['id'])[0]['id'];
            $reports->resolve(end($created),$adminId,'resolved','Procedure paging fixture closed');
        }
        $first=$reports->adminPage($adminId,20,0); $second=$reports->adminPage($adminId,20,20);
        ensure(count($first['reports'])===20 && $first['total']===$before+25 && $second['total']===$before+25,'Report page or total wrong.');
        $firstIds=array_map('intval',array_column($first['reports'],'id')); $secondIds=array_map('intval',array_column($second['reports'],'id'));
        ensure(!array_intersect($firstIds,$secondIds),'Report pages overlap.');
        ensure(array_slice(array_reverse($created),0,20)===$firstIds && array_slice(array_reverse($created),20,5)===array_slice($secondIds,0,5),'Report pages are not newest first.');
        refused([$reports,'adminPage'],[(int)$guest['id'],20,0]);
        refused([$reports,'adminPage'],[$adminId,0,0]);
        refused([$reports,'adminPage'],[$adminId,101,0]);
        refused([$reports,'adminPage'],[$adminId,20,-1]);
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS Admin report paging keeps the total, has no overlap, and refuses bad paging or non-Admins\n";

    $start=(new DateTimeImmutable('today'))->modify('+5500 days')->format('Y-m-d');
    $end=(new DateTimeImmutable($start))->modify('+2 days')->format('Y-m-d');
    $db->beginTransaction();
    $id=(new BookingService())->create($listingId,(int)$guest['id'],$start,$end,1);
    ensure($db->inTransaction(),'Procedure committed its caller transaction.');
    $db->rollBack();
    ensure(callRows('CALL sp_test_booking_inspect(?)',[$id])===[],'Caller rollback left a booking behind.');
    echo "PASS routine savepoint preserves outer transaction and full booking rollback\n";

    // Rejecting a listing closes its requests the way a take-down does; everything rolls back with the outer transaction.
    $db->beginTransaction();
    try {
        $service=new BookingService(); $moderation=new AdminRepository(); $adminId=(int)$admin['id']; $hostId=(int)$host['id']; $guestId=(int)$guest['id'];
        $day=new DateTimeImmutable($start);
        $stay=[$day->modify('+10 days')->format('Y-m-d'),$day->modify('+12 days')->format('Y-m-d')];
        $later=[$day->modify('+20 days')->format('Y-m-d'),$day->modify('+22 days')->format('Y-m-d')];

        $closed=$service->create($listingId,$guestId,$start,$end,1);
        $kept=$service->create($listingId,$guestId,$later[0],$later[1],1);
        $service->hostTransition($kept,$hostId,'confirmed');
        $moderation->moderate($listingId,$adminId,'rejected','Procedure rejected fixture');
        $state=bookingState($closed);
        ensure($state['status']==='rejected' && (int)$state['events']===2,'Rejecting a listing left its request open or without an event.');
        $notices=noticesFor($guestId,$closed,'booking_rejected');
        ensure(count($notices)===1 && str_contains($notices[0]['body'],'chỗ ở không được duyệt'),'Rejecting a listing did not tell the guest why.');
        ensure(bookingState($kept)['status']==='confirmed','Rejecting a listing changed a confirmed stay.');

        // Only rows from before this rule are pending on a rejected listing. Nobody can confirm them; reject still works.
        callRows('CALL sp_test_listing_approve(?)',[$listingId]);
        $old=$service->create($listingId,$guestId,$start,$end,1);
        callRows('CALL sp_test_listing_mark_rejected(?)',[$listingId]);
        refusedSaying([$service,'hostTransition'],[$old,$hostId,'confirmed'],'từ chối kiểm duyệt');
        refusedSaying([$service,'adminTransition'],[$old,$adminId,'confirmed'],'từ chối kiểm duyệt');
        ensure(bookingState($old)['status']==='pending','A refused confirm changed the request.');
        $service->hostTransition($old,$hostId,'rejected');
        ensure(bookingState($old)['status']==='rejected','A request on a rejected listing could not be rejected.');

        // A Host edit back to review and a hidden listing do not close the listing.
        callRows('CALL sp_test_listing_approve(?)',[$listingId]);
        $reviewed=$service->create($listingId,$guestId,$start,$end,1);
        $hidden=$service->create($listingId,$guestId,$stay[0],$stay[1],1);
        $model->update($listingId,$hostId,$data);
        ensure($model->findOwned($listingId,$hostId)['moderation_status']==='pending','A Host edit did not send the listing back to review.');
        $service->hostTransition($reviewed,$hostId,'confirmed');
        ensure(bookingState($reviewed)['status']==='confirmed','A request on a listing under review could not be confirmed.');
        callRows('CALL sp_test_listing_approve(?)',[$listingId]);
        $moderation->setListingVisibility($listingId,$adminId,false);
        $service->hostTransition($hidden,$hostId,'confirmed');
        ensure(bookingState($hidden)['status']==='confirmed','A request on a hidden listing could not be confirmed.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS rejecting a listing closes its requests with event and notice, keeps confirmed stays, and only a rejected listing refuses confirm\n";

    // The Host hears why a listing was rejected or removed; everything rolls back with the outer transaction.
    $db->beginTransaction();
    try {
        $moderation=new AdminRepository(); $adminId=(int)$admin['id']; $hostId=(int)$host['id']; $reason='Ảnh chưa rõ & thiếu địa chỉ';
        $rejected=count(noticesFor($hostId,null,'listing_rejected')); $removed=count(noticesFor($hostId,null,'listing_removed'));
        $moderation->moderate($listingId,$adminId,'approved','Not a rejection');
        ensure(count(noticesFor($hostId,null,'listing_rejected'))===$rejected,'An approval told the Host the listing was rejected.');
        refused([$moderation,'moderate'],[$listingId,$adminId,'rejected','   ']);
        ensure(count(noticesFor($hostId,null,'listing_rejected'))===$rejected,'A refused rejection still notified the Host.');
        $moderation->moderate($listingId,$adminId,'rejected',$reason);
        $notices=noticesFor($hostId,null,'listing_rejected');
        ensure(count($notices)===$rejected+1 && str_contains($notices[0]['body'],$reason) && str_contains($notices[0]['body'],$data['title']),'The Host was not told the rejection reason.');
        ensure($model->findOwned($listingId,$hostId)['moderation_reason']===$reason,'The rejection reason did not reach the owned listing.');
        $moderation->softDeleteListing($listingId,$adminId);
        $notices=noticesFor($hostId,null,'listing_removed');
        ensure(count($notices)===$removed+1 && str_contains($notices[0]['body'],$data['title']),'The Host was not told about the take-down.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS the Host is notified with the reason on rejection and on take-down, in the same transaction, and not on approval or a refused rejection\n";

    // Deleting an account: refused while a confirmed stay is unfinished, otherwise its requests close with a notice to the other party; everything rolls back.
    $db->beginTransaction();
    try {
        $service=new BookingService(); $hostId=(int)$host['id']; $guestId=(int)$guest['id']; $day=new DateTimeImmutable($start); $tag=bin2hex(random_bytes(6));
        $stay=[$day->modify('+10 days')->format('Y-m-d'),$day->modify('+12 days')->format('Y-m-d')];
        $oneNight=[$day->modify('+20 days')->format('Y-m-d'),$day->modify('+21 days')->format('Y-m-d')];
        $goneGuestEmail='audit-'.$tag.'-guest@home2home.test'; $goneHostEmail='audit-'.$tag.'-host@home2home.test';
        $goneGuest=$users->adminCreate(['email'=>$goneGuestEmail,'password'=>bin2hex(random_bytes(16)),'full_name'=>'Audit leaving guest','phone'=>''],['guest']);
        $goneHost=$users->adminCreate(['email'=>$goneHostEmail,'password'=>bin2hex(random_bytes(16)),'full_name'=>'Audit leaving host','phone'=>''],['guest','host']);
        $goneListing=$model->create($goneHost,['title'=>'Audit fixture BX '.$tag]+$data);
        callRows('CALL sp_test_listing_approve(?)',[$goneListing]);

        $guestRequest=$service->create($listingId,$goneGuest,$start,$end,1);
        $guestStay=$service->create($listingId,$goneGuest,$stay[0],$stay[1],1);
        $service->hostTransition($guestStay,$hostId,'confirmed');
        refusedSaying([$users,'softDelete'],[$goneGuest],'còn 1 booking đã xác nhận chưa kết thúc');
        ensure($users->findByEmail($goneGuestEmail)['status']==='active' && bookingState($guestRequest)['status']==='pending','A refused delete changed the guest account or request.');

        $hostRequest=$service->create($goneListing,$guestId,$start,$end,1);
        $hostStay=$service->create($goneListing,$guestId,$stay[0],$stay[1],1);
        $finished=$service->create($goneListing,$guestId,$oneNight[0],$oneNight[1],1);
        $service->hostTransition($hostStay,$goneHost,'confirmed');
        $service->hostTransition($finished,$goneHost,'confirmed');
        callRows('CALL sp_test_booking_extras_backdate(?)',[$finished]);
        refusedSaying([$users,'softDelete'],[$goneHost],'còn 1 booking đã xác nhận chưa kết thúc');
        ensure($users->findByEmail($goneHostEmail)['status']==='active' && bookingState($hostRequest)['status']==='pending','A refused delete changed the Host account or request.');

        // Both stays end: nothing holds the delete any more, and a finished stay stays as it is.
        $service->cancelByGuest($guestStay,$goneGuest,'Procedure account delete fixture');
        $service->cancelByGuest($hostStay,$guestId,'Procedure account delete fixture');
        $users->softDelete($goneGuest);
        $state=bookingState($guestRequest); $notices=noticesFor($hostId,$guestRequest,'booking_rejected');
        ensure($users->findByEmail($goneGuestEmail)['status']==='deleted' && $state['status']==='rejected' && (int)$state['events']===2,'Deleting a guest left the request open.');
        ensure(count($notices)===1 && str_contains($notices[0]['body'],'tài khoản khách đã bị xóa'),'The Host was not told the guest account was deleted.');
        $users->softDelete($goneHost);
        $state=bookingState($hostRequest); $notices=noticesFor($guestId,$hostRequest,'booking_rejected');
        ensure($users->findByEmail($goneHostEmail)['status']==='deleted' && $state['status']==='rejected' && (int)$state['events']===2,'Deleting a Host left the request open.');
        ensure(count($notices)===1 && str_contains($notices[0]['body'],'tài khoản Host đã bị xóa'),'The guest was not told the Host account was deleted.');
        ensure(bookingState($finished)['status']==='confirmed','Deleting a Host changed a finished stay.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS account delete refused with the count for an unfinished stay as guest or Host; otherwise requests close with event and notice to the other party\n";

    // Confirming needs both accounts live, for any actor; reject stays possible and confirmed stays are left alone. Everything rolls back.
    $db->beginTransaction();
    try {
        $service=new BookingService(); $adminId=(int)$admin['id']; $hostId=(int)$host['id']; $guestId=(int)$guest['id']; $day=new DateTimeImmutable($start); $tag=bin2hex(random_bytes(6));
        $stay=[$day->modify('+10 days')->format('Y-m-d'),$day->modify('+12 days')->format('Y-m-d')];
        $later=[$day->modify('+20 days')->format('Y-m-d'),$day->modify('+22 days')->format('Y-m-d')];
        $lockedGuest=$users->adminCreate(['email'=>'audit-'.$tag.'-guest@home2home.test','password'=>bin2hex(random_bytes(16)),'full_name'=>'Audit locked guest','phone'=>''],['guest']);
        $lockedHost=$users->adminCreate(['email'=>'audit-'.$tag.'-host@home2home.test','password'=>bin2hex(random_bytes(16)),'full_name'=>'Audit locked host','phone'=>''],['guest','host']);
        $lockedListing=$model->create($lockedHost,['title'=>'Audit fixture '.$tag]+$data);
        callRows('CALL sp_test_listing_approve(?)',[$lockedListing]);

        $request=$service->create($listingId,$lockedGuest,$start,$end,1);
        $held=$service->create($listingId,$lockedGuest,$stay[0],$stay[1],1);
        $service->hostTransition($held,$hostId,'confirmed');
        $users->setStatus($lockedGuest,'suspended');
        refusedSaying([$service,'hostTransition'],[$request,$hostId,'confirmed'],'Tài khoản khách đang bị khóa');
        refusedSaying([$service,'adminTransition'],[$request,$adminId,'confirmed'],'Tài khoản khách đang bị khóa');
        ensure(bookingState($request)['status']==='pending','A refused confirm changed the request.');
        ensure(bookingState($held)['status']==='confirmed','Suspending a guest changed a confirmed stay.');
        $service->hostTransition($request,$hostId,'rejected');
        ensure(bookingState($request)['status']==='rejected','A request from a suspended guest could not be rejected.');

        $hostRequest=$service->create($lockedListing,$guestId,$start,$end,1);
        $hostHeld=$service->create($lockedListing,$guestId,$stay[0],$stay[1],1);
        $service->hostTransition($hostHeld,$lockedHost,'confirmed');
        $users->setStatus($lockedHost,'suspended');
        refusedSaying([$service,'adminTransition'],[$hostRequest,$adminId,'confirmed'],'Tài khoản Host đang bị khóa');
        ensure(bookingState($hostRequest)['status']==='pending' && bookingState($hostHeld)['status']==='confirmed','Suspending a Host changed the bookings.');
        $service->adminTransition($hostRequest,$adminId,'rejected');
        ensure(bookingState($hostRequest)['status']==='rejected','An Admin could not reject a request on a suspended Host listing.');

        // Back to active, a confirm goes through again.
        $users->setStatus($lockedHost,'active');
        $reopened=$service->create($lockedListing,$guestId,$later[0],$later[1],1);
        $service->hostTransition($reopened,$lockedHost,'confirmed');
        ensure(bookingState($reopened)['status']==='confirmed','An active Host could not confirm.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS confirm refused for a suspended guest or Host for any actor; reject still works and confirmed stays are unchanged\n";

    // The Host role stays while the account has listings or unfinished bookings as Host; everything rolls back.
    $db->beginTransaction();
    try {
        $service=new BookingService(); $moderation=new AdminRepository(); $adminId=(int)$admin['id']; $guestId=(int)$guest['id']; $tag=bin2hex(random_bytes(6));
        $demoted=$users->adminCreate(['email'=>'audit-'.$tag.'-host@home2home.test','password'=>bin2hex(random_bytes(16)),'full_name'=>'Audit demoted host','phone'=>''],['guest','host']);
        $users->replaceRoles($demoted,['guest']);
        ensure($users->findWithRoles($demoted)['roles']===['guest'],'A Host with nothing to answer for could not lose the role.');
        $users->replaceRoles($demoted,['guest','host']);
        $demotedListing=$model->create($demoted,['title'=>'Audit fixture '.$tag]+$data);
        callRows('CALL sp_test_listing_approve(?)',[$demotedListing]);
        refusedSaying([$users,'replaceRoles'],[$demoted,['guest']],'tài khoản còn 1 chỗ ở và 0 booking');
        $request=$service->create($demotedListing,$guestId,$start,$end,1);
        refusedSaying([$users,'replaceRoles'],[$demoted,['guest','admin']],'tài khoản còn 1 chỗ ở và 1 booking');
        ensure($users->findWithRoles($demoted)['roles']===['guest','host'],'A refused role change changed the roles.');
        $users->replaceRoles($demoted,['guest','host','admin']);
        ensure(in_array('admin',$users->findWithRoles($demoted)['roles'],true),'Keeping the Host role was refused.');
        $users->replaceRoles($demoted,['guest','host']);

        // Take the listing down first, as the message says: the request closes and the role can go.
        $moderation->softDeleteListing($demotedListing,$adminId);
        ensure(bookingState($request)['status']==='rejected','The take-down left the request open.');
        $users->replaceRoles($demoted,['guest']);
        ensure($users->findWithRoles($demoted)['roles']===['guest'],'The role could not go after the take-down.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS Host role cannot be removed while listings or unfinished bookings remain, and can after the take-down\n";

    // An Admin's removal of the Host role holds against /profile/become-host until an Admin gives it back. Everything rolls back.
    $db->beginTransaction();
    try {
        $adminId=(int)$admin['id']; $tag=bin2hex(random_bytes(6));
        $applicant=$users->adminCreate(['email'=>'audit-'.$tag.'-applicant@home2home.test','password'=>bin2hex(random_bytes(16)),'full_name'=>'Audit applicant','phone'=>''],['guest']);
        ensure(!$users->hostRoleRemoved($applicant),'A person who never had the Host role read as removed.');
        $users->grantHost($applicant);
        ensure($users->findWithRoles($applicant)['roles']===['guest','host'],'A person who never had the Host role could not take it.');
        $users->replaceRoles($applicant,['guest'],$adminId);
        ensure($users->hostRoleRemoved($applicant),'An Admin removal left no record.');
        refusedSaying([$users,'grantHost'],[$applicant],'Admin đã gỡ vai trò Host');
        ensure($users->findWithRoles($applicant)['roles']===['guest'],'A refused grant changed the roles.');
        $users->replaceRoles($applicant,['guest'],$adminId);
        ensure($users->hostRoleRemoved($applicant),'An Admin saving unchanged roles lifted the removal.');
        $users->replaceRoles($applicant,['guest','host'],$adminId);
        ensure(!$users->hostRoleRemoved($applicant),'An Admin giving the role back did not lift the removal.');
        $users->grantHost($applicant);
        $users->replaceRoles($applicant,['guest','host','admin'],$adminId);
        ensure(!$users->hostRoleRemoved($applicant),'An Admin keeping the Host role read as a removal.');
        $users->replaceRoles($applicant,['guest'],$adminId);
        refusedSaying([$users,'grantHost'],[$applicant],'Admin đã gỡ vai trò Host');
        refused([$users,'replaceRoles'],[$applicant,['guest','host'],(int)$guest['id']]);
        ensure($users->findWithRoles($applicant)['roles']===['guest'],'A non-Admin recorded a role change.');
        $unrecorded=$users->adminCreate(['email'=>'audit-'.$tag.'-unrecorded@home2home.test','password'=>bin2hex(random_bytes(16)),'full_name'=>'Audit unrecorded','phone'=>''],['guest','host']);
        $users->replaceRoles($unrecorded,['guest']);
        ensure(!$users->hostRoleRemoved($unrecorded),'A change with no Admin named read as an Admin removal.');
        $users->grantHost($unrecorded);
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS Host role: never-had and given-back can take it, an Admin removal holds until an Admin gives it back\n";

    // A former Host keeps their old booking notifications, without a link the permission rule would answer with a 404. Everything rolls back.
    $db->beginTransaction();
    try {
        $service=new BookingService(); $moderation=new AdminRepository(); $guestId=(int)$guest['id']; $tag=bin2hex(random_bytes(6));
        $formerHost=$users->adminCreate(['email'=>'audit-'.$tag.'-former@home2home.test','password'=>bin2hex(random_bytes(16)),'full_name'=>'Audit former host','phone'=>''],['guest','host']);
        $formerListing=$model->create($formerHost,['title'=>'Audit fixture '.$tag]+$data);
        callRows('CALL sp_test_listing_approve(?)',[$formerListing]);
        $formerRequest=$service->create($formerListing,$guestId,$start,$end,1);
        ensure(noticeLinks($formerHost,$formerRequest)===[1],'A Host could not open a booking from its notification.');
        $service->hostTransition($formerRequest,$formerHost,'rejected');
        $moderation->softDeleteListing($formerListing,(int)$admin['id']);
        $users->replaceRoles($formerHost,['guest'],(int)$admin['id']);
        ensure(noticeLinks($formerHost,$formerRequest)!==[] && !in_array(1,noticeLinks($formerHost,$formerRequest),true),'A former Host was offered a booking they cannot open.');
        refused('callRows',['CALL sp_booking_detail(?,?)',[$formerRequest,$formerHost]]);
        ensure(!in_array(0,noticeLinks($guestId,$formerRequest),true) && noticeLinks($guestId,$formerRequest)!==[],'The Guest lost the link to their own booking.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS notifications link to a booking only when that person can open it\n";

    // The moderation reason has the same 5000-character limit as the other free-text fields. Everything rolls back.
    $db->beginTransaction();
    try {
        $moderation=new AdminRepository(); $adminId=(int)$admin['id']; $tag=bin2hex(random_bytes(6));
        $limitListing=$model->create((int)$host['id'],['title'=>'Audit fixture '.$tag]+$data);
        callRows('CALL sp_test_listing_approve(?)',[$limitListing]);
        refusedSaying([$moderation,'moderate'],[$limitListing,$adminId,'rejected',str_repeat('ệ',5001)],'tối đa 5000 ký tự');
        ensure($moderation->findListing($limitListing)['moderation_status']==='approved','A refused reason changed the listing.');
        $moderation->moderate($limitListing,$adminId,'rejected',str_repeat('ệ',5000));
        $limitRow=$moderation->findListing($limitListing);
        ensure($limitRow['moderation_status']==='rejected' && mb_strlen((string)$limitRow['moderation_reason'])===5000,'A reason of exactly 5000 characters was not kept whole.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS moderation reason: 5000 characters kept, 5001 refused with nothing changed\n";

    // Booking needs the Guest role; what someone already booked stays theirs, and no ordinary path loses the role. Everything rolls back.
    $db->beginTransaction();
    try {
        $service=new BookingService(); $tag=bin2hex(random_bytes(6));
        foreach ([$host,$guest,$admin] as $seeded) { ensure(in_array('guest',$users->findWithRoles((int)$seeded['id'])['roles'],true),'A seed account has no Guest role.'); }
        $registered=$users->create('audit-'.$tag.'-registered@home2home.test',bin2hex(random_bytes(16)),'Audit registered',null);
        ensure($users->findWithRoles($registered)['roles']===['guest'],'Registering does not give the Guest role.');
        $users->grantHost($registered);
        ensure($users->findWithRoles($registered)['roles']===['guest','host'],'Becoming a Host changed the Guest role.');
        $day=new DateTimeImmutable($start);
        $keptBooking=$service->create($listingId,$registered,$start,$end,1);
        $users->replaceRoles($registered,['host']);
        refusedSaying([$service,'create'],[$listingId,$registered,$day->modify('+20 days')->format('Y-m-d'),$day->modify('+22 days')->format('Y-m-d'),1],'không có vai trò Guest');
        ensure(bookingState($keptBooking)['status']==='pending','Losing the Guest role changed a booking.');
        ensure(callRows('CALL sp_booking_detail(?,?)',[$keptBooking,$registered])!==[],'A person lost sight of their booking with the Guest role.');
        $service->cancelByGuest($keptBooking,$registered,'Procedure role fixture');
        ensure(bookingState($keptBooking)['status']==='cancelled','A person could not cancel their booking without the Guest role.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS Guest role: no new booking without it, existing bookings stay theirs, seed, register and become-host keep it\n";

    // A hide by an Admin holds until an Admin shows the listing; a Host's own hide stays theirs. Everything rolls back.
    $db->beginTransaction();
    try {
        $moderation=new AdminRepository(); $adminId=(int)$admin['id']; $hostId=(int)$host['id'];
        $hiddenListing=$model->create($hostId,['title'=>'Audit fixture '.bin2hex(random_bytes(6))]+$data);
        callRows('CALL sp_test_listing_approve(?)',[$hiddenListing]);

        // Old data and a Host's own hide: no audit row, so the Host hid it and can show it.
        $model->setVisible($hiddenListing,$hostId,false);
        ensure(empty(hostListingRow($hostId,$hiddenListing)['admin_hidden']),'A Host\'s own hide read as an Admin hide.');
        $model->setVisible($hiddenListing,$hostId,true);
        ensure((int)hostListingRow($hostId,$hiddenListing)['is_visible']===1,'A Host could not show their own hidden listing.');
        ensure(listingNoticeCount($hostId,$hiddenListing,'listing_hidden')===0 && listingNoticeCount($hostId,$hiddenListing,'listing_shown')===0,'A Host was notified of their own action.');

        // Admin hides: the Host's Show is refused, and the state and the notice reach the Host.
        $moderation->setListingVisibility($hiddenListing,$adminId,false);
        ensure(!empty(hostListingRow($hostId,$hiddenListing)['admin_hidden']) && listingNoticeCount($hostId,$hiddenListing,'listing_hidden')===1,'An Admin hide did not reach the Host.');
        refusedSaying([$model,'setVisible'],[$hiddenListing,$hostId,true],'chỉ Admin mới hiện lại được');
        ensure((int)hostListingRow($hostId,$hiddenListing)['is_visible']===0,'A refused Show changed the listing.');
        ensure($model->findPublic($hiddenListing)===null,'An Admin-hidden listing stayed public.');
        $moderation->setListingVisibility($hiddenListing,$adminId,false);
        ensure(listingNoticeCount($hostId,$hiddenListing,'listing_hidden')===1,'A repeat Admin hide notified the Host again.');

        // Admin shows: the hold ends, and the Host's own hide and show work again.
        $moderation->setListingVisibility($hiddenListing,$adminId,true);
        ensure((int)hostListingRow($hostId,$hiddenListing)['is_visible']===1 && empty(hostListingRow($hostId,$hiddenListing)['admin_hidden']) && listingNoticeCount($hostId,$hiddenListing,'listing_shown')===1,'An Admin show did not reach the Host.');
        $moderation->setListingVisibility($hiddenListing,$adminId,true);
        ensure(listingNoticeCount($hostId,$hiddenListing,'listing_shown')===1,'A repeat Admin show notified the Host again.');
        $model->setVisible($hiddenListing,$hostId,false);
        ensure(empty(hostListingRow($hostId,$hiddenListing)['admin_hidden']),'A Host hide after an Admin show read as an Admin hide.');
        $model->setVisible($hiddenListing,$hostId,true);
        ensure((int)hostListingRow($hostId,$hiddenListing)['is_visible']===1,'A Host could not show their listing after an Admin show.');

        // Admin hides what the Host had already hidden: the Admin now holds it, and an Admin show releases a Host hide too.
        $model->setVisible($hiddenListing,$hostId,false);
        $moderation->setListingVisibility($hiddenListing,$adminId,false);
        ensure(listingNoticeCount($hostId,$hiddenListing,'listing_hidden')===2,'An Admin taking over a Host hide did not tell the Host.');
        refusedSaying([$model,'setVisible'],[$hiddenListing,$hostId,true],'chỉ Admin mới hiện lại được');
        $moderation->setListingVisibility($hiddenListing,$adminId,true);
        ensure((int)hostListingRow($hostId,$hiddenListing)['is_visible']===1 && listingNoticeCount($hostId,$hiddenListing,'listing_shown')===2,'An Admin show after a takeover did not reach the Host.');

        // The permission refusal is unchanged, and it comes first.
        refusedSaying([$model,'setVisible'],[$hiddenListing,(int)$guest['id'],true],'Không có quyền');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS an Admin hide holds against the Host and notifies them; a Host's own hide and show keep working\n";

    // A Host may block a date inside a pending request; Accept then says what to do and Reject keeps working. Everything rolls back.
    $db->beginTransaction();
    try {
        $service=new BookingService(); $hostId=(int)$host['id']; $guestId=(int)$guest['id'];
        $blockedRequest=$service->create($listingId,$guestId,$start,$end,1);
        ensure($model->setAvailability($listingId,$hostId,$start,false,'Procedure block fixture'),'A Host could not block a date inside a pending request.');
        refusedSaying([$service,'hostTransition'],[$blockedRequest,$hostId,'confirmed'],'Hãy mở lại ngày đó trong Lịch khả dụng');
        refusedSaying([$service,'adminTransition'],[$blockedRequest,(int)$admin['id'],'confirmed'],'Hãy nhờ Host mở lại ngày đó');
        ensure(bookingState($blockedRequest)['status']==='pending','A refused Accept changed the request.');
        ensure($model->setAvailability($listingId,$hostId,$start,true,null),'A Host could not reopen the date.');
        $service->hostTransition($blockedRequest,$hostId,'confirmed');
        ensure(bookingState($blockedRequest)['status']==='confirmed','Reopening the date did not allow Accept.');
        $day=new DateTimeImmutable($start);
        $rejectable=$service->create($listingId,$guestId,$day->modify('+10 days')->format('Y-m-d'),$day->modify('+12 days')->format('Y-m-d'),1);
        ensure($model->setAvailability($listingId,$hostId,$day->modify('+10 days')->format('Y-m-d'),false,'Procedure block fixture'),'A Host could not block a second date.');
        $service->hostTransition($rejectable,$hostId,'rejected');
        ensure(bookingState($rejectable)['status']==='rejected','A request with a blocked date could not be rejected.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS blocked date inside a pending request: Accept says how to proceed, reopening allows it, Reject still works\n";

    // A pending request whose check-in has passed holds no dates; one that can still be confirmed does. Everything rolls back.
    $db->beginTransaction();
    try {
        $service=new BookingService(); $discovery=new Discovery(); $hostId=(int)$host['id']; $guestId=(int)$guest['id']; $tag=bin2hex(random_bytes(6));
        $today=new DateTimeImmutable('today',new DateTimeZone('Asia/Ho_Chi_Minh')); $farAway=$today->modify('+100 days');
        $staleListing=$model->create($hostId,['title'=>'Audit fixture BX '.$tag,'latitude'=>10.5,'longitude'=>106.5]+$data);
        callRows('CALL sp_test_listing_approve(?)',[$staleListing]);
        $stale=$service->create($staleListing,$guestId,$farAway->format('Y-m-d'),$farAway->modify('+6 days')->format('Y-m-d'),1);
        callRows('CALL sp_test_booking_extras_backdate(?)',[$stale]);
        $free=[$today->modify('+2 days')->format('Y-m-d'),$today->modify('+3 days')->format('Y-m-d')];
        $filters=['location'=>'Audit','check_in'=>$free[0],'check_out'=>$free[1]];

        ensure($service->quote($staleListing,$free[0],$free[1],1)['nights']===1,'A stale request blocked the quote.');
        ensure($model->calendar($staleListing,0,$free[0],$free[1])[0]['day_status']==='available','A stale request still shows on the calendar.');
        ensure(in_array($staleListing,array_map('intval',array_column($model->search($filters,100),'id')),true),'A stale request hid the listing from search.');
        ensure(in_array($staleListing,array_map('intval',array_column($discovery->mapSearch(null,$filters)['listings'],'id')),true),'A stale request hid the listing from the map.');
        $taken=$service->create($staleListing,$guestId,$free[0],$free[1],1);
        ensure(bookingState($taken)['status']==='pending','A stale request blocked a new booking.');
        refusedSaying([$service,'hostTransition'],[$stale,$hostId,'confirmed'],'Đã qua ngày nhận phòng');

        ensure($model->calendar($staleListing,0,$free[0],$free[1])[0]['day_status']==='pending','A live request is missing from the calendar.');
        refusedSaying([$service,'quote'],[$staleListing,$free[0],$free[1],1],'yêu cầu đặt chỗ khác');
        refusedSaying([$service,'create'],[$staleListing,$guestId,$free[0],$free[1],1],'yêu cầu đặt chỗ khác');
        ensure(!in_array($staleListing,array_map('intval',array_column($model->search($filters,100),'id')),true),'A live request did not hide the listing from search.');
        ensure(!in_array($staleListing,array_map('intval',array_column($discovery->mapSearch(null,$filters)['listings'],'id')),true),'A live request did not hide the listing from the map.');

        // Check-in today can still be confirmed, so it still holds its dates.
        $todayListing=$model->create($hostId,['title'=>'Audit fixture BX '.$tag.' today']+$data);
        callRows('CALL sp_test_listing_approve(?)',[$todayListing]);
        $service->create($todayListing,$guestId,$today->format('Y-m-d'),$today->modify('+1 day')->format('Y-m-d'),1);
        refusedSaying([$service,'quote'],[$todayListing,$today->format('Y-m-d'),$today->modify('+1 day')->format('Y-m-d'),1],'yêu cầu đặt chỗ khác');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS a pending request past its check-in holds no dates in quote, booking, calendar, search or map; a live one still does\n";

    for ($i=0;$i<2;$i++) {
        $pipes=[];
        $process=proc_open([PHP_BINARY,__FILE__,'--worker',(string)$listingId,(string)$guest['id'],$start,$end],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);
        ensure(is_resource($process),'Could not start concurrency worker.');
        $processes[]=[$process,$pipes];
        ensure(trim(fgets($pipes[1]))==='READY','Concurrency worker did not initialize.');
    }
    foreach ($processes as [$process,$pipes]) { fwrite($pipes[0],"GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
    $created=0; $blocked=0;
    foreach ($processes as [$process,$pipes]) {
        $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit=proc_close($process);
        ensure($exit===0 && $error==='','Concurrency worker failed.');
        $result=json_decode(trim($output),true,512,JSON_THROW_ON_ERROR);
        if (isset($result['created'])) { $created++; $bookingIds[]=(int)$result['created']; }
        if (!empty($result['blocked'])) { $blocked++; }
    }
    $processes=[];
    ensure($created===1 && $blocked===1,'Concurrent booking was not serialized.');
    domainFailure(fn()=>callRows('CALL sp_booking_transition(?,?,?,?)',[$bookingIds[0],(int)$guest['id'],'confirmed',true]));
    ensure(callRows('CALL sp_test_booking_inspect(?)',[$bookingIds[0]])[0]['status']==='pending','Unauthorized transition changed status.');
    ensure(callRows('CALL sp_booking_find_for_user(?,?,?)',[$bookingIds[0],999999999,true])===[],'Admin flag bypassed role validation.');
    domainFailure(fn()=>callRows('CALL sp_booking_transition(?,?,?,?)',[$bookingIds[0],(int)$guest['id'],'confirmed',null]));
    domainFailure(fn()=>callRows('CALL sp_booking_transition(?,?,?,?)',[$bookingIds[0],(int)$guest['id'],'confirmed',2]));
    echo "PASS two-process overlapping booking with stale snapshots: one created, one blocked\n";
    echo "PASS direct routine booking authorization and unchanged pending state\n";

    $repository=new AdminRepository();
    $data['nightly_price']=125000;
    $repository->updateListing($listingId,(int)$admin['id'],$data);
    ensure((float)$repository->findListing($listingId)['nightly_price']===125000.0,'Admin listing update failed.');
    $guestBookings=(new Booking())->forGuest((int)$guest['id']);
    $snapshot=current(array_filter($guestBookings,fn($row)=>(int)$row['id']===$bookingIds[0]));
    ensure((float)$snapshot['total_amount']===220000.0,'Listing price edit changed an existing booking snapshot.');
    $repository->setListingVisibility($listingId,(int)$admin['id'],false);
    ensure($model->findPublic($listingId)===null,'Hidden listing remained public.');
    $repository->setListingVisibility($listingId,(int)$admin['id'],true);
    ensure($model->findPublic($listingId)!==null,'Restored listing remained hidden.');
    domainFailure(fn()=>callRows('CALL sp_admin_listing_visibility(?,?,?)',[false,$listingId,(int)$guest['id']]));
    $repository->softDeleteListing($listingId,(int)$admin['id']);
    ensure($repository->findListing($listingId)===null && $model->findPublic($listingId)===null,'Soft deleted listing remained visible.');
    echo "PASS Admin listing update/visibility/soft-delete, role guards and unchanged price snapshots\n";

    $fixtureEmail='audit-'.bin2hex(random_bytes(6)).'@home2home.test'; $fixturePassword=bin2hex(random_bytes(16));
    $fixtureUser=$users->adminCreate(['email'=>$fixtureEmail,'password'=>$fixturePassword,
        'full_name'=>'Audit profile','phone'=>''],['guest','host']);
    ensure($users->findWithRoles($fixtureUser)['roles']===['guest','host'],'Admin account roles were not saved.');
    $avatar='/assets/images/demo/1600585154340-be6161a56a0c.jpg';
    $users->updateProfile($fixtureUser,'Audit changed',null,$avatar);
    $users->updateProfile($fixtureUser,'Audit retained avatar',null,null);
    $profile=callRows('CALL DST_sp_get_user_profile(?)',[$fixtureUser])[0];
    ensure($profile['avatar_url']===$avatar && $profile['full_name']==='Audit retained avatar','NULL avatar did not preserve the saved image.');
    $users->setStatus($fixtureUser,'suspended');
    ensure($users->findWithRoles($fixtureUser)===null,'Suspended user remained authenticated.');
    $users->setStatus($fixtureUser,'active');
    ensure($users->findWithRoles($fixtureUser)!==null,'User could not be reactivated.');
    $users->softDelete($fixtureUser); $users->setStatus($fixtureUser,'active');
    ensure($users->findWithRoles($fixtureUser)===null,'Soft-deleted user was reactivated.');
    $users->setStatus($fixtureUser,'suspended');
    ensure($users->findByEmail($fixtureEmail)['status']==='deleted','Status action changed a soft-deleted user.');
    ensure(emailIsTaken($users,$fixtureEmail),'A soft-deleted email could be registered again.');
    ensure(!Core\Auth::attempt($fixtureEmail,$fixturePassword),'A soft-deleted user could log in.');
    ensure(array_column($users->all($fixtureEmail),'status')===['deleted'],'Admin search cannot find a soft-deleted user.');
    $users->setStatus($fixtureUser,'restore');
    $restored=$users->findByEmail($fixtureEmail);
    ensure($restored['status']==='active' && $restored['deleted_at']===null && $users->findWithRoles($fixtureUser)['roles']===['guest','host'],'Restore did not bring the account back with its roles.');
    ensure(restoreRefused($users,$fixtureUser) && restoreRefused($users,PHP_INT_MAX),'Restore accepted an account that is not deleted.');
    echo "PASS user create/roles/profile/avatar preservation/status/soft-delete through routines\n";
} finally {
    foreach ($processes as [$process,$pipes]) { proc_terminate($process); foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } proc_close($process); }
    if ($db->inTransaction()) { $db->rollBack(); }
    if ($listingId!==null) {
        // Resolve only this run's listing if a child failed before reporting its ID.
        foreach ((new Booking())->forGuest((int)$guest['id']) as $booking) {
            if ((int)$booking['listing_id']===$listingId) { $bookingIds[]=(int)$booking['id']; }
        }
        $db->beginTransaction();
        foreach (array_unique($bookingIds) as $id) { callRows('CALL sp_test_cleanup_booking(?)',[$id]); }
        callRows('CALL sp_test_cleanup_listing(?)',[$listingId]);
        if ($fixtureUser!==null) { callRows('CALL sp_test_cleanup_user(?)',[$fixtureUser]); }
        $db->commit();
    }
}
