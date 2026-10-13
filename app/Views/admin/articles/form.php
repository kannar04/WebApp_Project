<?php
use App\Services\ArticleText;
$old=$old??[]; $isEdit=$article!==null;
$fields=['title'=>'','category'=>'guide','slug'=>'','excerpt'=>'','body'=>'','cover_alt'=>''];
$values=array_replace($fields,$isEdit?array_intersect_key($article,$fields):[],array_intersect_key($old,$fields));
$values=array_map('strval',$values);
$published=$isEdit && $article['status']==='published';
?>
<section class="page-section"><div class="container narrow">
<div class="section-title"><div><p class="eyebrow"><?= e(__('QUẢN TRỊ · BÀI VIẾT')) ?></p><h1><?= e($isEdit?__('Sửa bài viết'):__('Viết bài mới')) ?></h1><p><?= e(__('Thay đổi của Admin được ghi vào nhật ký quản trị.')) ?></p></div><a class="btn btn-ghost" href="<?= e(url('/admin/articles')) ?>"><?= e(__('Quay lại danh sách')) ?></a></div>
<?php if($isEdit): ?>
<section class="surface-card mb-4" aria-labelledby="article-state-title"><h2 id="article-state-title"><?= e(__('Trạng thái')) ?></h2>
<p class="mb-2"><span class="status <?= $published?'status-approved':'status-draft' ?>"><?= e(__($statuses[$article['status']]??$article['status'])) ?></span></p>
<ul class="article-state-meta"><?php if($article['published_at']): ?><li><?= e(__('Đăng lần đầu:')) ?> <strong><?= e(ArticleText::date($article['published_at'])) ?></strong></li><?php endif; ?><li><?= e(__('Cập nhật:')) ?> <strong><?= e(ArticleText::date($article['updated_at'])) ?></strong></li><li><?= e(__('Tác giả:')) ?> <strong><?= e($article['author_name']) ?></strong></li></ul>
<p class="text-muted small"><?= e($published?__('Khách đang xem được bài này tại'):__('Bản nháp chưa hiển thị công khai. Khi đăng, bài sẽ có địa chỉ')) ?> <span class="article-slug">/articles/<?= e($article['slug']) ?></span></p>
<div class="action-row">
<a class="btn btn-ghost" href="<?= e(url('/admin/articles/'.(int)$article['id'].'/preview')) ?>"><?= e(__('Xem trước')) ?></a>
<?php if($published): ?><a class="btn btn-ghost" href="<?= e(url('/articles/'.$article['slug'])) ?>"><?= e(__('Xem trên trang Cẩm nang')) ?></a><?php endif; ?>
<form method="post" action="<?= e(url('/admin/articles/'.(int)$article['id'].'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="edit"><input type="hidden" name="status" value="<?= $published?'draft':'published' ?>"><button class="btn <?= $published?'btn-outline-primary':'btn-primary' ?>" type="submit"><?= e($published?__('Gỡ xuống (chuyển về nháp)'):__('Đăng bài')) ?></button></form>
<form method="post" action="<?= e(url('/admin/articles/'.(int)$article['id'].'/delete')) ?>"><?= csrf_field() ?><button class="btn btn-outline-danger" type="submit" data-confirm="<?= e(__('Xóa bài viết “{title}”? Bài sẽ không còn hiển thị công khai.', ['title'=>$article['title']])) ?>"><?= e(__('Xóa bài viết')) ?></button></form>
</div></section>
<?php endif; ?>
<form class="surface-card article-form" method="post" enctype="multipart/form-data" action="<?= e(url($isEdit?'/admin/articles/'.(int)$article['id']:'/admin/articles')) ?>"><?= csrf_field() ?>
<div class="form-group"><label for="article-title"><?= e(__('Tiêu đề')) ?></label><input class="form-control" id="article-title" name="title" value="<?= e($values['title']) ?>" minlength="5" maxlength="200" required aria-describedby="article-title-help"><small id="article-title-help" class="form-text text-muted"><?= e(__('Từ 5 đến 200 ký tự.')) ?></small></div>
<div class="form-row">
<div class="form-group col-md-4"><label for="article-category"><?= e(__('Chuyên mục')) ?></label><select class="custom-select form-control" id="article-category" name="category" required><?php foreach($categories as $key=>$label): ?><option value="<?= e($key) ?>"<?= $values['category']===$key?' selected':'' ?>><?= e(__($label)) ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-8"><label for="article-slug"><?= e(__('Đường dẫn (slug)')) ?></label><div class="input-group"><div class="input-group-prepend"><span class="input-group-text">/articles/</span></div><input class="form-control" id="article-slug" name="slug" value="<?= e($values['slug']) ?>" maxlength="200" autocomplete="off" spellcheck="false" aria-describedby="article-slug-help"></div>
<small id="article-slug-help" class="form-text text-muted"><?= e(__('Để trống để tự tạo từ tiêu đề (không dấu, ví dụ cam-nang-da-lat). Đường dẫn trùng sẽ bị từ chối; đổi đường dẫn của bài đã đăng làm liên kết cũ ngừng hoạt động.')) ?></small></div>
</div>
<div class="form-group"><label for="article-excerpt"><?= e(__('Tóm tắt')) ?></label><textarea class="form-control" id="article-excerpt" name="excerpt" rows="3" maxlength="500" aria-describedby="article-excerpt-help"><?= e($values['excerpt']) ?></textarea><small id="article-excerpt-help" class="form-text text-muted"><?= e(__('Không bắt buộc, tối đa 500 ký tự. Để trống để dùng đoạn đầu của nội dung.')) ?></small></div>
<div class="form-group"><label for="article-body"><?= e(__('Nội dung')) ?></label><textarea class="form-control article-body-input" id="article-body" name="body" rows="16" minlength="20" maxlength="50000" required aria-describedby="article-body-help"><?= e($values['body']) ?></textarea><small id="article-body-help" class="form-text text-muted"><?= e(__('Văn bản thuần, không nhận HTML. Để một dòng trống giữa các đoạn; bắt đầu dòng bằng “## ” để tạo tiêu đề nhỏ.')) ?></small></div>
<fieldset class="form-group article-cover-fields"><legend><?= e(__('Ảnh bìa')) ?></legend>
<?php if($isEdit && !empty($article['cover_image_url'])): ?><img class="article-cover-preview" src="<?= e(image_url($article['cover_image_url'])) ?>" alt="<?= e(__('Ảnh bìa hiện tại: {alt}', ['alt'=>(string)$article['cover_alt']])) ?>">
<div class="custom-control custom-checkbox mb-3"><input class="custom-control-input" type="checkbox" id="article-remove-cover" name="remove_cover" value="1"><label class="custom-control-label" for="article-remove-cover"><?= e(__('Gỡ ảnh bìa hiện tại')) ?></label></div><?php endif; ?>
<div class="form-group"><label for="article-cover"><?= e($isEdit && !empty($article['cover_image_url'])?__('Thay ảnh bìa'):__('Tải ảnh bìa')) ?> <?= e(__('(JPG, PNG hoặc WebP, tối đa 5 MB)')) ?></label><input class="form-control-file" type="file" id="article-cover" name="cover" accept="image/jpeg,image/png,image/webp"></div>
<div class="form-group mb-0"><label for="article-cover-alt"><?= e(__('Mô tả ảnh bìa')) ?></label><input class="form-control" id="article-cover-alt" name="cover_alt" value="<?= e($values['cover_alt']) ?>" maxlength="255" aria-describedby="article-cover-alt-help"><small id="article-cover-alt-help" class="form-text text-muted"><?= e(__('Giúp người dùng trình đọc màn hình hiểu nội dung ảnh. Để trống sẽ dùng tiêu đề bài viết.')) ?></small></div>
</fieldset>
<div class="action-row"><?php if($isEdit): ?><button class="btn btn-primary" type="submit" name="intent" value="save"><?= e(__('Lưu thay đổi')) ?></button><?php else: ?><button class="btn btn-outline-primary" type="submit" name="intent" value="draft"><?= e(__('Lưu nháp')) ?></button><button class="btn btn-primary" type="submit" name="intent" value="publish"><?= e(__('Đăng bài')) ?></button><?php endif; ?><a class="btn btn-ghost" href="<?= e(url('/admin/articles')) ?>"><?= e(__('Hủy')) ?></a></div>
</form>
</div></section>
