<?php

declare(strict_types=1);

namespace App\Models;

use Core\ProcedureConnection as Database;

/** Map search and per-account browsing history used for recommendations. */
final class Discovery
{
    private \Core\ProcedureConnection $db;
    public function __construct() { $this->db = Database::connection(); }

    /**
     * @param array{north:string,south:string,east:string,west:string}|null $bounds null = no box (first fit of a search)
     */
    public function mapSearch(?array $bounds, array $filters, int $limit = 100): array
    {
        $statement = $this->db->prepare('CALL sp_discovery_map_search(?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$bounds['north'] ?? null, $bounds['south'] ?? null, $bounds['east'] ?? null, $bounds['west'] ?? null,
            trim((string) ($filters['location'] ?? '')), (int) ($filters['guests'] ?? 0), (int) ($filters['type'] ?? 0),
            (float) ($filters['min_price'] ?? 0), (float) ($filters['max_price'] ?? 0),
            ($filters['check_in'] ?? '') ?: null, ($filters['check_out'] ?? '') ?: null, $limit]);
        $rows = $statement->fetchAll();
        $metadata = array_pop($rows);
        return ['listings' => $rows, 'total' => (int) ($metadata['__total'] ?? 0)];
    }

    public function recordView(int $userId, int $listingId): bool
    {
        $statement = $this->db->prepare('CALL sp_discovery_view_record(?,?)');
        $statement->execute([$userId, $listingId]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function recordSearch(int $userId, string $source, array $filters): bool
    {
        $statement = $this->db->prepare('CALL sp_discovery_search_record(?,?,?,?,?,?,?,?,?)');
        $statement->execute([$userId, $source, trim((string) ($filters['location'] ?? '')),
            ($filters['check_in'] ?? '') ?: null, ($filters['check_out'] ?? '') ?: null, (int) ($filters['guests'] ?? 0),
            (int) ($filters['type'] ?? 0), (float) ($filters['min_price'] ?? 0), (float) ($filters['max_price'] ?? 0)]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function recommendations(int $userId, int $limit = 8): array
    {
        $statement = $this->db->prepare('CALL sp_discovery_recommendations(?,?)');
        $statement->execute([$userId, $limit]);
        return $statement->fetchAll();
    }

    public function recentViews(int $userId, int $limit = 8): array
    {
        $statement = $this->db->prepare('CALL sp_discovery_recent_views(?,?)');
        $statement->execute([$userId, $limit]);
        return $statement->fetchAll();
    }

    public function historySummary(int $userId): array
    {
        $statement = $this->db->prepare('CALL sp_discovery_history_summary(?)');
        $statement->execute([$userId]);
        return $statement->fetch() ?: [];
    }

    public function clearHistory(int $userId): array
    {
        $statement = $this->db->prepare('CALL sp_discovery_history_clear(?)');
        $statement->execute([$userId]);
        return $statement->fetch() ?: [];
    }
}
