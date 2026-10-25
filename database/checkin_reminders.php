<?php
declare(strict_types=1);

/*
 * Check-in reminders job: creates one in-app reminder for the Guest and one for the Host of every
 * confirmed booking whose check-in falls between today and today + N days (Vietnam date).
 * Idempotent: running it again never duplicates a reminder (booking_reminders primary key).
 *
 * Usage:   C:\xampp\php\php.exe database\checkin_reminders.php [--days=1]
 * Windows Task Scheduler, daily at 07:00 (one line; replace the project path):
 *   schtasks /Create /TN "Home2Home check-in reminders" /SC DAILY /ST 07:00 /F /TR "C:\xampp\php\php.exe C:\path\to\project\database\checkin_reminders.php --days=1"
 * Logged-in page loads also run a per-user check at most every 30 minutes, so the job is a backstop.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require dirname(__DIR__).'/core/bootstrap.php';

$days = App\Models\CheckInReminder::DEFAULT_DAYS;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--days=(\d{1,2})$/D', $argument, $match) && (int) $match[1] <= 30) {
        $days = (int) $match[1];
        continue;
    }
    fwrite(STDERR, "Usage: php database/checkin_reminders.php [--days=0..30]\n");
    exit(2);
}
try {
    $created = (new App\Models\CheckInReminder())->run($days, null);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Check-in reminders failed: '.$exception->getMessage()."\n");
    exit(1);
}
echo "Check-in reminders: {$created} created for check-ins within {$days} day(s).\n";