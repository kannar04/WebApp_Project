<?php
$workspaceLinks = $isAdminWorkspace ? [
    ['/admin/host-requests',__('Host requests'),'user'],
    ['/admin',__('Tổng quan'),'grid'], ['/admin/catalog',__('Danh mục'),'sliders'],
    ['/admin/reviews',__('Đánh giá'),'star'], ['/admin/reports',__('Báo cáo'),'shield'],
    ['/admin/payments',__('Thanh toán'),'calendar'], ['/admin/host-payouts',__('Chi trả Host'),'ticket'], ['/admin/promotions',__('Khuyến mãi'),'plus'],
    ['/admin/holidays',__('Ngày lễ'),'calendar'], ['/admin/verifications',__('Xác minh danh tính'),'shield'],
    ['/admin/messages',__('Tin nhắn'),'message'], ['/admin/articles',__('Bài viết'),'book'],
] : [
    ['/host',__('Tổng quan'),'grid'], ['/host/listings/create',__('Đăng chỗ ở'),'plus'],
    ['/host/reviews',__('Đánh giá của khách'),'star'], ['/host/payouts',__('Tài khoản nhận tiền'),'ticket'], ['/messages',__('Tin nhắn'),'message'],
    ['/notifications',__('Thông báo'),'bell'], ['/profile',__('Hồ sơ'),'user'],
];
?>
<aside class="workspace-nav">
    <div class="workspace-identity"><span class="account-avatar"><?= e(mb_strtoupper(mb_substr($currentUser['full_name'],0,1))) ?></span><div><strong><?= e($currentUser['full_name']) ?></strong><small><?= e($isAdminWorkspace?__('Quản trị'):__('Không gian Host')) ?></small></div></div>
    <nav aria-label="<?= e(__('Điều hướng không gian làm việc')) ?>">
        <?php foreach($workspaceLinks as [$navPath,$navLabel,$navIcon]): $navActive=$requestPath===$navPath || ($navPath!==($isAdminWorkspace?'/admin':'/host') && str_starts_with($requestPath,$navPath.'/')); ?>
        <a href="<?= e(url($navPath)) ?>" class="workspace-link<?= $navActive?' is-active':'' ?>" <?= $navActive?'aria-current="page"':'' ?>><?= ui_icon($navIcon) ?><span><?= e($navLabel) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <a class="workspace-return" href="<?= e(url('/')) ?>"><?= ui_icon('arrow') ?> <?= e(__('Khám phá chỗ ở')) ?></a>
</aside>
