<h1><?= e(__('Chào mừng trở lại.')) ?></h1>
<p class="auth-intro"><?= e(__('Những chỗ ở đã lưu, những chuyến đi sắp tới — tất cả đang chờ bạn.')) ?></p>
<form method="post" action="<?= e(url('/login')) ?>">
    <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next??'') ?>">
    <div class="form-group"><label for="email">Email</label><input class="form-control" id="email" type="email" name="email" value="<?= e($old['email']??'') ?>" autocomplete="email" placeholder="you@example.com" required></div>
    <div class="form-group"><div class="auth-password-heading"><label for="password"><?= e(__('Mật khẩu')) ?></label><a href="<?= e(url('/forgot-password')) ?>"><?= e(__('Quên mật khẩu?')) ?></a></div><input class="form-control" id="password" type="password" name="password" autocomplete="current-password" required></div>
    <button class="btn btn-primary btn-block" type="submit"><?= e(__('Đăng nhập')) ?> <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6"/></svg></button>
</form>
<p class="auth-switch"><?= e(__('Chưa có tài khoản?')) ?> <a href="<?= e(url('/register'.(!empty($next)?'?'.http_build_query(['next'=>$next]):''))) ?>"><?= e(__('Đăng ký ngay')) ?></a></p>
<?php if((require dirname(__DIR__,3).'/config/app.php')['env']==='local'): ?>
<details class="auth-demo"><summary><?= e(__('Tài khoản trải nghiệm')) ?></summary><p><?= e(__('Tài khoản kiểm thử — mật khẩu {password}',['password'=>'Password123!'])) ?></p><p>Guest: guest@home2home.test<br>Host: host@home2home.test<br>Admin: admin@home2home.test</p></details>
<?php endif; ?>
