<?php
declare(strict_types=1);
// Nice-to-have trust: identity verification, trusted Host badge, check-in guide.
// Runs on the owned fresh_setup database or a per-workspace dev database; every fixture row is tagged and removed in finally.
require dirname(__DIR__).'/core/bootstrap.php';

use App\Models\CheckInReminder;
use App\Models\Listing;
use App\Models\Notification;
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

/** Whether a search result holds the fixture row with this id. */
function trustHasRow(array $rows, string $idColumn, int $id): bool
{
    return in_array((string) $id, array_map('strval', array_column($rows, $idColumn)), true);
}

function trustIsTrusted(Trust $trust, int $hostId): bool
{
    return (int) trustBadge($trust, $hostId, $hostId)['is_trusted_host'] === 1;
}

/** $guide null: the row must carry no door code or Wi-Fi at all. */
function trustCodesAre(?array $row, string $state, ?array $guide): bool
{
    return $row !== null && $row['codes_state'] === $state && $row['door_code'] === ($guide['door_code'] ?? null)
        && $row['wifi_name'] === ($guide['wifi_name'] ?? null) && $row['wifi_password'] === ($guide['wifi_password'] ?? null);
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

/** The Admin dashboard's table row for one listing. */
function trustAdminListingRow(string $page, int $listingId): string
{
    preg_match('~<tr><td class="table-title">(?:(?!</tr>).)*?/admin/listings/'.$listingId.'/preview(?:(?!</tr>).)*</tr>~su', $page, $row);
    return $row[0] ?? '';
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
    trustExpect((string) $users->findWithRoles($guestId)['phone'] === '' && $users->findWithRoles($guestId)['full_name'] === 'Trust Guest', 'Rejected verification changed the contact phone or account name.');
    $rejectedView = $trust->verificationStatus($guestId);
    trustExpect($rejectedView['status'] === 'rejected' && str_contains((string) $rejectedView['decision_reason'], 'không khớp'), 'Owner cannot see the rejection reason.');
    $history = $trust->adminVerifications($adminId, 'rejected', sprintf($emailFor, 'guest'));
    trustExpect(count($history) === 1 && $history[0]['id_number'] === '********5678', 'Decided history exposed the full CCCD.');
    $secondRequest = $trust->submitVerification($guestId, 'Nguyễn Văn Tín', '0912345678', '079012345678');
    $trust->decideVerification($secondRequest, $adminId, 'approved', '');
    trustExpect($trust->verificationStatus($guestId)['status'] === 'approved', 'Approved status missing.');
    trustExpect((int) trustBadge($trust, $guestId, null)['is_verified'] === 1, 'Verified badge missing after approval.');
    $account = $users->findWithRoles($guestId);
    trustExpect($account['phone'] === '0912345678' && $account['full_name'] === 'Nguyễn Văn Tín', 'Approved phone and card name did not become the contact phone and account name.');
    trustRejects([$trust, 'submitVerification'], [$guestId, 'Nguyễn Văn Tín', '0912345678', '079012345678'], 'Verified account submitted again.');
    $approved = trustCall('CALL sp_test_trust_verification_inspect(?)', [$secondRequest])[0];
    trustExpect($approved['id_number'] === '********5678' && (int) $approved['notification_count'] === 2 && str_contains((string) $approved['audit_summary'], 'approved'), 'Approval masking/audit/notification failed.');
    // The badge holds only while the profile phone is the verified one; the same number written another way still counts.
    $users->updateProfile($guestId, 'Nguyễn Văn Tín', '+84 912-345-678', null);
    trustExpect((int) trustBadge($trust, $guestId, null)['is_verified'] === 1 && (int) $trust->verificationStatus($guestId)['is_current'] === 1, 'Reformatted verified phone hid the badge.');
    $users->updateProfile($guestId, 'Nguyễn Văn Tín', '0911111111', null);
    $staleView = $trust->verificationStatus($guestId);
    trustExpect((int) trustBadge($trust, $guestId, null)['is_verified'] === 0 && $staleView['status'] === 'approved' && (int) $staleView['is_current'] === 0, 'Badge kept after the profile phone changed.');
    $users->updateProfile($guestId, 'Nguyễn Văn Tín', '0912 345 678', null);
    trustExpect((int) trustBadge($trust, $guestId, null)['is_verified'] === 1, 'Badge did not return with the verified phone.');
    $users->adminUpdate($guestId, ['email' => sprintf($emailFor, 'guest'), 'full_name' => 'Nguyễn Văn Tín', 'phone' => '0922222222', 'password' => '']);
    trustExpect((int) trustBadge($trust, $guestId, null)['is_verified'] === 0, 'Badge kept after an Admin changed the phone.');
    $thirdRequest = $trust->submitVerification($guestId, 'Nguyễn Văn Tín', '0922222222', '079012345678');
    trustExpect($trust->verificationStatus($guestId)['status'] === 'pending', 'New request after a phone change is not the one shown.');
    $trust->decideVerification($thirdRequest, $adminId, 'approved', '');
    trustExpect((int) trustBadge($trust, $guestId, null)['is_verified'] === 1 && $users->findWithRoles($guestId)['phone'] === '0922222222', 'Verifying the new phone did not restore the badge.');
    echo "PASS trust Admin review: role/decision/reason/self-review guards, CCCD masked on decision, audit without CCCD, notifications, approved phone and card name become contact phone and account name, resubmit after rejection only\n";
    echo "PASS trust badge sync: reformatted phone keeps the badge, Guest or Admin phone change hides it, old phone restores it, new number can be verified\n";

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
    // Only what the Host did counts: a request closed by an Admin or automatically is neither a Host refusal nor a Host decision.
    $moderation = new \App\Models\AdminRepository();
    [$checkIn, $checkOut] = trustDates($slot++);
    $service->adminTransition($service->create($listingId, $otherGuestId, $checkIn, $checkOut, 1), $adminId, 'rejected');
    $departingGuest = $users->adminCreate(['email' => sprintf($emailFor, 'departing'), 'password' => bin2hex(random_bytes(16)), 'full_name' => 'Trust Departing Guest', 'phone' => ''], ['guest']);
    [$checkIn, $checkOut] = trustDates($slot++);
    $service->create($listingId, $departingGuest, $checkIn, $checkOut, 1);
    $users->softDelete($departingGuest);
    $moderatedListing = $listings->create($hostId, ['title' => 'Audit fixture trust '.$tag.' moderated'] + $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$moderatedListing]);
    [$checkIn, $checkOut] = trustDates($slot++);
    $service->create($moderatedListing, $otherGuestId, $checkIn, $checkOut, 1);
    $moderation->moderate($moderatedListing, $adminId, 'rejected', 'Trust rate fixture');
    $takenDownListing = $listings->create($hostId, ['title' => 'Audit fixture trust '.$tag.' taken down'] + $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$takenDownListing]);
    [$checkIn, $checkOut] = trustDates($slot++);
    $service->create($takenDownListing, $otherGuestId, $checkIn, $checkOut, 1);
    $moderation->softDeleteListing($takenDownListing, $adminId);
    $metrics = trustBadge($trust, $hostId, $hostId);
    trustExpect((int) $metrics['decided_requests'] === 11 && (int) $metrics['host_side_cancellations'] === 1 && abs((float) $metrics['cancel_pct'] - 9.1) < 0.01 && (int) $metrics['is_trusted_host'] === 1, 'A rejection the Host did not make changed the Host rate.');
    trustExpect((int) trustBadge($trust, $hostId, null)['is_trusted_host'] === 1 && (int) trustBadge($trust, $hostId, $adminId)['host_side_cancellations'] === 1, 'The public badge or the Admin figure disagrees with the Host rate.');
    $reviewBadges = $trust->reviewBadges($listingId);
    trustExpect(count($reviewBadges) === 3 && !in_array(false, $reviewBadges, true), 'Verified reviewer badge missing on visible reviews.');
    $users->updateProfile($guestId, 'Nguyễn Văn Tín', '0933000000', null);
    trustExpect(!in_array(true, $trust->reviewBadges($listingId), true), 'Reviewer badge kept after the reviewer changed phone.');
    $users->updateProfile($guestId, 'Nguyễn Văn Tín', '0922222222', null);
    echo "PASS trust Host badge: >=3 visible reviews, avg >=4.5, hidden reviews ignored, <3 decided requests skip the cancellation rule, 11.1% fails, 10% passes, guest cancellations ignored, metrics private\n";

    // Eleven reviews averaging 4.4545: the rule stays exact, and the figure beside it does not round up to the minimum.
    $nearEmail = sprintf($emailFor, 'near');
    $nearHost = $users->adminCreate(['email' => $nearEmail, 'password' => $password, 'full_name' => 'Trust Near Host', 'phone' => ''], ['guest', 'host']);
    $nearListing = $listings->create($nearHost, ['title' => 'Audit fixture trust '.$tag.' near'] + $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$nearListing]);
    foreach ([4, 4, 4, 4, 4, 4, 5, 5, 5, 5, 5] as $index => $rating) {
        $nearStay = (int) trustCall('CALL sp_test_trust_completed_booking(?,?,?)', [$nearListing, $guestId, 400 + 3 * $index])[0]['booking_id'];
        trustExpect($reviews->create($nearStay, $guestId, $rating, 'Kỳ nghỉ kiểm thử gần ngưỡng'), 'Near-threshold review failed.');
    }
    $nearMetrics = trustBadge($trust, $nearHost, $nearHost);
    trustExpect((int) $nearMetrics['review_count'] === 11 && (int) $nearMetrics['rating_met'] === 0 && (int) $nearMetrics['is_trusted_host'] === 0, 'An average of 4.4545 met the 4.5 minimum.');
    trustExpect(display_trust_rating($nearMetrics) === '4,45', 'An average just under the minimum still rounds up to it.');
    // Two decimals rounded to the nearest would read 4,50 at 4.496, so they round down; everywhere else one decimal is enough.
    trustExpect(display_trust_rating(['average_rating' => 4.5, 'average_rating_floor' => 4.49, 'rating_met' => 0, 'min_rating' => 4.5]) === '4,49', 'A two-decimal average just under the minimum reads as the minimum.');
    trustExpect(display_trust_rating(['average_rating' => 4.5, 'average_rating_floor' => 4.5, 'rating_met' => 1, 'min_rating' => 4.5]) === '4,5', 'An average at the minimum changed format.');
    trustExpect(display_trust_rating(['average_rating' => 3.24, 'average_rating_floor' => 3.24, 'rating_met' => 0, 'min_rating' => 4.5]) === '3,2' && display_trust_rating(['average_rating' => 4.96, 'average_rating_floor' => 4.96, 'rating_met' => 1, 'min_rating' => 4.5]) === '5,0', 'An average far from the minimum changed format.');
    echo "PASS trust rating near the minimum: the rule stays exact and the panel never reads 4,5 against a 4,5 minimum as unmet\n";

    // 21 Host refusals in 209 decided requests is 10.05%, the fewest requests where one decimal reads 10,0: the rule stays exact, and the figure beside it does not round down to the maximum.
    $nearRateEmail = sprintf($emailFor, 'nearrate');
    $nearRateHost = $users->adminCreate(['email' => $nearRateEmail, 'password' => $password, 'full_name' => 'Trust Rate Host', 'phone' => ''], ['guest', 'host']);
    $nearRateListing = $listings->create($nearRateHost, ['title' => 'Audit fixture trust '.$tag.' near rate'] + $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$nearRateListing]);
    for ($i = 0; $i < 21; $i++) {
        [$checkIn, $checkOut] = trustDates($slot++);
        $service->hostTransition($service->create($nearRateListing, $otherGuestId, $checkIn, $checkOut, 1), $nearRateHost, 'rejected');
    }
    for ($i = 0; $i < 188; $i++) {
        trustCall('CALL sp_test_trust_completed_booking(?,?,?)', [$nearRateListing, $guestId, 10 + $i]);
    }
    $nearRateMetrics = trustBadge($trust, $nearRateHost, $nearRateHost);
    trustExpect((int) $nearRateMetrics['decided_requests'] === 209 && (int) $nearRateMetrics['host_side_cancellations'] === 21 && abs((float) $nearRateMetrics['cancel_pct'] - 10.0) < 0.01 && (int) $nearRateMetrics['cancel_met'] === 0, 'A rate of 10.05% met the 10% maximum.');
    trustExpect(display_trust_cancel($nearRateMetrics) === '10,05', 'A rate just over the maximum still rounds down to it.');
    trustExpect(trustBadge($trust, $nearRateHost, null)['cancel_met'] === null && trustBadge($trust, $nearRateHost, null)['cancel_pct_ceil'] === null && trustBadge($trust, $nearRateHost, $otherHostId)['cancel_pct_ceil'] === null, 'The cancellation figures reached someone other than the Host or an Admin.');
    trustExpect(trustBadge($trust, $nearRateHost, $adminId)['cancel_pct_ceil'] !== null, 'The Admin lost the cancellation figure.');
    // Two decimals rounded to the nearest would read 10,00 at 10.004, so they round up; everywhere else one decimal is enough.
    trustExpect(display_trust_cancel(['cancel_pct' => 10.0, 'cancel_pct_ceil' => 10.01, 'cancel_met' => 0, 'max_cancel_pct' => 10.0]) === '10,01', 'A two-decimal rate just over the maximum reads as the maximum.');
    trustExpect(display_trust_cancel(['cancel_pct' => 10.0, 'cancel_pct_ceil' => 10.0, 'cancel_met' => 1, 'max_cancel_pct' => 10.0]) === '10,0', 'A rate at the maximum changed format.');
    trustExpect(display_trust_cancel(['cancel_pct' => 33.3, 'cancel_pct_ceil' => 33.34, 'cancel_met' => 0, 'max_cancel_pct' => 10.0]) === '33,3' && display_trust_cancel(['cancel_pct' => 9.1, 'cancel_pct_ceil' => 9.1, 'cancel_met' => 1, 'max_cancel_pct' => 10.0]) === '9,1', 'A rate far from the maximum changed format.');
    echo "PASS trust rate near the maximum: the rule stays exact and the panel never reads 10,0 against a 10 maximum as met\n";

    // Closing a request whose check-in has passed is not a refusal: Accept is already impossible and Reject is the only tidy-up the site offers.
    $rateHost = $users->adminCreate(['email' => sprintf($emailFor, 'rate'), 'password' => $password, 'full_name' => 'Trust Rate Host', 'phone' => ''], ['guest', 'host']);
    $rateListing = $listings->create($rateHost, ['title' => 'Audit fixture trust '.$tag.' rate'] + $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$rateListing]);
    $rateReviewed = $listings->create($rateHost, ['title' => 'Audit fixture trust '.$tag.' rate reviewed'] + $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$rateReviewed]);
    foreach ([400, 395, 390] as $daysAgo) {
        $rateStay = (int) trustCall('CALL sp_test_trust_completed_booking(?,?,?)', [$rateReviewed, $guestId, $daysAgo])[0]['booking_id'];
        trustExpect($reviews->create($rateStay, $guestId, 5, 'Kỳ nghỉ kiểm thử tỷ lệ từ chối'), 'Rate fixture review failed.');
    }
    for ($i = 0; $i < 3; $i++) {
        [$checkIn, $checkOut] = trustDates($slot++);
        $service->hostTransition($service->create($rateListing, $otherGuestId, $checkIn, $checkOut, 1), $rateHost, 'confirmed');
    }
    $metrics = trustBadge($trust, $rateHost, $rateHost);
    trustExpect((int) $metrics['decided_requests'] === 3 && (int) $metrics['host_side_cancellations'] === 0 && (int) $metrics['is_trusted_host'] === 1, 'The rate fixture Host is not trusted.');
    [$checkIn, $checkOut] = trustDates($slot++);
    $expiredRequest = $service->create($rateListing, $otherGuestId, $checkIn, $checkOut, 1);
    trustCall('CALL sp_test_trust_move_booking(?,?)', [$expiredRequest, -1]);
    trustRejects([$service, 'hostTransition'], [$expiredRequest, $rateHost, 'confirmed'], 'A request whose check-in passed could be accepted.');
    $service->hostTransition($expiredRequest, $rateHost, 'rejected');
    $metrics = trustBadge($trust, $rateHost, $rateHost);
    trustExpect((int) $metrics['decided_requests'] === 3 && (int) $metrics['host_side_cancellations'] === 0 && (int) $metrics['is_trusted_host'] === 1, 'Closing a request whose check-in passed cost the Host the badge.');
    // Check-in today can still be accepted, so rejecting it is a refusal.
    [$checkIn, $checkOut] = trustDates($slot++);
    $todayRequest = $service->create($rateListing, $otherGuestId, $checkIn, $checkOut, 1);
    trustCall('CALL sp_test_trust_move_booking(?,?)', [$todayRequest, 0]);
    $service->hostTransition($todayRequest, $rateHost, 'rejected');
    $metrics = trustBadge($trust, $rateHost, $rateHost);
    trustExpect((int) $metrics['decided_requests'] === 4 && (int) $metrics['host_side_cancellations'] === 1 && (int) $metrics['is_trusted_host'] === 0, 'Rejecting on the check-in day did not count as a refusal.');
    // A refusal ahead of the dates counts, as it always did.
    [$checkIn, $checkOut] = trustDates($slot++);
    $service->hostTransition($service->create($rateListing, $otherGuestId, $checkIn, $checkOut, 1), $rateHost, 'rejected');
    $metrics = trustBadge($trust, $rateHost, $rateHost);
    trustExpect((int) $metrics['decided_requests'] === 5 && (int) $metrics['host_side_cancellations'] === 2, 'A refusal before the check-in day did not count.');
    echo "PASS trust rate: closing a request whose check-in passed counts nothing; rejecting on or before the check-in day still counts\n";

    // The badge reads only reviews someone can see: a listing taken down takes its reviews out of the count and the average.
    $metrics = trustBadge($trust, $rateHost, $rateHost);
    trustExpect((int) $metrics['review_count'] === 3 && count($trust->reviewBadges($rateReviewed)) === 3, 'The rate fixture lost its reviews.');
    (new \App\Models\AdminRepository())->softDeleteListing($rateReviewed, $adminId);
    $metrics = trustBadge($trust, $rateHost, $rateHost);
    trustExpect((int) $metrics['review_count'] === 0 && (float) $metrics['average_rating'] === 0.0 && (int) $metrics['rating_met'] === 0 && (int) $metrics['is_trusted_host'] === 0 && $trust->reviewBadges($rateReviewed) === [], 'Reviews on a taken-down listing still count toward the badge.');
    echo "PASS trust reviews: a listing taken down takes its reviews out of the badge, as sp_trust_review_badges does\n";

    // The Admin's preview reads the reviews the public page shows, and keeps them while the listing is hidden, so the Admin can judge it.
    $publicReviews = $listings->findPublic($listingId)['reviews'];
    $previewReviews = $listings->adminPreview($listingId, $adminId)['reviews'];
    trustExpect(count($publicReviews) === 3 && array_column($previewReviews, 'id') === array_column($publicReviews, 'id') && array_column($previewReviews, 'rating') === array_column($publicReviews, 'rating') && array_column($previewReviews, 'guest_name') === array_column($publicReviews, 'guest_name'), 'The Admin preview and the public page disagree about the reviews.');
    $moderation->setListingVisibility($listingId, $adminId, false);
    trustExpect(array_column($listings->adminPreview($listingId, $adminId)['reviews'], 'id') === array_column($publicReviews, 'id'), 'The Admin preview lost the reviews of a hidden listing.');
    $moderation->setListingVisibility($listingId, $adminId, true);
    trustExpect($listings->findPublic($listingId) !== null, 'The listing did not come back after the preview check.');
    trustRejects('trustCall', ['CALL sp_listing_reviews_for_admin(?,?)', [$listingId, $guestId]], 'A Guest read the Admin review list.');
    echo "PASS Admin preview shows the same reviews as the public page, also while the listing is hidden, and only to an Admin\n";


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
    // Confirmed but far from check-in: the guide text only; the codes wait for their window and the Guest is told when.
    $earlyGuide = $trust->guideForBooking($stayId, $guestId);
    trustExpect(($earlyGuide['arrival_instructions'] ?? null) === $guide['arrival_instructions'] && $earlyGuide['note'] === null, 'Confirmed Guest cannot read the guide text.');
    trustExpect(trustCodesAre($earlyGuide, 'upcoming', null) && $earlyGuide['codes_from'] === (new DateTimeImmutable($checkIn))->modify('-1 day')->format('Y-m-d'), 'Guest got door code or Wi-Fi before the window.');
    trustExpect(trustCodesAre($trust->guideForBooking($stayId, $adminId), 'upcoming', null), 'Admin got door code or Wi-Fi before the window.');
    trustExpect(trustCodesAre($trust->guideForBooking($stayId, $hostId), 'open', $guide), 'The listing Host lost the guide they wrote.');
    trustExpect($trust->guideForBooking($stayId, $otherGuestId) === null && $trust->guideForBooking($stayId, $otherHostId) === null, 'Unrelated account read the guide.');
    // The window opens as far ahead as the check-in reminder and closes after the check-out date (fixture stay: 2 nights).
    [$checkIn, $checkOut] = trustDates($slot++);
    $windowStay = $service->create($listingId, $guestId, $checkIn, $checkOut, 1);
    $service->hostTransition($windowStay, $hostId, 'confirmed');
    $lead = CheckInReminder::DEFAULT_DAYS;
    foreach ([$lead + 1 => 'upcoming', $lead => 'open', 0 => 'open', -1 => 'open', -2 => 'open', -3 => 'ended'] as $offset => $state) {
        trustCall('CALL sp_test_trust_move_booking(?,?)', [$windowStay, $offset]);
        $expected = $state === 'open' ? $guide : null;
        trustExpect(trustCodesAre($trust->guideForBooking($windowStay, $guestId), $state, $expected), 'Guest window wrong '.$offset.' days from check-in.');
        trustExpect(trustCodesAre($trust->guideForBooking($windowStay, $adminId), $state, $expected), 'Admin window wrong '.$offset.' days from check-in.');
        trustExpect(trustCodesAre($trust->guideForBooking($windowStay, $hostId), 'open', $guide), 'Host lost the codes '.$offset.' days from check-in.');
    }
    // Completing on the check-out date ends the codes even though the date is still inside the window.
    trustCall('CALL sp_test_trust_move_booking(?,?)', [$windowStay, -2]);
    $service->hostTransition($windowStay, $hostId, 'completed');
    trustExpect(trustCodesAre($trust->guideForBooking($windowStay, $guestId), 'ended', null), 'Completed stay kept door code or Wi-Fi.');
    trustExpect(trustCodesAre($trust->guideForBooking($stays[400], $guestId), 'ended', null), 'Old completed stay kept door code or Wi-Fi.');
    trustExpect(trustCodesAre($trust->guideForBooking($stays[400], $hostId), 'open', $guide), 'Host lost the guide on a completed stay.');
    // Cancelling inside the window takes the guide away, as for the instant-book guest who cancels after reading it.
    [$checkIn, $checkOut] = trustDates($slot++);
    $cancelledStay = $service->create($listingId, $guestId, $checkIn, $checkOut, 1);
    $service->hostTransition($cancelledStay, $hostId, 'confirmed');
    trustCall('CALL sp_test_trust_move_booking(?,?)', [$cancelledStay, 0]);
    trustExpect(trustCodesAre($trust->guideForBooking($cancelledStay, $guestId), 'open', $guide), 'Guest cannot read the codes on check-in day.');
    $service->cancelByGuest($cancelledStay, $guestId, 'Trust test');
    trustExpect($trust->guideForBooking($cancelledStay, $guestId) === null && $trust->guideForBooking($cancelledStay, $adminId) === null, 'Cancelled booking exposed the guide.');
    // A guide without codes promises nothing, and no notification ever repeats a code.
    $otherListingId = $listings->create($hostId, ['title' => 'Audit fixture trust '.$tag.' no codes'] + $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$otherListingId]);
    $trust->saveGuide($otherListingId, $hostId, ['door_code' => '', 'wifi_name' => '', 'wifi_password' => ''] + $guide);
    [$checkIn, $checkOut] = trustDates($slot++);
    $noCodesStay = $service->create($otherListingId, $guestId, $checkIn, $checkOut, 1);
    $service->hostTransition($noCodesStay, $hostId, 'confirmed');
    trustExpect(($trust->guideForBooking($noCodesStay, $guestId)['codes_state'] ?? null) === 'none', 'Guide without codes promised codes.');
    (new CheckInReminder())->run($lead, null);
    foreach ([$guestId, $hostId] as $recipient) {
        foreach ((new Notification())->forUser($recipient) as $notification) {
            trustExpect(!str_contains((string) $notification['body'], 'K-4821') && !str_contains((string) $notification['body'], 'pw-'.$tag), 'A notification carried a door code or Wi-Fi password.');
        }
    }
    trustExpect($trust->guideForBooking($rejectedBooking, $otherGuestId) === null, 'Rejected booking exposed the guide.');
    trustExpect($trust->guideForBooking(999999999, $guestId) === null, 'Unknown booking returned a guide.');
    echo "PASS trust check-in guide: owner-only edit/read, validation, upsert/clear, pending hidden from everyone, confirmed/completed Guest+Host+Admin only, door code and Wi-Fi only inside the stay window (Host always)\n";

    // Review comment length: the routine refuses over 5000 characters instead of cutting at 64 KB; on another Host so the badge counts above stay as they are.
    $reviewListing = $listings->create($otherHostId, ['title' => 'Audit fixture trust '.$tag.' reviews'] + $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$reviewListing]);
    $reviewStay = (int) trustCall('CALL sp_test_trust_completed_booking(?,?,?)', [$reviewListing, $guestId, 10])[0]['booking_id'];
    trustRejects([$reviews, 'create'], [$reviewStay, $guestId, 5, str_repeat('a', Review::MAX_COMMENT_LENGTH + 1)], 'Review comment over the limit accepted.');
    trustRejects([$reviews, 'create'], [$reviewStay, $guestId, 5, str_repeat('ệ', 30000)], 'Review comment over 64 KB accepted.');
    trustExpect($reviews->create($reviewStay, $guestId, 5, str_repeat('ệ', Review::MAX_COMMENT_LENGTH)), 'Review comment exactly at the limit refused.');
    $storedComment = '';
    foreach ($reviews->forHost($otherHostId) as $review) { if ((int) $review['booking_id'] === $reviewStay) { $storedComment = (string) $review['comment']; } }
    trustExpect($storedComment === str_repeat('ệ', Review::MAX_COMMENT_LENGTH), 'Review comment at the limit was not stored whole.');
    $httpReviewStay = (int) trustCall('CALL sp_test_trust_completed_booking(?,?,?)', [$reviewListing, $guestId, 20])[0]['booking_id'];
    echo "PASS trust review comment: routine refuses over 5000 characters, exactly 5000 multibyte characters stored\n";

    // Admin searches by name or title fold đ on both sides, as location search does. Each column holds its own marker, so each is proved separately; accented searches and emails keep working.
    $bookingSearch = new \App\Models\Booking();
    $paymentSearch = new \App\Models\BookingExtras();
    $messageSearch = new \App\Models\BookingMessage();
    $foldEmail = sprintf($emailFor, 'foldguest');
    $foldGuest = $users->adminCreate(['email' => $foldEmail, 'password' => $password, 'full_name' => 'Trust '.$tag.'g Đạt', 'phone' => ''], ['guest']);
    $foldHost = $users->adminCreate(['email' => sprintf($emailFor, 'foldhost'), 'password' => $password, 'full_name' => 'Trust '.$tag.'h Đạt', 'phone' => ''], ['guest', 'host']);
    $foldListing = $listings->create($foldHost, ['title' => 'Audit fixture trust '.$tag.'t Đà Lạt'] + $listingData);
    trustCall('CALL sp_test_listing_approve(?)', [$foldListing]);
    [$checkIn, $checkOut] = trustDates($slot++);
    $foldBooking = $service->create($foldListing, $foldGuest, $checkIn, $checkOut, 1);
    $service->hostTransition($foldBooking, $foldHost, 'confirmed');
    $messageSearch->send($foldBooking, $foldGuest, 'Hỏi đường '.$tag.'m Đà Lạt');
    $foldRequest = $trust->submitVerification($foldGuest, 'Đoàn Văn Đạt', '0912345681', '079012345681');
    foreach (['Dat', 'dat', 'DAT', 'Đạt', 'đạt', 'Đat', 'dạt'] as $typed) {
        $guestTyped = $tag.'g '.$typed;
        $hostTyped = $tag.'h '.$typed;
        trustExpect(trustHasRow($users->all($guestTyped), 'id', $foldGuest), 'Admin user search missed the name for: '.$typed);
        trustExpect(trustHasRow($bookingSearch->adminSearch($adminId, $guestTyped, ''), 'id', $foldBooking), 'Admin booking search missed the Guest for: '.$typed);
        trustExpect(trustHasRow($bookingSearch->adminSearch($adminId, $hostTyped, ''), 'id', $foldBooking), 'Admin booking search missed the Host for: '.$typed);
        trustExpect(trustHasRow($paymentSearch->adminPayments($adminId, '', $guestTyped), 'booking_id', $foldBooking), 'Admin payment search missed the Guest for: '.$typed);
        trustExpect(trustHasRow($trust->adminVerifications($adminId, '', $guestTyped), 'id', $foldRequest), 'Admin verification search missed the account name for: '.$typed);
        trustExpect(trustHasRow($messageSearch->adminSearch($adminId, '', null, $guestTyped), 'booking_id', $foldBooking), 'Admin message search missed the sender for: '.$typed);
        trustExpect(trustHasRow($messageSearch->adminSearch($adminId, '', null, $hostTyped), 'booking_id', $foldBooking), 'Admin message search missed the recipient for: '.$typed);
    }
    foreach (['Da Lat', 'da lat', 'DA LAT', 'Đà Lạt', 'đà lạt', 'Đa Lat', 'dà lạt'] as $typed) {
        trustExpect(trustHasRow($bookingSearch->adminSearch($adminId, $tag.'t '.$typed, ''), 'id', $foldBooking), 'Admin booking search missed the title for: '.$typed);
        trustExpect(trustHasRow($paymentSearch->adminPayments($adminId, '', '  '.$tag.'t '.$typed.' '), 'booking_id', $foldBooking), 'Admin payment search missed the title for: '.$typed);
        trustExpect(trustHasRow($messageSearch->adminSearch($adminId, $tag.'m '.$typed, null, ''), 'booking_id', $foldBooking), 'Admin message search missed the text for: '.$typed);
    }
    foreach (['Doan Van Dat', 'doan van dat', 'Đoàn Văn Đạt', 'đoàn văn đạt', 'Đoan Van Đat'] as $typed) {
        trustExpect(trustHasRow($trust->adminVerifications($adminId, '', $typed), 'id', $foldRequest), 'Admin verification search missed the card name for: '.$typed);
    }
    trustExpect(trustHasRow($users->all($foldEmail), 'id', $foldGuest) && trustHasRow($paymentSearch->adminPayments($adminId, '', $foldEmail), 'booking_id', $foldBooking)
        && trustHasRow($trust->adminVerifications($adminId, '', $foldEmail), 'id', $foldRequest) && trustHasRow($messageSearch->adminSearch($adminId, '', null, $foldEmail), 'booking_id', $foldBooking), 'Searching by email stopped working.');
    trustExpect($users->all($tag.'g Dan') === [] && $bookingSearch->adminSearch($adminId, $tag.'t Da Nang', '') === [] && $paymentSearch->adminPayments($adminId, '', $tag.'t Da Nang') === []
        && !trustHasRow($trust->adminVerifications($adminId, '', 'Doan Van Dan'), 'id', $foldRequest) && $messageSearch->adminSearch($adminId, $tag.'m Da Nang', null, '') === [], 'Folding made a search match a different word.');
    trustExpect($messageSearch->adminSearch($adminId, '', null, $tag.'g%Dat') === [] && $messageSearch->adminSearch($adminId, $tag.'m_Da Lat', null, '') === [], 'A message search stopped treating % and _ literally.');
    trustExpect($users->all($tag.'g Dat')[0]['full_name'] === 'Trust '.$tag.'g Đạt' && $bookingSearch->adminSearch($adminId, $tag.'t Da Lat', '')[0]['title'] === 'Audit fixture trust '.$tag.'t Đà Lạt', 'Folding changed the text shown for a result.');
    echo "PASS Admin searches by name, title, card name and message text fold đ on both sides; accents, emails and stored text unchanged\n";

    // The same four searches match typed %, _ and a backslash literally, as the article and message searches do, and keep the đ folding and accents.
    $literalGuest = $users->adminCreate(['email' => sprintf($emailFor, 'literal_guest'), 'password' => $password, 'full_name' => 'Trust '.$tag.'x 5%_a\\b Đạt', 'phone' => ''], ['guest']);
    [$checkIn, $checkOut] = trustDates($slot++);
    $literalBooking = $service->create($foldListing, $literalGuest, $checkIn, $checkOut, 1);
    $literalRequest = $trust->submitVerification($literalGuest, 'Trần Văn Lịch', '0912345682', '079012345682');
    foreach ([$tag.'x 5%_a\\b', $tag.'x 5%_a\\b Dat', $tag.'x 5%_A\\B đạt', '%_a\\b', '_a\\', '5%', '\\b'] as $typed) {
        trustExpect(trustHasRow($users->all($typed), 'id', $literalGuest), 'Admin user search lost the literal text: '.$typed);
        trustExpect(trustHasRow($bookingSearch->adminSearch($adminId, $typed, ''), 'id', $literalBooking), 'Admin booking search lost the literal text: '.$typed);
        trustExpect(trustHasRow($paymentSearch->adminPayments($adminId, '', $typed), 'booking_id', $literalBooking), 'Admin payment search lost the literal text: '.$typed);
        trustExpect(trustHasRow($trust->adminVerifications($adminId, '', $typed), 'id', $literalRequest), 'Admin verification search lost the literal text: '.$typed);
    }
    foreach ([$tag.'g_Dat', $tag.'g%Dat', '%'.$tag.'g', $tag.'_g', $tag.'x 5_%a', $tag.'x 5%_ab', $tag.'x 5%_a\\\\b'] as $typed) {
        trustExpect($users->all($typed) === [], 'Admin user search read a wildcard: '.$typed);
        trustExpect($bookingSearch->adminSearch($adminId, $typed, '') === [], 'Admin booking search read a wildcard: '.$typed);
        trustExpect($paymentSearch->adminPayments($adminId, '', $typed) === [], 'Admin payment search read a wildcard: '.$typed);
        trustExpect($trust->adminVerifications($adminId, '', $typed) === [], 'Admin verification search read a wildcard: '.$typed);
    }
    foreach (['%', '_'] as $typed) {
        trustExpect(!trustHasRow($users->all($typed), 'id', $foldHost) && !trustHasRow($bookingSearch->adminSearch($adminId, $typed, ''), 'id', $foldBooking)
            && !trustHasRow($paymentSearch->adminPayments($adminId, '', $typed), 'booking_id', $foldBooking) && !trustHasRow($trust->adminVerifications($adminId, '', $typed), 'id', $foldRequest), 'A lone '.$typed.' listed rows that do not hold it.');
    }
    trustExpect(trustHasRow($users->all(sprintf($emailFor, 'literal_guest')), 'id', $literalGuest) && $users->all(sprintf($emailFor, 'literalxguest')) === []
        && trustHasRow($paymentSearch->adminPayments($adminId, '', sprintf($emailFor, 'literal_guest')), 'booking_id', $literalBooking) && $paymentSearch->adminPayments($adminId, '', sprintf($emailFor, 'literalxguest')) === []
        && trustHasRow($trust->adminVerifications($adminId, '', sprintf($emailFor, 'literal_guest')), 'id', $literalRequest) && $trust->adminVerifications($adminId, '', sprintf($emailFor, 'literalxguest')) === [], 'An underscore in an email search matched another character or missed its own.');
    echo "PASS Admin searches for users, bookings, payments and verifications match %, _ and a backslash literally\n";

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
        trustExpect($confirmedPage['status'] === 200 && !str_contains($confirmedPage['body'], 'K-4821') && !str_contains($confirmedPage['body'], 'pw-'.$tag) && str_contains($confirmedPage['body'], 'Hướng dẫn nhận phòng') && str_contains($confirmedPage['body'], 'sẽ hiển thị từ ngày'), 'Confirmed Guest page must show the guide and say when the codes appear.');
        [$checkIn, $checkOut] = trustDates($slot++);
        $openStay = $service->create($listingId, $guestId, $checkIn, $checkOut, 1);
        $service->hostTransition($openStay, $hostId, 'confirmed');
        trustCall('CALL sp_test_trust_move_booking(?,?)', [$openStay, 0]);
        $openPage = trustHttp($guestClient, $baseUrl, '/bookings/'.$openStay);
        trustExpect($openPage['status'] === 200 && str_contains($openPage['body'], 'K-4821') && !str_contains($openPage['body'], 'sẽ hiển thị từ ngày'), 'Guest page lacks the codes on check-in day.');
        $endedPage = trustHttp($guestClient, $baseUrl, '/bookings/'.$windowStay);
        trustExpect($endedPage['status'] === 200 && !str_contains($endedPage['body'], 'K-4821') && str_contains($endedPage['body'], 'không còn hiển thị'), 'Completed-stay Guest page must not show the codes.');
        trustExpect(!str_contains(trustHttp($guestClient, $baseUrl, '/notifications')['body'], 'K-4821'), 'Notifications page carried the door code.');
        // A completed stay's detail page leads to the review form on the trips page; a stay waiting for the Host says why there is no form yet.
        $reviewHref = '/bookings#review-'.$httpReviewStay.'"';
        $completedHint = 'sau khi Host đánh dấu hoàn tất';
        $reviewable = trustHttp($guestClient, $baseUrl, '/bookings/'.$httpReviewStay)['body'];
        trustExpect(str_contains($reviewable, $reviewHref) && !str_contains($reviewable, $completedHint), 'A completed stay offers no way to review from its detail page.');
        trustExpect(str_contains(trustHttp($guestClient, $baseUrl, '/bookings')['body'], 'id="review-'.$httpReviewStay.'"'), 'The review link has no form to land on.');
        [$checkIn, $checkOut] = trustDates($slot++);
        $waitingStay = $service->create($rateListing, $guestId, $checkIn, $checkOut, 1);
        $service->hostTransition($waitingStay, $rateHost, 'confirmed');
        trustExpect(!str_contains(trustHttp($guestClient, $baseUrl, '/bookings/'.$waitingStay)['body'], $completedHint), 'A stay before its check-out says reviewing waits for the Host.');
        trustCall('CALL sp_test_trust_move_booking(?,?)', [$waitingStay, -5]);
        $waiting = trustHttp($guestClient, $baseUrl, '/bookings/'.$waitingStay)['body'];
        trustExpect(str_contains($waiting, $completedHint) && !str_contains($waiting, '#review-'.$waitingStay), 'A stay past check-out does not say reviewing waits for the Host.');
        $reviewAdmin = trustLogin($baseUrl, sprintf($emailFor, 'admin'), $password);
        trustExpect(!str_contains(trustHttp(trustLogin($baseUrl, sprintf($emailFor, 'rate'), $password), $baseUrl, '/bookings/'.$waitingStay)['body'], $completedHint) && !str_contains(trustHttp($reviewAdmin, $baseUrl, '/bookings/'.$waitingStay)['body'], $completedHint), 'The review hint reached someone who is not the Guest.');
        trustExpect(!str_contains(trustHttp($reviewAdmin, $baseUrl, '/bookings/'.$httpReviewStay)['body'], $reviewHref), 'The review link reached someone who is not the Guest.');
        $service->hostTransition($waitingStay, $rateHost, 'completed');
        $done = trustHttp($guestClient, $baseUrl, '/bookings/'.$waitingStay)['body'];
        trustExpect(str_contains($done, '#review-'.$waitingStay) && !str_contains($done, $completedHint), 'Completing the stay did not open the review link.');
        // Over-long review comments come back as a friendly error with the input kept, also past the database packet limit (1 MiB), which used to answer 500.
        $trips = trustHttp($guestClient, $baseUrl, '/bookings');
        $tooLong = trustHttp($guestClient, $baseUrl, '/bookings/'.$httpReviewStay.'/review', ['_token' => trustToken($trips['body']), 'rating' => 4, 'comment' => str_repeat('x', Review::MAX_COMMENT_LENGTH + 1)]);
        trustExpect($tooLong['status'] === 200 && $tooLong['path'] === '/bookings' && str_contains($tooLong['body'], 'alert-danger') && str_contains($tooLong['body'], 'tối đa 5000 ký tự'), 'Over-long review comment did not get the friendly error.');
        trustExpect(str_contains($tooLong['body'], 'value="'.str_repeat('x', Review::MAX_COMMENT_LENGTH).'"') && str_contains($tooLong['body'], '<option value="4" selected>'), 'Over-long review comment lost the input.');
        foreach ([70000, 2097152] as $size) {
            $huge = trustHttp($guestClient, $baseUrl, '/bookings/'.$httpReviewStay.'/review', ['_token' => trustToken($trips['body']), 'rating' => 5, 'comment' => str_repeat('y', $size)]);
            trustExpect($huge['status'] === 200 && $huge['path'] === '/bookings' && str_contains($huge['body'], 'tối đa 5000 ký tự'), 'A '.$size.'-byte review comment did not get the friendly error.');
        }
        $accepted = trustHttp($guestClient, $baseUrl, '/bookings/'.$httpReviewStay.'/review', ['_token' => trustToken($trips['body']), 'rating' => 5, 'comment' => 'Kỳ nghỉ rất tốt']);
        trustExpect($accepted['path'] === '/bookings' && str_contains($accepted['body'], 'alert-success'), 'Normal review comment refused.');
        $reviewed = trustHttp($guestClient, $baseUrl, '/bookings/'.$httpReviewStay)['body'];
        trustExpect(!str_contains($reviewed, $reviewHref) && str_contains($reviewed, 'Bạn đã đánh giá chuyến đi này'), 'A reviewed stay still offers to review.');
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
        $previewBody = trustHttp($adminClient, $baseUrl, '/admin/listings/'.$listingId.'/preview')['body'];
        $publicBody = trustHttp($anonymous, $baseUrl, '/listings/'.$listingId)['body'];
        $ratingLine = '~★ [\d.]+ · \d+ đánh giá~u';
        trustExpect(preg_match($ratingLine, $previewBody, $previewRating) === 1 && preg_match($ratingLine, $publicBody, $publicRating) === 1 && $previewRating === $publicRating, 'The Admin preview and the public page show a different rating.');
        trustExpect(substr_count($previewBody, '<article class="review">') === substr_count($publicBody, '<article class="review">') && substr_count($previewBody, 'Đã xác minh') === substr_count($publicBody, 'Đã xác minh'), 'The Admin preview and the public page show different reviews or verified marks.');
        $queue = trustHttp($adminClient, $baseUrl, '/admin/verifications?'.http_build_query(['status' => 'pending', 'q' => sprintf($emailFor, 'otherguest')]));
        trustExpect($queue['status'] === 200 && str_contains($queue['body'], '123456784321'), 'Admin queue lacks the pending CCCD.');
        $otherRequest = (int) $trust->verificationStatus($otherGuestId)['id'];
        $noToken = trustHttp($adminClient, $baseUrl, '/admin/verifications/'.$otherRequest.'/decide', ['decision' => 'approved'], ['Accept: application/json']);
        trustExpect($noToken['status'] === 419 && $trust->verificationStatus($otherGuestId)['status'] === 'pending', 'Admin decision without CSRF was accepted.');
        $decided = trustHttp($adminClient, $baseUrl, '/admin/verifications/'.$otherRequest.'/decide', ['_token' => trustToken($queue['body']), 'decision' => 'approved', 'reason' => '']);
        trustExpect($decided['path'] === '/admin/verifications' && str_contains($decided['body'], 'alert-success') && $trust->verificationStatus($otherGuestId)['status'] === 'approved', 'HTTP Admin approval failed.');
        $historyPage = trustHttp($adminClient, $baseUrl, '/admin/verifications?'.http_build_query(['status' => '', 'q' => sprintf($emailFor, 'otherguest')]));
        trustExpect(str_contains($historyPage['body'], '********4321') && !str_contains($historyPage['body'], '123456784321'), 'Decided Admin history exposed the CCCD.');
        // A pasted email with a space around it finds its row, as the other Admin searches do.
        foreach ([sprintf($emailFor, 'otherguest').' ', ' '.sprintf($emailFor, 'otherguest')] as $pasted) {
            trustExpect(str_contains(trustHttp($adminClient, $baseUrl, '/admin?'.http_build_query(['q' => $pasted]))['body'], '<small class="table-person">'.sprintf($emailFor, 'otherguest').'</small>'), 'The Admin user search did not trim: "'.$pasted.'"');
        }
        $dashboard = trustHttp($adminClient, $baseUrl, '/admin?'.http_build_query(['q' => 'audit-trust-'.$tag]));
        trustExpect($dashboard['status'] === 200 && str_contains($dashboard['body'], '/admin/verifications') && str_contains($dashboard['body'], 'Host uy tín') && str_contains($dashboard['body'], 'Đã xác minh'), 'Admin user list lacks trust badges.');
        trustExpect(str_contains(trustHttp($adminClient, $baseUrl, '/admin/users/'.$hostId.'/edit')['body'], 'Host uy tín'), 'Admin user edit lacks the Host badge.');
        trustExpect(str_contains(trustHttp($adminClient, $baseUrl, '/bookings/'.$openStay)['body'], 'K-4821'), 'Admin booking detail lacks the codes inside the window.');
        trustExpect(!str_contains(trustHttp($adminClient, $baseUrl, '/bookings/'.$stayId)['body'], 'K-4821'), 'Admin booking detail leaked the codes before the window.');

        $hostClient = trustLogin($baseUrl, sprintf($emailFor, 'host'), $password);
        $nearPanel = trustHttp(trustLogin($baseUrl, $nearEmail, $password), $baseUrl, '/host')['body'];
        trustExpect(str_contains($nearPanel, '4,45★ / cần ≥ 4,5★ · Chưa đạt'), 'The trust panel contradicts itself just under the minimum.');
        trustExpect(str_contains(trustHttp($adminClient, $baseUrl, '/admin?q='.rawurlencode($nearEmail))['body'], '11/3 đánh giá · 4,45★'), 'The Admin row rounds an unmet rating up to the minimum.');
        $nearRateClient = trustLogin($baseUrl, $nearRateEmail, $password);
        $nearRatePanel = trustHttp($nearRateClient, $baseUrl, '/host')['body'];
        trustExpect(str_contains($nearRatePanel, '10,05 % (21/209 yêu cầu) / cần ≤ 10 % · Chưa đạt') && str_contains($nearRatePanel, 'is-unmet"><span><span aria-hidden="true">✗</span> Tỷ lệ Host từ chối/hủy'), 'The trust panel contradicts itself just over the maximum.');
        trustExpect(str_contains(trustHttp($adminClient, $baseUrl, '/admin?q='.rawurlencode($nearRateEmail))['body'], 'từ chối/hủy 10,05%'), 'The Admin row rounds an unmet rate down to the maximum.');
        // One more decided request makes it exactly 10%, which the rule allows, in the usual one decimal.
        trustCall('CALL sp_test_trust_completed_booking(?,?,?)', [$nearRateListing, $guestId, 10]);
        trustExpect((int) trustBadge($trust, $nearRateHost, $nearRateHost)['cancel_met'] === 1 && str_contains(trustHttp($nearRateClient, $baseUrl, '/host')['body'], '10,0 % (21/210 yêu cầu) / cần ≤ 10 % · Đạt'), 'Exactly 10% no longer reads as met.');
        $hostDashboard = trustHttp($hostClient, $baseUrl, '/host');
        trustExpect(str_contains($hostDashboard['body'], 'Huy hiệu Host uy tín') && str_contains($hostDashboard['body'], '3/3 đánh giá') && str_contains($hostDashboard['body'], '/checkin-guide'), 'Host dashboard lacks trust progress or the guide link.');
        $editor = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide');
        trustExpect($editor['status'] === 200 && str_contains($editor['body'], 'K-4821'), 'Host guide editor unavailable.');
        $saved = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide', ['_token' => trustToken($editor['body'])] + ['door_code' => 'K-9999'] + $guide);
        trustExpect(str_contains($saved['body'], 'alert-success') && $trust->guideForHost($listingId, $hostId)['door_code'] === 'K-9999', 'HTTP guide save failed.');
        // A line break counts once, as in the form's maxlength: the guide and a reply at their limits are saved and still show the break.
        $breakGuide = ['_token' => trustToken($saved['body']), 'door_code' => 'K-9999', 'arrival_instructions' => str_repeat('a', 1499)."\r\n".str_repeat('b', 1500), 'note' => str_repeat('c', 999)."\r\n".str_repeat('d', 1000)] + $guide;
        trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide', $breakGuide);
        $storedGuide = $trust->guideForHost($listingId, $hostId);
        trustExpect($storedGuide['arrival_instructions'] === str_repeat('a', 1499)."\n".str_repeat('b', 1500) && $storedGuide['note'] === str_repeat('c', 999)."\n".str_repeat('d', 1000), 'A guide at its limits with a line break was refused or kept its CR.');
        trustExpect(str_contains(trustHttp($guestClient, $baseUrl, '/bookings/'.$stayId)['body'], "a<br />\nb"), 'The guide lost its line break on the Guest page.');
        trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide', ['_token' => trustToken($saved['body']), 'door_code' => 'K-9999'] + $guide);
        $breakReview = (int) $reviews->forHost($hostId)[0]['id'];
        trustHttp($hostClient, $baseUrl, '/host/reviews/'.$breakReview.'/reply', ['_token' => trustToken($saved['body']), 'reply' => str_repeat('a', 2499)."\r\n".str_repeat('b', 2500)]);
        foreach ($reviews->forHost($hostId) as $review) { if ((int) $review['id'] === $breakReview) { trustExpect($review['host_reply'] === str_repeat('a', 2499)."\n".str_repeat('b', 2500), 'A reply at the limit with a line break was refused or kept its CR.'); } }
        $tooShort = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide', ['_token' => trustToken($saved['body']), 'arrival_instructions' => 'ngắn', 'door_code' => 'K-1111']);
        trustExpect(str_contains($tooShort['body'], 'alert-danger') && str_contains($tooShort['body'], 'K-1111') && $trust->guideForHost($listingId, $hostId)['door_code'] === 'K-9999', 'Invalid HTTP guide save changed data or lost input.');
        trustExpect(str_contains(trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/edit')['body'], '/checkin-guide'), 'Listing edit page lacks the guide link.');

        $otherHostClient = trustLogin($baseUrl, sprintf($emailFor, 'otherhost'), $password);
        trustExpect(trustHttp($otherHostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide')['status'] === 404, 'Non-owner Host opened the guide editor.');
        $foreignPage = trustHttp($otherHostClient, $baseUrl, '/host');
        $foreign = trustHttp($otherHostClient, $baseUrl, '/host/listings/'.$listingId.'/checkin-guide', ['_token' => trustToken($foreignPage['body'])] + $guide);
        trustExpect($foreign['path'] === '/host' && str_contains($foreign['body'], 'alert-danger') && $trust->guideForHost($listingId, $hostId)['door_code'] === 'K-9999', 'Non-owner Host saved the guide over HTTP.');
        // The rejection reason reaches the Host, escaped, on the dashboard row, the edit page and the notification.
        $rejectedListing = $listings->create($hostId, ['title' => 'Audit fixture trust '.$tag.' rejected'] + $listingData);
        trustCall('CALL sp_test_listing_approve(?)', [$rejectedListing]);
        $reasonRaw = '<b>Ảnh '.$tag.'</b> chưa rõ';
        $reasonSafe = '&lt;b&gt;Ảnh '.$tag.'&lt;/b&gt; chưa rõ';
        $moderationPage = trustHttp($adminClient, $baseUrl, '/admin/listings');
        $refusedReason = trustHttp($adminClient, $baseUrl, '/admin/listings/'.$rejectedListing.'/moderate', ['_token' => trustToken($moderationPage['body']), 'action' => 'rejected', 'note' => str_repeat('ệ', 5001)]);
        trustExpect(str_contains($refusedReason['body'], 'alert-danger') && str_contains($refusedReason['body'], 'tối đa 5000 ký tự'), 'A rejection reason over 5000 characters was not refused.');
        trustExpect(str_contains($refusedReason['body'], 'value="'.str_repeat('ệ', 5000).'"'), 'The refused reason was not kept in its form.');
        trustExpect(!str_contains(trustHttp($adminClient, $baseUrl, '/admin')['body'], 'value="'.str_repeat('ệ', 5000).'"'), 'The refused reason stayed after the next page load.');
        $rejectedBy = trustHttp($adminClient, $baseUrl, '/admin/listings/'.$rejectedListing.'/moderate', ['_token' => trustToken($moderationPage['body']), 'action' => 'rejected', 'note' => $reasonRaw]);
        trustExpect(str_contains($rejectedBy['body'], 'alert-success'), 'Admin could not reject the fixture listing.');
        foreach (['/host', '/host/listings/'.$rejectedListing.'/edit', '/notifications'] as $hostPage) {
            $seen = trustHttp($hostClient, $baseUrl, $hostPage)['body'];
            trustExpect(str_contains($seen, $reasonSafe) && !str_contains($seen, $reasonRaw), 'Rejection reason missing or unescaped on '.$hostPage);
        }
        trustExpect(!str_contains(trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/edit')['body'], 'Lý do từ chối'), 'An approved listing shows a rejection reason.');
        trustCall('CALL sp_test_listing_approve(?)', [$rejectedListing]);
        trustExpect(!str_contains(trustHttp($hostClient, $baseUrl, '/host')['body'], $reasonSafe), 'The dashboard kept a reason after the listing was approved.');
        echo "PASS rejection reason shown escaped to the Host on the dashboard, edit page and notification, only while the listing is rejected\n";

        // Deleting an account with an unfinished stay: the Admin reads why on the dashboard, not a 500.
        $leavingEmail = sprintf($emailFor, 'leaving');
        $leavingGuest = $users->adminCreate(['email' => $leavingEmail, 'password' => $password, 'full_name' => 'Trust Leaving Guest', 'phone' => ''], ['guest']);
        [$leavingIn, $leavingOut] = trustDates(40);
        $leavingStay = $service->create($listingId, $leavingGuest, $leavingIn, $leavingOut, 1);
        $service->hostTransition($leavingStay, $hostId, 'confirmed');
        $refusedDelete = trustHttp($adminClient, $baseUrl, '/admin/users/'.$leavingGuest.'/delete', ['_token' => trustToken(trustHttp($adminClient, $baseUrl, '/admin')['body'])]);
        trustExpect($refusedDelete['status'] === 200 && $refusedDelete['path'] === '/admin' && str_contains($refusedDelete['body'], 'alert-danger') && str_contains($refusedDelete['body'], 'còn 1 booking đã xác nhận chưa kết thúc'), 'The Admin did not read why the delete was refused.');
        trustExpect($users->findByEmail($leavingEmail)['status'] === 'active', 'A refused delete changed the account.');
        echo "PASS refused account delete shows the Admin the count on the dashboard\n";

        // Removing the Host role: both Admin forms show why it was refused. The account has live listings here.
        $hostWithRoles = $users->findWithRoles($hostId)['roles'];
        $refusedRole = trustHttp($adminClient, $baseUrl, '/admin/users/'.$hostId.'/roles', ['_token' => trustToken(trustHttp($adminClient, $baseUrl, '/admin')['body']), 'roles' => ['guest']]);
        trustExpect($refusedRole['status'] === 200 && $refusedRole['path'] === '/admin' && str_contains($refusedRole['body'], 'alert-danger') && str_contains($refusedRole['body'], 'Không thể bỏ vai trò Host'), 'The dashboard roles form hid why the Host role stayed.');
        $editPage = trustHttp($adminClient, $baseUrl, '/admin/users/'.$hostId.'/edit');
        $refusedEdit = trustHttp($adminClient, $baseUrl, '/admin/users/'.$hostId, ['_token' => trustToken($editPage['body']), 'email' => sprintf($emailFor, 'host'), 'full_name' => 'Trust Host '.$tag, 'phone' => '', 'password' => '', 'roles' => ['guest']]);
        trustExpect($refusedEdit['status'] === 200 && $refusedEdit['path'] === '/admin/users/'.$hostId.'/edit' && str_contains($refusedEdit['body'], 'alert-danger') && str_contains($refusedEdit['body'], 'Không thể bỏ vai trò Host'), 'The user edit form hid why the Host role stayed.');
        trustExpect($users->findWithRoles($hostId)['roles'] === $hostWithRoles, 'A refused role change changed the roles.');
        echo "PASS refused Host role removal shows the Admin the reason on the dashboard and on the user edit page\n";

        // The link to the public listing is only offered while that page exists, for every party.
        $guestOfLeaving = trustLogin($baseUrl, $leavingEmail, $password);
        $linkLabel = 'Xem chỗ ở công khai';
        $bookingPath = '/bookings/'.$leavingStay;
        foreach ([$guestOfLeaving, $hostClient, $adminClient] as $party) {
            trustExpect(str_contains(trustHttp($party, $baseUrl, $bookingPath)['body'], $linkLabel), 'A public listing lost its link on the booking page.');
        }
        trustExpect(trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$listingId)['status'] === 200, 'The public listing fixture is not public.');
        trustExpect(trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/visibility', ['_token' => trustToken(trustHttp($hostClient, $baseUrl, '/host')['body']), 'visible' => '0'])['status'] === 200, 'The Host could not hide the listing.');
        trustExpect(trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$listingId)['status'] === 404, 'A hidden listing stayed public.');
        foreach ([$guestOfLeaving, $hostClient, $adminClient] as $party) {
            trustExpect(!str_contains(trustHttp($party, $baseUrl, $bookingPath)['body'], $linkLabel), 'The booking page links to a hidden listing.');
        }
        trustExpect(trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/visibility', ['_token' => trustToken(trustHttp($hostClient, $baseUrl, '/host')['body']), 'visible' => '1'])['status'] === 200, 'The Host could not show the listing again.');
        trustExpect(str_contains(trustHttp($guestOfLeaving, $baseUrl, $bookingPath)['body'], $linkLabel), 'The link did not come back with the listing.');
        echo "PASS booking page links to the public listing only while it is public, for guest, Host and Admin\n";

        // The contact note says only what is true in each state. Neither party of this booking has a phone.
        $quietHostEmail = sprintf($emailFor, 'quiethost');
        $quietHost = $users->adminCreate(['email' => $quietHostEmail, 'password' => $password, 'full_name' => 'Trust Quiet Host', 'phone' => ''], ['guest', 'host']);
        $quietListing = $listings->create($quietHost, ['title' => 'Audit fixture trust '.$tag.' quiet'] + $listingData);
        trustCall('CALL sp_test_listing_approve(?)', [$quietListing]);
        $quietClient = trustLogin($baseUrl, $quietHostEmail, $password);
        $contactNote = 'Thông tin liên hệ của hai bên chỉ mở sau khi booking được xác nhận.';
        $noHostPhone = 'Host chưa cung cấp số điện thoại.';
        $noGuestPhone = 'Khách chưa cung cấp số điện thoại.';
        [$quietIn, $quietOut] = trustDates(41);
        $quietStay = $service->create($quietListing, $leavingGuest, $quietIn, $quietOut, 1);
        $quietPath = '/bookings/'.$quietStay;
        $asGuest = trustHttp($guestOfLeaving, $baseUrl, $quietPath)['body'];
        $asHost = trustHttp($quietClient, $baseUrl, $quietPath)['body'];
        $asAdmin = trustHttp($adminClient, $baseUrl, $quietPath)['body'];
        trustExpect(str_contains($asGuest, $contactNote) && str_contains($asHost, $contactNote) && !str_contains($asAdmin, $contactNote), 'Pending: the note must reach the two parties and not the Admin.');
        trustExpect(!str_contains($asGuest, $noHostPhone) && !str_contains($asHost, $noGuestPhone), 'Pending: phones are not missing yet, only closed.');
        $service->hostTransition($quietStay, $quietHost, 'confirmed');
        $asGuest = trustHttp($guestOfLeaving, $baseUrl, $quietPath)['body'];
        $asHost = trustHttp($quietClient, $baseUrl, $quietPath)['body'];
        $asAdmin = trustHttp($adminClient, $baseUrl, $quietPath)['body'];
        trustExpect(str_contains($asGuest, $noHostPhone) && !str_contains($asGuest, $contactNote), 'Confirmed: the guest was not told the Host has no phone.');
        trustExpect(str_contains($asHost, $noGuestPhone) && !str_contains($asHost, $contactNote), 'Confirmed: the Host was not told the guest has no phone.');
        trustExpect(!str_contains($asAdmin, $contactNote) && !str_contains($asAdmin, $noHostPhone) && !str_contains($asAdmin, $noGuestPhone), 'Confirmed: the Admin got a contact note.');
        $service->cancelByGuest($quietStay, $leavingGuest, 'Trust contact note fixture');
        [$quietIn, $quietOut] = trustDates(42);
        $quietRefused = $service->create($quietListing, $leavingGuest, $quietIn, $quietOut, 1);
        $service->hostTransition($quietRefused, $quietHost, 'rejected');
        foreach ([$quietPath, '/bookings/'.$quietRefused] as $closedPath) {
            foreach ([$guestOfLeaving, $quietClient, $adminClient] as $party) {
                $closed = trustHttp($party, $baseUrl, $closedPath)['body'];
                trustExpect(!str_contains($closed, $contactNote) && !str_contains($closed, $noHostPhone) && !str_contains($closed, $noGuestPhone), 'A cancelled or rejected booking still shows a contact note.');
            }
        }
        // An Admin hide reaches the Host's dashboard in place of the button, and the POST is refused with the reason.
        (new \App\Models\AdminRepository())->setListingVisibility($quietListing, $adminId, false);
        $hiddenDashboard = trustHttp($quietClient, $baseUrl, '/host')['body'];
        trustExpect(str_contains($hiddenDashboard, 'data-admin-hidden') && !str_contains($hiddenDashboard, '/host/listings/'.$quietListing.'/visibility'), 'The dashboard still offers Show on a listing an Admin hid.');
        $undoHide = trustHttp($quietClient, $baseUrl, '/host/listings/'.$quietListing.'/visibility', ['_token' => trustToken($hiddenDashboard), 'visible' => '1']);
        trustExpect(str_contains($undoHide['body'], 'alert-danger') && str_contains($undoHide['body'], 'chỉ Admin mới hiện lại được'), 'The Host was not told why Show was refused.');
        trustExpect(trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$quietListing)['status'] === 404, 'A refused Show made the listing public.');
        trustExpect(str_contains(trustHttp($quietClient, $baseUrl, '/notifications')['body'], 'Admin đã ẩn tin'), 'The Host got no notice of the Admin hide.');
        (new \App\Models\AdminRepository())->setListingVisibility($quietListing, $adminId, true);
        $shownDashboard = trustHttp($quietClient, $baseUrl, '/host')['body'];
        trustExpect(!str_contains($shownDashboard, 'data-admin-hidden') && str_contains($shownDashboard, '/host/listings/'.$quietListing.'/visibility'), 'An Admin show did not give the Host the button back.');
        trustExpect(str_contains(trustHttp($quietClient, $baseUrl, '/notifications')['body'], 'Admin đã hiện lại tin'), 'The Host got no notice of the Admin show.');
        // A Host's own hide: the Admin's row says so and offers no Show, and a direct POST is refused with the reason.
        $quietVisibility = trustHttp($quietClient, $baseUrl, '/host/listings/'.$quietListing.'/visibility', ['_token' => trustToken($shownDashboard), 'visible' => '0']);
        trustExpect($quietVisibility['status'] === 200 && trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$quietListing)['status'] === 404, 'The Host could not hide their listing.');
        $adminPage = trustHttp($adminClient, $baseUrl, '/admin')['body'];
        $hostHiddenRow = trustAdminListingRow($adminPage, $quietListing);
        trustExpect(str_contains($hostHiddenRow, 'data-hidden-by="host"') && str_contains($hostHiddenRow, 'name="visible" value="0"') && !str_contains($hostHiddenRow, 'name="visible" value="1"'), 'The Admin row offers Show on a listing the Host hid, or does not say who hid it.');
        $adminShow = trustHttp($adminClient, $baseUrl, '/admin/listings/'.$quietListing.'/visibility', ['_token' => trustToken($adminPage), 'visible' => '1']);
        trustExpect(str_contains($adminShow['body'], 'alert-danger') && str_contains($adminShow['body'], 'chỉ Host mới hiện lại được') && trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$quietListing)['status'] === 404, 'A direct Admin POST undid a Host hide or gave no reason.');
        // The Admin can hide it too, which blocks the Host; ending that hold gives the Host their button back and leaves the listing hidden.
        trustHttp($adminClient, $baseUrl, '/admin/listings/'.$quietListing.'/visibility', ['_token' => trustToken($adminPage), 'visible' => '0']);
        $adminPage = trustHttp($adminClient, $baseUrl, '/admin')['body'];
        $bothRow = trustAdminListingRow($adminPage, $quietListing);
        trustExpect(str_contains($bothRow, 'data-hidden-by="both"') && str_contains($bothRow, 'name="visible" value="1"') && str_contains($bothRow, 'Bỏ ẩn của Admin'), 'The Admin row does not show an Admin hide over a Host hide.');
        $heldDashboard = trustHttp($quietClient, $baseUrl, '/host')['body'];
        trustExpect(str_contains($heldDashboard, 'data-admin-hidden') && str_contains(trustHttp($quietClient, $baseUrl, '/notifications')['body'], 'tin mà bạn đang tự ẩn'), 'The Host was not told about the Admin hide over their own.');
        trustHttp($adminClient, $baseUrl, '/admin/listings/'.$quietListing.'/visibility', ['_token' => trustToken($adminPage), 'visible' => '1']);
        $handedBack = trustHttp($quietClient, $baseUrl, '/host')['body'];
        trustExpect(!str_contains($handedBack, 'data-admin-hidden') && trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$quietListing)['status'] === 404 && str_contains(trustHttp($quietClient, $baseUrl, '/notifications')['body'], 'Tin vẫn đang ẩn'), 'Ending the Admin hold undid the Host hide or said otherwise.');
        trustExpect(trustHttp($quietClient, $baseUrl, '/host/listings/'.$quietListing.'/visibility', ['_token' => trustToken($handedBack), 'visible' => '1'])['status'] === 200 && trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$quietListing)['status'] === 200, 'The Host could not show the listing after the Admin hold ended.');
        echo "PASS Admin hide: dashboard state in place of the button, Show refused with the reason, notices for hide and show; the Admin row says who hid it and cannot undo a Host hide\n";

        // An Admin's removal of the Host role holds on the profile page and against a direct POST, until an Admin gives it back.
        $applicantProfile = trustHttp($guestOfLeaving, $baseUrl, '/profile')['body'];
        trustExpect(str_contains($applicantProfile, '/profile/become-host') && !str_contains($applicantProfile, 'data-host-role-removed'), 'A person who never had the Host role was not offered it.');
        $became = trustHttp($guestOfLeaving, $baseUrl, '/profile/become-host', ['_token' => trustToken($applicantProfile)]);
        trustExpect($became['status'] === 200 && $became['path'] === '/host', 'A person who never had the Host role could not take it.');
        trustHttp($adminClient, $baseUrl, '/admin/users/'.$leavingGuest.'/roles', ['_token' => trustToken(trustHttp($adminClient, $baseUrl, '/admin')['body']), 'roles' => ['guest']]);
        trustExpect(trustHttp($guestOfLeaving, $baseUrl, '/host')['status'] === 403, 'The Admin removal did not take the Host role.');
        $removedProfile = trustHttp($guestOfLeaving, $baseUrl, '/profile')['body'];
        trustExpect(str_contains($removedProfile, 'data-host-role-removed') && !str_contains($removedProfile, '/profile/become-host'), 'The profile still offers the role an Admin removed.');
        $undoRemoval = trustHttp($guestOfLeaving, $baseUrl, '/profile/become-host', ['_token' => trustToken($removedProfile)]);
        trustExpect($undoRemoval['path'] === '/profile' && str_contains($undoRemoval['body'], 'alert-danger') && str_contains($undoRemoval['body'], 'Hãy liên hệ Admin'), 'A direct POST was not told to contact an Admin.');
        trustExpect(trustHttp($guestOfLeaving, $baseUrl, '/host')['status'] === 403, 'A direct POST undid the Admin removal.');
        trustHttp($adminClient, $baseUrl, '/admin/users/'.$leavingGuest.'/roles', ['_token' => trustToken(trustHttp($adminClient, $baseUrl, '/admin')['body']), 'roles' => ['guest', 'host']]);
        trustExpect(trustHttp($guestOfLeaving, $baseUrl, '/host')['status'] === 200 && !str_contains(trustHttp($guestOfLeaving, $baseUrl, '/profile')['body'], 'data-host-role-removed'), 'An Admin giving the role back did not lift the removal.');
        echo "PASS Host role removal: the profile explains, a direct POST is refused, an Admin giving it back lifts it\n";

        // Without the Guest role: a note in place of the form, the POST refused, and what was already booked stays theirs.
        [$keptIn, $keptOut] = trustDates(44);
        $keptStay = $service->create($listingId, $leavingGuest, $keptIn, $keptOut, 1);
        trustHttp($adminClient, $baseUrl, '/admin/users/'.$leavingGuest.'/roles', ['_token' => trustToken(trustHttp($adminClient, $baseUrl, '/admin')['body']), 'roles' => ['host']]);
        $noGuestPage = trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$listingId)['body'];
        trustExpect(str_contains($noGuestPage, 'data-no-guest-role') && !str_contains($noGuestPage, 'data-booking-form'), 'A person without the Guest role was offered the booking form.');
        [$refusedIn, $refusedOut] = trustDates(45);
        $refusedBook = trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$listingId.'/book', ['_token' => trustToken(trustHttp($guestOfLeaving, $baseUrl, '/profile')['body']), 'check_in' => $refusedIn, 'check_out' => $refusedOut, 'guests' => '1', 'promo_code' => '']);
        trustExpect(str_contains($refusedBook['body'], 'alert-danger') && str_contains($refusedBook['body'], 'không có vai trò Guest'), 'A booking POST without the Guest role was not refused with the reason.');
        trustExpect(trustHttp($guestOfLeaving, $baseUrl, '/bookings/'.$keptStay)['status'] === 200 && str_contains(trustHttp($guestOfLeaving, $baseUrl, '/bookings')['body'], '/bookings/'.$keptStay), 'A person lost sight of their booking with the Guest role.');
        $keptCancel = trustHttp($guestOfLeaving, $baseUrl, '/bookings/'.$keptStay.'/cancel', ['_token' => trustToken(trustHttp($guestOfLeaving, $baseUrl, '/bookings/'.$keptStay)['body']), 'reason' => 'Trust role fixture']);
        trustExpect(str_contains($keptCancel['body'], 'alert-success'), 'A person could not cancel their booking without the Guest role.');
        trustHttp($adminClient, $baseUrl, '/admin/users/'.$leavingGuest.'/roles', ['_token' => trustToken(trustHttp($adminClient, $baseUrl, '/admin')['body']), 'roles' => ['guest', 'host']]);
        $withGuestPage = trustHttp($guestOfLeaving, $baseUrl, '/listings/'.$listingId)['body'];
        trustExpect(str_contains($withGuestPage, 'data-booking-form') && !str_contains($withGuestPage, 'data-no-guest-role'), 'The booking form did not return with the Guest role.');
        echo "PASS no Guest role: note in place of the form, POST refused, own booking still opens and cancels\n";

        // The Host dashboard promises contact only while the request can still be confirmed; the quiet Host's two rows are cancelled and rejected.
        $contactLater = 'Liên hệ sau xác nhận';
        trustExpect(!str_contains(trustHttp($quietClient, $baseUrl, '/host')['body'], $contactLater), 'The dashboard promises contact on a rejected or cancelled row.');
        [$laterIn, $laterOut] = trustDates(46);
        $quietPending = $service->create($quietListing, $leavingGuest, $laterIn, $laterOut, 1);
        trustExpect(str_contains(trustHttp($quietClient, $baseUrl, '/host')['body'], $contactLater), 'The dashboard lost the contact note on a pending row.');
        $service->hostTransition($quietPending, $quietHost, 'rejected');
        trustExpect(!str_contains(trustHttp($quietClient, $baseUrl, '/host')['body'], $contactLater), 'The dashboard kept the contact note after the request was rejected.');
        echo "PASS Host dashboard: contact note only on pending rows\n";

        foreach ([$guestOfLeaving, $quietClient] as $client) { curl_close($client); }
        echo "PASS contact note: pending for the two parties, missing phone when confirmed, nothing when cancelled or rejected or for the Admin\n";

        // Saving the photo form with nothing changed touches nothing and says so; a real change still goes back to review.
        $photoModel = new Listing();
        $photoModel->addPhoto($listingId, '/uploads/audit-a.jpg', 'Audit A', $hostId);
        $photoModel->addPhoto($listingId, '/uploads/audit-b.jpg', 'Audit B', $hostId);
        $photoIds = array_map('intval', array_column($photoModel->photos($listingId, $hostId), 'id'));
        trustExpect(count($photoIds) === 2 && $photoModel->findOwned($listingId, $hostId)['moderation_status'] === 'approved', 'The photo fixture is not a live listing with two photos.');
        $photoForm = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/edit')['body'];
        $photoToken = trustToken($photoForm);
        // The dialog opens before anyone knows whether anything changed, so it promises a re-review only for a change.
        trustExpect(str_contains($photoForm, 'data-confirm="Lưu thứ tự/gỡ ảnh đã chọn? Nếu ảnh thay đổi, tin sẽ được gửi duyệt lại."') && !str_contains($photoForm, 'và gửi tin duyệt lại?'), 'The photo save dialog promises a re-review even with nothing changed.');
        foreach ([[1, 2], [3, 7]] as $samePositions) {
            $photoSave = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/photos', ['_token' => $photoToken, 'positions' => array_combine($photoIds, $samePositions)]);
            trustExpect(str_contains($photoSave['body'], 'Ảnh không có thay đổi nên tin vẫn giữ nguyên.') && !str_contains($photoSave['body'], 'Đã cập nhật ảnh') && $photoModel->findOwned($listingId, $hostId)['moderation_status'] === 'approved', 'Saving unchanged photos took a live listing off the site.');
        }
        $photoSave = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/photos', ['_token' => $photoToken, 'positions' => array_combine($photoIds, [2, 1])]);
        trustExpect(str_contains($photoSave['body'], 'Đã cập nhật ảnh và gửi tin duyệt lại.') && $photoModel->findOwned($listingId, $hostId)['moderation_status'] === 'pending' && array_map('intval', array_column($photoModel->photos($listingId, $hostId), 'id')) === array_reverse($photoIds), 'A new photo order did not go back to review.');
        (new \App\Models\AdminRepository())->moderate($listingId, $adminId, 'approved', 'Trust photo fixture');
        $photoSave = trustHttp($hostClient, $baseUrl, '/host/listings/'.$listingId.'/photos', ['_token' => $photoToken, 'positions' => array_combine($photoIds, [1, 2]), 'remove' => [$photoIds[0]]]);
        trustExpect(str_contains($photoSave['body'], 'Đã cập nhật ảnh và gửi tin duyệt lại.') && $photoModel->findOwned($listingId, $hostId)['moderation_status'] === 'pending' && count($photoModel->photos($listingId, $hostId)) === 1, 'Removing a photo did not go back to review.');

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
