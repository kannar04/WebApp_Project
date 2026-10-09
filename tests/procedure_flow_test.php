<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';

use App\Models\Listing;
use App\Models\User;
use App\Models\Booking;
use App\Models\Wishlist;
use App\Models\AdminRepository;
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
function domainFailure(callable $operation): void
{
    try { $operation(); } catch (DomainException $exception) { return; }
    throw new RuntimeException('A routine accepted an invalid business operation.');
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

    $start=(new DateTimeImmutable('today'))->modify('+5500 days')->format('Y-m-d');
    $end=(new DateTimeImmutable($start))->modify('+2 days')->format('Y-m-d');
    $db->beginTransaction();
    $id=(new BookingService())->create($listingId,(int)$guest['id'],$start,$end,1);
    ensure($db->inTransaction(),'Procedure committed its caller transaction.');
    $db->rollBack();
    ensure(callRows('CALL sp_test_booking_inspect(?)',[$id])===[],'Caller rollback left a booking behind.');
    echo "PASS routine savepoint preserves outer transaction and full booking rollback\n";

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

    $fixtureUser=$users->adminCreate(['email'=>'audit-'.bin2hex(random_bytes(6)).'@home2home.test','password'=>bin2hex(random_bytes(16)),
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
