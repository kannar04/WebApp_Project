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
    public function thread(string $id): void
    {
        $user = $this->requireAuth();
        try {
            $booking = (new \App\Models\Booking())->detail((int)$id, (int)$user['id']);
            $conversation = ConversationService::bookingPanel((int)$id, (int)$user['id'], $booking);
            if ($conversation === null) { throw new \DomainException('Không tìm thấy cuộc trò chuyện.'); }
        } catch (\DomainException $exception) {
            http_response_code(404); $this->render('errors/404', ['title'=>'Không tìm thấy cuộc trò chuyện']); return;
        }
        $this->render('messages/show', compact('booking', 'conversation') + ['title'=>'Tin nhắn · '.$booking['title']]);
    }

    public function updates(string $id): never
    {
        $user = $this->requireAuth();
        header('Cache-Control: no-store');
        try {
            $model = new BookingMessage();
            $state = $model->state((int)$id, (int)$user['id']);
            $messages = $model->thread((int)$id, (int)$user['id']);
            $this->json(true, '', ['state'=>$state, 'messages'=>$messages]);
        } catch (\DomainException $exception) {
            $this->json(false, 'Không tìm thấy cuộc trò chuyện.', null, [], 404);
        }
    }

    public function index(): void
    {
        $user = $this->requireAuth();
        $this->render('messages/index', ['title' => 'Tin nhắn', 'directConversations'=>(new \App\Models\DirectConversation())->inbox((int)$user['id']), 'conversations' => (new BookingMessage())->inbox((int) $user['id'])]);
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
        $ajax = str_contains((string)($_SERVER['HTTP_ACCEPT']??''), 'application/json');
        if ($body === null) {
            if ($ajax) { $this->json(false, 'Tin nhắn cần từ 1 đến 2000 ký tự.', null, [], 422); }
            ConversationService::keepDraft($bookingId, $raw);
            Session::flash('error', 'Tin nhắn cần từ 1 đến '.BookingMessage::MAX_LENGTH.' ký tự.');
            $this->redirect('/messages/'.$bookingId);
        }
        try {
            (new BookingMessage())->send($bookingId, (int) $user['id'], $body);
            if ($ajax) { $this->json(true, ''); }
        } catch (\DomainException $exception) {
            if ($ajax) { $this->json(false, $exception->getMessage(), null, [], 422); }
            ConversationService::keepDraft($bookingId, $raw);
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('/messages/'.$bookingId);
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
