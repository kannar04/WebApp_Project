<?php
declare(strict_types=1);
namespace App\Models;

use Core\ProcedureConnection as Database;

final class Article
{
    private \Core\ProcedureConnection $db;

    public function __construct() { $this->db = Database::connection(); }

    /** @return array{articles: array<int,array>, total: int} */
    public function publicPage(string $category, int $limit, int $offset): array
    {
        $statement = $this->db->prepare('CALL sp_article_public_list(?,?,?)');
        $statement->execute([$category, $limit, $offset]);
        return $this->withTotal($statement->fetchAll(), 'articles');
    }

    public function findPublished(string $slug): ?array
    {
        $statement = $this->db->prepare('CALL sp_article_public_get(?)');
        $statement->execute([$slug]);
        return $statement->fetch() ?: null;
    }

    public function related(int $id, int $limit = 3): array
    {
        $statement = $this->db->prepare('CALL sp_article_public_related(?,?)');
        $statement->execute([$id, $limit]);
        return $statement->fetchAll();
    }

    /** @return array{articles: array<int,array>, total: int} */
    public function adminPage(int $actor, string $query, string $category, string $status, int $limit, int $offset): array
    {
        $statement = $this->db->prepare('CALL sp_admin_articles(?,?,?,?,?,?)');
        $statement->execute([$actor, $query, $category, $status, $limit, $offset]);
        return $this->withTotal($statement->fetchAll(), 'articles');
    }

    public function findForAdmin(int $actor, int $id): ?array
    {
        $statement = $this->db->prepare('CALL sp_admin_article_find(?,?)');
        $statement->execute([$actor, $id]);
        return $statement->fetch() ?: null;
    }

    /** Creates when $id is 0; returns the saved id, final slug and status. */
    public function save(int $actor, int $id, array $data): array
    {
        $statement = $this->db->prepare('CALL sp_admin_article_save(?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$actor, $id, $data['category'], $data['title'], $data['slug'], $data['auto_slug'] ? 1 : 0,
            $data['excerpt'], $data['body'], $data['cover'], $data['cover_alt'] !== '' ? $data['cover_alt'] : null,
            $data['remove_cover'] ? 1 : 0, $data['status']]);
        $saved = $statement->fetch();
        if (!$saved) { throw new \RuntimeException('Article save returned no result.'); }
        return $saved;
    }

    public function setStatus(int $actor, int $id, string $status): void
    {
        $this->db->prepare('CALL sp_admin_article_set_status(?,?,?)')->execute([$actor, $id, $status]);
    }

    public function delete(int $actor, int $id): void
    {
        $this->db->prepare('CALL sp_admin_article_delete(?,?)')->execute([$actor, $id]);
    }

    private function withTotal(array $rows, string $key): array
    {
        $total = 0;
        if ($rows && array_key_exists('__total', $rows[array_key_last($rows)])) { $total = (int) array_pop($rows)['__total']; }
        return [$key => $rows, 'total' => $total];
    }
}