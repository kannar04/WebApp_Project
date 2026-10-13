<?php
declare(strict_types=1);
// Nice-to-have trust: identity verification, trusted Host badge, check-in guide.
// Runs on the owned fresh_setup database or a per-workspace dev database; every fixture row is tagged and removed in finally.
require dirname(__DIR__).'/core/bootstrap.php';

use App\Models\Listing;
use App\Models\Review;
use App\Models\Trust;
use App\Models\User;
use App\Services\BookingService;
use Core\ProcedureConnection;

$trustConfig = require dirname(__DIR__).'/config/database.php';
if (PHP_SAPI !== 'cli' || !preg_match('/^(db_home2home_schema_test_[a-f0-9]{12}|db_h2h_ws_[a-z0-9_]+)$/D', $trustConfig['database'])
    || !in_array($trustConfig['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Trust feature suite requires an isolated schema-test or db_h2h_ws_* database on loopback.');
}

function trustExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

/** Calls $operation with $arguments and requires a routine/domain rejection (no closures needed). */
function trustRejects(callable $operation, array $arguments, string $message): void
{
    try { $operation(...$arguments); } catch (DomainException $expected) { return; }
    throw new RuntimeException($message);
}

function trustCall(string $sql, array $parameters = []): array
{
    $statement = ProcedureConnection::connection()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

function trustBadge(Trust $trust, int $userId, ?int $actorId): array
{
    $rows = $trust->badges([$userId], $actorId);
    trustExpect(isset($rows[$userId]), 'Badge row missing for an active account.');
    return $rows[$userId];
}

function trustIsTrusted(Trust $trust, int $hostId): bool
{
    return (int) trustBadge($trust, $hostId, $hostId)['is_trusted_host'] === 1;
}

function trustDates(int $slot): array
{
    $start = (new DateTimeImmutable('today'))->modify('+'.(5100 + 3 * $slot).' days');
    return [$start->format('Y-m-d'), $start->modify('+2 days')->format('Y-m-d')];
}

function trustHttpClient(): CurlHandle
{
    $client = curl_init();
    curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 20]);
    return $client;
}

function trustHttp(CurlHandle $client, string $baseUrl, string $path, ?array $data = null, array $headers = []): array
{
    curl_setopt($client, CURLOPT_URL, $baseUrl.$path);
    curl_setopt($client, CURLOPT_HTTPHEADER, $headers);
    if ($data !== null) {
        curl_setopt($client, CURLOPT_POST, true);
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($data));
    } else {
        curl_setopt($client, CURLOPT_HTTPGET, true);
    }
    $body = curl_exec($client);
    if ($body === false) { throw new RuntimeException('HTTP request failed: '.$path); }
    return ['status' => (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE),
        'path' => (string) parse_url((string) curl_getinfo($client, CURLINFO_EFFECTIVE_URL), PHP_URL_PATH), 'body' => (string) $body];
}

function trustToken(string $html): string
{
    if (!preg_match('/name="_token" value="([^"]+)"/', $html, $match)) { throw new RuntimeException('CSRF token missing from page.'); }
    return html_entity_decode($match[1], ENT_QUOTES);
}

function trustLogin(string $baseUrl, string $email, string $password): CurlHandle
{
    $client = trustHttpClient();
    $page = trustHttp($client, $baseUrl, '/login');
    $response = trustHttp($client, $baseUrl, '/login', ['_token' => trustToken($page['body']), 'email' => $email, 'password' => $password]);
    trustExpect($response['status'] === 200 && $response['path'] !== '/login', 'HTTP login failed for a trust fixture account.');
    return $client;
}

$tag = bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(12));
$users = new User();
$trust = new Trust();
$listings = new Listing();
$reviews = new Review();
$service = new BookingService();
$cleanupNeeded = false;

