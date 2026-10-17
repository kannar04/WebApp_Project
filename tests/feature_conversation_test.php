<?php
declare(strict_types=1);

// Nice-to-have conversation: booking messages H20/G23 (thread + Zalo link), A31 (Admin moderation), check-in reminders G31/A35.
// Works on a workspace dev database and inside fresh_setup_test.php (throwaway DB + HOME2HOME_TEST_URL server).
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\CheckInReminder;
use App\Models\Listing;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ConversationService;
use App\Services\ZaloLink;
use Core\ProcedureConnection;

require dirname(__DIR__).'/core/bootstrap.php';

const CONVERSATION_DEMO_PASSWORD = 'Password123!';

function conversationExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

/** The operation must be refused with a DomainException (a SIGNAL from a routine or a PHP shape check). */
function conversationRejects(callable $operation, array $arguments, string $message): string
{
    try { $operation(...$arguments); } catch (DomainException $exception) { return $exception->getMessage(); }
    throw new RuntimeException($message);
}

function conversationSendRaw(int $bookingId, int $actorId, ?string $body): void
{
    ProcedureConnection::connection()->prepare('CALL sp_booking_message_send(?,?,?)')->execute([$bookingId, $actorId, $body]);
}

function conversationInspect(int $bookingId, int $userId): array
{
    $statement = ProcedureConnection::connection()->prepare('CALL sp_test_conversation_inspect(?,?)');
    $statement->execute([$bookingId, $userId]);
    return $statement->fetch();
}

function conversationComplete(int $bookingId, int $daysSinceCheckout): void
{
    ProcedureConnection::connection()->prepare('CALL sp_test_conversation_complete(?,?)')->execute([$bookingId, $daysSinceCheckout]);
}

function conversationRunCli(array $arguments): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__).'/database/checkin_reminders.php'], $arguments);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    conversationExpect(is_resource($process), 'Reminder CLI could not start.');
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => (string) $output];
}

function conversationHttpClient(): CurlHandle
{
    $client = curl_init();
    curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => '',
        CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true]);
    return $client;
}

function conversationHttp(CurlHandle $client, string $baseUrl, string $path, ?array $post = null): array
{
    curl_setopt($client, CURLOPT_URL, $baseUrl.$path);
    if ($post !== null) {
        curl_setopt($client, CURLOPT_POST, true);
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($post));
    } else {
        curl_setopt($client, CURLOPT_HTTPGET, true);
    }
    $raw = curl_exec($client);
    conversationExpect(is_string($raw), 'HTTP request failed: '.$path);
    $headerSize = (int) curl_getinfo($client, CURLINFO_HEADER_SIZE);
    $location = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $headerSize), $match) ? $match[1] : '';
    return ['status' => (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE), 'location' => $location, 'body' => substr($raw, $headerSize)];
}

function conversationToken(string $html): string
{
    conversationExpect((bool) preg_match('/name="csrf-token" content="([^"]+)"|name="_token" value="([^"]+)"/', $html, $match), 'CSRF token missing.');
    return $match[1] !== '' ? $match[1] : $match[2];
}

function conversationLogin(CurlHandle $client, string $baseUrl, string $email): string
{
    $form = conversationHttp($client, $baseUrl, '/login');
    $login = conversationHttp($client, $baseUrl, '/login', ['_token' => conversationToken($form['body']), 'email' => $email, 'password' => CONVERSATION_DEMO_PASSWORD]);
    conversationExpect($login['status'] === 302 && !str_contains($login['location'], '/login'), 'Login failed: '.$email);
    return conversationToken(conversationHttp($client, $baseUrl, '/messages')['body']);
}

$appConfig = require dirname(__DIR__).'/config/app.php';
$dbConfig = require dirname(__DIR__).'/config/database.php';
conversationExpect($appConfig['env'] === 'local' && in_array($dbConfig['host'], ['localhost', '127.0.0.1', '::1'], true), 'Run fixture tests only on a local database.');
$database = ProcedureConnection::connection();
$users = new User();
$host = $users->findByEmail('host@home2home.test');
$guest = $users->findByEmail('guest@home2home.test');
$admin = $users->findByEmail('admin@home2home.test');
conversationExpect($host && $guest && $admin, 'Local demo accounts required.');
$hostId = (int) $host['id']; $guestId = (int) $guest['id']; $adminId = (int) $admin['id'];
$messages = new BookingMessage();
$reminders = new CheckInReminder();
$bookingService = new BookingService();
$listingId = null;
$outsiderId = null;
$tag = bin2hex(random_bytes(5));
$today = new DateTimeImmutable('today');

