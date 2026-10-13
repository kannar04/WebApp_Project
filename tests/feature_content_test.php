<?php
declare(strict_types=1);

use App\Models\Article;
use App\Models\User;
use App\Services\ArticleText;
use Core\ProcedureConnection as Database;

require dirname(__DIR__).'/core/bootstrap.php';

// Articles and travel guides: procedures, model, text helpers and (when HOME2HOME_TEST_URL is set) the HTTP pages.

function contentExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

/** Calls an array callable and requires a DomainException whose message contains $needle. */
function contentRejected(array $callable, array $arguments, string $needle, string $label): void
{
    try {
        call_user_func_array($callable, $arguments);
    } catch (DomainException $exception) {
        contentExpect(str_contains($exception->getMessage(), $needle), $label.': thông báo lỗi không đúng: '.$exception->getMessage());
        return;
    }
    throw new RuntimeException($label.': thao tác không bị từ chối.');
}

function contentArticle(string $title, string $slug, string $category = 'guide', ?string $status = 'draft', array $overrides = []): array
{
    return array_replace([
        'category' => $category, 'title' => $title, 'slug' => $slug, 'auto_slug' => false,
        'excerpt' => 'Tóm tắt bài viết kiểm thử tự động.',
        'body' => "Đoạn mở đầu đủ dài cho bài viết kiểm thử.\n\n## Mục phụ\nDòng thứ hai của bài.",
        'cover' => null, 'cover_alt' => '', 'remove_cover' => false, 'status' => $status,
    ], $overrides);
}

function contentInspect(Database $db, int $id): array
{
    $statement = $db->prepare('CALL sp_test_content_article_inspect(?)');
    $statement->execute([$id]);
    $row = $statement->fetch();
    contentExpect($row !== false, 'Không đọc được bài viết kiểm thử #'.$id);
    return $row;
}

/** Slugs of every published article in a category (the list is capped at 50 per call, so walk all pages). */
function contentPublicSlugs(Article $model, string $category): array
{
    $slugs = [];
    $offset = 0;
    do {
        $page = $model->publicPage($category, 50, $offset);
        foreach ($page['articles'] as $row) { $slugs[] = $row['slug']; }
        $offset += 50;
    } while ($offset < $page['total']);
    return $slugs;
}

function contentHttpClient()
{
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => '',
        CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
    return $curl;
}

/** GET when $fields is null; POST urlencoded, or multipart when $multipart is true. */
function contentRequest($curl, string $base, string $path, ?array $fields = null, bool $multipart = false): array
{
    curl_setopt($curl, CURLOPT_URL, $base.$path);
    if ($fields === null) {
        curl_setopt($curl, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
    }
    $raw = curl_exec($curl);
    contentExpect(is_string($raw), 'Không gọi được '.$path.': '.curl_error($curl));
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = substr($raw, 0, $headerSize);
    $location = preg_match('/^Location:\s*(\S+)/mi', $headers, $match) ? (string) parse_url($match[1], PHP_URL_PATH) : '';
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'location' => $location, 'body' => substr($raw, $headerSize)];
}

function contentToken(string $html): string
{
    contentExpect((bool) preg_match('/name="(?:_token" value|csrf-token" content)="([^"]+)"/', $html, $match), 'Không tìm thấy CSRF token trên trang.');
    return $match[1];
}

function contentLogin($curl, string $base, string $email, string $password): string
{
    $form = contentRequest($curl, $base, '/login');
    $login = contentRequest($curl, $base, '/login', ['_token' => contentToken($form['body']), 'email' => $email, 'password' => $password]);
    contentExpect($login['status'] === 302 && $login['location'] !== '/login', 'Đăng nhập tài khoản kiểm thử thất bại.');
    return $login['location'];
}

/** Id of this run's article with an exact slug, seen through the Admin search procedure. */
function contentAdminFind(Article $model, int $admin, string $slug): ?array
{
    $result = $model->adminPage($admin, $slug, '', '', 100, 0);
    foreach ($result['articles'] as $row) {
        if ($row['slug'] === $slug) { return $row; }
    }
    return null;
}

$appConfig = require dirname(__DIR__).'/config/app.php';
$dbConfig = require dirname(__DIR__).'/config/database.php';
contentExpect($appConfig['env'] === 'local' && in_array($dbConfig['host'], ['localhost', '127.0.0.1', '::1'], true), 'Chỉ chạy kiểm thử trên cơ sở dữ liệu cục bộ.');

$db = Database::connection();
$model = new Article();
$users = new User();
$marker = bin2hex(random_bytes(8));
$prefix = 'content-audit-'.$marker;
$password = bin2hex(random_bytes(12));
$fixtureUsers = [];
$uploadRoot = realpath(dirname(__DIR__).'/public/uploads');
$coverPaths = [];
$badUpload = null;

