<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\Article;
use App\Services\ArticleText;
use App\Services\UploadService;
use Core\Controller;
use Core\Csrf;
use Core\Session;

/** Admin management of articles; the stored procedures re-check the admin role and write the audit log. */
final class AdminArticleController extends Controller
{
    private const PER_PAGE = 20;
    private const STATUSES = ['draft' => 'Bản nháp', 'published' => 'Đã đăng'];

    public function index(): void
    {
        $user = $this->requireRole('admin');
        $query = $this->queryString('q', 200);
        $category = $this->queryString('category', 10);
        $status = $this->queryString('status', 10);
        if (!isset(ArticleText::CATEGORIES[$category])) { $category = ''; }
        if (!isset(self::STATUSES[$status])) { $status = ''; }
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
        $model = new Article();
        $result = $model->adminPage((int) $user['id'], trim($query), $category, $status, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $result = $model->adminPage((int) $user['id'], trim($query), $category, $status, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }
        $this->render('admin/articles/index', ['title' => 'Quản lý bài viết', 'articles' => $result['articles'], 'total' => $result['total'],
            'filters' => ['q' => trim($query), 'category' => $category, 'status' => $status], 'page' => $page, 'pages' => $pages,
            'statuses' => self::STATUSES]);
    }

    public function createForm(): void
    {
        $this->requireRole('admin');
        $this->renderForm(null, Session::pullOld());
    }

    public function store(): never
    {
        Csrf::ensure();
        $user = $this->requireRole('admin');
        $this->persist($user, null);
    }

    public function editForm(string $id): void
    {
        $user = $this->requireRole('admin');
        $article = (new Article())->findForAdmin((int) $user['id'], (int) $id);
        if (!$article) {
            $this->notFound();
            return;
        }
        $this->renderForm($article, Session::pullOld());
    }

    public function update(string $id): void
    {
        Csrf::ensure();
        $user = $this->requireRole('admin');
        $article = (new Article())->findForAdmin((int) $user['id'], (int) $id);
        if (!$article) {
            $this->notFound();
            return;
        }
        $this->persist($user, $article);
    }

    public function preview(string $id): void
    {
        $user = $this->requireRole('admin');
        $article = (new Article())->findForAdmin((int) $user['id'], (int) $id);
        if (!$article) {
            $this->notFound();
            return;
        }
        $this->render('articles/show', ['title' => 'Xem trước: '.$article['title'], 'article' => $article, 'related' => [], 'preview' => true]);
    }

    public function status(string $id): never
    {
        Csrf::ensure();
        $user = $this->requireRole('admin');
        $status = $_POST['status'] ?? '';
        $back = ($_POST['return'] ?? '') === 'edit' ? '/admin/articles/'.(int) $id.'/edit' : '/admin/articles';
        try {
            if (!is_string($status) || !isset(self::STATUSES[$status])) { throw new \DomainException('Trạng thái bài viết không hợp lệ.'); }
            (new Article())->setStatus((int) $user['id'], (int) $id, $status);
            Session::flash('success', $status === 'published' ? 'Đã đăng bài viết lên trang Cẩm nang.' : 'Đã chuyển bài viết về bản nháp. Bài không còn hiển thị công khai.');
        } catch (\DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect($back);
    }

    public function delete(string $id): never
    {
        Csrf::ensure();
        $user = $this->requireRole('admin');
        try {
            (new Article())->delete((int) $user['id'], (int) $id);
            Session::flash('success', 'Đã xóa bài viết. Bài không còn hiển thị và vẫn được lưu trong lịch sử quản trị.');
        } catch (\DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('/admin/articles');
    }

    private function persist(array $user, ?array $existing): never
    {
        $back = $existing ? '/admin/articles/'.(int) $existing['id'].'/edit' : '/admin/articles/create';
        $input = [];
        foreach (['title', 'category', 'slug', 'excerpt', 'body', 'cover_alt'] as $field) {
            $value = $_POST[$field] ?? '';
            $input[$field] = is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';
        }
        $intent = $_POST['intent'] ?? 'save';
        $status = match ($intent) { 'publish' => 'published', 'draft' => 'draft', default => $existing ? null : 'draft' };
        $removeCover = ($_POST['remove_cover'] ?? '') === '1';
        $errors = [];
        $titleLength = mb_strlen($input['title']);
        if ($titleLength < 5 || $titleLength > 200) { $errors[] = 'Tiêu đề cần từ 5 đến 200 ký tự.'; }
        if (!isset(ArticleText::CATEGORIES[$input['category']])) { $errors[] = 'Vui lòng chọn chuyên mục.'; }
        $autoSlug = $input['slug'] === '';
        $slug = ArticleText::slugify($autoSlug ? $input['title'] : $input['slug']);
        if ($autoSlug && $slug === '') { $slug = 'bai-viet'; }
        if ($slug === '' || strlen($slug) > 200) { $errors[] = 'Đường dẫn cần có chữ cái hoặc số (tối đa 200 ký tự).'; }
        $bodyLength = mb_strlen($input['body']);
        if ($bodyLength < 20 || $bodyLength > 50000) { $errors[] = 'Nội dung cần từ 20 đến 50.000 ký tự.'; }
        $excerpt = $input['excerpt'] !== '' ? (string) preg_replace('/\s+/u', ' ', $input['excerpt']) : ArticleText::excerptFrom($input['body']);
        if (mb_strlen($excerpt) > 500) { $errors[] = 'Tóm tắt tối đa 500 ký tự.'; }
        if (mb_strlen($input['cover_alt']) > 255) { $errors[] = 'Mô tả ảnh bìa tối đa 255 ký tự.'; }
        if ($errors) { $this->back($back, $input, implode(' ', $errors)); }

        $cover = null;
        try {
            $file = $_FILES['cover'] ?? [];
            $cover = (new UploadService())->image(is_array($file) ? $file : [], 'article');
            $hasCover = $cover !== null || (!$removeCover && !empty($existing['cover_image_url']));
            $alt = $input['cover_alt'] !== '' ? $input['cover_alt'] : ($hasCover ? mb_substr($input['title'], 0, 255) : '');
            $saved = (new Article())->save((int) $user['id'], (int) ($existing['id'] ?? 0), [
                'category' => $input['category'], 'title' => $input['title'], 'slug' => $slug, 'auto_slug' => $autoSlug,
                'excerpt' => $excerpt, 'body' => $input['body'], 'cover' => $cover, 'cover_alt' => $hasCover ? $alt : '',
                'remove_cover' => $removeCover && $cover === null, 'status' => $status]);
        } catch (\DomainException $exception) {
            $this->discardUpload($cover);
            $this->back($back, $input, $exception->getMessage());
        } catch (\PDOException $exception) {
            $this->discardUpload($cover);
            if ($exception->getCode() !== '23000') { throw $exception; }
            $this->back($back, $input, 'Đường dẫn đã được dùng cho bài viết khác.');
        }
        $message = $existing ? 'Đã lưu bài viết.' : 'Đã tạo bài viết.';
        $message .= $saved['status'] === 'published' ? ' Bài đang hiển thị công khai.' : ' Bài đang là bản nháp, chưa hiển thị công khai.';
        Session::flash('success', $message);
        $this->redirect('/admin/articles/'.(int) $saved['id'].'/edit');
    }

    private function back(string $path, array $input, string $error): never
    {
        Session::old($input);
        Session::flash('error', $error);
        $this->redirect($path);
    }

    private function discardUpload(?string $cover): void
    {
        if ($cover === null || !preg_match('~^/uploads/(article-[a-f0-9]{24}\.(?:jpg|png|webp))$~D', $cover, $match)) { return; }
        $config = require dirname(__DIR__, 2).'/config/app.php';
        $path = $config['upload_path'].DIRECTORY_SEPARATOR.$match[1];
        if (is_file($path)) { unlink($path); }
    }

    private function renderForm(?array $article, array $old): void
    {
        $this->render('admin/articles/form', ['title' => $article ? 'Sửa bài viết' : 'Viết bài mới', 'article' => $article,
            'old' => $old, 'categories' => ArticleText::CATEGORIES, 'statuses' => self::STATUSES]);
    }

    private function queryString(string $key, int $max): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) ? mb_substr($value, 0, $max) : '';
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('errors/404', ['title' => 'Không tìm thấy bài viết']);
    }
}
