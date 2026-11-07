<?php use Core\Session; require_once dirname(__DIR__).'/components/ui-icons.php'; ?>
<!doctype html><html lang="<?= e(\Core\I18n::locale()) ?>">
<head><?php require dirname(__DIR__).'/components/document-head.php'; ?><link rel="icon" type="image/svg+xml" href="<?= e(asset('images/favicon.svg')) ?>"></head>
<body class="auth-body">
<a class="skip-link" href="#auth-content"><?= e(__('Bỏ qua điều hướng')) ?></a>
<header class="auth-header container-fluid"><a class="brand" href="<?= e(url('/')) ?>">home2home<span class="brand-dot" aria-hidden="true">.</span></a><div class="appearance-controls auth-tools position-static align-self-center"><?php require dirname(__DIR__).'/components/appearance-controls.php'; ?></div></header>
<main class="auth-experience container-fluid"><div class="row align-items-stretch">
    <aside class="col-lg-6 auth-story" aria-label="<?= e(__('Cảm hứng du lịch Việt Nam')) ?>">
        <div class="auth-story-photo"><img src="<?= e(asset('images/figma/home-hero.png')) ?>" width="1312" height="520" alt="" fetchpriority="high"><div class="auth-story-copy"><p><?= e(__('Việt Nam, theo nhịp riêng của bạn.')) ?></p><h2><?= e(__('Ở đâu cũng thấy')) ?><br><em><?= e(__('như ở nhà.')) ?></em></h2><span class="auth-story-location"><?= ui_icon('pin') ?> <?= e(__('Hội An, Quảng Nam')) ?></span></div></div>
    </aside>
    <section class="col-lg-6 auth-panel" id="auth-content" tabindex="-1"><div class="auth-form-wrap">
        <a class="auth-back" href="<?= e(url('/')) ?>"><?= ui_icon('arrow') ?> <?= e(__('Quay lại khám phá')) ?></a>
        <?php if($message=Session::flash('success')): ?><div class="alert alert-success" role="status"><?= e(__($message)) ?></div><?php endif; ?>
        <?php if($message=Session::flash('error')): ?><div class="alert alert-danger" role="alert"><?= e(__($message)) ?></div><?php endif; ?>
        <?= $content ?>
        <p class="auth-footer">© <?= date('Y') ?> Home2Home · <?= e(__('Việt Nam, theo nhịp riêng của bạn.')) ?></p>
    </div></section>
</div></main><script src="<?= e(asset('js/app.js')) ?>"></script></body></html>
