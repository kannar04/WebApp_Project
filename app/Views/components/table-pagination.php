<?php
/** Independent table pager; preserve other tables and filters. */
$tableCurrent=$tablePage['page'];
$tableLast=$tablePage['pages'];
$tableQuery=array_filter($_GET,static fn($value)=>is_string($value));
$tableUrl=static fn($number)=>url(parse_url($_SERVER['REQUEST_URI']??'/admin',PHP_URL_PATH).'?'.http_build_query(array_replace($tableQuery,[$tablePageKey=>$number])).'#'.$tableAnchor);
?>
<?php if($tableLast>1): ?>
<nav class="mt-3" aria-label="<?= e(__('Phân trang').' · '.__('Trang').' '.$tableCurrent.'/'.$tableLast) ?>">
<ul class="pagination flex-wrap">
<?php $lastShown=0; foreach(array_unique([1,max(1,$tableCurrent-1),$tableCurrent,min($tableLast,$tableCurrent+1),$tableLast]) as $number): ?>
<?php if($lastShown && $number-$lastShown>1): ?><li class="page-item disabled"><span class="page-link" aria-hidden="true">…</span></li><?php endif; $lastShown=$number; ?>
<li class="page-item<?= $number===$tableCurrent?' active':'' ?>"><a class="page-link" href="<?= e($tableUrl($number)) ?>"<?= $number===$tableCurrent?' aria-current="page"':'' ?>><?= (int)$number ?></a></li>
<?php endforeach; ?>
</ul></nav>
<?php endif; ?>
