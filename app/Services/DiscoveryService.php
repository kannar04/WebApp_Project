<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Discovery;
use Core\Auth;

/**
 * Page-safe wrappers: browsing history and recommendations are optional extras,
 * so a failure is logged and the page keeps rendering without them.
 */
final class DiscoveryService
{
    public const HOME_RECOMMENDATIONS = 4;
    public const HOME_RECENT = 8;

    public static function recordView(array $listing): void
    {
        $user = Auth::user();
        if ($user === null || (int) ($listing['host_id'] ?? 0) === (int) $user['id']) { return; }
        try {
            (new Discovery())->recordView((int) $user['id'], (int) $listing['id']);
        } catch (\Throwable $exception) {
            error_log('Home2Home discovery view history skipped: '.$exception->getMessage());
        }
    }

    public static function recordSearch(string $source, array $filters): void
    {
        $user = Auth::user();
        if ($user === null) { return; }
        try {
            (new Discovery())->recordSearch((int) $user['id'], $source, $filters);
        } catch (\Throwable $exception) {
            error_log('Home2Home discovery search history skipped: '.$exception->getMessage());
        }
    }

    /** @return array{recommendations:array,recent:array} empty lists when the account has no history */
    public static function homeShelf(int $userId): array
    {
        $shelf = ['recommendations' => [], 'recent' => []];
        try {
            $model = new Discovery();
            $recommendations = $model->recommendations($userId, self::HOME_RECOMMENDATIONS);
            if ($recommendations && (int) $recommendations[0]['personalized'] === 1) {
                $shelf['recommendations'] = $recommendations;
            }
            $shelf['recent'] = $model->recentViews($userId, self::HOME_RECENT);
        } catch (\Throwable $exception) {
            error_log('Home2Home discovery recommendations skipped: '.$exception->getMessage());
        }
        return $shelf;
    }

    public static function summary(int $userId): ?array
    {
        try {
            return (new Discovery())->historySummary($userId) ?: null;
        } catch (\Throwable $exception) {
            error_log('Home2Home discovery history summary skipped: '.$exception->getMessage());
            return null;
        }
    }
}
