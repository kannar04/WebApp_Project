<?php use App\Services\ArticleText; $tabs=[''=>'Tất cả']+ArticleText::CATEGORIES; ?>
<section class="page-section article-hub"><div class="container">
<div class="section-title"><div><p class="eyebrow">CẨM NANG HOME2HOME</p><h1>Cẩm nang du lịch và tin tức</h1><p>Gợi ý lịch trình, kinh nghiệm chọn chỗ ở và cập nhật mới nhất từ Home2Home.</p></div></div>
<nav class="article-tabs" aria-label="Chuyên mục bài viết">
<?php foreach($tabs as $key=>$label): ?><a class="article-tab<?= $key===$category?' is-active':'' ?>" href="<?= e(url('/articles'.($key!==''?'?'.http_build_query(['category'=>$key]):''))) ?>"<?= $key===$category?' aria-current="page"':'' ?>><?= e($label) ?></a>
<?php endforeach; ?>
</nav>
<p class="results-summary" role="status"><strong><?= (int)$total ?> bài viết</strong><?php if($pages>1): ?> · Trang <?= (int)$page ?>/<?= (int)$pages ?><?php endif; ?></p>
<?php if(!$articles): ?>
<div class="empty-state"><h2>Chưa có bài viết trong mục này</h2><p>Các bài viết mới sẽ sớm được cập nhật. Bạn có thể xem những chuyên mục khác.</p><a class="btn btn-primary" href="<?= e(url('/articles')) ?>">Xem tất cả bài viết</a></div>
<?php else: ?>
<div class="row">
<?php foreach($articles as $item): ?><div class="col-md-6 col-lg-4 mb-4"><article class="article-card">
<div class="card-image-wrap"><img class="card-image" src="<?= e(image_url($item['cover_image_url'])) ?>" alt="<?= e((string)($item['cover_alt']??'')) ?>" loading="lazy"></div>
<div class="card-body"><p class="article-meta"><span class="article-category"><?= e(ArticleText::categoryLabel($item['category'])) ?></span> · <time datetime="<?= e(substr((string)$item['published_at'],0,10)) ?>"><?= e(ArticleText::date($item['published_at'])) ?></time></p>
<h2 class="article-card-title"><a class="stretched-link" href="<?= e(url('/articles/'.$item['slug'])) ?>"><?= e($item['title']) ?></a></h2>
<p class="article-excerpt"><?= e($item['excerpt']) ?></p></div>
</article></div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php $pageBase='/articles'; $pageQuery=['category'=>$category]; $pageLabel='Phân trang bài viết'; require __DIR__.'/pagination.php'; ?>
</div></section>
