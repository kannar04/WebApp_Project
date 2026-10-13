<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';

// Discovery: map search, view/search history and recommendations.
// Fixtures sit in the South Pacific (no real listing there) and are removed by one exact random marker.
// Set HOME2HOME_TEST_URL to also exercise the pages and JSON API over HTTP (fresh_setup_test.php does this).

use App\Controllers\DiscoveryController;
use App\Models\Discovery;
use App\Models\Listing;
use App\Models\User;
use Core\ProcedureConnection;

$discoveryConfig=require dirname(__DIR__).'/config/database.php';
if (PHP_SAPI!=='cli' || !in_array($discoveryConfig['host'],['127.0.0.1','localhost','::1'],true)
    || !preg_match('/^(?:db_home2home_schema_test_[a-f0-9]{12}|db_h2h_ws_[a-z0-9_]+)$/D',(string)$discoveryConfig['database'])) {
    throw new RuntimeException('Discovery feature test needs a local workspace or isolated test database, never the shared one.');
}

function discoveryExpect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }

/** Calls $operation with $arguments and requires a DomainException (optionally containing $fragment). */
function discoveryRejects(callable $operation,array $arguments,string $message,string $fragment=''): void
{
    try { call_user_func_array($operation,$arguments); }
    catch (DomainException $exception) {
        if ($fragment!=='' && !str_contains($exception->getMessage(),$fragment)) {
            throw new RuntimeException($message.' Unexpected message: '.$exception->getMessage());
        }
        return;
    }
    throw new RuntimeException($message);
}

function discoveryCall(string $call,array $parameters): array
{
    $statement=ProcedureConnection::connection()->prepare($call);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

/** @return list<int> */
function discoveryIds(array $rows): array { return array_map('intval',array_column($rows,'id')); }

/** Keeps only ids that belong to this run's fixtures, preserving order. */
function discoveryOwnIds(array $rows,array $fixtureIds): array { return array_values(array_intersect(discoveryIds($rows),$fixtureIds)); }

function discoverySameSet(array $actual,array $expected): bool { sort($actual); sort($expected); return $actual===$expected; }

function discoveryListingData(string $marker,string $suffix,string $city,?float $lat,?float $lng,int $type,float $price,int $guests): array
{
    return ['property_type_id'=>$type,'cancellation_policy_id'=>1,'title'=>'Discovery test '.$marker.' '.$suffix,
        'address'=>$suffix.' Discovery Street','city'=>$city,'description'=>'Discovery feature test fixture.',
        'nightly_price'=>$price,'cleaning_fee'=>0,'max_guests'=>$guests,'room_count'=>1,'bedroom_count'=>1,'bed_count'=>1,
        'check_in_time'=>'14:00','check_out_time'=>'11:00','latitude'=>$lat,'longitude'=>$lng];
}

function discoveryBox(string $north,string $south,string $east,string $west): array
{
    return ['north'=>$north,'south'=>$south,'east'=>$east,'west'=>$west];
}

/** @return array{status:int,url:string,type:string,body:string} */
function discoveryRequest(CurlHandle $curl,string $baseUrl,string $path,?array $post=null): array
{
    curl_setopt($curl,CURLOPT_URL,$baseUrl.$path);
    if ($post!==null) { curl_setopt($curl,CURLOPT_POST,true); curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post)); }
    else { curl_setopt($curl,CURLOPT_HTTPGET,true); }
    $body=curl_exec($curl);
    if (!is_string($body)) { throw new RuntimeException('HTTP request failed: '.$path.' '.curl_error($curl)); }
    return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'url'=>(string)curl_getinfo($curl,CURLINFO_EFFECTIVE_URL),
        'type'=>(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE),'body'=>$body];
}

function discoveryToken(string $html): string
{
    if (!preg_match('/name="_token" value="([^"]+)"/',$html,$match) && !preg_match('/<meta name="csrf-token" content="([^"]+)"/',$html,$match)) {
        throw new RuntimeException('CSRF token missing from page.');
    }
    return $match[1];
}

function discoveryJson(array $response): array
{
    discoveryExpect(str_contains($response['type'],'application/json'),'Map API did not answer JSON.');
    $decoded=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);
    discoveryExpect(is_array($decoded) && array_keys($decoded)===['success','message','data','errors'],'Map API envelope shape changed.');
    return $decoded;
}

