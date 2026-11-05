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
    domainFailure(fn()=>callRows('CALL sp_user_roles_replace(?,?)',[(int)$guest['id'],'["superadmin"]']));
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

    // A repeated report adds nothing while the first is open on the same target; everything rolls back with the outer transaction.
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
        ensure($reports->create($repeatReporter,$listingId,null,'other',$body)===true,'A report under investigation is no longer open, so a new one must be accepted.');
        $reports->resolve((int)$reports->forUser($repeatReporter)[0]['id'],(int)$admin['id'],'resolved','Closed');
        ensure($reports->create($repeatReporter,$listingId,null,'other',$body)===true,'A closed report blocked a new one.');
    } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    echo "PASS repeated report on an open target saves nothing; other reporters and closed reports are unaffected\n";

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
