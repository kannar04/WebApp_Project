<?php
use Core\Auth;
use Core\Session;
require_once dirname(__DIR__).'/components/ui-icons.php';
$currentUser=Auth::user();
$requestPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/';
$basePath=rtrim(parse_url(url('/'),PHP_URL_PATH)??'','/');
if($basePath!=='' && str_starts_with($requestPath,$basePath.'/')) { $requestPath=substr($requestPath,strlen($basePath)); }
$isAdminWorkspace=$currentUser && in_array('admin',$currentUser['roles'],true) && ($requestPath==='/admin'||str_starts_with($requestPath,'/admin/'));
$isHostWorkspace=$currentUser && in_array('host',$currentUser['roles'],true) && ($requestPath==='/host'||str_starts_with($requestPath,'/host/'));
$isWorkspace=$isAdminWorkspace||$isHostWorkspace;
$isAccountPage=$currentUser && preg_match('~^/(profile|bookings|wishlist|messages|notifications|reports|payments)(/|$)~',$requestPath);
$messageUnread=$currentUser?\App\Services\ConversationService::navigation((int)$currentUser['id']):0;
?>
<!doctype html>
<html lang="<?= e(\Core\I18n::locale()) ?>">
<head><?php require dirname(__DIR__).'/components/document-head.php'; ?></head>
<body class="experience-body<?= $isWorkspace?' workspace-body':'' ?>">
<a class="skip-link" href="#main-content"><?= e(__('Bỏ qua điều hướng')) ?></a>
<header class="site-header"><div class="container">
<nav class="navbar navbar-expand-lg navbar-light px-0" aria-label="<?= e(__('Điều hướng chính')) ?>">
    <a class="navbar-brand brand" href="<?= e(url('/')) ?>">home2home<span class="brand-dot" aria-hidden="true">.</span></a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="<?= e(__('Mở điều hướng')) ?>"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav mx-auto">
            <?php foreach([['/','Khám phá','search'],['/wishlist','Đã lưu','heart'],['/bookings','Chuyến đi','calendar']] as [$navPath,$navLabel,$navIcon]): $active=$requestPath===$navPath || ($navPath!=='/' && str_starts_with($requestPath,$navPath.'/')); ?>
            <li class="nav-item"><a class="nav-link<?= $active?' is-active':'' ?>" href="<?= e(url($navPath)) ?>" <?= $active?'aria-current="page"':'' ?>><?= ui_icon($navIcon) ?> <?= e(__($navLabel)) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <div class="navbar-actions">
            <div class="appearance-controls"><?php require dirname(__DIR__).'/components/appearance-controls.php'; ?></div>
            <?php if(!$currentUser): ?>
                <a class="btn btn-ghost" href="<?= e(url('/login?'.http_build_query(['next'=>\App\Services\TripContext::safeReturn(\Core\I18n::returnPath())]))) ?>"><?= e(__('Đăng nhập')) ?></a>
                <a class="btn btn-primary nav-signup" href="<?= e(url('/register?'.http_build_query(['next'=>\App\Services\TripContext::safeReturn(\Core\I18n::returnPath())]))) ?>"><?= e(__('Đăng ký')) ?> <?= ui_icon('arrow') ?></a>
            <?php else: ?>
                <a class="nav-message icon-control" href="<?= e(url('/messages')) ?>" aria-label="<?= e(__('Tin nhắn')) ?>"><?= ui_icon('message') ?><?php if($messageUnread>0): ?><span class="nav-unread-badge"><?= $messageUnread>99?'99+':(int)$messageUnread ?><span class="sr-only"> <?= e(__('tin nhắn chưa đọc')) ?></span></span><?php endif; ?></a>
                <div class="dropdown account-dropdown">
                    <button class="account-trigger" id="account-menu-button" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="account-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($currentUser['full_name'],0,1))) ?></span><span class="account-name"><?= e($currentUser['full_name']) ?></span><?= ui_icon('chevron') ?></button>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="account-menu-button">
                        <?php foreach([['/profile','Hồ sơ','user'],['/bookings','Đặt chỗ','calendar'],['/wishlist','Yêu thích','heart'],['/notifications','Thông báo','bell']] as [$accountPath,$accountLabel,$accountIcon]): ?><a class="dropdown-item" href="<?= e(url($accountPath)) ?>"><?= ui_icon($accountIcon) ?> <?= e(__($accountLabel)) ?></a><?php endforeach; ?>
                        <div class="dropdown-divider"></div>
                        <?php if(in_array('host',$currentUser['roles'],true)): ?><a class="dropdown-item" href="<?= e(url('/host')) ?>"><?= ui_icon('home') ?> <?= e(__('Không gian Host')) ?></a><?php endif; ?>
                        <?php if(in_array('admin',$currentUser['roles'],true)): ?><a class="dropdown-item" href="<?= e(url('/admin')) ?>"><?= ui_icon('grid') ?> <?= e(__('Quản trị')) ?></a><?php endif; ?>
                        <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="dropdown-item" type="submit"><?= ui_icon('logout') ?> <?= e(__('Đăng xuất')) ?></button></form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav></div></header>