$marker=bin2hex(random_bytes(6));
$secret='Dq'.bin2hex(random_bytes(10)).'!7';
$users=new User(); $listings=new Listing(); $discovery=new Discovery();
$alpha='Discovery QA '.$marker.' Alpha'; $beta='Discovery QA '.$marker.' Beta';
$curl=null;
try {
    $people=[];
    foreach (['host','guest','fresh','searcher','web'] as $name) {
        $people[$name]=$users->create('discovery-test-'.$marker.'-'.$name.'@home2home.test',$secret,'Discovery QA '.ucfirst($name),null);
        discoveryExpect($people[$name]>0,'Fixture account was not created.');
    }
    $users->replaceRoles($people['host'],['guest','host']);
    $host=$people['host']; $guest=$people['guest']; $fresh=$people['fresh']; $searcher=$people['searcher']; $web=$people['web'];

    // A,B,E inside the test box (E exactly on its north-east corner); O outside; P/H/D not public; N has no coordinates; M across the antimeridian.
    $plan=[
        'A'=>[$alpha,-40.0,-140.0,1,1000000,4,'approved',true,false],
        'B'=>[$alpha,-40.05,-140.05,2,1100000,2,'approved',true,false],
        'E'=>[$alpha,-39.9,-139.9,1,1200000,2,'approved',true,false],
        'O'=>[$alpha,-41.5,-140.0,1,900000,2,'approved',true,false],
        'P'=>[$alpha,-40.01,-140.01,1,1000000,2,'pending',true,false],
        'H'=>[$alpha,-40.02,-140.02,1,1000000,2,'approved',false,false],
        'D'=>[$alpha,-40.03,-140.03,1,1000000,2,'approved',true,true],
        'N'=>[$alpha,null,null,1,1000000,2,'approved',true,false],
        'M'=>[$beta,-40.0,179.5,2,500000,2,'approved',true,false],
    ];
    $id=[];
    foreach ($plan as $suffix=>[$city,$lat,$lng,$type,$price,$guests,$status,$visible,$deleted]) {
        $id[$suffix]=$listings->create($host,discoveryListingData($marker,$suffix,$city,$lat,$lng,$type,(float)$price,$guests));
        discoveryExpect($id[$suffix]>0,'Fixture listing was not created.');
        if ($status!=='pending' || !$visible || $deleted) {
            $changed=discoveryCall('CALL sp_test_discovery_listing_state(?,?,?,?)',[$id[$suffix],$status,$visible,$deleted]);
            discoveryExpect((int)$changed[0]['changed']===1,'Fixture listing state was not applied.');
        }
    }
    $fixtureIds=array_values($id);
    $stored=$listings->findOwned($id['A'],$host);
    discoveryExpect($stored!==null && (float)$stored['latitude']===-40.0 && (float)$stored['longitude']===-140.0,'Listing coordinates were not stored.');
    discoveryExpect($listings->findOwned($id['N'],$host)['latitude']===null,'Listing without coordinates got a position.');

    // Listing coordinates: both or neither, within range (create and update).
    discoveryRejects([$listings,'create'],[$host,discoveryListingData($marker,'X1',$alpha,-40.0,null,1,1000000,2)],'Latitude without longitude accepted.','Tọa độ');
    discoveryRejects([$listings,'create'],[$host,discoveryListingData($marker,'X2',$alpha,95.0,10.0,1,1000000,2)],'Latitude above 90 accepted.','Tọa độ');
    discoveryRejects([$listings,'create'],[$host,discoveryListingData($marker,'X3',$alpha,10.0,-181.0,1,1000000,2)],'Longitude below -180 accepted.','Tọa độ');
    discoveryRejects([$listings,'update'],[$id['N'],$host,discoveryListingData($marker,'N',$alpha,null,10.0,1,1000000,2)],'Listing update with longitude only accepted.','Tọa độ');
    discoveryRejects([$listings,'update'],[$id['N'],$host,discoveryListingData($marker,'N',$alpha,-91.0,10.0,1,1000000,2)],'Listing update with latitude below -90 accepted.','Tọa độ');
    discoveryRejects([$listings,'update'],[$id['N'],$people['guest'],discoveryListingData($marker,'N',$alpha,-40.0,-140.0,1,1000000,2)],'Non-owner updated a listing position.');
    discoveryExpect($listings->findOwned($id['N'],$host)['latitude']===null,'Rejected update changed the position.');
    echo "PASS discovery listing coordinates: stored on create, both-or-neither, range and ownership validation on create/update\n";

    // Map box: inside, outside, exact edge, non-public rows, limit/total, antimeridian, filters.
    $box=discoveryBox('-39.900000','-40.200000','-139.900000','-140.100000');
    $result=$discovery->mapSearch($box,[],100);
    discoveryExpect(discoverySameSet(discoveryOwnIds($result['listings'],$fixtureIds),[$id['A'],$id['B'],$id['E']]),'Map box returned the wrong fixtures.');
    discoveryExpect(discoveryIds($result['listings'])===discoveryOwnIds($result['listings'],$fixtureIds) && $result['total']===3,'Map box leaked other listings or miscounted.');
    discoveryExpect(discoveryOwnIds($result['listings'],$fixtureIds)===[$id['E'],$id['B'],$id['A']],'Unrated map results not ordered newest first.');
    $first=$result['listings'][0];
    foreach (['id','title','city','property_type','max_guests','nightly_price','currency','latitude','longitude','image_url','rating','review_count'] as $column) {
        discoveryExpect(array_key_exists($column,$first),'Map search column missing: '.$column);
    }
    $limited=$discovery->mapSearch($box,[],2);
    discoveryExpect(count($limited['listings'])===2 && $limited['total']===3,'Map limit/total incorrect.');
    $edge=$discovery->mapSearch(discoveryBox('-39.900001','-40.200000','-139.900001','-140.100000'),[],100);
    discoveryExpect(discoverySameSet(discoveryIds($edge['listings']),[$id['A'],$id['B']]),'Listing just outside the edge was included.');
    $outside=$discovery->mapSearch(discoveryBox('-41.000000','-41.400000','-139.000000','-141.000000'),[],100);
    discoveryExpect($outside['listings']===[] && $outside['total']===0,'Empty box returned listings.');
    $wrapped=$discovery->mapSearch(discoveryBox('-39.000000','-41.000000','-179.000000','179.000000'),[],100);
    discoveryExpect(discoveryIds($wrapped['listings'])===[$id['M']],'Antimeridian box incorrect.');
    $plain=$discovery->mapSearch(discoveryBox('-39.000000','-41.000000','-139.000000','-141.000000'),[],100);
    discoveryExpect(!in_array($id['M'],discoveryIds($plain['listings']),true),'Antimeridian listing leaked into an ordinary box.');
    $everywhere=$discovery->mapSearch(null,['location'=>'Discovery QA '.$marker],100);
    discoveryExpect(discoverySameSet(discoveryIds($everywhere['listings']),[$id['A'],$id['B'],$id['E'],$id['O'],$id['M']]),'Pending/hidden/deleted/unplaced listings reached the map.');
    discoveryExpect(discoveryIds($discovery->mapSearch($box,['guests'=>3],100)['listings'])===[$id['A']],'Guest-count filter incorrect.');
    discoveryExpect(discoveryIds($discovery->mapSearch($box,['type'=>2],100)['listings'])===[$id['B']],'Property type filter incorrect.');
    discoveryExpect(discoverySameSet(discoveryIds($discovery->mapSearch($box,['min_price'=>1050000],100)['listings']),[$id['B'],$id['E']]),'Minimum price filter incorrect.');
    discoveryExpect(discoveryIds($discovery->mapSearch($box,['max_price'=>1050000],100)['listings'])===[$id['A']],'Maximum price filter incorrect.');
    discoveryExpect($discovery->mapSearch($box,['location'=>$beta],100)['total']===0,'Location filter ignored inside the box.');
    $stayIn=(new DateTimeImmutable('today'))->modify('+40 days')->format('Y-m-d');
    $stayOut=(new DateTimeImmutable('today'))->modify('+42 days')->format('Y-m-d');
    discoveryExpect($listings->setAvailability($id['A'],$host,$stayIn,false,'Discovery test block'),'Fixture date block failed.');
    discoveryExpect(discoverySameSet(discoveryIds($discovery->mapSearch($box,['check_in'=>$stayIn,'check_out'=>$stayOut],100)['listings']),[$id['B'],$id['E']]),'Blocked dates not excluded from the map.');
    echo "PASS discovery map box: inside/outside/exact edge, pending/hidden/deleted/unplaced excluded, limit+total, antimeridian, guests/type/price/place/date filters\n";

    // Bad boxes and parameters are rejected by the procedure; the controller validates raw query strings first.
    discoveryRejects([$discovery,'mapSearch'],[['north'=>'-39.9'],[],100],'Partial map box accepted.','bốn cạnh');
    discoveryRejects([$discovery,'mapSearch'],[discoveryBox('-40.2','-39.9','-139.9','-140.1'),[],100],'South above north accepted.','Khung bản đồ');
    discoveryRejects([$discovery,'mapSearch'],[discoveryBox('-39.9','-40.2','-140.1','-140.1'),[],100],'Zero-width box accepted.','Khung bản đồ');
    discoveryRejects([$discovery,'mapSearch'],[discoveryBox('91','-40.2','-139.9','-140.1'),[],100],'Latitude above 90 accepted.','Khung bản đồ');
    discoveryRejects([$discovery,'mapSearch'],[discoveryBox('-39.9','-40.2','181','-140.1'),[],100],'Longitude above 180 accepted.','Khung bản đồ');
    discoveryRejects([$discovery,'mapSearch'],[$box,[],0],'Zero map limit accepted.','1 đến 100');
    discoveryRejects([$discovery,'mapSearch'],[$box,[],101],'Oversized map limit accepted.','1 đến 100');
    discoveryRejects([$discovery,'mapSearch'],[$box,['min_price'=>2000000,'max_price'=>1000000],100],'Inverted price range accepted.');
    discoveryRejects([$discovery,'mapSearch'],[$box,['check_in'=>(new DateTimeImmutable('yesterday'))->format('Y-m-d'),'check_out'=>$stayOut],100],'Past check-in accepted.');
    [$parsed,$errors]=DiscoveryController::bounds(['north'=>'-39.9','south'=>'-40.2','east'=>'-139.9','west'=>'-140.1']);
    discoveryExpect($errors===[] && $parsed===discoveryBox('-39.900000','-40.200000','-139.900000','-140.100000'),'Valid query box not normalized.');
    discoveryExpect(DiscoveryController::bounds([])===[null,[]] && DiscoveryController::bounds(['north'=>'','south'=>'','east'=>'','west'=>''])===[null,[]],'Empty box not treated as no box.');
    $badInputs=[['north'=>'-39.9','south'=>'-40.2','east'=>'-139.9'],['north'=>'abc','south'=>'-40.2','east'=>'-139.9','west'=>'-140.1'],
        ['north'=>'1e5','south'=>'-40.2','east'=>'-139.9','west'=>'-140.1'],['north'=>'-41','south'=>'-40.2','east'=>'-139.9','west'=>'-140.1'],
        ['north'=>'-39.9','south'=>'-40.2','east'=>'190','west'=>'-140.1'],['north'=>['-39.9'],'south'=>'-40.2','east'=>'-139.9','west'=>'-140.1']];
    foreach ($badInputs as $input) {
        [$parsed,$errors]=DiscoveryController::bounds($input);
        discoveryExpect($parsed===null && isset($errors['bounds']) && $errors['bounds']!=='','Bad query box accepted.');
    }
    $items=DiscoveryController::mapItems($result['listings']);
    discoveryExpect(array_keys($items[0])===['id','title','city','property_type','max_guests','price','price_label','rating','review_count','latitude','longitude','photo','url'],'Map item shape changed.');
    discoveryExpect($items[0]['id']===$id['E'] && $items[0]['latitude']===-39.9 && $items[0]['price_label']==='1.200.000đ' && str_ends_with($items[0]['url'],'/listings/'.$id['E']),'Map item values incorrect.');
    echo "PASS discovery bad boxes: partial/inverted/zero-width/out-of-range/limit/price/date rejected; query parsing, normalization and JSON item shape\n";

    // View history: public listings of other hosts only; repeat views increment one row.
    discoveryExpect($discovery->recordView($guest,$id['A']) && $discovery->recordView($guest,$id['A']),'View not recorded.');
    foreach (['P','H','D'] as $suffix) { discoveryExpect(!$discovery->recordView($guest,$id[$suffix]),'Non-public listing view recorded: '.$suffix); }
    discoveryExpect(!$discovery->recordView($guest,PHP_INT_MAX),'Unknown listing view recorded.');
    discoveryExpect(!$discovery->recordView($host,$id['A']),'Host own-listing view recorded.');
    discoveryRejects([$discovery,'recordView'],[PHP_INT_MAX,$id['A']],'View recorded for an unknown account.','Tài khoản');
    $views=discoveryCall('CALL sp_test_discovery_views(?)',[$guest]);
    discoveryExpect(count($views)===1 && (int)$views[0]['listing_id']===$id['A'] && (int)$views[0]['view_count']===2,'View history rows incorrect.');
    discoveryExpect(discoveryCall('CALL sp_test_discovery_views(?)',[$host])===[],'Host history polluted by own listing.');
    $recent=$discovery->recentViews($guest,8);
    discoveryExpect(discoveryIds($recent)===[$id['A']] && (int)$recent[0]['view_count']===2,'Recent views incorrect.');
    discoveryCall('CALL sp_test_discovery_listing_state(?,?,?,?)',[$id['A'],'approved',false,false]);
    discoveryExpect($discovery->recentViews($guest,8)===[],'Hidden listing still shown in recent views.');
    discoveryCall('CALL sp_test_discovery_listing_state(?,?,?,?)',[$id['A'],'approved',true,false]);
    discoveryRejects([$discovery,'recentViews'],[$guest,21],'Oversized recent-view limit accepted.');
    echo "PASS discovery view history: repeat views counted once, own/pending/hidden/deleted/unknown skipped, recent views public-only\n";

    // Recommendations: unseen listings in a viewed city first, already-seen lower, own listings never, fallback without history.
    $recommended=$discovery->recommendations($guest,24);
    $order=discoveryIds($recommended);
    discoveryExpect(discoverySameSet(array_slice($order,0,4),[$id['B'],$id['E'],$id['O'],$id['N']]),'Unseen listings in the viewed city are not ranked first: '
        .json_encode(array_map(null,array_column($recommended,'id'),array_column($recommended,'score'),array_column($recommended,'reason')),JSON_UNESCAPED_UNICODE));
    foreach (array_slice($recommended,0,4) as $row) {
        discoveryExpect($row['reason']==='Vì bạn đã xem chỗ ở tại '.$alpha && (int)$row['personalized']===1 && (int)$row['seen_count']===0,'City recommendation reason incorrect.');
    }
    $seenAt=array_search($id['A'],$order,true);
    discoveryExpect($seenAt===false || ($seenAt>3 && $recommended[$seenAt]['reason']==='Bạn đã xem chỗ ở này gần đây'),'Already-viewed listing outranked new suggestions.');
    foreach (['P','H','D'] as $suffix) { discoveryExpect(!in_array($id[$suffix],$order,true),'Non-public listing recommended: '.$suffix); }
    discoveryExpect(array_intersect(discoveryIds($discovery->recommendations($host,24)),$fixtureIds)===[],'Host was recommended own listings.');
    $fallback=$discovery->recommendations($fresh,24);
    discoveryExpect($fallback!==[],'No fallback recommendations.');
    $previous=INF;
    foreach ($fallback as $row) {
        discoveryExpect((int)$row['personalized']===0 && in_array($row['reason'],['Được khách đánh giá cao','Nhiều người đang quan tâm','Chỗ ở nổi bật trên Home2Home'],true),'Fallback recommendation claimed personalization.');
        discoveryExpect((float)$row['score']<=$previous,'Fallback recommendations not ordered by score.');
        $previous=(float)$row['score'];
    }
    discoveryExpect($discovery->recordSearch($searcher,'home',['location'=>$beta]),'Search not recorded.');
    $bySearch=$discovery->recommendations($searcher,4);
    discoveryExpect((int)$bySearch[0]['id']===$id['M'] && $bySearch[0]['reason']==='Vì bạn đã tìm kiếm “'.$beta.'”','Searched place not recommended first.');
    discoveryRejects([$discovery,'recommendations'],[$guest,0],'Zero recommendation limit accepted.');
    discoveryRejects([$discovery,'recommendations'],[$guest,25],'Oversized recommendation limit accepted.');
    echo "PASS discovery recommendations: viewed-city ranking, seen ranked lower, own/non-public excluded, searched place, rating fallback without history\n";

    // Search history: empty searches skipped, repeats refreshed, invalid rejected, trimmed to the latest 20.
    discoveryExpect(!$discovery->recordSearch($fresh,'home',['guests'=>1]),'Empty search stored.');
    discoveryExpect(discoveryCall('CALL sp_test_discovery_searches(?)',[$fresh])===[],'Empty search left a row.');
    discoveryExpect($discovery->recordSearch($fresh,'map',['location'=>'Đà Lạt','guests'=>2]),'Place search not stored.');
    $before=discoveryCall('CALL sp_test_discovery_searches(?)',[$fresh]);
    discoveryExpect($discovery->recordSearch($fresh,'home',['location'=>'Đà Lạt','guests'=>2]),'Repeated search not refreshed.');
    $after=discoveryCall('CALL sp_test_discovery_searches(?)',[$fresh]);
    discoveryExpect(count($after)===1 && $after[0]['id']===$before[0]['id'] && $after[0]['source']==='home' && $after[0]['location']==='Đà Lạt' && (int)$after[0]['guest_count']===2,'Repeated search duplicated.');
    discoveryRejects([$discovery,'recordSearch'],[$fresh,'admin',['location'=>'Đà Lạt']],'Unknown search source accepted.','Điều kiện tìm kiếm');
    discoveryRejects([$discovery,'recordSearch'],[$fresh,'home',['min_price'=>500,'max_price'=>100]],'Inverted price search stored.','Điều kiện tìm kiếm');
    discoveryRejects([$discovery,'recordSearch'],[$fresh,'home',['check_in'=>$stayOut,'check_out'=>$stayIn]],'Inverted dates stored.','Điều kiện tìm kiếm');
    discoveryRejects([$discovery,'recordSearch'],[$fresh,'home',['type'=>PHP_INT_MAX]],'Unknown property type stored.','Điều kiện tìm kiếm');
    discoveryRejects([$discovery,'recordSearch'],[PHP_INT_MAX,'home',['location'=>'Đà Lạt']],'Search stored for an unknown account.','Tài khoản');
    for ($i=1; $i<=24; $i++) { $discovery->recordSearch($fresh,'home',['location'=>'Discovery trim '.$i,'check_in'=>$stayIn,'check_out'=>$stayOut]); }
    $trimmed=discoveryCall('CALL sp_test_discovery_searches(?)',[$fresh]);
    discoveryExpect(count($trimmed)===20 && $trimmed[0]['location']==='Discovery trim 24' && $trimmed[19]['location']==='Discovery trim 5' && $trimmed[0]['check_in']===$stayIn,'Search history not trimmed to the latest 20.');
    echo "PASS discovery search history: default-only searches skipped, latest repeat refreshed, invalid input rejected, kept to latest 20\n";

    // Privacy: each account sees and clears only its own history.
    $summary=$discovery->historySummary($guest);
    discoveryExpect((int)$summary['viewed_listings']===1 && (int)$summary['total_views']===2 && (int)$summary['searches']===0 && $summary['last_viewed_at']!==null,'Guest history summary incorrect.');
    discoveryExpect((int)$discovery->historySummary($fresh)['viewed_listings']===0 && (int)$discovery->historySummary($fresh)['searches']===20,'Other account summary incorrect.');
    $cleared=$discovery->clearHistory($fresh);
    discoveryExpect((int)$cleared['views_cleared']===0 && (int)$cleared['searches_cleared']===20,'Clear count incorrect.');
    discoveryExpect(count(discoveryCall('CALL sp_test_discovery_views(?)',[$guest]))===1 && count(discoveryCall('CALL sp_test_discovery_searches(?)',[$searcher]))===1,'Clearing one account touched another account.');
    $cleared=$discovery->clearHistory($guest);
    discoveryExpect((int)$cleared['views_cleared']===1 && (int)$cleared['searches_cleared']===0,'Guest clear count incorrect.');
    $summary=$discovery->historySummary($guest);
    discoveryExpect((int)$summary['viewed_listings']===0 && (int)$summary['searches']===0 && $summary['last_viewed_at']===null,'History not empty after clearing.');
    discoveryExpect((int)$discovery->recommendations($guest,4)[0]['personalized']===0 && $discovery->recentViews($guest,8)===[],'Recommendations still personalized after clearing.');
    discoveryRejects([$discovery,'clearHistory'],[PHP_INT_MAX],'Clear accepted an unknown account.','Tài khoản');
    discoveryRejects([$discovery,'historySummary'],[PHP_INT_MAX],'Summary accepted an unknown account.','Tài khoản');
    echo "PASS discovery privacy: summaries per account, clear removes only the caller's views and searches, recommendations reset\n";

    $baseUrl=rtrim((string)getenv('HOME2HOME_TEST_URL'),'/');
    if ($baseUrl==='') {
        echo "PASS discovery HTTP checks skipped (set HOME2HOME_TEST_URL to run them)\n";
    } else {
        $curl=curl_init();
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]);
        $page=discoveryRequest($curl,$baseUrl,'/map');
        discoveryExpect($page['status']===200 && str_contains($page['body'],'Tìm chỗ ở trên bản đồ') && str_contains($page['body'],'data-discovery-map="search"') && str_contains($page['body'],'js/map.js'),'Map page failed.');
        $located=discoveryRequest($curl,$baseUrl,'/map?'.http_build_query(['location'=>$alpha]));
        discoveryExpect($located['status']===200 && str_contains($located['body'],'Discovery test '.$marker.' B') && !str_contains($located['body'],'Discovery test '.$marker.' P'),'Map page place search failed.');
        $script=discoveryRequest($curl,$baseUrl,'/assets/js/map.js');
        discoveryExpect($script['status']===200 && str_contains($script['body'],'OpenStreetMap contributors') && !str_contains($script['body'],'=>'),'Map script missing attribution or uses arrow functions.');
        $api=discoveryJson(discoveryRequest($curl,$baseUrl,'/api/listings/map?'.http_build_query(['north'=>'-39.9','south'=>'-40.2','east'=>'-139.9','west'=>'-140.1'])));
        discoveryExpect($api['success']===true && $api['data']['total']===3 && $api['data']['limit']===100 && array_column($api['data']['listings'],'id')===[$id['E'],$id['B'],$id['A']],'Map API box result incorrect.');
        discoveryExpect(array_keys($api['data']['listings'][0])===['id','title','city','property_type','max_guests','price','price_label','rating','review_count','latitude','longitude','photo','url'],'Map API item shape incorrect.');
        $api=discoveryJson(discoveryRequest($curl,$baseUrl,'/api/listings/map?'.http_build_query(['north'=>'-39','south'=>'-41','east'=>'-179','west'=>'179','type'=>2])));
        discoveryExpect(array_column($api['data']['listings'],'id')===[$id['M']],'Map API antimeridian/type result incorrect.');
        $badQueries=[['north'=>'-39.9'],['north'=>'abc','south'=>'-40.2','east'=>'-139.9','west'=>'-140.1'],['north'=>'-41','south'=>'-40.2','east'=>'-139.9','west'=>'-140.1'],
            ['north'=>'-39.9','south'=>'-40.2','east'=>'-139.9','west'=>'-140.1','guests'=>'-2'],['north'=>'-39.9','south'=>'-40.2','east'=>'-139.9','west'=>'-140.1','min_price'=>'9','max_price'=>'1']];
        foreach ($badQueries as $query) {
            $response=discoveryRequest($curl,$baseUrl,'/api/listings/map?'.http_build_query($query));
            $decoded=discoveryJson($response);
            discoveryExpect($response['status']===422 && $decoded['success']===false && $decoded['errors']!==[] && $decoded['message']!=='','Bad map query not rejected with 422: '.http_build_query($query));
        }
        echo "PASS discovery HTTP map page, place search, script attribution, JSON box/antimeridian/type results and 422 validation\n";

        $login=discoveryRequest($curl,$baseUrl,'/login');
        $signedIn=discoveryRequest($curl,$baseUrl,'/login',['_token'=>discoveryToken($login['body']),'email'=>'discovery-test-'.$marker.'-web@home2home.test','password'=>$secret]);
        discoveryExpect($signedIn['status']===200 && str_contains($signedIn['body'],'Đăng xuất'),'Fixture account could not sign in.');
        discoveryExpect(discoveryRequest($curl,$baseUrl,'/listings/'.$id['A'])['status']===200 && discoveryRequest($curl,$baseUrl,'/listings/'.$id['A'])['status']===200,'Listing detail failed.');
        discoveryExpect(discoveryRequest($curl,$baseUrl,'/listings/'.$id['P'])['status']===404,'Pending listing detail was public.');
        $views=discoveryCall('CALL sp_test_discovery_views(?)',[$web]);
        discoveryExpect(count($views)===1 && (int)$views[0]['listing_id']===$id['A'] && (int)$views[0]['view_count']===2,'Signed-in detail views not recorded.');
        $filtered=discoveryRequest($curl,$baseUrl,'/?'.http_build_query(['location'=>$beta]));
        discoveryExpect($filtered['status']===200 && !str_contains($filtered['body'],'data-discovery-shelf') && str_contains($filtered['body'],'Xem trên bản đồ'),'Filtered home failed, showed the shelf or lost the map link.');
        discoveryExpect(discoveryRequest($curl,$baseUrl,'/map?'.http_build_query(['location'=>$alpha]))['status']===200,'Signed-in map search failed.');
        $searches=discoveryCall('CALL sp_test_discovery_searches(?)',[$web]);
        discoveryExpect(count($searches)===2 && $searches[0]['source']==='map' && $searches[0]['location']===$alpha && $searches[1]['source']==='home' && $searches[1]['location']===$beta,'Home/map searches not recorded.');
        $home=discoveryRequest($curl,$baseUrl,'/');
        discoveryExpect($home['status']===200 && str_contains($home['body'],'data-discovery-shelf') && str_contains($home['body'],'Gợi ý cho bạn')
            && str_contains($home['body'],'Vì bạn đã xem chỗ ở tại '.e($alpha)) && str_contains($home['body'],'Đã xem gần đây'),'Signed-in home recommendations missing.');
        $profile=discoveryRequest($curl,$baseUrl,'/profile');
        discoveryExpect($profile['status']===200 && str_contains($profile['body'],'id="discovery-history"') && str_contains($profile['body'],'Đang lưu <strong>1</strong> chỗ ở đã xem và <strong>2</strong> lần tìm kiếm.'),'Profile history summary incorrect.');
        discoveryRequest($curl,$baseUrl,'/profile/history/clear',['_token'=>'invalid']);
        discoveryExpect(count(discoveryCall('CALL sp_test_discovery_views(?)',[$web]))===1,'History cleared without a valid CSRF token.');
        $clearedPage=discoveryRequest($curl,$baseUrl,'/profile/history/clear',['_token'=>discoveryToken($profile['body'])]);
        discoveryExpect($clearedPage['status']===200 && parse_url($clearedPage['url'],PHP_URL_PATH)==='/profile' && str_contains($clearedPage['body'],'Đã xóa lịch sử xem và tìm kiếm')
            && str_contains($clearedPage['body'],'Chưa có lịch sử xem hoặc tìm kiếm nào được lưu.'),'Clear history page flow failed.');
        discoveryExpect(discoveryCall('CALL sp_test_discovery_views(?)',[$web])===[] && discoveryCall('CALL sp_test_discovery_searches(?)',[$web])===[],'HTTP clear left history rows.');
        discoveryExpect(!str_contains(discoveryRequest($curl,$baseUrl,'/')['body'],'data-discovery-shelf'),'Shelf still shown after clearing history.');
        discoveryExpect(count(discoveryCall('CALL sp_test_discovery_searches(?)',[$searcher]))===1,'HTTP clear touched another account.');
        discoveryRequest($curl,$baseUrl,'/logout',['_token'=>discoveryToken($clearedPage['body'])]);
        $anonymous=discoveryRequest($curl,$baseUrl,'/');
        discoveryExpect(!str_contains($anonymous['body'],'data-discovery-shelf') && !str_contains($anonymous['body'],'id="discovery-history"'),'Anonymous visitor saw personal history.');
        $guarded=discoveryRequest($curl,$baseUrl,'/profile/history/clear',['_token'=>discoveryToken($anonymous['body'])]);
        discoveryExpect(parse_url($guarded['url'],PHP_URL_PATH)==='/login','Anonymous clear was not sent to login.');
        echo "PASS discovery HTTP signed-in views/searches recorded, home recommendations + recent views, profile summary, CSRF-protected clear, anonymous guard\n";
    }
} finally {
    if ($curl!==null) { curl_close($curl); }
    $remaining=discoveryCall('CALL sp_test_discovery_cleanup(?)',[$marker]);
    if ((int)$remaining[0]['remaining_listings']!==0 || (int)$remaining[0]['remaining_users']!==0) {
        throw new RuntimeException('Discovery fixture cleanup incomplete.');
    }
    echo "PASS discovery fixture cleanup by exact marker\n";
}
