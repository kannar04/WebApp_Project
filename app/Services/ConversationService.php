<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\BookingMessage;
use App\Models\CheckInReminder;
use Core\Session;

/** Page-level glue for booking messages and check-in reminders. Navigation helpers never throw. */
final class ConversationService
{
    public const REMINDER_INTERVAL_SECONDS = 1800;
    private const REMINDER_SESSION_KEY = '_checkin_reminders_checked';
    private const DRAFT_SESSION_KEY = '_message_draft';

    /** Navbar unread badge; also runs the throttled reminder check. Returns 0 if the database is unavailable. */
    public static function navigation(int $userId): int
    {
        self::remindOpportunistically($userId);
        try {
            return (new BookingMessage())->unreadCount($userId);
        } catch (\Throwable $exception) {
            error_log('Home2Home message badge skipped: '.$exception::class);
            return 0;
        }
    }

    /** At most once per session and user every 30 minutes; failures are logged and never reach the page. */
    public static function remindOpportunistically(int $userId): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) { return; }
        $last = $_SESSION[self::REMINDER_SESSION_KEY] ?? null;
        if (is_array($last) && ($last['user'] ?? null) === $userId
            && time() - (int) ($last['at'] ?? 0) < self::REMINDER_INTERVAL_SECONDS) {
            return;
        }
        // Record the attempt first so an unavailable database is not retried on every page load.
        $_SESSION[self::REMINDER_SESSION_KEY] = ['user' => $userId, 'at' => time()];
        try {
            (new CheckInReminder())->run(CheckInReminder::DEFAULT_DAYS, $userId);
        } catch (\Throwable $exception) {
            error_log('Home2Home check-in reminders skipped: '.$exception::class);
        }
    }

    /** Thread data for the booking detail page; null when the viewer is not the Guest or the listing Host. */
    public static function bookingPanel(int $bookingId, int $actorId, array $booking): ?array
    {
        if ($actorId !== (int) $booking['guest_id'] && $actorId !== (int) $booking['host_id']) { return null; }
        $messages = new BookingMessage();
        try {
            $state = $messages->state($bookingId, $actorId);
            $thread = $messages->thread($bookingId, $actorId);
        } catch (\DomainException $exception) {
            return null;
        }
        $draft = Session::pull(self::DRAFT_SESSION_KEY);
        $draftBody = is_array($draft) && ($draft['booking'] ?? null) === $bookingId ? (string) ($draft['body'] ?? '') : '';
        return ['state' => $state, 'messages' => $thread, 'draft' => $draftBody];
    }

    /** "2026-10-13 09:05:00.000000" -> "13/10/2026 09:05" for message timestamps. */
    public static function displayTime(?string $value): string
    {
        $time = self::parseTime($value);
        return $time ? $time->format('d/m/Y H:i') : (string) $value;
    }

    /** Machine-readable value for <time datetime>. */
    public static function isoTime(?string $value): string
    {
        $time = self::parseTime($value);
        return $time ? $time->format('Y-m-d\TH:i:s') : '';
    }

    public static function displayDate(?string $value): string
    {
        $date = $value ? \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10)) : false;
        return $date ? $date->format('d/m/Y') : (string) $value;
    }

    private static function parseTime(?string $value): ?\DateTimeImmutable
    {
        if (!$value) { return null; }
        $time = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19));
        return $time ?: null;
    }

    /** Keeps a rejected message so the sender does not lose it after the redirect. */
    public static function keepDraft(int $bookingId, mixed $raw): void
    {
        $body = is_string($raw) && mb_check_encoding($raw, 'UTF-8') ? mb_substr($raw, 0, BookingMessage::MAX_LENGTH + 100) : '';
        Session::put(self::DRAFT_SESSION_KEY, ['booking' => $bookingId, 'body' => $body]);
    }
}
