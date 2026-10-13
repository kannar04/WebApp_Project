<?php use App\Services\ArticleText; $categoryLabel=ArticleText::categoryLabel($article['category']); $publishedDate=ArticleText::date($article['published_at']??null); ?>
<div class="page-section article-page"><div class="container article-container">
<?php if($preview): ?><div class="alert alert-info" role="status"><strong>Bản xem trước dành cho Admin.</strong> <?= ($article['status']??'')==='published'?'Bài viết đang hiển thị công khai.':'Bài viết đang là bản nháp, khách chưa thể xem.' ?> <a href="<?= e(url('/admin/articles/'.(int)$article['id'].'/edit')) ?>">Quay lại chỉnh sửa</a></div><?php endif; ?>
<nav aria-label="Đường dẫn trang"><ol class="breadcrumb article-breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url('/articles')) ?>">Cẩm nang</a></li><li class="breadcrumb-item"><a href="<?= e(url('/articles?'.http_build_query(['category'=>$article['category']]))) ?>"><?= e($categoryLabel) ?></a></li><li class="breadcrumb-item active" aria-current="page"><?= e($article['title']) ?></li></ol></nav>
<article class="article-detail">
<header class="article-header"><p class="eyebrow"><?= e(mb_strtoupper($categoryLabel)) ?></p><h1><?= e($article['title']) ?></h1>
<p class="article-meta">Bởi <strong><?= e($article['author_name']) ?></strong> · <?php if($publishedDate!==''): ?><time datetime="<?= e(substr((string)$article['published_at'],0,10)) ?>"><?= e($publishedDate) ?></time><?php else: ?>Chưa đăng<?php endif; ?> · <?= ArticleText::readingMinutes($article['body']) ?> phút đọc</p>
<p class="article-lead"><?= e($article['excerpt']) ?></p></header>
<?php if(!empty($article['cover_image_url'])): ?><figure class="article-cover"><img src="<?= e(image_url($article['cover_image_url'])) ?>" alt="<?= e((string)($article['cover_alt']??'')) ?>"></figure><?php endif; ?>
<div class="article-body"><?= ArticleText::toHtml($article['body']) ?></div>
<p class="mt-4"><a class="btn btn-outline-primary" href="<?= e(url('/articles')) ?>">‹ Quay lại Cẩm nang</a></p>
</article>
</div>
<?php if($related): ?><section class="article-related" aria-labelledby="related-articles-title"><div class="container"><h2 id="related-articles-title">Bài viết khác</h2><div class="row">
<?php foreach($related as $item): ?><div class="col-md-6 col-lg-4 mb-4"><article class="article-card">
<div class="card-image-wrap"><img class="card-image" src="<?= e(image_url($item['cover_image_url'])) ?>" alt="<?= e((string)($item['cover_alt']??'')) ?>" loading="lazy"></div>
<div class="card-body"><p class="article-meta"><span class="article-category"><?= e(ArticleText::categoryLabel($item['category'])) ?></span> · <time datetime="<?= e(substr((string)$item['published_at'],0,10)) ?>"><?= e(ArticleText::date($item['published_at'])) ?></time></p>
<h3 class="article-card-title"><a class="stretched-link" href="<?= e(url('/articles/'.$item['slug'])) ?>"><?= e($item['title']) ?></a></h3>
<p class="article-excerpt"><?= e($item['excerpt']) ?></p></div>
</article></div><?php endforeach; ?>
</div></div></section><?php endif; ?>
</div>
