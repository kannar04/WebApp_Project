<?php
if(!(require dirname(__DIR__,3).'/config/facebook.php')['enabled']){return;}
$facebookReady=(new \App\Services\FacebookAuth())->configured();
?>
<div class="facebook-connection">
    <div class="google-connection-heading"><?php require __DIR__.'/facebook-mark.php'; ?><strong>Facebook</strong><?php if(!empty($facebookLinked)): ?><span class="google-connected"><?= e(__('Đã liên kết')) ?></span><?php endif; ?></div>
    <?php if(!empty($facebookLinked)): ?>
    <p class="text-muted mb-0"><?= e(__('Bạn có thể đăng nhập Home2Home bằng tài khoản Facebook đã liên kết.')) ?></p>
    <?php elseif($facebookReady): ?>
    <p class="text-muted"><?= e(__('Xác nhận mật khẩu hiện tại rồi chọn tài khoản Facebook muốn dùng để đăng nhập.')) ?></p>
    <form method="post" action="<?= e(url('/auth/facebook/link')) ?>">
        <?= csrf_field() ?>
        <label for="facebook-link-password"><?= e(__('Mật khẩu hiện tại')) ?></label>
        <input class="form-control mb-3" type="password" id="facebook-link-password" name="current_password" autocomplete="current-password" required>
        <button class="google-auth-button facebook-auth-button" type="submit"><?php require __DIR__.'/facebook-mark.php'; ?><span><?= e(__('Liên kết với Facebook')) ?></span></button>
    </form>
    <?php else: ?><p class="text-muted mb-0"><?= e(__('Facebook hiện chưa khả dụng. Bạn vẫn có thể dùng Google hoặc email và mật khẩu.')) ?></p><?php endif; ?>
</div>