try {
    // H20/G23: Zalo link normalisation (Vietnamese mobiles only).
    foreach (['0900000002' => '0900000002', '+84 912 345 678' => '0912345678', '84912345678' => '0912345678', '0084 912 345 678' => '0912345678',
        '091-234-5678' => '0912345678', '912345678' => '0912345678'] as $phone => $digits) {
        conversationExpect(ZaloLink::url((string) $phone) === 'https://zalo.me/'.$digits, 'Zalo normalisation failed: '.$phone);
    }
    foreach ([null, '', 'abc', '123', '(028) 3822 1234', '0123456789', '09123456789'] as $phone) {
        conversationExpect(ZaloLink::url($phone) === null, 'Invalid phone produced a Zalo link.');
    }
    echo "PASS Zalo link: +84/84/0084/local formats normalised, landline/short/invalid numbers ignored\n";

    $listings = new Listing();
    $policy = $listings->policies()[0];
    $listingData = [
        'property_type_id' => (int) $listings->types()[0]['id'], 'cancellation_policy_id' => (int) $policy['id'],
        'title' => 'Conversation fixture '.$tag, 'address' => 'Test only', 'city' => 'Audit', 'description' => 'Temporary conversation fixture',
        'nightly_price' => 100000, 'cleaning_fee' => 10000, 'max_guests' => 2, 'room_count' => 1, 'bedroom_count' => 1, 'bed_count' => 1,
        'check_in_time' => '15:30', 'check_out_time' => '11:00',
    ];
    $listingId = $listings->create($hostId, $listingData);
    $database->prepare('CALL sp_test_conversation_listing_approve(?)')->execute([$listingId]);
    $outsiderEmail = 'audit-'.$tag.'@home2home.test';
    $outsiderId = $users->create($outsiderEmail, bin2hex(random_bytes(16)), 'Audit outsider', null);
    $far = $today->modify('+6000 days');
    $bookingA = $bookingService->create($listingId, $guestId, $far->format('Y-m-d'), $far->modify('+2 days')->format('Y-m-d'), 2);
    $bookingB = $bookingService->create($listingId, $guestId, $far->modify('+10 days')->format('Y-m-d'), $far->modify('+11 days')->format('Y-m-d'), 1);
    $bookingC = $bookingService->create($listingId, $guestId, $far->modify('+20 days')->format('Y-m-d'), $far->modify('+21 days')->format('Y-m-d'), 1);
    $bookingG = $bookingService->create($listingId, $guestId, $far->modify('+30 days')->format('Y-m-d'), $far->modify('+32 days')->format('Y-m-d'), 1);
    $bookingService->hostTransition($bookingB, $hostId, 'rejected');
    $bookingService->cancelByGuest($bookingC, $guestId, 'Fixture cancellation');
    $bookingService->hostTransition($bookingG, $hostId, 'confirmed');

    // H20/G23: sending, anti-flood notifications, read receipts.
    $hostUnreadBefore = $messages->unreadCount($hostId);
    $guestUnreadBefore = $messages->unreadCount($guestId);
    $state = $messages->state($bookingA, $guestId);
    conversationExpect($state['my_role'] === 'guest' && (int) $state['other_id'] === $hostId && (int) $state['can_send'] === 1, 'Pending booking state wrong.');
    $xss = '<script>alert("conv-'.$tag.'")</script> & "quotes"'."\nDòng thứ hai";
    $first = $messages->send($bookingA, $guestId, $xss);
    conversationExpect($first > 0, 'Message id missing.');
    $inspect = conversationInspect($bookingA, $hostId);
    conversationExpect((int) $inspect['messages'] === 1 && (int) $inspect['message_notifications'] === 1 && (int) $inspect['unread_message_notifications'] === 1, 'First message notification missing.');
    $messages->send($bookingA, $guestId, 'Cho mình hỏi giờ nhận phòng sớm được không? conv-'.$tag);
    $inspect = conversationInspect($bookingA, $hostId);
    conversationExpect((int) $inspect['messages'] === 2 && (int) $inspect['message_notifications'] === 1, 'Anti-flood created a second unread notification.');
    conversationExpect($messages->unreadCount($hostId) === $hostUnreadBefore + 2, 'Host unread badge count wrong.');
    $hostThread = $messages->thread($bookingA, $hostId);
    conversationExpect(count($hostThread) === 2 && (int) $hostThread[0]['is_mine'] === 0 && $hostThread[0]['body'] === trim($xss) && $hostThread[0]['read_at'] === null, 'Host thread projection wrong.');
    $inspect = conversationInspect($bookingA, $hostId);
    conversationExpect((int) $inspect['unread_messages'] === 0 && (int) $inspect['unread_message_notifications'] === 0, 'Opening the thread did not mark messages/notifications read.');
    conversationExpect($messages->unreadCount($hostId) === $hostUnreadBefore, 'Host unread badge not cleared.');
    $messages->send($bookingA, $guestId, 'Mình sẽ đến khoảng 16 giờ.');
    conversationExpect((int) conversationInspect($bookingA, $hostId)['message_notifications'] === 2, 'New notification after read was not created.');
    $messages->send($bookingA, $hostId, "Được bạn nhé.\nHẹn gặp bạn.");
    $guestThread = $messages->thread($bookingA, $guestId);
    conversationExpect(count($guestThread) === 4 && (int) $guestThread[3]['is_mine'] === 0 && $guestThread[0]['read_at'] !== null && $guestThread[2]['read_at'] === null, 'Guest thread/read receipts wrong.');
    conversationExpect($messages->unreadCount($guestId) === $guestUnreadBefore, 'Guest unread not cleared by opening the thread.');
    echo "PASS messages: send, one unread notification per booking (anti-flood), read marking, receipts and unread badge counts\n";

    // Validation in PHP (shape) and SQL (business rules).
    conversationExpect(BookingMessage::normalize("  Xin chào\r\nbạn  ") === "Xin chào\nbạn", 'Normalisation failed.');
    conversationExpect(BookingMessage::normalize(str_repeat('ệ', 2000)) !== null && BookingMessage::normalize(str_repeat('ệ', 2001)) === null, 'PHP length guard wrong.');
    foreach ([null, '', "   \n\t ", ['array'], "\xC3\x28"] as $invalid) { conversationExpect(BookingMessage::normalize($invalid) === null, 'Invalid body accepted by PHP.'); }
    conversationRejects('conversationSendRaw', [$bookingA, $guestId, "  \n\t  "], 'Whitespace-only body accepted by SQL.');
    conversationRejects('conversationSendRaw', [$bookingA, $guestId, null], 'NULL body accepted by SQL.');
    conversationRejects('conversationSendRaw', [$bookingA, $guestId, str_repeat('a', 2001)], '2001-character body accepted by SQL.');
    $long = $messages->send($bookingA, $guestId, str_repeat('ệ', 2000));
    conversationExpect($long > 0, '2000 multibyte characters rejected.');
    echo "PASS validation: 1-2000 characters (multibyte), whitespace/NULL/oversized refused in PHP and in the routine\n";

    // Ownership, role and state guards.
    conversationRejects([$messages, 'send'], [$bookingA, $adminId, 'Admin không phải người tham gia'], 'Admin sent as a participant.');
    conversationRejects([$messages, 'send'], [$bookingA, $outsiderId, 'Người ngoài'], 'Outsider sent a message.');
    conversationRejects([$messages, 'send'], [PHP_INT_MAX, $guestId, 'Không tồn tại'], 'Message to a missing booking accepted.');
    conversationRejects([$messages, 'thread'], [$bookingA, $outsiderId], 'Outsider read the thread.');
    conversationRejects([$messages, 'thread'], [$bookingA, $adminId], 'Admin used the participant thread (would mark messages read).');
    conversationRejects([$messages, 'state'], [$bookingA, $outsiderId], 'Outsider read the state.');
    conversationExpect($messages->inbox($outsiderId) === [], 'Outsider inbox leaked conversations.');
    $rejected = conversationRejects([$messages, 'send'], [$bookingB, $guestId, 'Còn phòng không?'], 'Rejected booking accepted a message.');
    conversationExpect(str_contains($rejected, 'chỉ còn để xem'), 'Rejected booking message unclear.');
    conversationRejects([$messages, 'send'], [$bookingC, $hostId, 'Xin lỗi bạn'], 'Cancelled booking accepted a message.');
    conversationExpect((int) $messages->state($bookingB, $guestId)['can_send'] === 0 && $messages->thread($bookingB, $guestId) === [], 'Read-only booking state wrong.');
    conversationComplete($bookingG, 3);
    conversationExpect((int) $messages->state($bookingG, $guestId)['can_send'] === 1, 'Completed stay within 14 days is closed.');
    $messages->send($bookingG, $guestId, 'Cảm ơn Host, chuyến đi rất vui!');
    conversationComplete($bookingG, 14);
    $messages->send($bookingG, $hostId, 'Cảm ơn bạn đã ghé!');
    conversationComplete($bookingG, 15);
    conversationExpect((int) $messages->state($bookingG, $hostId)['can_send'] === 0, 'Completed stay after 14 days still open.');
    $closed = conversationRejects([$messages, 'send'], [$bookingG, $guestId, 'Còn ai không?'], 'Message accepted 15 days after check-out.');
    conversationExpect(str_contains($closed, '14 ngày'), 'Closed conversation message unclear.');
    echo "PASS guards: participants only (Admin/outsider/missing booking refused), rejected/cancelled read-only, completed open through check-out + 14 days\n";

    // Inbox: newest conversation first, other party, snippet and unread counts.
    $messages->send($bookingA, $hostId, 'Nhắn thêm: nhớ mang giấy tờ tùy thân nhé.');
    $inbox = $messages->inbox($guestId);
    conversationExpect($inbox && (int) $inbox[0]['booking_id'] === $bookingA && $inbox[0]['other_name'] === $host['full_name'] && (int) $inbox[0]['unread_count'] === 1
        && str_starts_with($inbox[0]['last_snippet'], 'Nhắn thêm') && (int) $inbox[0]['last_mine'] === 0, 'Guest inbox projection wrong.');
    $ids = array_map('intval', array_column($messages->inbox($hostId), 'booking_id'));
    conversationExpect(in_array($bookingA, $ids, true) && in_array($bookingG, $ids, true) && !in_array($bookingB, $ids, true), 'Host inbox wrong.');
    $closedThread = $messages->thread($bookingG, $guestId);
    conversationExpect(count($closedThread) === 2 && $closedThread[1]['body'] === 'Cảm ơn bạn đã ghé!', 'Closed conversation is no longer readable.');
    echo "PASS inbox: newest first, other party, snippet, unread count; bookings without messages omitted; closed threads stay readable\n";

    // A31: Admin search, thread, hide/unhide with audited reason.
    $byKeyword = $messages->adminSearch($adminId, 'conv-'.$tag, null, '');
    conversationExpect(count($byKeyword) === 2, 'Keyword search failed.');
    foreach ($byKeyword as $row) { conversationExpect(str_contains($row['body'], 'conv-'.$tag), 'Keyword search returned unrelated rows.'); }
    conversationExpect(count($messages->adminSearch($adminId, '%', $bookingA, '')) === 0, 'LIKE wildcard was not escaped.');
    $byBooking = $messages->adminSearch($adminId, '', $bookingA, '');
    conversationExpect(count($byBooking) === 6 && count(array_unique(array_column($byBooking, 'booking_id'))) === 1, 'Booking search failed.');
    conversationExpect(count($messages->adminSearch($adminId, '', $bookingG, 'guest@home2home.test')) === 2
        && $messages->adminSearch($adminId, '', null, $outsiderEmail) === [], 'User search failed.');
    $adminThread = $messages->adminThread($adminId, $bookingA);
    conversationExpect(count($adminThread) === 6 && $adminThread[0]['sender_role'] === 'guest' && $adminThread[0]['body'] === trim($xss), 'Admin thread wrong.');
    conversationExpect((int) conversationInspect($bookingA, $guestId)['unread_messages'] === 1, 'Admin reading changed read receipts.');
    conversationRejects([$messages, 'adminSearch'], [$guestId, '', null, ''], 'Guest searched all messages.');
    conversationRejects([$messages, 'adminThread'], [$hostId, $bookingA], 'Host used the admin thread.');
    conversationRejects([$messages, 'moderate'], [$first, $hostId, 'hide', 'Không phải admin'], 'Host moderated a message.');
    conversationRejects([$messages, 'moderate'], [$first, $adminId, 'hide', 'abc'], 'Short moderation reason accepted.');
    conversationRejects([$messages, 'moderate'], [$first, $adminId, 'delete', 'Thao tác không có'], 'Unknown moderation action accepted.');
    conversationRejects([$messages, 'moderate'], [PHP_INT_MAX, $adminId, 'hide', 'Tin nhắn không tồn tại'], 'Missing message moderated.');
    conversationExpect($messages->moderate($first, $adminId, 'hide', 'Nội dung chứa mã độc thử nghiệm') === $bookingA, 'Hide did not return the booking.');
    conversationRejects([$messages, 'moderate'], [$first, $adminId, 'hide', 'Ẩn lần thứ hai'], 'Message hidden twice.');
    $hiddenView = $messages->thread($bookingA, $guestId)[0];
    conversationExpect((int) $hiddenView['is_hidden'] === 1 && $hiddenView['body'] === null, 'Hidden body leaked to participants.');
    $adminView = $messages->adminThread($adminId, $bookingA)[0];
    conversationExpect($adminView['hidden_at'] !== null && $adminView['hidden_reason'] === 'Nội dung chứa mã độc thử nghiệm' && $adminView['body'] === trim($xss), 'Admin hidden view wrong.');
    conversationExpect((int) conversationInspect($bookingA, $guestId)['message_audits'] === 1, 'Hide was not audited.');
    $hostUnread = $messages->unreadCount($hostId);
    $unreadMessage = $messages->send($bookingA, $guestId, 'Tin nhắn sẽ bị ẩn khi chưa đọc');
    conversationExpect($messages->unreadCount($hostId) === $hostUnread + 1, 'Unread count did not increase.');
    $messages->moderate($unreadMessage, $adminId, 'hide', 'Ẩn tin nhắn chưa đọc');
    conversationExpect($messages->unreadCount($hostId) === $hostUnread, 'Hidden unread message still counted.');
    $messages->moderate($first, $adminId, 'unhide', 'Đã xác minh nội dung an toàn');
    conversationRejects([$messages, 'moderate'], [$first, $adminId, 'unhide', 'Hiện lần thứ hai'], 'Message restored twice.');
    conversationExpect((int) $messages->thread($bookingA, $guestId)[0]['is_hidden'] === 0 && (int) conversationInspect($bookingA, $guestId)['message_audits'] === 3, 'Unhide/audit failed.');
    echo "PASS admin: keyword/booking/user search (wildcards literal), read-only thread, hide/unhide with reason, idempotency guard, audit log, role guards\n";

    // G31/A35: idempotent check-in reminders (Vietnam date window), throttled page-load trigger, CLI job.
    $bookingD = $bookingService->create($listingId, $guestId, $today->modify('+1 day')->format('Y-m-d'), $today->modify('+2 days')->format('Y-m-d'), 2);
    $bookingE = $bookingService->create($listingId, $guestId, $today->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'), 1);
    $bookingF = $bookingService->create($listingId, $guestId, $today->modify('+3 days')->format('Y-m-d'), $today->modify('+4 days')->format('Y-m-d'), 1);
    $bookingService->hostTransition($bookingD, $hostId, 'confirmed');
    $bookingService->hostTransition($bookingF, $hostId, 'confirmed');
    // The Host moves check-in to 16:00 after booking: page and reminders follow it, the refund deadline keeps 15:30.
    $listings->update($listingId, $hostId, ['check_in_time' => '16:00'] + $listingData);
    $detailD = (new Booking())->detail($bookingD, $guestId);
    conversationExpect($detailD['current_check_in_time'] === '16:00' && substr((string) $detailD['policy']['check_in_time'], 0, 5) === '15:30', 'Booking detail did not follow the new check-in time.');
    conversationRejects([$reminders, 'run'], [-1, null], 'Negative reminder window accepted.');
    conversationRejects([$reminders, 'run'], [31, null], 'Oversized reminder window accepted.');
    $_SESSION = [];
    ConversationService::remindOpportunistically($guestId);
    $inspectD = conversationInspect($bookingD, $guestId);
    conversationExpect((int) $inspectD['reminders'] === 1 && (int) $inspectD['reminder_notifications'] === 1 && (int) $inspectD['linked_reminders'] === 1, 'Guest page-load reminder missing.');
    conversationExpect(str_contains($inspectD['reminder_body'], 'Conversation fixture '.$tag) && str_contains($inspectD['reminder_body'], $today->modify('+1 day')->format('d/m/Y'))
        && str_contains($inspectD['reminder_body'], '16:00') && !str_contains($inspectD['reminder_body'], '15:30') && str_contains($inspectD['reminder_body'], '#'.$bookingD), 'Reminder text wrong.');
    conversationExpect((int) conversationInspect($bookingE, $guestId)['reminders'] === 0, 'Pending booking was reminded.');
    $bookingService->hostTransition($bookingE, $hostId, 'confirmed');
    ConversationService::remindOpportunistically($guestId);
    conversationExpect((int) conversationInspect($bookingE, $guestId)['reminders'] === 0, 'Page-load trigger ignored the 30-minute throttle.');
    $_SESSION = [];
    ConversationService::remindOpportunistically($guestId);
    conversationExpect((int) conversationInspect($bookingE, $guestId)['reminder_notifications'] === 1, 'Reminder after throttle window missing.');
    ConversationService::remindOpportunistically($hostId);
    $inspectHost = conversationInspect($bookingD, $hostId);
    conversationExpect((int) $inspectHost['reminders'] === 2 && (int) $inspectHost['reminder_notifications'] === 1 && str_starts_with((string) $inspectHost['reminder_body'], 'Khách '), 'Host reminder missing (user switch must bypass the throttle).');
    conversationExpect($reminders->run(1, $guestId) === 0 && $reminders->run(1, $hostId) === 0, 'Reminder run not idempotent.');
    conversationExpect((int) conversationInspect($bookingF, $guestId)['reminders'] === 0, 'Check-in outside the window was reminded.');
    $reminders->run(1, null);
    conversationExpect($reminders->run(1, null) === 0, 'Global reminder run not idempotent.');
    $cli = conversationRunCli(['--days=3']);
    conversationExpect($cli['code'] === 0 && preg_match('/: (\d+) created/', $cli['output'], $match) && (int) $match[1] >= 2, 'Reminder CLI failed.');
    conversationExpect((int) conversationInspect($bookingF, $guestId)['reminder_notifications'] === 1 && (int) conversationInspect($bookingF, $hostId)['reminders'] === 2, 'CLI did not remind both participants.');
    $again = conversationRunCli(['--days=3']);
    conversationExpect($again['code'] === 0 && str_contains($again['output'], ': 0 created'), 'Reminder CLI not idempotent.');
    conversationExpect(conversationRunCli(['--days=99'])['code'] === 2, 'Reminder CLI accepted an invalid window.');
    $source = (string) file_get_contents(dirname(__DIR__).'/database/checkin_reminders.php');
    conversationExpect(str_contains($source, "PHP_SAPI !== 'cli'") && str_contains($source, 'schtasks /Create'), 'Reminder CLI guard/schedule documentation missing.');
    $outage = proc_open([PHP_BINARY, '-r', 'require '.var_export(dirname(__DIR__).'/core/bootstrap.php', true).'; $_SESSION=[]; echo App\Services\ConversationService::navigation(1), "|", isset($_SESSION["_checkin_reminders_checked"]) ? "throttled" : "none";'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $outagePipes, dirname(__DIR__), array_merge(getenv(), ['DB_PORT' => '1']));
    conversationExpect(is_resource($outage), 'Outage probe could not start.');
    $outageOutput = stream_get_contents($outagePipes[1]); stream_get_contents($outagePipes[2]);
    fclose($outagePipes[1]); fclose($outagePipes[2]);
    conversationExpect(proc_close($outage) === 0 && trim((string) $outageOutput) === '0|throttled', 'Navigation helper broke when the database was unavailable.');
    echo "PASS reminders: confirmed check-ins only, guest+host text with the listing's current date/time, PK idempotency, 30-minute page throttle, CLI job, safe during DB outage\n";

    $baseUrl = rtrim((string) getenv('HOME2HOME_TEST_URL'), '/');
    if ($baseUrl === '') {
        echo "SKIP HTTP checks: set HOME2HOME_TEST_URL to a running server for this database\n";
    } else {
        $anonymous = conversationHttpClient();
        foreach (['/messages', '/admin/messages'] as $path) {
            $page = conversationHttp($anonymous, $baseUrl, $path);
            conversationExpect($page['status'] === 302 && str_contains($page['location'], '/login'), 'Guest-only page open to visitors: '.$path);
        }
        $before = (int) conversationInspect($bookingA, $hostId)['messages'];
        $forged = conversationHttp($anonymous, $baseUrl, '/bookings/'.$bookingA.'/messages', ['body' => 'Forged']);
        conversationExpect($forged['status'] === 302 && (int) conversationInspect($bookingA, $hostId)['messages'] === $before, 'Anonymous POST without CSRF stored a message.');

        $messages->send($bookingA, $hostId, 'Badge test from Host');
        $guestClient = conversationHttpClient();
        $guestToken = conversationLogin($guestClient, $baseUrl, 'guest@home2home.test');
        $inboxPage = conversationHttp($guestClient, $baseUrl, '/messages');
        conversationExpect($inboxPage['status'] === 200 && str_contains($inboxPage['body'], 'Conversation fixture '.$tag) && str_contains($inboxPage['body'], 'nav-unread-badge')
            && str_contains($inboxPage['body'], 'chưa đọc'), 'Guest inbox page/badge failed.');
        $threadPage = conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingA);
        conversationExpect($threadPage['status'] === 200 && str_contains($threadPage['body'], 'id="booking-messages"') && str_contains($threadPage['body'], '&lt;script&gt;alert(&quot;conv-'.$tag)
            && !str_contains($threadPage['body'], '<script>alert') && str_contains($threadPage['body'], 'Tin nhắn đã bị quản trị viên ẩn.') && str_contains($threadPage['body'], 'js/messages.js')
            && !str_contains($threadPage['body'], 'zalo.me'), 'Guest thread page failed (escaping, hidden placeholder, script or premature Zalo link).');
        $stayPage = conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingD)['body'];
        conversationExpect(str_contains($stayPage, 'Giờ nhận phòng 16:00') && str_contains($stayPage, 'giờ nhận phòng lúc đặt (15:30)'), 'Booking page lost the new check-in time or the booked refund time.');
        conversationExpect(!str_contains(conversationHttp($guestClient, $baseUrl, '/messages')['body'], 'nav-unread-badge'), 'Badge remained after opening the thread.');
        $noToken = conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingA.'/messages', ['body' => 'Không có token']);
        conversationExpect($noToken['status'] === 302 && (int) conversationInspect($bookingA, $hostId)['messages'] === $before + 1, 'POST without CSRF token stored a message.');
        $sent = conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingA.'/messages', ['_token' => $guestToken, 'body' => "HTTP xin chào $tag\r\nDòng 2"]);
        conversationExpect($sent['status'] === 302 && str_contains($sent['location'], '/bookings/'.$bookingA.'#booking-messages'), 'Send did not redirect back to the thread.');
        $afterSend = conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingA)['body'];
        conversationExpect(str_contains($afterSend, 'Đã gửi tin nhắn.') && str_contains($afterSend, 'HTTP xin chào '.$tag.'<br />'), 'Sent message not rendered with line break.');
        conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingA.'/messages', ['_token' => $guestToken, 'body' => '   ']);
        $emptyPage = conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingA)['body'];
        conversationExpect(str_contains($emptyPage, 'Tin nhắn cần từ 1 đến 2000 ký tự.'), 'Empty message error not shown.');
        conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingB.'/messages', ['_token' => $guestToken, 'body' => 'Còn phòng không?', ]);
        $readOnly = conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingB)['body'];
        conversationExpect(str_contains($readOnly, 'chỉ còn để xem') && !str_contains($readOnly, 'name="body"'), 'Read-only booking still offers the form.');
        $confirmedPage = conversationHttp($guestClient, $baseUrl, '/bookings/'.$bookingD)['body'];
        conversationExpect(str_contains($confirmedPage, 'href="https://zalo.me/0900000002"') && str_contains($confirmedPage, 'Liên hệ qua Zalo'), 'Zalo link missing for confirmed booking.');
        conversationExpect(conversationHttp($guestClient, $baseUrl, '/admin/messages')['status'] === 403, 'Guest opened admin messages.');
        $notifications = conversationHttp($guestClient, $baseUrl, '/notifications')['body'];
        conversationExpect(str_contains($notifications, 'notification-checkin-reminder') && str_contains($notifications, 'Nhắc lịch: bạn nhận phòng'), 'Reminder not highlighted on /notifications.');

        $hostClient = conversationHttpClient();
        conversationLogin($hostClient, $baseUrl, 'host@home2home.test');
        $dashboard = conversationHttp($hostClient, $baseUrl, '/host')['body'];
        conversationExpect(str_contains($dashboard, 'href="https://zalo.me/0900000003"'), 'Zalo link missing on Host dashboard.');
        $hostNotifications = conversationHttp($hostClient, $baseUrl, '/notifications')['body'];
        conversationExpect(str_contains($hostNotifications, 'notification-booking-message') && str_contains($hostNotifications, '#booking-messages'), 'Message notification link missing.');
        conversationExpect(str_contains(conversationHttp($hostClient, $baseUrl, '/bookings/'.$bookingA)['body'], 'HTTP xin chào '.$tag), 'Host thread page failed.');

        $adminClient = conversationHttpClient();
        $adminToken = conversationLogin($adminClient, $baseUrl, 'admin@home2home.test');
        conversationExpect(str_contains(conversationHttp($adminClient, $baseUrl, '/admin')['body'], '/admin/messages'), 'Admin dashboard link missing.');
        $adminPage = conversationHttp($adminClient, $baseUrl, '/admin/messages?q='.rawurlencode('conv-'.$tag));
        conversationExpect($adminPage['status'] === 200 && str_contains($adminPage['body'], 'Kết quả tìm kiếm') && str_contains($adminPage['body'], '&lt;script&gt;'), 'Admin search page failed.');
        conversationExpect(conversationHttp($adminClient, $baseUrl, '/admin/messages?booking=abc')['status'] === 200, 'Malformed booking filter broke the page.');
        $adminThreadPage = conversationHttp($adminClient, $baseUrl, '/admin/messages?booking='.$bookingA);
        conversationExpect($adminThreadPage['status'] === 200 && str_contains($adminThreadPage['body'], 'moderation-reason-'.$first), 'Admin thread page failed.');
        $noAdminToken = conversationHttp($adminClient, $baseUrl, '/admin/messages/'.$first.'/moderate', ['action' => 'hide', 'reason' => 'Thiếu token CSRF', 'booking' => $bookingA]);
        conversationExpect($noAdminToken['status'] === 302 && $messages->adminThread($adminId, $bookingA)[0]['hidden_at'] === null, 'Moderation without CSRF token applied.');
        $hide = conversationHttp($adminClient, $baseUrl, '/admin/messages/'.$first.'/moderate', ['_token' => $adminToken, 'action' => 'hide', 'reason' => 'Ẩn qua HTTP để kiểm thử', 'booking' => $bookingA]);
        conversationExpect($hide['status'] === 302 && str_contains($hide['location'], '/admin/messages?booking='.$bookingA) && $messages->adminThread($adminId, $bookingA)[0]['hidden_at'] !== null, 'HTTP hide failed.');
        conversationExpect(str_contains(conversationHttp($adminClient, $baseUrl, '/admin/messages?booking='.$bookingA)['body'], 'Ẩn qua HTTP để kiểm thử'), 'Hidden reason not shown to Admin.');
        conversationExpect(str_contains(conversationHttp($adminClient, $baseUrl, '/bookings/'.$bookingA)['body'], 'Mở cuộc trò chuyện (quản trị)'), 'Admin booking page lacks the admin thread link.');
        $hostOnAdmin = conversationHttp($hostClient, $baseUrl, '/admin/messages/'.$first.'/moderate', ['_token' => conversationToken(conversationHttp($hostClient, $baseUrl, '/messages')['body']), 'action' => 'unhide', 'reason' => 'Host không có quyền']);
        conversationExpect($hostOnAdmin['status'] === 403 && $messages->adminThread($adminId, $bookingA)[0]['hidden_at'] !== null, 'Host moderated over HTTP.');
        foreach ([$anonymous, $guestClient, $hostClient, $adminClient] as $client) { curl_close($client); }
        echo "PASS HTTP: login redirects/403, CSRF on send and moderation, escaped nl2br thread, badge, flash errors, read-only form, Zalo links, reminders page, admin search/hide\n";
    }
} finally {
    if ($database->inTransaction()) { $database->rollBack(); }
    $database->beginTransaction();
    if ($listingId !== null) { $database->prepare('CALL sp_test_conversation_cleanup(?)')->execute([$listingId]); }
    if ($outsiderId !== null) { $database->prepare('CALL sp_test_cleanup_user(?)')->execute([$outsiderId]); }
    $database->commit();
}
echo "PASS conversation fixtures removed\n";
