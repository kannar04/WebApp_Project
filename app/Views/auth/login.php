<h1><?= e(__('Chào mừng trở lại.')) ?></h1>
<p class="auth-intro"><?= e(__('Những chỗ ở đã lưu, những chuyến đi sắp tới — tất cả đang chờ bạn.')) ?></p>
<?php require dirname(__DIR__).'/components/google-sign-in.php'; ?>
<form method="post" action="<?= e(url('/login')) ?>">
    <?= csrf_field() ?><?php if($returnTarget!==''): ?><input type="hidden" name="next" value="<?= e($returnTarget) ?>"><?php endif; ?>
    <div class="form-group"><label for="email">Email</label><input class="form-control" id="email" type="email" name="email" value="<?= e($old['email']??'') ?>" autocomplete="email" placeholder="you@example.com" required></div>
    <div class="form-group"><div class="auth-password-heading"><label for="password"><?= e(__('Mật khẩu')) ?></label><a href="<?= e(url('/forgot-password').($returnTarget!==''?'?next='.rawurlencode($returnTarget):'')) ?>"><?= e(__('Quên mật khẩu?')) ?></a></div><input class="form-control" id="password" type="password" name="password" autocomplete="current-password" required></div>
    <?php $captchaAction='login'; require dirname(__DIR__).'/components/captcha.php'; ?>
    <button class="btn btn-primary btn-block" type="submit"><?= e(__('Đăng nhập')) ?> <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6"/></svg></button>
</form>
<p class="auth-switch"><?= e(__('Chưa có tài khoản?')) ?> <a href="<?= e(url('/register').($returnTarget!==''?'?next='.rawurlencode($returnTarget):'')) ?>"><?= e(__('Đăng ký ngay')) ?></a></p>
<?php if((require dirname(__DIR__,3).'/config/app.php')['env']==='local'): ?>
<details class="auth-demo"><summary><?= e(__('Tài khoản trải nghiệm')) ?></summary><div class="demo-note"><p><?= e(__('Tài khoản kiểm thử — mật khẩu {password}',['password'=>'Password123!'])) ?></p><p>Guest: guest@home2home.test<br>Host: host@home2home.test<br>Admin: admin@home2home.test</p></div></details>
<?php endif; ?>
