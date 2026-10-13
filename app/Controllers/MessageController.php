<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\BookingMessage;
use App\Services\ConversationService;
use Core\Controller;
use Core\Csrf;
use Core\Session;

/** Booking messages: participant inbox/send and Admin search/moderation. Routines re-check every rule. */
final class MessageController extends Controller
{
    public function index(): void
    {
        $user = $this->requireAuth();
        $this->render('messages/index', ['title' => 'Tin nhắn', 'conversations' => (new BookingMessage())->inbox((int) $user['id'])]);
    }

    public function send(string $id): never
    {
        Csrf::ensure();
        $user = $this->requireAuth();
        $bookingId = $this->positiveId($id);
        if ($bookingId === null) {
            Session::flash('error', 'Booking không tồn tại.');
            $this->redirect('/messages');
        }
        $raw = $_POST['body'] ?? null;
        $body = BookingMessage::normalize($raw);
        if ($body === null) {
            ConversationService::keepDraft($bookingId, $raw);
            Session::flash('error', 'Tin nhắn cần từ 1 đến '.BookingMessage::MAX_LENGTH.' ký tự.');
            $this->redirect('/bookings/'.$bookingId.'#booking-messages');
        }
        try {
            (new BookingMessage())->send($bookingId, (int) $user['id'], $body);
            Session::flash('success', 'Đã gửi tin nhắn.');
        } catch (\DomainException $exception) {
            ConversationService::keepDraft($bookingId, $raw);
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('/bookings/'.$bookingId.'#booking-messages');
    }

    public function admin(): void
    {
        $user = $this->requireRole('admin');
        $filters = [
            'q' => $this->queryText('q', 200),
            'booking' => $this->positiveId($_GET['booking'] ?? null),
            'user' => $this->queryText('user', 254),
        ];
        $messages = new BookingMessage();
        // A booking ID alone opens that conversation; any text filter switches to search results.
        $threadMode = $filters['booking'] !== null && $filters['q'] === '' && $filters['user'] === '';
        $thread = $threadMode ? $messages->adminThread((int) $user['id'], $filters['booking']) : null;
        $results = $threadMode ? [] : $messages->adminSearch((int) $user['id'], $filters['q'], $filters['booking'], $filters['user']);
        $this->render('admin/messages', ['title' => 'Tin nhắn booking', 'filters' => $filters, 'thread' => $thread, 'results' => $results]);
    }

    public function moderate(string $id): never
    {
        Csrf::ensure();
        $user = $this->requireRole('admin');
        $returnBooking = $this->positiveId($_POST['booking'] ?? null);
        $action = $_POST['action'] ?? '';
        $reason = $_POST['reason'] ?? '';
        $messageId = $this->positiveId($id);
        try {
            if ($messageId === null || !is_string($action) || !in_array($action, ['hide', 'unhide'], true) || !is_string($reason) || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 500) {
                throw new \DomainException('Thông tin kiểm duyệt không hợp lệ.');
            }
            $returnBooking = (new BookingMessage())->moderate($messageId, (int) $user['id'], $action, $reason);
            Session::flash('success', $action === 'hide' ? 'Đã ẩn tin nhắn và ghi nhật ký quản trị.' : 'Đã hiện lại tin nhắn và ghi nhật ký quản trị.');
        } catch (\DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect($returnBooking !== null ? '/admin/messages?booking='.$returnBooking : '/admin/messages');
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) { return $value > 0 ? $value : null; }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,18}$/D', $value)) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id === false ? null : $id;
    }

    private function queryText(string $key, int $maxLength): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? mb_substr(trim($value), 0, $maxLength) : '';
    }
}