try {
    $cleanupNeeded = true;
    $emailFor = 'audit-trust-'.$tag.'-%s@home2home.test';
    $hostId = $users->adminCreate(['email' => sprintf($emailFor, 'host'), 'password' => $password, 'full_name' => 'Trust Host '.$tag, 'phone' => ''], ['guest', 'host']);
    $otherHostId = $users->adminCreate(['email' => sprintf($emailFor, 'otherhost'), 'password' => $password, 'full_name' => 'Trust Other Host', 'phone' => ''], ['guest', 'host']);
    $guestId = $users->adminCreate(['email' => sprintf($emailFor, 'guest'), 'password' => $password, 'full_name' => 'Trust Guest', 'phone' => ''], ['guest']);
    $otherGuestId = $users->adminCreate(['email' => sprintf($emailFor, 'otherguest'), 'password' => $password, 'full_name' => 'Trust Other Guest', 'phone' => ''], ['guest']);
    $adminId = $users->adminCreate(['email' => sprintf($emailFor, 'admin'), 'password' => $password, 'full_name' => 'Trust Admin', 'phone' => ''], ['guest', 'admin']);
    $listingData = ['property_type_id' => (int) $listings->types()[0]['id'], 'cancellation_policy_id' => (int) $listings->policies()[0]['id'],
        'title' => 'Audit fixture trust '.$tag.' Đà Lạt', 'address' => 'Test only', 'city' => 'Audit', 'description' => 'Trust feature fixture',
        'nightly_price' => 100000, 'cleaning_fee' => 20000, 'max_guests' => 2, 'room_count' => 1, 'bedroom_count' => 1, 'bed_count' => 1,
        'check_in_time' => '14:00', 'check_out_time' => '11:00'];
    $listingId = $listings->create($hostId, $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$listingId]);

    // E. Identity verification: normalization, validation guards, single pending request.
    trustExpect(Trust::normalizePhone('+84 912-345.678') === '0912345678' && Trust::normalizePhone('84912345678') === '0912345678', 'Phone normalization failed.');
    trustExpect(Trust::normalizeIdNumber('0790 1234 5678') === '079012345678', 'CCCD normalization failed.');
    trustRejects([$trust, 'submitVerification'], [$guestId, 'Nguyễn Văn Tín', '12345', '079012345678'], 'Invalid phone accepted.');
    trustRejects([$trust, 'submitVerification'], [$guestId, 'Nguyễn Văn Tín', '0212345678', '079012345678'], 'Landline-style phone accepted.');
    trustRejects([$trust, 'submitVerification'], [$guestId, 'Nguyễn Văn Tín', '0912345678', '07901234567'], '11-digit CCCD accepted.');
    trustRejects([$trust, 'submitVerification'], [$guestId, 'Nguyễn Văn Tín', '0912345678', '07901234567A'], 'Non-digit CCCD accepted.');
    trustRejects([$trust, 'submitVerification'], [$guestId, 'N', '0912345678', '079012345678'], 'One-letter card name accepted.');
    trustRejects([$trust, 'submitVerification'], [999999999, 'Nguyễn Văn Tín', '0912345678', '079012345678'], 'Unknown account submitted a verification.');
    trustExpect($trust->verificationStatus($guestId) === null, 'Rejected submissions left a verification row.');
    $firstRequest = $trust->submitVerification($guestId, '  Nguyễn Văn Tín ', '+84 912 345 678', '0790 1234 5678');
    trustExpect($firstRequest > 0, 'Verification insert id missing.');
    trustRejects([$trust, 'submitVerification'], [$guestId, 'Nguyễn Văn Tín', '0912345678', '079012345678'], 'Second pending verification accepted.');
    $ownStatus = $trust->verificationStatus($guestId);
    trustExpect($ownStatus['status'] === 'pending' && $ownStatus['phone'] === '0912345678' && $ownStatus['full_name_on_card'] === 'Nguyễn Văn Tín', 'Owner status projection wrong.');
    trustExpect($ownStatus['id_number_masked'] === '********5678' && !in_array('079012345678', array_map('strval', $ownStatus), true), 'Owner view exposed the full CCCD.');
    trustExpect((int) trustBadge($trust, $guestId, null)['is_verified'] === 0, 'Pending request already shows the verified badge.');
    echo "PASS trust identity submit: phone/CCCD normalization, validation guards, one pending request, owner sees masked CCCD only\n";

    // E. Admin review: authorization, privacy masking, audit and in-app notification.
    trustRejects([$trust, 'adminVerifications'], [$guestId, 'pending', ''], 'Guest listed identity verifications.');
    trustRejects([$trust, 'adminVerifications'], [$adminId, 'unknown', ''], 'Invalid status filter accepted.');
    $pending = $trust->adminVerifications($adminId, 'pending', sprintf($emailFor, 'guest'));
    trustExpect(count($pending) === 1 && $pending[0]['id_number'] === '079012345678', 'Admin cannot see the full pending CCCD.');
    trustRejects([$trust, 'decideVerification'], [$firstRequest, $guestId, 'approved', ''], 'Guest decided a verification.');
    trustRejects([$trust, 'decideVerification'], [$firstRequest, $adminId, 'maybe', 'Không rõ'], 'Unknown decision accepted.');
    trustRejects([$trust, 'decideVerification'], [$firstRequest, $adminId, 'rejected', ' '], 'Rejection without a reason accepted.');
    trustRejects([$trust, 'decideVerification'], [999999999, $adminId, 'approved', ''], 'Missing verification decided.');
    $adminRequest = $trust->submitVerification($adminId, 'Trust Admin', '0987654321', '001122334455');
    trustRejects([$trust, 'decideVerification'], [$adminRequest, $adminId, 'approved', ''], 'Admin approved their own verification.');
    $trust->decideVerification($firstRequest, $adminId, 'rejected', 'Ảnh/thông tin CCCD không khớp họ tên.');
    $stored = trustCall('CALL sp_test_trust_verification_inspect(?)', [$firstRequest])[0];
    trustExpect($stored['status'] === 'rejected' && $stored['id_number'] === '********5678' && (int) $stored['reviewed_by'] === $adminId, 'Decision did not mask the stored CCCD.');
    trustExpect((int) $stored['audit_count'] === 1 && !str_contains((string) $stored['audit_summary'], '079012345678') && str_contains((string) $stored['audit_summary'], 'rejected'), 'Admin audit entry missing or leaked the CCCD.');
    trustExpect((int) $stored['notification_count'] === 1, 'Rejected user was not notified in-app.');
    trustRejects([$trust, 'decideVerification'], [$firstRequest, $adminId, 'approved', ''], 'A decided verification was decided again.');
    trustExpect((string) $users->findWithRoles($guestId)['phone'] === '', 'Rejected verification changed the contact phone.');
    $rejectedView = $trust->verificationStatus($guestId);
    trustExpect($rejectedView['status'] === 'rejected' && str_contains((string) $rejectedView['decision_reason'], 'không khớp'), 'Owner cannot see the rejection reason.');
    $history = $trust->adminVerifications($adminId, 'rejected', sprintf($emailFor, 'guest'));
    trustExpect(count($history) === 1 && $history[0]['id_number'] === '********5678', 'Decided history exposed the full CCCD.');
    $secondRequest = $trust->submitVerification($guestId, 'Nguyễn Văn Tín', '0912345678', '079012345678');
    $trust->decideVerification($secondRequest, $adminId, 'approved', '');
    trustExpect($trust->verificationStatus($guestId)['status'] === 'approved', 'Approved status missing.');
    trustExpect((int) trustBadge($trust, $guestId, null)['is_verified'] === 1, 'Verified badge missing after approval.');
    trustExpect($users->findWithRoles($guestId)['phone'] === '0912345678', 'Approved phone did not become the contact phone.');
    trustRejects([$trust, 'submitVerification'], [$guestId, 'Nguyễn Văn Tín', '0912345678', '079012345678'], 'Verified account submitted again.');
    $approved = trustCall('CALL sp_test_trust_verification_inspect(?)', [$secondRequest])[0];
    trustExpect($approved['id_number'] === '********5678' && (int) $approved['notification_count'] === 2 && str_contains((string) $approved['audit_summary'], 'approved'), 'Approval masking/audit/notification failed.');
    echo "PASS trust Admin review: role/decision/reason/self-review guards, CCCD masked on decision, audit without CCCD, notifications, approved phone becomes contact phone, resubmit after rejection only\n";

    // F. Trusted Host badge: reviews (visible only), average, host-side cancellation ratio with the minimum-decided rule.
    trustExpect(!trustIsTrusted($trust, $hostId) && (int) trustBadge($trust, $hostId, null)['review_count'] === 0, 'New Host is already trusted.');
    $stays = [];
    foreach ([400 => 5, 395 => 5, 390 => 4] as $daysAgo => $rating) {
        $stays[$daysAgo] = (int) trustCall('CALL sp_test_trust_completed_booking(?,?,?)', [$listingId, $guestId, $daysAgo])[0]['booking_id'];
        trustExpect($reviews->create($stays[$daysAgo], $guestId, $rating, 'Kỳ nghỉ kiểm thử uy tín'), 'Completed-stay review failed.');
        if ($daysAgo === 395) { trustExpect(!trustIsTrusted($trust, $hostId), 'Host trusted with only two reviews.'); }
    }
    $metrics = trustBadge($trust, $hostId, $hostId);
    trustExpect((int) $metrics['is_trusted_host'] === 1 && (int) $metrics['review_count'] === 3 && abs((float) $metrics['average_rating'] - 4.67) < 0.01, 'Three high reviews did not earn the badge.');
    trustExpect((int) $metrics['min_reviews'] === 3 && (float) $metrics['min_rating'] === 4.5 && (float) $metrics['max_cancel_pct'] === 10.0 && (int) $metrics['window_months'] === 12, 'Threshold columns changed.');
    trustExpect((int) $metrics['decided_requests'] === 0, 'Stays older than 12 months counted as recent requests.');
    trustExpect(trustBadge($trust, $hostId, null)['decided_requests'] === null && trustBadge($trust, $hostId, $guestId)['decided_requests'] === null, 'Cancellation metrics leaked to the public/another user.');
    trustExpect(trustBadge($trust, $hostId, $adminId)['decided_requests'] !== null, 'Admin cannot see Host trust metrics.');
    trustExpect((int) trustBadge($trust, $guestId, null)['is_trusted_host'] === 0, 'Guest-only account received the Host badge.');
    $lowStay = (int) trustCall('CALL sp_test_trust_completed_booking(?,?,?)', [$listingId, $guestId, 385])[0]['booking_id'];
    trustExpect($reviews->create($lowStay, $guestId, 1, 'Đánh giá thấp kiểm thử'), 'Low review failed.');
    trustExpect(!trustIsTrusted($trust, $hostId), 'Average below 4.5 kept the badge.');
    $lowReviewId = 0;
    foreach ($reviews->forHost($hostId) as $review) { if ((int) $review['booking_id'] === $lowStay) { $lowReviewId = (int) $review['id']; } }
    $reviews->moderate($lowReviewId, $adminId, 'hidden');
    trustExpect(trustIsTrusted($trust, $hostId), 'Hidden review still affected the badge.');
    $slot = 0;
    [$checkIn, $checkOut] = trustDates($slot++);
    $rejectedBooking = $service->create($listingId, $otherGuestId, $checkIn, $checkOut, 1);
    $service->hostTransition($rejectedBooking, $hostId, 'rejected');
    trustExpect(trustIsTrusted($trust, $hostId), 'Cancellation rule applied before the minimum decided requests.');
    for ($i = 0; $i < 2; $i++) {
        [$checkIn, $checkOut] = trustDates($slot++);
        $service->hostTransition($service->create($listingId, $otherGuestId, $checkIn, $checkOut, 1), $hostId, 'confirmed');
    }
    $metrics = trustBadge($trust, $hostId, $hostId);
    trustExpect((int) $metrics['decided_requests'] === 3 && (int) $metrics['host_side_cancellations'] === 1 && (int) $metrics['is_trusted_host'] === 0, '1/3 Host rejections kept the badge.');
    for ($i = 0; $i < 6; $i++) {
        [$checkIn, $checkOut] = trustDates($slot++);
        $service->hostTransition($service->create($listingId, $otherGuestId, $checkIn, $checkOut, 1), $hostId, 'confirmed');
    }
    trustExpect(!trustIsTrusted($trust, $hostId), '1/9 (11.1%) Host rejections earned the badge.');
    [$checkIn, $checkOut] = trustDates($slot++);
    $service->hostTransition($service->create($listingId, $otherGuestId, $checkIn, $checkOut, 1), $hostId, 'confirmed');
    $metrics = trustBadge($trust, $hostId, $hostId);
    trustExpect((int) $metrics['decided_requests'] === 10 && abs((float) $metrics['cancel_pct'] - 10.0) < 0.01 && (int) $metrics['is_trusted_host'] === 1, 'Exactly 10% Host rejections did not earn the badge.');
    [$checkIn, $checkOut] = trustDates($slot++);
    $guestCancelled = $service->create($listingId, $otherGuestId, $checkIn, $checkOut, 1);
    $service->cancelByGuest($guestCancelled, $otherGuestId, 'Guest cancellation is not Host-side');
    $metrics = trustBadge($trust, $hostId, $hostId);
    trustExpect((int) $metrics['decided_requests'] === 11 && (int) $metrics['host_side_cancellations'] === 1 && (int) $metrics['is_trusted_host'] === 1, 'Guest cancellation counted against the Host.');
    $reviewBadges = $trust->reviewBadges($listingId);
    trustExpect(count($reviewBadges) === 3 && !in_array(false, $reviewBadges, true), 'Verified reviewer badge missing on visible reviews.');
    echo "PASS trust Host badge: >=3 visible reviews, avg >=4.5, hidden reviews ignored, <3 decided requests skip the cancellation rule, 11.1% fails, 10% passes, guest cancellations ignored, metrics private\n";

    // G. Check-in guide: owner-only editing, entitlement by booking status and participant.
    $guide = ['arrival_instructions' => 'Lấy chìa khóa ở hộp khóa cạnh cổng xanh, đi thang bộ lên tầng 2.', 'door_code' => 'K-4821',
        'wifi_name' => 'H2H-Trust', 'wifi_password' => 'pw-'.$tag, 'note' => 'Giữ yên lặng sau 22:00.'];
    trustRejects([$trust, 'saveGuide'], [$listingId, $otherHostId, $guide], 'Non-owner Host edited the check-in guide.');
    trustRejects([$trust, 'saveGuide'], [$listingId, $guestId, $guide], 'Guest edited the check-in guide.');
    trustRejects([$trust, 'guideForHost'], [$listingId, $otherHostId], 'Non-owner Host read the check-in guide editor.');
    trustRejects([$trust, 'saveGuide'], [$listingId, $hostId, ['arrival_instructions' => 'ngắn'] + $guide], 'Too-short arrival instructions accepted.');
    trustRejects([$trust, 'saveGuide'], [$listingId, $hostId, ['door_code' => str_repeat('9', 61)] + $guide], 'Overlong door code accepted.');
    trustExpect($trust->guideForHost($listingId, $hostId) === null, 'Rejected saves created a guide.');
    $trust->saveGuide($listingId, $hostId, ['door_code' => '0000', 'note' => ''] + $guide);
    $trust->saveGuide($listingId, $hostId, $guide);
    $hostGuide = $trust->guideForHost($listingId, $hostId);
    trustExpect($hostGuide['door_code'] === 'K-4821' && $hostGuide['wifi_password'] === 'pw-'.$tag && $hostGuide['note'] === 'Giữ yên lặng sau 22:00.', 'Guide upsert did not persist the latest values.');
    $trust->saveGuide($listingId, $hostId, ['note' => '   '] + $guide);
    trustExpect($trust->guideForHost($listingId, $hostId)['note'] === null, 'Blank optional field was not cleared.');
    [$checkIn, $checkOut] = trustDates($slot++);
    $stayId = $service->create($listingId, $guestId, $checkIn, $checkOut, 1);
    foreach ([$guestId, $otherGuestId, $hostId, $adminId, $otherHostId] as $actor) {
        trustExpect($trust->guideForBooking($stayId, $actor) === null, 'Guide visible on a pending booking.');
    }
    $service->hostTransition($stayId, $hostId, 'confirmed');
    trustExpect(($trust->guideForBooking($stayId, $guestId)['door_code'] ?? null) === 'K-4821', 'Confirmed Guest cannot read the guide.');
    trustExpect($trust->guideForBooking($stayId, $hostId) !== null && $trust->guideForBooking($stayId, $adminId) !== null, 'Host/Admin cannot read the confirmed guide.');
    trustExpect($trust->guideForBooking($stayId, $otherGuestId) === null && $trust->guideForBooking($stayId, $otherHostId) === null, 'Unrelated account read the guide.');
    trustExpect($trust->guideForBooking($stays[400], $guestId) !== null, 'Completed-stay Guest cannot read the guide.');
    trustExpect($trust->guideForBooking($rejectedBooking, $otherGuestId) === null, 'Rejected booking exposed the guide.');
    trustExpect($trust->guideForBooking(999999999, $guestId) === null, 'Unknown booking returned a guide.');
    echo "PASS trust check-in guide: owner-only edit/read, validation, upsert/clear, pending hidden from everyone, confirmed/completed Guest+Host+Admin only\n";

    $baseUrl = rtrim((string) getenv('HOME2HOME_TEST_URL'), '/');
    if ($baseUrl !== '') {
        $anonymous = trustHttpClient();
        trustExpect(trustHttp($anonymous, $baseUrl, '/admin/verifications')['path'] === '/login', 'Anonymous reached Admin verifications.');
        trustExpect(trustHttp($anonymous, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide')['path'] === '/login', 'Anonymous reached the guide editor.');
        $public = trustHttp($anonymous, $baseUrl, '/listings/'.$listingId);
        trustExpect($public['status'] === 200 && str_contains($public['body'], 'Host uy tín') && str_contains($public['body'], 'Đã xác minh'), 'Public listing lacks Trusted Host / verified reviewer badges.');
        trustExpect(!str_contains($public['body'], 'K-4821') && !str_contains($public['body'], 'pw-'.$tag), 'Public listing leaked check-in secrets.');
        $api = json_decode(trustHttp($anonymous, $baseUrl, '/api/listings?location=Audit')['body'], true);
        $apiRows = [];
        foreach ($api['data']['listings'] ?? [] as $row) { if ((int) $row['id'] === $listingId) { $apiRows[] = $row; } }
        trustExpect(count($apiRows) === 1 && $apiRows[0]['trusted_host'] === true, 'Search API lacks the trusted_host flag.');

        $guestClient = trustLogin($baseUrl, sprintf($emailFor, 'guest'), $password);
        trustExpect(trustHttp($guestClient, $baseUrl, '/admin/verifications')['status'] === 403, 'Guest reached Admin verifications.');
        trustExpect(trustHttp($guestClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide')['status'] === 403, 'Guest reached the guide editor.');
        $confirmedPage = trustHttp($guestClient, $baseUrl, '/bookings/'.$stayId);
        trustExpect($confirmedPage['status'] === 200 && str_contains($confirmedPage['body'], 'K-4821') && str_contains($confirmedPage['body'], 'Hướng dẫn nhận phòng'), 'Confirmed Guest page lacks the guide.');
        [$checkIn, $checkOut] = trustDates($slot++);
        $pendingId = $service->create($listingId, $guestId, $checkIn, $checkOut, 1);
        $pendingPage = trustHttp($guestClient, $baseUrl, '/bookings/'.$pendingId);
        trustExpect($pendingPage['status'] === 200 && !str_contains($pendingPage['body'], 'K-4821') && str_contains($pendingPage['body'], 'sau khi Host xác nhận'), 'Pending Guest page leaked the guide.');
        $profile = trustHttp($guestClient, $baseUrl, '/profile');
        trustExpect(str_contains($profile['body'], 'id="verification"') && str_contains($profile['body'], 'Đã xác minh') && str_contains($profile['body'], '********5678') && !str_contains($profile['body'], '079012345678'), 'Verified profile state wrong.');

        $otherClient = trustLogin($baseUrl, sprintf($emailFor, 'otherguest'), $password);
        trustExpect(trustHttp($otherClient, $baseUrl, '/bookings/'.$stayId)['status'] === 404, 'Other Guest opened a foreign booking.');
        $profile = trustHttp($otherClient, $baseUrl, '/profile');
        $missingToken = trustHttp($otherClient, $baseUrl, '/profile/verification', ['full_name_on_card' => 'Trần Thị Khách', 'phone' => '0933333333', 'id_number' => '123456784321'], ['Accept: application/json']);
        trustExpect($missingToken['status'] === 419 && $trust->verificationStatus($otherGuestId) === null, 'Verification POST without CSRF was accepted.');
        $submitted = trustHttp($otherClient, $baseUrl, '/profile/verification', ['_token' => trustToken($profile['body']), 'full_name_on_card' => 'Trần Thị Khách', 'phone' => '093 333 3333', 'id_number' => '1234 5678 4321']);
        trustExpect($submitted['path'] === '/profile' && str_contains($submitted['body'], 'Đang chờ duyệt') && str_contains($submitted['body'], '********4321') && !str_contains($submitted['body'], '123456784321'), 'HTTP verification submit failed or leaked the CCCD.');
        $invalid = trustHttp($otherClient, $baseUrl, '/profile/verification', ['_token' => trustToken($submitted['body']), 'full_name_on_card' => 'Trần Thị Khách', 'phone' => '0933333333', 'id_number' => '123456784321']);
        trustExpect(str_contains($invalid['body'], 'alert-danger') && str_contains($invalid['body'], 'đang chờ duyệt'), 'Duplicate HTTP submission was not reported.');

        $adminClient = trustLogin($baseUrl, sprintf($emailFor, 'admin'), $password);
        $queue = trustHttp($adminClient, $baseUrl, '/admin/verifications?'.http_build_query(['status' => 'pending', 'q' => sprintf($emailFor, 'otherguest')]));
        trustExpect($queue['status'] === 200 && str_contains($queue['body'], '123456784321'), 'Admin queue lacks the pending CCCD.');
        $otherRequest = (int) $trust->verificationStatus($otherGuestId)['id'];
        $noToken = trustHttp($adminClient, $baseUrl, '/admin/verifications/'.$otherRequest.'/decide', ['decision' => 'approved'], ['Accept: application/json']);
        trustExpect($noToken['status'] === 419 && $trust->verificationStatus($otherGuestId)['status'] === 'pending', 'Admin decision without CSRF was accepted.');
        $decided = trustHttp($adminClient, $baseUrl, '/admin/verifications/'.$otherRequest.'/decide', ['_token' => trustToken($queue['body']), 'decision' => 'approved', 'reason' => '']);
        trustExpect($decided['path'] === '/admin/verifications' && str_contains($decided['body'], 'alert-success') && $trust->verificationStatus($otherGuestId)['status'] === 'approved', 'HTTP Admin approval failed.');
        $historyPage = trustHttp($adminClient, $baseUrl, '/admin/verifications?'.http_build_query(['status' => '', 'q' => sprintf($emailFor, 'otherguest')]));
        trustExpect(str_contains($historyPage['body'], '********4321') && !str_contains($historyPage['body'], '123456784321'), 'Decided Admin history exposed the CCCD.');
        $dashboard = trustHttp($adminClient, $baseUrl, '/admin?'.http_build_query(['q' => 'audit-trust-'.$tag]));
        trustExpect($dashboard['status'] === 200 && str_contains($dashboard['body'], '/admin/verifications') && str_contains($dashboard['body'], 'Host uy tín') && str_contains($dashboard['body'], 'Đã xác minh'), 'Admin user list lacks trust badges.');
        trustExpect(str_contains(trustHttp($adminClient, $baseUrl, '/admin/users/'.$hostId.'/edit')['body'], 'Host uy tín'), 'Admin user edit lacks the Host badge.');
        trustExpect(str_contains(trustHttp($adminClient, $baseUrl, '/bookings/'.$stayId)['body'], 'K-4821'), 'Admin booking detail lacks the guide.');

        $hostClient = trustLogin($baseUrl, sprintf($emailFor, 'host'), $password);
        $hostDashboard = trustHttp($hostClient, $baseUrl, '/host');
        trustExpect(str_contains($hostDashboard['body'], 'Huy hiệu Host uy tín') && str_contains($hostDashboard['body'], '3/3 đánh giá') && str_contains($hostDashboard['body'], '/checkin-guide'), 'Host dashboard lacks trust progress or the guide link.');
        $editor = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide');
        trustExpect($editor['status'] === 200 && str_contains($editor['body'], 'K-4821'), 'Host guide editor unavailable.');
        $saved = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide', ['_token' => trustToken($editor['body'])] + ['door_code' => 'K-9999'] + $guide);
        trustExpect(str_contains($saved['body'], 'alert-success') && $trust->guideForHost($listingId, $hostId)['door_code'] === 'K-9999', 'HTTP guide save failed.');
        $tooShort = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide', ['_token' => trustToken($saved['body']), 'arrival_instructions' => 'ngắn', 'door_code' => 'K-1111']);
        trustExpect(str_contains($tooShort['body'], 'alert-danger') && str_contains($tooShort['body'], 'K-1111') && $trust->guideForHost($listingId, $hostId)['door_code'] === 'K-9999', 'Invalid HTTP guide save changed data or lost input.');
        trustExpect(str_contains(trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/edit')['body'], '/checkin-guide'), 'Listing edit page lacks the guide link.');

        $otherHostClient = trustLogin($baseUrl, sprintf($emailFor, 'otherhost'), $password);
        trustExpect(trustHttp($otherHostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide')['status'] === 404, 'Non-owner Host opened the guide editor.');
        $foreignPage = trustHttp($otherHostClient, $baseUrl, '/host');
        $foreign = trustHttp($otherHostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide', ['_token' => trustToken($foreignPage['body'])] + $guide);
        trustExpect($foreign['path'] === '/host' && str_contains($foreign['body'], 'alert-danger') && $trust->guideForHost($listingId, $hostId)['door_code'] === 'K-9999', 'Non-owner Host saved the guide over HTTP.');
        foreach ([$anonymous, $guestClient, $otherClient, $adminClient, $hostClient, $otherHostClient] as $client) { curl_close($client); }
        echo "PASS trust HTTP: login/403/404 guards, CSRF 419 on verification and decision, profile submit/duplicate, Admin queue+approval+masked history+badges, Host progress/guide save/validation, public pages without secrets\n";
    }
} finally {
    if ($cleanupNeeded) {
        $db = ProcedureConnection::connection();
        if ($db->inTransaction()) { $db->rollBack(); }
        trustCall('CALL sp_test_trust_cleanup(?)', [$tag]);
        trustExpect(!$users->findByEmail(sprintf('audit-trust-'.$tag.'-%s@home2home.test', 'host')), 'Trust fixture cleanup left accounts behind.');
    }
}
echo "PASS trust fixture cleanup: tagged accounts, listing, bookings, reviews, guide, verifications, audits and notifications removed\n";
