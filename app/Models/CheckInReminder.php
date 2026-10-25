<?php
declare(strict_types=1);
namespace App\Models;

use Core\ProcedureConnection;

/** Idempotent in-app check-in reminders for confirmed bookings (one per booking, participant and kind). */
final class CheckInReminder
{
    public const DEFAULT_DAYS = 1;

    /** Creates missing reminders for check-ins from today (Vietnam date) to today + $days; null user = everyone. */
    public function run(int $days = self::DEFAULT_DAYS, ?int $userId = null): int
    {
        $statement = ProcedureConnection::connection()->prepare('CALL sp_checkin_reminders_run(?,?)');
        $statement->execute([$days, $userId]);
        return (int) $statement->fetchColumn();
    }
}