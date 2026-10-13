<?php use App\Services\ArticleText; $hasFilters=$filters['q']!==''||$filters['category']!==''||$filters['status']!==''; ?>
<section class="page-section"><div class="container">
<div class="section-title"><div><p class="eyebrow">QUẢN TRỊ</p><h1>Quản lý bài viết</h1><p>Tin tức và cẩm nang du lịch. Mọi thao tác tạo, sửa, đăng và xóa đều được ghi vào nhật ký quản trị.</p></div><div class="action-row"><a class="btn btn-ghost" href="<?= e(url('/admin')) ?>">Quay lại quản trị</a><a class="btn btn-primary" href="<?= e(url('/admin/articles/create')) ?>">Viết bài mới</a></div></div>
<form class="surface-card article-filter mb-4" method="get" action="<?= e(url('/admin/articles')) ?>" role="search" aria-label="Lọc bài viết"><div class="form-row">
<div class="form-group col-lg-5"><label for="article-filter-q">Tìm theo tiêu đề hoặc đường dẫn</label><input class="form-control" id="article-filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="200"></div>
<div class="form-group col-sm-6 col-lg-3"><label for="article-filter-category">Chuyên mục</label><select class="custom-select form-control" id="article-filter-category" name="category"><option value="">Tất cả chuyên mục</option><?php foreach(ArticleText::CATEGORIES as $key=>$label): ?><option value="<?= e($key) ?>"<?= $filters['category']===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
<div class="form-group col-sm-6 col-lg-2"><label for="article-filter-status">Trạng thái</label><select class="custom-select form-control" id="article-filter-status" name="status"><option value="">Tất cả</option><?php foreach($statuses as $key=>$label): ?><option value="<?= e($key) ?>"<?= $filters['status']===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
<div class="form-group col-lg-2 article-filter-submit"><button class="btn btn-primary btn-block" type="submit">Lọc</button></div>
</div><?php if($hasFilters): ?><a href="<?= e(url('/admin/articles')) ?>">Xóa bộ lọc</a><?php endif; ?></form>
<p class="results-summary" role="status"><strong><?= (int)$total ?> bài viết</strong> · Trang <?= (int)$page ?>/<?= (int)$pages ?><?= $hasFilters?' · Đã áp dụng bộ lọc.':'' ?></p>
<?php if(!$articles): ?>
<div class="empty-state"><h2><?= $hasFilters?'Không có bài viết phù hợp':'Chưa có bài viết nào' ?></h2><p><?= $hasFilters?'Hãy đổi từ khóa hoặc bỏ bớt bộ lọc.':'Bắt đầu bằng một bài cẩm nang hoặc tin tức đầu tiên.' ?></p><?php if($hasFilters): ?><a class="btn btn-outline-primary" href="<?= e(url('/admin/articles')) ?>">Xóa bộ lọc</a><?php else: ?><a class="btn btn-primary" href="<?= e(url('/admin/articles/create')) ?>">Viết bài mới</a><?php endif; ?></div>
<?php else: ?>
<div class="surface-card"><div class="table-responsive" tabindex="0" role="region" aria-label="Danh sách bài viết"><table class="table article-table"><thead><tr><th scope="col">Bài viết</th><th scope="col">Chuyên mục</th><th scope="col">Trạng thái</th><th scope="col">Cập nhật</th><th scope="col">Thao tác</th></tr></thead><tbody>
<?php foreach($articles as $item): $published=$item['status']==='published'; ?><tr>
<td><strong><a href="<?= e(url('/admin/articles/'.(int)$item['id'].'/edit')) ?>"><?= e($item['title']) ?></a></strong><br><small class="text-muted article-slug">/articles/<?= e($item['slug']) ?></small></td>
<td data-label="Chuyên mục"><?= e(ArticleText::categoryLabel($item['category'])) ?></td>
<td data-label="Trạng thái"><span class="status <?= $published?'status-approved':'status-draft' ?>"><?= e($statuses[$item['status']]??$item['status']) ?></span><?php if($item['published_at']): ?><br><small class="text-muted">Đăng <?= e(ArticleText::date($item['published_at'])) ?></small><?php endif; ?></td>
<td data-label="Cập nhật"><?= e(ArticleText::date($item['updated_at'])) ?><br><small class="text-muted"><?= e($item['author_name']) ?></small></td>
<td class="article-actions"><div class="action-row">
<a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/articles/'.(int)$item['id'].'/edit')) ?>" aria-label="Sửa bài: <?= e($item['title']) ?>">Sửa</a>
<a class="btn btn-sm btn-ghost" href="<?= e(url($published?'/articles/'.$item['slug']:'/admin/articles/'.(int)$item['id'].'/preview')) ?>" aria-label="<?= $published?'Xem':'Xem trước' ?> bài: <?= e($item['title']) ?>"><?= $published?'Xem':'Xem trước' ?></a>
<form method="post" action="<?= e(url('/admin/articles/'.(int)$item['id'].'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $published?'draft':'published' ?>"><button class="btn btn-sm btn-ghost" type="submit" aria-label="<?= $published?'Gỡ xuống':'Đăng' ?> bài: <?= e($item['title']) ?>"><?= $published?'Gỡ xuống':'Đăng' ?></button></form>
<form method="post" action="<?= e(url('/admin/articles/'.(int)$item['id'].'/delete')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Xóa bài viết “<?= e($item['title']) ?>”? Bài sẽ không còn hiển thị công khai." aria-label="Xóa bài: <?= e($item['title']) ?>">Xóa</button></form>
</div></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php $pageBase='/admin/articles'; $pageQuery=$filters; $pageLabel='Phân trang danh sách bài viết'; require dirname(__DIR__,2).'/articles/pagination.php'; ?>
</div></section>
