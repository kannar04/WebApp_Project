<?php

declare(strict_types=1);

use App\Services\BookingService;
use Core\ProcedureConnection as Database;

require dirname(__DIR__) . '/core/bootstrap.php';

function fixtureRead(\Core\ProcedureConnection $db, int $id, string $field): mixed
{
    $statement = $db->prepare('CALL sp_test_booking_inspect(?)');
    $statement->execute([$id]);
    return $statement->fetch()[$field];
}

$service = new BookingService();
$db = Database::connection();
$start = (new DateTimeImmutable('today'))->modify('+3000 days');
$end = $start->modify('+2 days');
$bookingIds = [];

try {
    $quote = $service->quote(1, $start->format('Y-m-d'), $end->format('Y-m-d'), 2);
    if ($quote['nights'] !== 2 || abs($quote['total'] - 2650000.0) > 0.01) {
        throw new RuntimeException('Quote không đúng snapshot giá seed.');
    }

    $bookingId = $service->create(1, 3, $start->format('Y-m-d'), $end->format('Y-m-d'), 2);
    $bookingIds[] = $bookingId;
    $nightCount = (int) fixtureRead($db, $bookingId, 'nights');
    if ($nightCount !== 2) {
        throw new RuntimeException('Không tạo đủ booking_nights.');
    }

    try {
        $service->create(1, 1, $start->format('Y-m-d'), $end->format('Y-m-d'), 1);
        throw new RuntimeException('Double-booking không bị chặn.');
    } catch (DomainException $expected) {
        if (!str_contains($expected->getMessage(), 'yêu cầu đặt chỗ khác')) {
            throw $expected;
        }
    }

    $service->hostTransition($bookingId, 2, 'confirmed');
    $refund = $service->cancelByGuest($bookingId, 3, 'Automated integration test');
    $status = fixtureRead($db, $bookingId, 'status');
    if ($status !== 'cancelled' || (float) $refund['refund_amount'] !== 2650000.0) {
        throw new RuntimeException('Vòng đời hoặc tính hoàn tiền không đúng.');
    }

    $events = (int) fixtureRead($db, $bookingId, 'events');
    $notifications = (int) fixtureRead($db, $bookingId, 'notifications');
    if ($events !== 3 || $notifications !== 3) {
        throw new RuntimeException('Event/notification dataflow không đầy đủ.');
    }
    $adminBookingId = $service->create(1, 3, $start->format('Y-m-d'), $end->format('Y-m-d'), 1);
    $bookingIds[] = $adminBookingId;
    $service->adminTransition($adminBookingId, 1, 'rejected');
    $adminStatus = fixtureRead($db, $adminBookingId, 'status');
    if ($adminStatus !== 'rejected') {
        throw new RuntimeException('Admin transition không cập nhật đúng trạng thái.');
    }
    echo "PASS booking flow: quote -> pending -> confirmed -> cancelled; double-booking blocked; admin transition" . PHP_EOL;
} finally {
    foreach ($bookingIds as $bookingId) {
        $db->beginTransaction();
        $db->prepare('CALL sp_test_cleanup_booking(?)')->execute([$bookingId]);
        $db->commit();
    }
}

