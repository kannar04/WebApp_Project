<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\Article;
use App\Services\ArticleText;
use Core\Controller;

/** Public "Cẩm nang": published news and travel guides only. */
final class ArticleController extends Controller
{
    private const PER_PAGE = 9;

    public function index(): void
    {
        $category = $_GET['category'] ?? '';
        if (!is_string($category) || ($category !== '' && !isset(ArticleText::CATEGORIES[$category]))) {
            $this->notFound();
            return;
        }
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
        $model = new Article();
        $result = $model->publicPage($category, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $result = $model->publicPage($category, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }
        $title = $category === '' ? 'Cẩm nang' : ArticleText::categoryLabel($category).' · Cẩm nang';
        $this->render('articles/index', ['title' => $title, 'articles' => $result['articles'], 'total' => $result['total'],
            'category' => $category, 'page' => $page, 'pages' => $pages]);
    }

    public function show(string $slug): void
    {
        if (strlen($slug) > 200 || !preg_match(ArticleText::SLUG_PATTERN, $slug)) {
            $this->notFound();
            return;
        }
        $model = new Article();
        $article = $model->findPublished($slug);
        if (!$article) {
            $this->notFound();
            return;
        }
        $this->render('articles/show', ['title' => $article['title'], 'article' => $article,
            'related' => $model->related((int) $article['id'], 3), 'preview' => false]);
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('errors/404', ['title' => 'Không tìm thấy bài viết']);
    }
}
