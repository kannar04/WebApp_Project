<?php use Core\Auth; use Core\Session; $currentUser=Auth::user(); ?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="app-base-url" content="<?= e(url('/')) ?>"><meta name="csrf-token" content="<?= e(\Core\Csrf::token()) ?>"><script src="<?= e(asset('js/theme.js')) ?>"></script><title><?= e($title??'Home2Home') ?> · Home2Home</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css"><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"></head>
<body><a class="skip-link" href="#main-content">Bỏ qua điều hướng</a>
<header class="site-header"><div class="container"><nav class="navbar navbar-expand-lg navbar-light px-0"><a class="navbar-brand brand" href="<?= e(url('/')) ?>"><span class="brand-mark" aria-hidden="true"></span>home 2 home</a><button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Mở điều hướng"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="mainNav">
<ul class="navbar-nav mx-auto">
<li class="nav-item"><a class="nav-link" href="<?= e(url('/')) ?>">Khám phá</a></li>
<li class="nav-item"><a class="nav-link" href="<?= e(url('/articles')) ?>">Cẩm nang</a></li>
<?php if($currentUser): ?>
<li class="nav-item"><a class="nav-link" href="<?= e(url('/wishlist')) ?>">Yêu thích</a></li>
<li class="nav-item"><a class="nav-link" href="<?= e(url('/bookings')) ?>">Đặt chỗ</a></li>
<?php endif; ?>
</ul>
<div class="navbar-actions">
<button class="btn btn-ghost theme-toggle" type="button" data-theme-toggle aria-pressed="false" title="Bật chế độ tối"><svg class="theme-icon theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg><svg class="theme-icon theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4.5"/><path d="M12 1.5v2.5M12 20v2.5M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M1.5 12H4M20 12h2.5M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg><span class="theme-toggle-label" data-theme-label>Bật chế độ tối</span></button>
<?php if(!$currentUser): ?>
<a class="btn btn-ghost mr-2" href="<?= e(url('/login')) ?>">Đăng nhập</a>
<a class="btn btn-primary" href="<?= e(url('/register')) ?>">Đăng ký</a>
<?php else: ?>
<?php if(in_array('host',$currentUser['roles'],true)): ?><a class="btn btn-ghost" href="<?= e(url('/host')) ?>">Không gian Host</a><?php endif; ?>

<?php if(in_array('admin',$currentUser['roles'],true)): ?><a class="btn btn-ghost" href="<?= e(url('/admin')) ?>">Quản trị</a><?php endif; ?>

<a class="btn btn-ghost" href="<?= e(url('/notifications')) ?>">Thông báo</a>
<a class="btn btn-soft" href="<?= e(url('/profile')) ?>"><?= e($currentUser['full_name']) ?></a>
<form class="d-inline" method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="btn btn-link text-muted" type="submit">Đăng xuất</button></form>
<?php endif; ?>
</div></div></nav></div></header>
<main id="main-content"><?php if($message=Session::flash('success')): ?><div class="container mt-3"><div class="alert alert-success" role="status"><?= e($message) ?></div></div><?php endif; ?><?php if($message=Session::flash('error')): ?><div class="container mt-3"><div class="alert alert-danger" role="alert"><?= e($message) ?></div></div><?php endif; ?><?= $content ?></main>
<footer class="site-footer"><div class="container d-flex flex-column flex-md-row justify-content-between"><span>© <?= date('Y') ?> Home2Home</span><a class="footer-link" href="<?= e(url('/articles')) ?>">Cẩm nang du lịch và tin tức</a><span>Ở đâu cũng thấy như ở nhà.</span></div></footer>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script><script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(asset('js/app.js')) ?>"></script>
<?php /* A view may set $pageScripts=['js/feature.js'] for page-only behaviour; render() shares its scope with this layout. */ ?>
<?php foreach(($pageScripts??[]) as $pageScript): ?><script src="<?= e(asset($pageScript)) ?>"></script><?php endforeach; ?>
</body></html>
