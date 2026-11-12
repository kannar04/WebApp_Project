<?php $googleReady=(new \App\Services\GoogleAuth())->configured(); ?>
<section class="surface-card mt-4" id="connections" aria-labelledby="connections-title">
    <h2 id="connections-title"><?= e(__('Tài khoản liên kết')) ?></h2>
    <div class="google-connection-heading"><?php require __DIR__.'/google-mark.php'; ?><strong>Google</strong><?php if(!empty($googleLinked)): ?><span class="google-connected"><?= e(__('Đã liên kết')) ?></span><?php endif; ?></div>
    <?php if(!empty($googleLinked)): ?>
        <p class="text-muted mb-0"><?= e(__('Bạn có thể đăng nhập Home2Home bằng tài khoản Google đã liên kết.')) ?></p>
    <?php elseif($googleReady): ?>
        <p class="text-muted"><?= e(__('Xác nhận mật khẩu hiện tại rồi chọn tài khoản Google muốn dùng để đăng nhập.')) ?></p>
        <form method="post" action="<?= e(url('/auth/google/link')) ?>">
            <?= csrf_field() ?>
            <label for="google-link-password"><?= e(__('Mật khẩu hiện tại')) ?></label>
            <input class="form-control mb-3" type="password" id="google-link-password" name="current_password" autocomplete="current-password" required>
            <button type="submit" class="google-auth-button"><?php require __DIR__.'/google-mark.php'; ?><span><?= e(__('Liên kết với Google')) ?></span></button>
        </form>
    <?php else: ?>
        <p class="text-muted mb-0"><?= e(__('Google hiện chưa khả dụng. Bạn vẫn có thể dùng email và mật khẩu.')) ?></p>
    <?php endif; ?>
    <?php require __DIR__.'/facebook-connection.php'; ?>
</section>