<main id="main-content" tabindex="-1">
    <?php if($isWorkspace): ?><div class="workspace-shell"><?php require dirname(__DIR__).'/components/workspace-nav.php'; ?><div class="workspace-content"><?php endif; ?>
    <?php if($isAccountPage): ?><div class="account-navigation"><nav class="container" aria-label="<?= e(__('Tài khoản của bạn')) ?>">
        <?php foreach([['/profile','Hồ sơ','user'],['/bookings','Đặt chỗ','calendar'],['/wishlist','Yêu thích','heart'],['/messages','Tin nhắn','message'],['/notifications','Thông báo','bell']] as [$accountPath,$accountLabel,$accountIcon]): $active=$requestPath===$accountPath || str_starts_with($requestPath,$accountPath.'/'); ?>
        <a href="<?= e(url($accountPath)) ?>" <?= $active?'class="is-active" aria-current="page"':'' ?>><?= ui_icon($accountIcon) ?> <?= e(__($accountLabel)) ?></a>
        <?php endforeach; ?>
    </nav></div><?php endif; ?>
    <?php if($message=Session::flash('success')): ?><div class="container mt-3"><div class="alert alert-success" role="status"><?= e(__($message)) ?></div></div><?php endif; ?>
    <?php if($message=Session::flash('error')): ?><div class="container mt-3"><div class="alert alert-danger" role="alert"><?= e(__($message)) ?></div></div><?php endif; ?>
    <?= $content ?>
    <?php if($isWorkspace): ?></div></div><?php endif; ?>
</main>
<footer class="site-footer"><div class="container">
    <div class="footer-main row">
        <div class="col-md-6"><a class="brand" href="<?= e(url('/')) ?>">home2home<span class="brand-dot" aria-hidden="true">.</span></a><p class="footer-manifesto"><?= e(__('Ở đâu cũng thấy như ở nhà.')) ?></p></div>
        <div class="col-6 col-md-3"><h2><?= e(__('Khám phá')) ?></h2><a href="<?= e(url('/')) ?>"><?= e(__('Tìm chỗ ở')) ?></a><a href="<?= e(url('/map')) ?>"><?= e(__('Xem trên bản đồ')) ?></a><a href="<?= e(url('/articles')) ?>"><?= e(__('Cẩm nang du lịch và tin tức')) ?></a></div>
        <div class="col-6 col-md-3"><h2><?= e(__('Hành trình của bạn')) ?></h2><a href="<?= e(url('/bookings')) ?>"><?= e(__('Đặt chỗ')) ?></a><a href="<?= e(url('/wishlist')) ?>"><?= e(__('Yêu thích')) ?></a><a href="<?= e(url('/profile')) ?>"><?= e(__('Tài khoản của bạn')) ?></a></div>
    </div>
    <div class="footer-bottom"><span>© <?= date('Y') ?> Home2Home</span><span><?= e(__('Việt Nam, theo nhịp riêng của bạn.')) ?></span><a href="#main-content"><?= e(__('Lên đầu trang')) ?> ↑</a></div>
</div></footer>
<?php if(!$isWorkspace && !preg_match('~^/listings/\d+(?:/review)?$~',$requestPath)): ?>
<nav class="mobile-guest-nav" aria-label="<?= e(__('Điều hướng chính')) ?>">
    <?php foreach([['/','Khám phá','search'],['/wishlist','Đã lưu','heart'],['/bookings','Chuyến đi','calendar']] as [$mobilePath,$mobileLabel,$mobileIcon]): ?>
    <a href="<?= e(url($mobilePath)) ?>" <?= $requestPath===$mobilePath?'aria-current="page"':'' ?>><?= ui_icon($mobileIcon) ?><span><?= e(__($mobileLabel)) ?></span></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<script src="<?= e(asset('vendor/jquery.slim.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>"></script>
<script type="application/json" id="h2h-i18n"><?= json_encode(\Core\I18n::scriptDictionary(),JSON_FORCE_OBJECT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach(($pageScripts??[]) as $pageScript): ?><script src="<?= e(asset($pageScript)) ?>"></script><?php endforeach; ?>
</body></html>