try {
    // Text helpers: Vietnamese slugs, escaping and summaries.
    contentExpect(ArticleText::slugify('Cẩm nang 2 ngày ở Đà Lạt!') === 'cam-nang-2-ngay-o-da-lat', 'Slug tiếng Việt sai.');
    contentExpect(ArticleText::slugify('  Hội An — mùa nào ĐẸP?  ') === 'hoi-an-mua-nao-dep', 'Slug chữ hoa/dấu câu sai.');
    contentExpect(ArticleText::slugify("Hue\u{0302}\u{0301} xu\u{031B}a") === 'hue-xua', 'Slug với dấu tổ hợp (NFD) sai.');
    contentExpect(ArticleText::slugify('*** ???') === '', 'Slug rỗng phải trả về chuỗi rỗng.');
    $longSlug = ArticleText::slugify(str_repeat('Đường dài ', 60));
    contentExpect(strlen($longSlug) <= 180 && preg_match(ArticleText::SLUG_PATTERN, $longSlug) === 1, 'Slug dài không được cắt gọn hợp lệ.');
    $html = ArticleText::toHtml("## Tiêu đề <b>đậm</b>\nDòng một <script>alert('x')</script>\nDòng hai\n\nĐoạn \"hai\" & cuối");
    contentExpect(str_starts_with($html, '<h2>Tiêu đề &lt;b&gt;đậm&lt;/b&gt;</h2><p>'), 'Tiêu đề phụ không được escape/định dạng.');
    contentExpect(!str_contains($html, '<script') && str_contains($html, '&lt;script&gt;') && str_contains($html, "<br>\n"), 'Nội dung không được escape trước khi định dạng.');
    contentExpect(str_contains($html, '<p>Đoạn &quot;hai&quot; &amp; cuối</p>'), 'Đoạn văn thứ hai sai.');
    $excerpt = ArticleText::excerptFrom("## Mở đầu\n".str_repeat('chữ ', 120));
    contentExpect(mb_strlen($excerpt) <= 200 && str_ends_with($excerpt, '…') && !str_contains($excerpt, '##'), 'Tóm tắt tự động sai.');
    echo "PASS article text: Vietnamese slugify (precomposed and NFD), length cap, HTML escaped before paragraph/heading formatting, excerpt\n";

    $adminId = $users->create('audit-content-'.$marker.'-admin@home2home.test', $password, 'Biên tập viên kiểm thử', null);
    $fixtureUsers[] = $adminId;
    $users->replaceRoles($adminId, ['guest', 'admin']);
    $guestId = $users->create('audit-content-'.$marker.'-guest@home2home.test', $password, 'Khách kiểm thử nội dung', null);
    $fixtureUsers[] = $guestId;
    $users->replaceRoles($guestId, ['guest']);

    // Admin-only: the procedures refuse non-admin actors even when PHP is bypassed.
    contentRejected([$model, 'save'], [$guestId, 0, contentArticle('Bài của khách '.$marker, $prefix.'-khach')], 'quyền quản trị', 'Guest tạo bài');
    contentRejected([$model, 'save'], [0, 0, contentArticle('Bài không tác giả '.$marker, $prefix.'-khong')], 'quyền quản trị', 'Tác giả không tồn tại');
    contentRejected([$model, 'adminPage'], [$guestId, '', '', '', 20, 0], 'quyền quản trị', 'Guest xem danh sách quản trị');
    $draft = $model->save($adminId, 0, contentArticle('Bản nháp kiểm thử '.$marker, $prefix.'-nhap'));
    $draftId = (int) $draft['id'];
    contentExpect($draft['slug'] === $prefix.'-nhap' && $draft['status'] === 'draft', 'Tạo bản nháp sai.');
    contentRejected([$model, 'findForAdmin'], [$guestId, $draftId], 'quyền quản trị', 'Guest đọc bài quản trị');
    contentRejected([$model, 'save'], [$guestId, $draftId, contentArticle('Khách sửa bài '.$marker, $prefix.'-nhap')], 'quyền quản trị', 'Guest sửa bài');
    contentRejected([$model, 'setStatus'], [$guestId, $draftId, 'published'], 'quyền quản trị', 'Guest đăng bài');
    contentRejected([$model, 'delete'], [$guestId, $draftId], 'quyền quản trị', 'Guest xóa bài');
    $row = contentInspect($db, $draftId);
    contentExpect($row['status'] === 'draft' && $row['deleted_at'] === null && $row['title'] === 'Bản nháp kiểm thử '.$marker
        && (int) $row['author_id'] === $adminId && $row['audit_actions'] === 'create_article', 'Thao tác bị từ chối vẫn làm thay đổi bài viết.');
    contentExpect($model->findPublished($prefix.'-nhap') === null, 'Bản nháp hiển thị công khai.');
    echo "PASS admin-only create/read/update/publish/delete enforced inside the procedures; rejected calls change nothing\n";

    // Slugs: generated slugs are de-duplicated, explicit duplicates are refused, format is enforced in SQL.
    $title = 'Content audit '.$marker.' Đà Lạt mùa sương';
    $autoSlug = ArticleText::slugify($title);
    contentExpect($autoSlug === $prefix.'-da-lat-mua-suong', 'Slug tự tạo từ tiêu đề sai.');
    $first = $model->save($adminId, 0, contentArticle($title, $autoSlug, 'guide', 'draft', ['auto_slug' => true]));
    $second = $model->save($adminId, 0, contentArticle($title, $autoSlug, 'guide', 'draft', ['auto_slug' => true]));
    $third = $model->save($adminId, 0, contentArticle($title, $autoSlug, 'guide', 'draft', ['auto_slug' => true]));
    contentExpect($first['slug'] === $autoSlug && $second['slug'] === $autoSlug.'-2' && $third['slug'] === $autoSlug.'-3', 'Slug tự tạo không thêm hậu tố -2, -3.');
    contentRejected([$model, 'save'], [$adminId, 0, contentArticle('Trùng đường dẫn '.$marker, $autoSlug)], 'Đường dẫn đã được dùng', 'Slug nhập tay bị trùng');
    contentRejected([$model, 'save'], [$adminId, (int) $second['id'], contentArticle($title, $autoSlug)], 'Đường dẫn đã được dùng', 'Đổi slug sang slug đã có');
    $kept = $model->save($adminId, (int) $first['id'], contentArticle($title.' (sửa)', $autoSlug, 'guide', null, ['auto_slug' => true]));
    contentExpect($kept['slug'] === $autoSlug && $kept['status'] === 'draft', 'Lưu lại bài không được giữ nguyên slug của chính nó.');
    foreach (['Chu-Hoa', 'a--b', '-dau', 'co dau cach', 'đà-lạt', $prefix.'-'] as $badSlug) {
        contentRejected([$model, 'save'], [$adminId, 0, contentArticle('Slug sai '.$marker, $badSlug)], 'Đường dẫn chỉ gồm', 'Slug sai định dạng '.$badSlug);
    }
    echo "PASS slug generation: Vietnamese title to slug, -2/-3 de-duplication, explicit duplicates and malformed slugs refused in SQL\n";

    // Validation in SQL, independent of the controller.
    $invalid = [
        ['Ngắn', [], 'Tiêu đề cần từ 5 đến 200 ký tự'],
        [str_repeat('Dài ', 50).'x', [], 'Tiêu đề cần từ 5 đến 200 ký tự'],
        ['Tiêu đề hợp lệ '.$marker, ['category' => 'blog'], 'Chuyên mục bài viết không hợp lệ'],
        ['Tiêu đề hợp lệ '.$marker, ['category' => 'news '], 'Chuyên mục bài viết không hợp lệ'],
        ['Tiêu đề hợp lệ '.$marker, ['slug' => str_repeat('a', 201)], 'Đường dẫn chỉ gồm'],
        ['Tiêu đề hợp lệ '.$marker, ['cover_alt' => str_repeat('ả', 256)], 'Mô tả ảnh bìa tối đa 255 ký tự'],
        ['Tiêu đề hợp lệ '.$marker, ['status' => 'draft '], 'Trạng thái bài viết không hợp lệ'],
        ['Tiêu đề hợp lệ '.$marker, ['body' => 'Quá ngắn'], 'Nội dung cần từ 20 đến 50000 ký tự'],
        ['Tiêu đề hợp lệ '.$marker, ['excerpt' => '   '], 'Tóm tắt cần từ 1 đến 500 ký tự'],
        ['Tiêu đề hợp lệ '.$marker, ['excerpt' => str_repeat('a', 501)], 'Tóm tắt cần từ 1 đến 500 ký tự'],
        ['Tiêu đề hợp lệ '.$marker, ['cover' => 'https://example.com/anh.jpg'], 'Ảnh bìa không hợp lệ'],
        ['Tiêu đề hợp lệ '.$marker, ['cover' => '/uploads/../config.jpg'], 'Ảnh bìa không hợp lệ'],
        ['Tiêu đề hợp lệ '.$marker, ['cover' => '/uploads/anh.gif'], 'Ảnh bìa không hợp lệ'],
        ['Tiêu đề hợp lệ '.$marker, ['status' => 'archived'], 'Trạng thái bài viết không hợp lệ'],
    ];
    foreach ($invalid as $index => [$badTitle, $overrides, $needle]) {
        contentRejected([$model, 'save'], [$adminId, 0, contentArticle($badTitle, $prefix.'-sai-'.$index, 'guide', 'draft', $overrides)], $needle, 'Dữ liệu sai #'.$index);
    }
    contentRejected([$model, 'save'], [$adminId, PHP_INT_MAX, contentArticle('Bài không tồn tại '.$marker, $prefix.'-mat')], 'không tồn tại', 'Sửa bài không tồn tại');
    contentRejected([$model, 'setStatus'], [$adminId, $draftId, 'archived'], 'Trạng thái bài viết không hợp lệ', 'Trạng thái sai');
    contentRejected([$model, 'publicPage'], ['blog', 9, 0], 'Chuyên mục bài viết không hợp lệ', 'Chuyên mục công khai sai');
    contentRejected([$model, 'publicPage'], ['', 0, 0], 'Phân trang không hợp lệ', 'Phân trang công khai sai');
    contentRejected([$model, 'adminPage'], [$adminId, '', 'guide', 'hidden', 20, 0], 'Bộ lọc bài viết không hợp lệ', 'Bộ lọc quản trị sai');
    $mine = $model->adminPage($adminId, $marker, '', '', 100, 0);
    contentExpect($mine['total'] === 4, 'Dữ liệu không hợp lệ vẫn tạo bài viết (tổng '.$mine['total'].').');
    echo "PASS validation in SQL: title, category, body, excerpt, cover path, status, missing article, filters and paging rejected without writes\n";

    // Publish, unpublish, edit and audit trail.
    $model->setStatus($adminId, $draftId, 'published');
    $public = $model->findPublished($prefix.'-nhap');
    contentExpect($public !== null && $public['author_name'] === 'Biên tập viên kiểm thử', 'Bài đã đăng không hiển thị công khai.');
    $publishedAt = contentInspect($db, $draftId)['published_at'];
    contentExpect($publishedAt !== null, 'Đăng bài không ghi thời điểm đăng.');
    $model->setStatus($adminId, $draftId, 'draft');
    contentExpect($model->findPublished($prefix.'-nhap') === null, 'Gỡ bài vẫn hiển thị công khai.');
    contentExpect(contentInspect($db, $draftId)['published_at'] === $publishedAt, 'Gỡ bài làm mất thời điểm đăng lần đầu.');
    $model->setStatus($adminId, $draftId, 'published');
    $edited = $model->save($adminId, $draftId, contentArticle('Bài đã sửa '.$marker, $prefix.'-nhap', 'news', null));
    $row = contentInspect($db, $draftId);
    contentExpect($edited['status'] === 'published' && $row['category'] === 'news' && $row['title'] === 'Bài đã sửa '.$marker
        && $row['published_at'] === $publishedAt, 'Sửa bài đã đăng làm đổi trạng thái hoặc thời điểm đăng.');
    contentExpect($row['audit_actions'] === 'create_article,publish_article,unpublish_article,publish_article,update_article', 'Nhật ký quản trị thiếu thao tác: '.$row['audit_actions']);
    echo "PASS publish/unpublish/republish/edit: public visibility follows status, first publish time kept, every action audited\n";

    // Public list: category filter, pagination and ordering; drafts and deleted articles never listed.
    $newsBefore = $model->publicPage('news', 9, 0)['total'];
    $allBefore = $model->publicPage('', 9, 0)['total'];
    $newsSlugs = [];
    for ($i = 1; $i <= 10; $i++) {
        $saved = $model->save($adminId, 0, contentArticle(sprintf('Tin kiểm thử %s số %02d', $marker, $i), sprintf('%s-tin-%02d', $prefix, $i), 'news', 'published'));
        $newsSlugs[] = $saved['slug'];
    }
    $model->save($adminId, 0, contentArticle('Tin nháp '.$marker, $prefix.'-tin-nhap', 'news', 'draft'));
    $deleted = $model->save($adminId, 0, contentArticle('Tin sẽ xóa '.$marker, $prefix.'-tin-xoa', 'news', 'published'));
    $model->delete($adminId, (int) $deleted['id']);
    $firstPage = $model->publicPage('news', 9, 0);
    $secondPage = $model->publicPage('news', 9, 9);
    contentExpect($firstPage['total'] === $newsBefore + 10 && $model->publicPage('', 9, 0)['total'] === $allBefore + 10, 'Tổng bài công khai sai (nháp hoặc bài đã xóa bị đếm).');
    contentExpect(count($firstPage['articles']) === 9 && count($secondPage['articles']) === min(9, $firstPage['total'] - 9), 'Phân trang danh sách công khai sai.');
    contentExpect($firstPage['articles'][0]['slug'] === $prefix.'-tin-10' && $firstPage['articles'][8]['slug'] === $prefix.'-tin-02'
        && $secondPage['articles'][0]['slug'] === $prefix.'-tin-01', 'Danh sách không sắp xếp bài mới nhất trước.');
    foreach (array_merge($firstPage['articles'], $secondPage['articles']) as $item) {
        contentExpect($item['category'] === 'news', 'Bộ lọc chuyên mục trả về bài khác chuyên mục.');
    }
    $guideSlugs = contentPublicSlugs($model, 'guide');
    $allSlugs = contentPublicSlugs($model, '');
    contentExpect(!array_intersect($newsSlugs, $guideSlugs) && !array_diff($newsSlugs, $allSlugs), 'Lọc chuyên mục Cẩm nang du lịch sai.');
    contentExpect(!in_array($prefix.'-tin-nhap', $allSlugs, true) && !in_array($prefix.'-tin-xoa', $allSlugs, true), 'Bản nháp hoặc bài đã xóa xuất hiện trong danh sách công khai.');
    $related = $model->related((int) $firstPage['articles'][0]['id'], 3);
    contentExpect(count($related) === 3 && $related[0]['category'] === 'news' && !in_array($firstPage['articles'][0]['id'], array_column($related, 'id'), true), 'Bài liên quan sai.');
    contentExpect($model->findPublished('cam-nang-2-ngay-o-da-lat') !== null, 'Bài mẫu trong seed không hiển thị.');
    echo "PASS public list: news/guide filter, 9-per-page pagination, newest first, related articles; drafts and deleted articles excluded\n";

    // Soft delete: invisible everywhere, kept for audit, slug still reserved.
    $model->delete($adminId, $draftId);
    $row = contentInspect($db, $draftId);
    contentExpect($row['deleted_at'] !== null && str_ends_with($row['audit_actions'], ',delete_article'), 'Xóa mềm không ghi nhận đúng.');
    contentExpect($model->findPublished($prefix.'-nhap') === null && $model->findForAdmin($adminId, $draftId) === null, 'Bài đã xóa vẫn đọc được.');
    contentExpect(contentAdminFind($model, $adminId, $prefix.'-nhap') === null, 'Bài đã xóa vẫn nằm trong danh sách quản trị.');
    contentRejected([$model, 'delete'], [$adminId, $draftId], 'không tồn tại', 'Xóa lại bài đã xóa');
    contentRejected([$model, 'setStatus'], [$adminId, $draftId, 'published'], 'không tồn tại', 'Đăng lại bài đã xóa');
    contentRejected([$model, 'save'], [$adminId, 0, contentArticle('Dùng lại slug đã xóa '.$marker, $prefix.'-nhap')], 'Đường dẫn đã được dùng', 'Dùng lại slug của bài đã xóa');
    $reused = $model->save($adminId, 0, contentArticle('Dùng lại slug đã xóa '.$marker, $prefix.'-nhap', 'guide', 'draft', ['auto_slug' => true]));
    contentExpect($reused['slug'] === $prefix.'-nhap-2', 'Slug tự tạo không tránh slug của bài đã xóa.');
    echo "PASS soft delete: hidden from public and Admin lists, audited, cannot be re-published, slug stays reserved\n";

    $base = rtrim((string) getenv('HOME2HOME_TEST_URL'), '/');
    if ($base === '') {
        echo "SKIP article HTTP checks: HOME2HOME_TEST_URL is not set\n";
    } else {
        $anonymous = contentHttpClient();
        $page = contentRequest($anonymous, $base, '/articles');
        contentExpect($page['status'] === 200 && str_contains($page['body'], 'Tin kiểm thử '.$marker.' số 10'), 'Trang Cẩm nang không hiển thị bài mới đăng.');
        contentExpect(!str_contains($page['body'], 'Tin nháp '.$marker) && !str_contains($page['body'], 'Tin sẽ xóa '.$marker), 'Trang Cẩm nang hiển thị bản nháp/bài đã xóa.');
        $page = contentRequest($anonymous, $base, '/articles?category=news&page=2');
        contentExpect($page['status'] === 200 && str_contains($page['body'], 'Trang 2/') && str_contains($page['body'], 'aria-current="page"')
            && str_contains($page['body'], 'Tin kiểm thử '.$marker.' số 01'), 'Trang 2 của mục Tin tức sai.');
        $page = contentRequest($anonymous, $base, '/articles?category=news&page=99999');
        contentExpect($page['status'] === 200 && (bool) preg_match('/Trang (\d+)\/\1</', $page['body']), 'Số trang quá lớn không được đưa về trang cuối.');
        contentExpect(contentRequest($anonymous, $base, '/articles?category=blog')['status'] === 404, 'Chuyên mục không tồn tại không trả về 404.');
        $page = contentRequest($anonymous, $base, '/articles/'.$prefix.'-tin-10');
        contentExpect($page['status'] === 200 && str_contains($page['body'], '<h2>Mục phụ</h2>') && str_contains($page['body'], 'Bài viết khác'), 'Trang chi tiết bài viết sai.');
        foreach (['/articles/'.$prefix.'-tin-nhap', '/articles/'.$prefix.'-tin-xoa', '/articles/'.$prefix.'-nhap', '/articles/Cam-Nang', '/articles/khong-ton-tai-'.$marker] as $missing) {
            contentExpect(contentRequest($anonymous, $base, $missing)['status'] === 404, 'Không trả về 404: '.$missing);
        }
        $redirect = contentRequest($anonymous, $base, '/admin/articles');
        contentExpect($redirect['status'] === 302 && $redirect['location'] === '/login', 'Khách chưa đăng nhập không bị chuyển tới trang đăng nhập.');
        curl_close($anonymous);
        echo "PASS HTTP public: list 200 with pagination/clamping, unknown category 404, detail 200, draft/deleted/unknown/uppercase slugs 404, anonymous Admin redirected to login\n";

        $guest = contentHttpClient();
        contentLogin($guest, $base, 'audit-content-'.$marker.'-guest@home2home.test', $password);
        $draftHttp = $model->save($adminId, 0, contentArticle('Bản nháp HTTP '.$marker, $prefix.'-http-nhap'));
        $draftHttpId = (int) $draftHttp['id'];
        foreach (['/admin/articles', '/admin/articles/create', '/admin/articles/'.$draftHttpId.'/edit', '/admin/articles/'.$draftHttpId.'/preview'] as $adminPath) {
            contentExpect(contentRequest($guest, $base, $adminPath)['status'] === 403, 'Guest không bị chặn (403) tại '.$adminPath);
        }
        $token = contentToken(contentRequest($guest, $base, '/articles')['body']);
        $forbidden = contentRequest($guest, $base, '/admin/articles/'.$draftHttpId.'/status', ['_token' => $token, 'status' => 'published']);
        $forbiddenCreate = contentRequest($guest, $base, '/admin/articles', ['_token' => $token, 'title' => 'Khách tạo bài '.$marker, 'category' => 'guide',
            'slug' => $prefix.'-http-khach', 'body' => 'Nội dung đủ dài để vượt qua kiểm tra.', 'intent' => 'publish']);
        contentExpect($forbidden['status'] === 403 && $forbiddenCreate['status'] === 403, 'Guest gửi biểu mẫu quản trị không bị chặn (403).');
        contentExpect(contentInspect($db, $draftHttpId)['status'] === 'draft' && contentAdminFind($model, $adminId, $prefix.'-http-khach') === null, 'Guest vẫn thay đổi được bài viết.');
        curl_close($guest);
        echo "PASS HTTP Guest: Admin article pages and POST actions return 403 and change nothing\n";

        $admin = contentHttpClient();
        contentExpect(contentLogin($admin, $base, 'audit-content-'.$marker.'-admin@home2home.test', $password) === '/admin', 'Admin kiểm thử không được chuyển tới trang quản trị.');
        $list = contentRequest($admin, $base, '/admin/articles?q='.$marker.'&status=draft');
        contentExpect($list['status'] === 200 && str_contains($list['body'], 'Bản nháp HTTP '.$marker) && !str_contains($list['body'], 'Tin kiểm thử '.$marker), 'Bộ lọc danh sách quản trị sai.');
        $preview = contentRequest($admin, $base, '/admin/articles/'.$draftHttpId.'/preview');
        contentExpect($preview['status'] === 200 && str_contains($preview['body'], 'Bản xem trước dành cho Admin'), 'Admin không xem trước được bản nháp.');
        $noToken = contentRequest($admin, $base, '/admin/articles/'.$draftHttpId.'/delete', []);
        $noTokenCreate = contentRequest($admin, $base, '/admin/articles', ['title' => 'Thiếu token '.$marker, 'category' => 'guide', 'slug' => $prefix.'-http-thieu-token',
            'body' => 'Nội dung đủ dài để vượt qua kiểm tra.', 'intent' => 'publish']);
        contentExpect($noToken['status'] === 302 && $noTokenCreate['status'] === 302, 'Yêu cầu thiếu CSRF không bị chuyển hướng.');
        contentExpect(contentInspect($db, $draftHttpId)['deleted_at'] === null && contentAdminFind($model, $adminId, $prefix.'-http-thieu-token') === null, 'Yêu cầu thiếu CSRF vẫn được thực hiện.');
        contentExpect(str_contains(contentRequest($admin, $base, $noToken['location'] ?: '/')['body'], 'Phiên biểu mẫu đã hết hạn'), 'Thiếu thông báo lỗi CSRF.');

        $form = contentRequest($admin, $base, '/admin/articles/create');
        contentExpect($form['status'] === 200 && str_contains($form['body'], 'enctype="multipart/form-data"'), 'Trang viết bài mới lỗi.');
        $token = contentToken($form['body']);
        $invalidPost = contentRequest($admin, $base, '/admin/articles', ['_token' => $token, 'title' => 'Ngắn', 'category' => 'guide', 'slug' => '', 'body' => 'Ít chữ', 'intent' => 'draft']);
        contentExpect($invalidPost['status'] === 302 && $invalidPost['location'] === '/admin/articles/create', 'Dữ liệu sai không quay lại biểu mẫu.');
        $back = contentRequest($admin, $base, '/admin/articles/create')['body'];
        contentExpect(str_contains($back, 'Tiêu đề cần từ 5 đến 200 ký tự.') && str_contains($back, 'Nội dung cần từ 20 đến 50.000 ký tự.') && str_contains($back, 'value="Ngắn"'), 'Biểu mẫu không báo lỗi/giữ dữ liệu đã nhập.');
        $duplicate = contentRequest($admin, $base, '/admin/articles', ['_token' => $token, 'title' => 'Trùng slug HTTP '.$marker, 'category' => 'guide',
            'slug' => $prefix.'-tin-01', 'body' => 'Nội dung đủ dài để vượt qua kiểm tra.', 'intent' => 'draft']);
        contentExpect($duplicate['status'] === 302 && str_contains(contentRequest($admin, $base, '/admin/articles/create')['body'], 'Đường dẫn đã được dùng cho bài viết khác.'), 'Slug trùng qua HTTP không bị từ chối.');
        $badUpload = tempnam(sys_get_temp_dir(), 'h2h');
        file_put_contents($badUpload, 'không phải ảnh');
        $fake = contentRequest($admin, $base, '/admin/articles', ['_token' => $token, 'title' => 'Ảnh giả '.$marker, 'category' => 'guide', 'slug' => $prefix.'-http-anh-gia',
            'body' => 'Nội dung đủ dài để vượt qua kiểm tra.', 'intent' => 'draft', 'cover' => new CURLFile($badUpload, 'image/jpeg', 'anh.jpg')], true);
        contentExpect($fake['status'] === 302 && str_contains(contentRequest($admin, $base, '/admin/articles/create')['body'], 'Chỉ chấp nhận ảnh JPG, PNG hoặc WebP.')
            && contentAdminFind($model, $adminId, $prefix.'-http-anh-gia') === null, 'Tệp không phải ảnh vẫn được nhận.');
        echo "PASS HTTP Admin: filtered list, draft preview, CSRF required on create/delete, validation, duplicate slug and non-image upload rejected with flash and old input\n";

        $httpTitle = 'Content audit '.$marker.' HTTP Hà Giang';
        $created = contentRequest($admin, $base, '/admin/articles', ['_token' => $token, 'title' => $httpTitle, 'category' => 'guide', 'slug' => '', 'excerpt' => '',
            'body' => "Mở đầu với ký tự <script>alert('h2h')</script> phải hiển thị nguyên văn.\n\n## Lịch trình\nNgày 1: Quản Bạ.",
            'cover_alt' => '', 'intent' => 'publish', 'cover' => new CURLFile(dirname(__DIR__).'/public/assets/images/demo/1600585154340-be6161a56a0c.jpg', 'image/jpeg', 'ha-giang.jpg')], true);
        $httpSlug = $prefix.'-http-ha-giang';
        $createdRow = contentAdminFind($model, $adminId, $httpSlug);
        contentExpect($created['status'] === 302 && $createdRow !== null && $created['location'] === '/admin/articles/'.$createdRow['id'].'/edit', 'Tạo bài qua HTTP thất bại.');
        $createdId = (int) $createdRow['id'];
        $row = contentInspect($db, $createdId);
        contentExpect($row['status'] === 'published' && $row['cover_alt'] === $httpTitle && (bool) preg_match('~^/uploads/article-[a-f0-9]{24}\.jpg$~D', (string) $row['cover_image_url']), 'Bài tạo qua HTTP lưu sai ảnh bìa/trạng thái.');
        $coverPaths[] = $row['cover_image_url'];
        contentExpect(is_file(dirname(__DIR__).'/public'.$row['cover_image_url']), 'Ảnh bìa không được lưu vào thư mục uploads.');
        $edit = contentRequest($admin, $base, $created['location']);
        contentExpect($edit['status'] === 200 && str_contains($edit['body'], 'Đã tạo bài viết. Bài đang hiển thị công khai.'), 'Thiếu thông báo tạo bài.');
        $detail = contentRequest($admin, $base, '/articles/'.$httpSlug);
        contentExpect($detail['status'] === 200 && str_contains($detail['body'], '&lt;script&gt;alert(&#039;h2h&#039;)&lt;/script&gt;') && !str_contains($detail['body'], "<script>alert('h2h')")
            && str_contains($detail['body'], '<h2>Lịch trình</h2>') && str_contains($detail['body'], $row['cover_image_url']), 'Trang chi tiết không escape nội dung hoặc thiếu ảnh bìa.');
        $token = contentToken($edit['body']);
        $updated = contentRequest($admin, $base, '/admin/articles/'.$createdId, ['_token' => $token, 'title' => $httpTitle.' mùa hoa', 'category' => 'guide',
            'slug' => $httpSlug, 'excerpt' => 'Tóm tắt đã sửa.', 'body' => 'Nội dung đã sửa, vẫn đủ dài để hợp lệ.', 'cover_alt' => 'Ruộng bậc thang', 'intent' => 'save']);
        $row = contentInspect($db, $createdId);
        contentExpect($updated['location'] === '/admin/articles/'.$createdId.'/edit' && $row['title'] === $httpTitle.' mùa hoa' && $row['status'] === 'published'
            && $row['cover_alt'] === 'Ruộng bậc thang' && $row['cover_image_url'] === $coverPaths[0], 'Sửa bài qua HTTP sai.');
        contentRequest($admin, $base, '/admin/articles/'.$createdId, ['_token' => $token, 'title' => 'Ngắn', 'category' => 'guide', 'slug' => $httpSlug,
            'body' => 'Nội dung đã sửa, vẫn đủ dài để hợp lệ.', 'intent' => 'save']);
        contentExpect(contentInspect($db, $createdId)['title'] === $httpTitle.' mùa hoa', 'Sửa với dữ liệu sai vẫn ghi vào cơ sở dữ liệu.');
        $unpublish = contentRequest($admin, $base, '/admin/articles/'.$createdId.'/status', ['_token' => $token, 'status' => 'draft', 'return' => 'edit']);
        contentExpect($unpublish['location'] === '/admin/articles/'.$createdId.'/edit' && contentRequest($admin, $base, '/articles/'.$httpSlug)['status'] === 404, 'Gỡ bài qua HTTP vẫn hiển thị công khai.');
        $remove = contentRequest($admin, $base, '/admin/articles/'.$createdId.'/delete', ['_token' => $token]);
        contentExpect($remove['location'] === '/admin/articles' && str_contains(contentRequest($admin, $base, '/admin/articles')['body'], 'Đã xóa bài viết.'), 'Thiếu thông báo xóa bài.');
        contentExpect(contentRequest($admin, $base, '/admin/articles/'.$createdId.'/edit')['status'] === 404, 'Bài đã xóa qua HTTP vẫn mở được trang sửa.');
        $row = contentInspect($db, $createdId);
        contentExpect($row['audit_actions'] === 'create_article,update_article,unpublish_article,delete_article', 'Nhật ký quản trị HTTP sai: '.$row['audit_actions']);
        curl_close($admin);
        echo "PASS HTTP Admin: multipart create with cover upload and auto slug, escaped body, edit, invalid edit ignored, unpublish to 404, delete, audit trail\n";
    }
} finally {
    $statement = $db->prepare('CALL sp_test_content_cleanup(?)');
    $statement->execute([$marker]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $path) { $coverPaths[] = $path; }
    foreach (array_unique($coverPaths) as $path) {
        if (!is_string($path) || !preg_match('~^/uploads/(article-[a-f0-9]{24}\.(?:jpg|png|webp))$~D', $path, $match) || $uploadRoot === false) { continue; }
        $file = $uploadRoot.DIRECTORY_SEPARATOR.$match[1];
        if (is_file($file)) { unlink($file); }
    }
    foreach ($fixtureUsers as $userId) { $db->prepare('CALL sp_test_cleanup_user(?)')->execute([$userId]); }
    if (is_string($badUpload) && is_file($badUpload)) { unlink($badUpload); }
}
echo "PASS content fixtures cleaned up (articles, audit rows, uploaded covers, users)\n";
