<?php
$workspaceLinks = $isAdminWorkspace ? [
    ['/admin','Tổng quan','grid'], ['/admin/catalog','Danh mục','sliders'],
    ['/admin/reviews','Đánh giá','star'], ['/admin/reports','Báo cáo','shield'],
    ['/admin/payments','Thanh toán','calendar'], ['/admin/promotions','Khuyến mãi','plus'],
    ['/admin/holidays','Ngày lễ','calendar'], ['/admin/verifications','Xác minh danh tính','shield'],
    ['/admin/messages','Tin nhắn','message'], ['/admin/articles','Bài viết','book'],
] : [
    ['/host','Tổng quan','grid'], ['/host/listings/create','Đăng chỗ ở','plus'],
    ['/host/reviews','Đánh giá của khách','star'], ['/messages','Tin nhắn','message'],
    ['/profile','Hồ sơ','user'],
];
?>
<aside class="workspace-nav">
    <div class="workspace-identity"><span class="account-avatar"><?= e(mb_strtoupper(mb_substr($currentUser['full_name'],0,1))) ?></span><div><strong><?= e($currentUser['full_name']) ?></strong><small><?= e($isAdminWorkspace?__('Quản trị'):__('Không gian Host')) ?></small></div></div>
    <nav aria-label="<?= e(__('Điều hướng không gian làm việc')) ?>">
        <?php foreach($workspaceLinks as [$navPath,$navLabel,$navIcon]): $navActive=$requestPath===$navPath || ($navPath!==($isAdminWorkspace?'/admin':'/host') && str_starts_with($requestPath,$navPath.'/')); ?>
        <a href="<?= e(url($navPath)) ?>" class="workspace-link<?= $navActive?' is-active':'' ?>" <?= $navActive?'aria-current="page"':'' ?>><?= ui_icon($navIcon) ?><span><?= e(__($navLabel)) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <a class="workspace-return" href="<?= e(url('/')) ?>"><?= ui_icon('arrow') ?> <?= e(__('Khám phá chỗ ở')) ?></a>
</aside>
