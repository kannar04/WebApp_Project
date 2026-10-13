<?php /* Shared by public and Admin article lists: $page, $pages, $pageBase (path), $pageQuery (array), $pageLabel. */ ?>
<?php if($pages>1): ?><?php $pageQuery=array_filter($pageQuery,'strlen'); $lastShown=0; ?>
<nav class="mt-3" aria-label="<?= e($pageLabel) ?>"><ul class="pagination flex-wrap">
<?php if($page>1): ?><li class="page-item"><a class="page-link" href="<?= e(url($pageBase.'?'.http_build_query(array_replace($pageQuery,['page'=>$page-1])))) ?>" rel="prev">‹ Trước</a></li><?php endif; ?>
<?php for($p=1;$p<=$pages;$p++): ?><?php if($p!==1 && $p!==$pages && abs($p-$page)>1){ continue; } ?>
<?php if($lastShown && $p-$lastShown>1): ?><li class="page-item disabled"><span class="page-link" aria-hidden="true">…</span></li><?php endif; ?><?php $lastShown=$p; ?>
<li class="page-item<?= $p===$page?' active':'' ?>"><a class="page-link" href="<?= e(url($pageBase.'?'.http_build_query(array_replace($pageQuery,['page'=>$p])))) ?>"<?= $p===$page?' aria-current="page"':'' ?>><span class="sr-only">Trang </span><?= (int)$p ?></a></li>
<?php endfor; ?>
<?php if($page<$pages): ?><li class="page-item"><a class="page-link" href="<?= e(url($pageBase.'?'.http_build_query(array_replace($pageQuery,['page'=>$page+1])))) ?>" rel="next">Sau ›</a></li><?php endif; ?>
</ul></nav>
<?php endif; ?>
